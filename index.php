<?php
require_once('PHP-GIS-Wrapper/gis-wrapper/AuthProviderUser.php');
require_once('PHP-GIS-Wrapper/gis-wrapper/GIS.php');

require_once('customerio.php/CustomerIO.php');

require_once('config.php');

date_default_timezone_set('Europe/Berlin');

//check that data directory is writeable
if(!file_exists('./data') || !is_writeable('./data')) {
    die("data directory must be writeable");
}

// login to GIS and instantiate GIS wrapper
$user = new \GIS\AuthProviderUser(GIS_USER, GIS_PW);
$gis = new \GIS\GIS($user);

// save GIS user id, because we need it to make us EP manager
define("GIS_UID", intval($gis->current_person->get()->person->id));

// instantiate customer.io wrapper
$cio = new CustomerIO(API_KEY, SITE_ID);

$gis->people->filters->committee_scope = 677;
foreach($gis->people as $p) {
    echo $p->full_name . PHP_EOL;

    // create an array with additional data to be sent to customer.io
    $userdata = array(
        "first_name" => $p->first_name,
        "last_name" => $p->last_name,
        "full_name" => $p->full_name,
        "birthday" => $p->dob,
        "interviewed" => $p->interviewed,
        "photo" => $p->profile_photo_url,
        "status" => $p->status,
        "cv" => $p->cv_url->url,
        "nps_score" => $p->nps_score,
        "can_apply" => $p->permissions->can_apply
    );

    // Create or Update user
    if(file_exists('./data/person_' . intval($p->id) . '.txt')) {
        $tmp = file_get_contents('./data/person_' . intval($p->id) . '.txt');
        if($tmp == "") $oud = array();
        else $oud = unserialize($tmp);
        unset($tmp);

        foreach($userdata as $key => $v) {
            if(isset($oud[$key]) && $oud[$key] == $v) unset($userdata[$key]);
        }

        $cio->EditUser(intval($p->id), $userdata);
    } else {
        $cio->addUser(intval($p->id), $p->email, strtotime($p->created_at), $userdata);

        file_put_contents('./data/person_' . intval($p->id) . '.txt', serialize($userdata));
    }

    foreach($gis->people->{'_' . intval($p->id)}->applications as $a) {
        $prg = strtoupper($a->opportunity->programmes[0]->short_name);

        // directly send applied event if needed, because we don't need to load the full applicatio for that
        if(!file_exists('./data/application_' . intval($a->id) . '.txt')) {
            $cio->triggerEvent($p->id, $prg . 'applied', array('oid' => intval($a->opportunity->id)), strtotime($a->created_at));
            if(file_put_contents('./data/application_' . intval($a->id) . '.txt', 'open') === false) throw new Exception("Couldn't save status open for application " . intval($a->id));

            $oldstatus = "open";
        } else {
            $oldstatus = file_get_contents('./data/application_' . intval($a->id) . '.txt');
        }

        if($oldstatus != "open" && $oldstatus != "matched" && $oldstatus != "accepted" && $oldstatus != "realized" && $oldstatus != "completed" && $oldstatus != "withdrawn") throw new Exception("Old status for application " . intval($a->id) . " is invalid.");

        // break if the status is still the same
        if($oldstatus == 'withdrawn' || $oldstatus == 'completed') break;
        if($oldstatus == 'accepted' && $a->status == 'matched' && $a->current_status == 'approved') break;
        if($oldstatus == $a->status && $a->current_status != 'approved') break;

        // if we are still here we have to load the full application to get the timestamps and therefore need to be EP manager
        $wasEPmanager = true;
        $ms = array();
        $p = $gis->people->{'_' . intval($p->id)}->get();
        foreach($p->managers as $m) {
            $ms[] = intval($m->id);
        }

        if(!in_array(GIS_UID, $ms)) {
            $wasEPmanager = false;
            $tmp = $ms;
            $tmp[] = GIS_UID;
            updateEPmanagers($gis, $p->id, $tmp);
            unset($tmp);
        }

        // now load the application
        $a = $gis->applications->{'_' . $a->id}->get();

        if($a->status == 'withdrawn') {
            // if it's withdrawn we only trigger the withdrawn event and nothing before (if it's not already triggered)
            $time = strtotime($a->meta->date_matched);
            if($time == 0) $time = null;
            $cio->triggerEvent($p->id, $prg . 'withdrawn', array('oid' => intval($a->opportunity->id)), $time);
            if(file_put_contents('./data/application_' . intval($a->id) . '.txt', 'withdrawn') === false) throw new Exception("Couldn't save status withdrawn for application " . intval($a->id));
        } else {
            switch($oldstatus) {
                case 'open':
                    // trigger matched event
                    $time = strtotime($a->meta->date_matched);
                    if($time == 0) $time = null;
                    $cio->triggerEvent($p->id, $prg . 'matched', array('oid' => intval($a->opportunity->id)), $time);

                    // leave if the current status is matched
                    if($a->status == 'matched' && $a->current_status == 'matched') {
                        if(file_put_contents('./data/application_' . intval($a->id) . '.txt', 'matched') === false) throw new Exception("Couldn't save status matched for application " . intval($a->id));
                        break;
                    }

                case 'matched':
                    // trigger accepted event
                    $time = strtotime($a->meta->date_approved);
                    if($time == 0) $time = null;
                    $cio->triggerEvent($p->id, $prg . 'accepted', array('oid' => intval($a->opportunity->id)), $time);

                    // leave if the current status is accepted
                    if($a->status == 'matched' && $a->current_status == 'approved') {
                        if(file_put_contents('./data/application_' . intval($a->id) . '.txt', 'accepted') === false) throw new Exception("Couldn't save status accepted for application " . intval($a->id));
                        break;
                    }

                case 'accepted':
                    // trigger realized event
                    $time = strtotime($a->meta->date_realized);
                    if($time == 0) $time = null;
                    $cio->triggerEvent($p->id, $prg . 'realized', array('oid' => intval($a->opportunity->id)), $time);

                    // leave if the current status is realized
                    if($a->status == 'matched' && $a->current_status == 'approved') {
                        if(file_put_contents('./data/application_' . intval($a->id) . '.txt', 'realized') === false) throw new Exception("Couldn't save status realized for application " . intval($a->id));
                        break;
                    }

                case 'realized':
                    // trigger completed event
                    $time = strtotime($a->meta->date_completed);
                    if($time == 0) $time = null;
                    $cio->triggerEvent($p->id, $prg . 'completed', array('oid' => intval($a->opportunity->id)), $time);

                    if(file_put_contents('./data/application_' . intval($a->id) . '.txt', 'completed') === false) throw new Exception("Couldn't save status completed for application " . intval($a->id));

            }
        }
        if(!$wasEPmanager) {
            updateEPmanagers($gis, $p->id, $ms);
        }
    }
}

