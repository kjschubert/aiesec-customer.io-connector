<?php
require_once(BASE_PATH . '/src/class.application.php');

/**
 * Class Person
 */
class Person {

    private $_userdata;

    private $_new;

    private $_id;

    private $_email;

    private $_created_at;

    private $_log;

    private $_filename;

    private $_managers = array();

    private $_GISAuth;

    /**
     * Person constructor.
     * @param $p        object with api response of this person
     * @param $GISAuth  instance of GISAuthProvider
     * @param $log      instance of KLogger
     */
    function __construct($p, $GISAuth, $log) {
        $this->_GISAuth = $GISAuth;
        $this->_log = $log;

        // retrieve needed userdata from GIS
        $this->_id = intval($p->id);
        $this->_email = $p->email;
        $this->_created_at = strtotime($p->created_at);
        $this->_userdata = array(
            "first_name" => $p->first_name,
            "last_name" => $p->last_name,
            "full_name" => $p->full_name,
            "birthday" => $p->dob,
            "interviewed" => $p->interviewed,
            "photo" => $p->profile_photo_url,
            "status" => $p->status,
            "cv" => $p->cv_url->url,
            "nps_score" => $p->nps_score,
            "can_apply" => $p->permissions->can_apply,
            "home_lc" => $p->home_lc->name,
            "home_lc_country" => $p->home_lc->country
        );

        //check that id is valid
        if($this->_id < 1) {
            $log->log(\Psr\Log\LogLevel::INFO, "user id " . $this->_id . " is not valid", (array)$p);
            return false;
        }

        // retrieve EP manager IDs
        foreach($p->managers as $m) {
            $i = intval($m->id);
            if($i < 1) {
                $this->_log->log(\Psr\Log\LogLevel::ALERT, "EP " . $this->_id . " has an EP manager with an invalid id", (array)$m);
                return false;
            } else {
                $this->_managers[] = $i;
            }
        }

        // construct filename for datafile
        $this->_filename = BASE_PATH . '/data/person_' . $this->_id . '.bin';
    }

    /**
     * @param $cio  Instance of customer.io wrapper
     * @return bool
     */
    function updateData($cio) {
        if(file_exists($this->_filename)) {
            // load serialized user data from disk
            $data = file_get_contents($this->_filename);
            if($data === false) {
                $this->_log->log(\Psr\Log\LogLevel::WARNING, "Couldn't load data from file " . $this->_filename);
                $old = array();
            } else {
                $old = unserialize($data);
            }

            if(!is_array($old)) {
                $this->_log->log(\Psr\Log\LogLevel::WARNING, "Data loaded for person " . $this->_id . " is corrupted");
                $old = array();
            }

            // only push data to customer.io which has changed
            $new = $this->_userdata;
            foreach($new as $key => $val) {
                if(array_key_exists($key, $old) && array_key_exists($key, $new) && $old[$key] == $val) unset($new[$key]);
            }

            // update customer.io if there are changes
            if(count($new) > 0) {
                if($cio->EditUser($this->_id, $this->_userdata)) {
                    $this->_log->log(\Psr\Log\LogLevel::DEBUG, "Updated user on customer.io", array($this->_id, (array)$this->_userdata));
                    return $this->saveUserdata();
                } else {
                    $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT update userdata on customer.io! UID: " . $this->id . ", Code: " . $cio->errorCode . ", Message: " . $cio->errorMessage);
                    return false;
                }
            }
            return true;
        } else {
            // add user to customer.io
            if($cio->addUser($this->_id, $this->_email, $this->_created_at, $this->_userdata)) {
                $this->_log->log(\Psr\Log\LogLevel::DEBUG, "Added user to customer.io", array($this->_id, $this->_email, $this->_created_at, (array)$this->_userdata));
                return $this->saveUserdata();
            } else {
                $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT send userdata to customer.io! UID: " . $this->id . ", Code: " . $cio->errorCode . ", Message: " . $cio->errorMessage);
                return false;
            }
        }
    }

