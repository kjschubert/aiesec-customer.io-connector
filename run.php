<?php
/**
 * AIESEC-Customer.io-Connector
 * In this file the environment is checked and if possible the sync is started
 *
 * @author: Karl Johann Schubert <karljohann@familieschubi.de>
 * @version: 0.2
 */

// composer autoload
require_once __DIR__ . '/vendor/autoload.php';

// define the parent folder of this file as base path
define("BASE_PATH", dirname(__FILE__));

// check for a config file
if(file_exists(BASE_PATH . '/config.php')) {
    require_once(BASE_PATH . '/config.php');
} else {
    trigger_error("AIESEC-Customer.io-Connector is missing a config file", E_USER_ERROR);
    die();
}

// instantiate KLogger (we don't catch anything here, because we can not do anything about it)
$log = new \Katzgrau\KLogger\Logger(LOG, LOGLEVEL);
$log->log(\Psr\Log\LogLevel::INFO, "AIESEC-Customer.io-Connector is starting.");

// try to get lock
$pid = Lock::lock($log);

// check if we got the lock
if($pid < 1) {
    // there is a problem or another process running. Shutdown...
    $log->log(\Psr\Log\LogLevel::INFO, "AIESEC-Customer.io-Connector didn't got the lock. This instance is shutting down...");
} else {
    // we got the lock, so check that data directory is writeable
    if(!file_exists('./data') || !is_writeable('./data')) {
        die("data directory must be writeable");
    }

    //try to run the core and sync once
    try {
        $core = new Core($log);
        if($core) $core->run();
    } catch(Exception $e) {
        // catch all Exception to release the lock before dying.
        $log->log(\Psr\Log\LogLevel::ERROR, "We encountered an unhandled Exception: " . $e->getMessage(), (array)$e->getTrace());
    }

    // unlock the base directory
    Lock::unlock($log);
}