// ATTENTION: Don't look at this function it contains a lot of very messy code
function updateEPmanagers($gis, $pid, $managers = array()) {
    global $user;
    $url = "https://gis-api.aiesec.org:443/v2/people/" . $pid . ".json?access_token=" . $user->getToken();
    $ms = "";
    if(count($managers) > 0) {
        foreach($managers as $m) {
            $ms .= "&person%5Bmanager_ids%5D%5B%5D=" . $m;
        }
    } else {
        $ms = "&person%5Bmanager_ids%5D";
    }

    $req = curl_init($url . $ms);
    curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($req, CURLOPT_CUSTOMREQUEST, 'PATCH');
    $res = json_decode(curl_exec($req));
    curl_close($req);

    if($res === null) throw new Exception("Invalid GIS API Response on " . $url . $ms);

    if(isset($res->status->code) && $res->status->code != "200") {
        if($res->status->code == "401") {
            $url = "https://gis-api.aiesec.org:443/v2/people/" . $pid . ".json?access_token=" . $user->getNewToken() . $ms;
            $req = curl_init($url);
            curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($req, CURLOPT_CUSTOMREQUEST, 'PATCH');
            $res = json_decode(curl_exec($req));
            curl_close($req);

            if($res == null) throw new Exception("Invalid GIS API Response on " . $url);

            if(isset($res->status->code) && $res->status->code != "200") throw new Exception($res->status->message);
        } else {
            throw new Exception($res->status->message);
        }
    }
}
?>