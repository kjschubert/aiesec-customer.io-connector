<?php
/**
 * AIESEC-Customer.io-Connector
 * In this file the environment is checked and if possible the sync is started
 *
 * @author: Karl Johann Schubert <karljohann@familieschubi.de>
 * @version: 0.1
 */

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
require_once(BASE_PATH . '/KLogger/load.php');
$log = new \Katzgrau\KLogger\Logger(LOG);
$log->info("AIESEC-Customer.io-Connector is starting.");

// try to get lock
require_once(BASE_PATH . '/src/class.lock.php');
$pid = Lock::lock($log);

// check if we got the lock
if($pid < 1) {
    // there is a problem or another process running. Shutdown...
    $log->info("AIESEC-Customer.io-Connector didn't got the lock. This instance is shutting down...");
} else {
    // we got the lock, so check that data directory is writeable
    if(!file_exists('./data') || !is_writeable('./data')) {
        die("data directory must be writeable");
    }

    // require the core
    require_once(BASE_PATH . '/class.core.php');

    //try to run the core and sync once
    try {
        $core = new Core($log);
        $core->run();

        if(Lock::unlock($log)) {
            $log->info("Sync run without unhandled Exceptions and we released to lock successfully");
        } else {
            $log->warning("Sync run without unhandled Exceptions but we couldn't release the lock. You definitely have to look into this!");
        }
    } catch(Exception $e) {
        // catch all Exception to release the lock before dying.
        $log->error("We encountered an unhandled Exception: " . $e->getMessage(), $e->getTrace());

        if(Lock::unlock($log)) {
            $log->info("We are dying, but we released to lock successfully");
        } else {
            $log->warning("We are dying and couldn't release the lock. You definitely have to look into this!");
        }
    }
}

