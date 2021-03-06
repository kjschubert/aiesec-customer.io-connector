<?php

/**
 * Class Lock
 * Coordinates the locking and unlocking of the base directory
 *
 * @author: Karl Johann Schubert <karljohann@familieschubi.de>
 * @version: 0.2
 */
class Lock {

        private static $_PID;

        function __construct() {}

        function __clone() {}

        private static function running() {
            $pids = explode(PHP_EOL, `ps -e | awk '{print $1}'`);

            if(in_array(self::$_PID, $pids))
                return TRUE;
            return FALSE;
        }

        public static function lock($log) {
            $lock_file = BASE_PATH . '/.lock';

            // check if lock file exists
            if(file_exists($lock_file)) {
                // get pid of locking process
                self::$_PID = file_get_contents($lock_file);

                // check if we locked it by ourselfs
                if(self::$_PID == getmypid()) return TRUE;

                // check if process is still running
                if(self::running()) {
                    $log->log(\Psr\Log\LogLevel::INFO, "AIESEC-Customer.io-Connector is still running with pid " . self::$_PID);
                    return FALSE;
                } else {
                    $log->log(\Psr\Log\LogLevel::ERROR, "AIESEC-Customer.io-Connector died. Please look into this accident and then manually delete the .lock-file");
                    die();
                }
            } else {
                // get our own pid
                self::$_PID = getmypid();

                // try to get the lock
                if(file_put_contents($lock_file, self::$_PID) > 0) {
                    // sleep 1s
                    sleep(1);

                    // check that we really got the lock
                    if(self::$_PID == file_get_contents($lock_file)) {
                        $log->log(\Psr\Log\LogLevel::INFO, "Process " . self::$_PID . " locked the base directory");
                        return self::$_PID;
                    } else {
                        $log->log(Psr\Log\LogLevel::WARNING, "Some process overwrote the lock for process " . self::$_PID);
                        return FALSE;
                    }
                } else {
                    $log->log(\Psr\Log\LogLevel::ERROR, "Couldn't write lock-file. Please make the base directory writeable for the php process");
                }
            }
        }

        public static function unlock($log) {
            $lock_file = BASE_PATH . '/.lock';

            if(file_exists($lock_file)) {
                // check that we had the lock
                if(file_get_contents($lock_file) == self::$_PID) {
                    // release the lock
                    if(unlink($lock_file)) {
                        $log->log(\Psr\Log\LogLevel::INFO, "Process" . self::$_PID . " released the lock");
                        return true;
                    } else {
                        $log->log(\Psr\Log\LogLevel::ERROR, "Process" . self::$_PID . " could NOT release the lock");
                        return false;
                    }
                } else {
                    $log->log(\Psr\Log\LogLevel::ERROR, "Process" . self::$_PID . " wanted to release the lock but didn't had it");
                    return false;
                }
            }
        }
    }
?>