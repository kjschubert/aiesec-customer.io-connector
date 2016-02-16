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

// instantiate customer.io wrapper
$cio = new \Narsic\Customerio\CustomerIO(API_KEY, SITE_ID);

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
    );

    if(file_exists('./data/person_' . intval($p->id) . '.txt')) {
        $cio->EditUser(intval($p->id), strtotime($p->created_at), $userdata);
    } else {
        $cio->addUser(intval($p->id), $p->email, strtotime($p->created_at), $userdata);

        // just create this file, don't care if it's working
        $f = fopen('./data/person_' . intval($p->id) . '.txt', 'w');
        fclose($f);
    }
}
?>