    /**
     * @return bool
     */
    private function saveUserdata() {
        // try to save serialized user data to disk
        if(!file_put_contents($this->_filename, serialize($this->_userdata))) {
            $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT save userdata for " . $this->_id . " in " . $this->_filename);
            return false;
        }

        return true;
    }

    /*
     * @param $cio  Instance of customer.io wrapper
     * @param $gis  Instance of GIS wrapper
     * @param $uid  uid of GIS Bot
     */
    function triggerEvents($cio, $gis, $uid) {
        // instantiate all applications, divide in those who need meta informations and those who don't
        $applications = array();
        $metaapplications = array();
        foreach($gis->people->{'_' . $this->_id}->applications as $a) {
            $application = new Application($a, $this->_id, $this->_log);
            if ($application) {
                if ($application->needMeta()) {
                    $metaapplications[] = $application;
                } else {
                    $applications[] = $application;
                }
            }
        }

        $this->_log->log(\Psr\Log\LogLevel::DEBUG, "User " . $this->_id . " has " . count($metaapplications) . " applications which need meta informations and " . count($applications) . " which don't need them.");

        // retrieve metadata for all applications which needs it
        if(count($metaapplications) > 0) {
            // we need to be EP manager to retrieve the meta data
            if(!in_array($uid, $this->_managers)) {
                $m = $this->_managers;
                $m[] = $uid;
                if(!$this->updateEPmanagers($m)) {
                    $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT become EP manager for user " . $this->_id . ". This means we have to skip all applications which need meta data");
                    $metaapplications = array();
                }
            }

            // retrieve all the metadata
            foreach($metaapplications as $application) {
                if($application->setMeta( $gis->applications->{'_' . $application->getId()}->get()->meta )) {
                    $applications[] = $application;
                } else {
                    $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT get meta data for application " . $application->getId() . ". Skipping that application.");
                }
            }
            unset($metaapplications);

            // check if we need to drop EP manager rights
            if(!in_array($uid, $this->_managers)) {
                if(!$this->updateEPmanagers($this->_managers)) {
                    $this->_log->log(\Psr\Log\LogLevel::INFO, "Could NOT remove GIS Bot as EP manager from person " . $this->_id, (array)$this->_managers);
                }
            }
        }

        // trigger the events for every application, but stop if we can not save the state of an application
        foreach($applications as $application) {
            $err = false;
            if(!$application->hasEvent()) var_dump($application);
            while($application->hasEvent() && !$err) {
                $application->nextEvent($cio);
                $err = !$application->saveStatus();
            }
        }
    }

    /**
     * update the EP managers of this person
     * @param $managers array with the ids of the EP managers
     */
    private function updateEPmanagers($managers) {
        // base url
        $url = "https://gis-api.aiesec.org:443/v2/people/" . $this->_id . ".json?";

        // add ep manager argument to url
        if(count($managers) > 0) {
            foreach($managers as $m) {
                $url .= "person%5Bmanager_ids%5D%5B%5D=" . $m . "&";
            }
        } else {
            $url .= "person%5Bmanager_ids%5D&";
        }

        // initialize working variables
        $success = false;
        $i = 0;
        $token = $this->_GISAuth->getToken();

        // try up to 3 times to update the EP managers
        while(!$success && $i < 3) {
            $i++;
            // send request with curl
            $req = curl_init($url . "access_token=" . $token);
            curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($req, CURLOPT_CUSTOMREQUEST, 'PATCH');
            $res = json_decode(curl_exec($req));
            curl_close($req);

            // check response
            if(isset($res->status) && isset($res->status->code)) {
                if($res->status->code == "401") {
                    $token = $this->_GISAuth->getNewToken();
                } else {
                    $this->_log->log(\Psr\Log\LogLevel::INFO, "GIS API responded with an error, while updating the EP managers of user " . $this->_id .  ":" . $res->status->code . " . " . $res->status->message . ". Attempt " . $i, array($url));
                }
            }
            if(is_array($res->managers)) {
                $this->_log->log(\Psr\Log\LogLevel::DEBUG, "Updated the EP managers of user " . $this->_id, (array)$res->managers);
                $success = true;
            } else {
                echo $url;
                var_dump($res);
            }
        }

        return $success;
    }
}