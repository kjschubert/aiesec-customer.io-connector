<?php
class Application {

    private $_id;
    private $_uid;
    private $_extraInfo = array();
    private $_PRG;
    private $_filename;
    private $_status;
    private $_oldstatus = 0;
    private $_needMeta = false;
    private $_created_at;

    private $_log;

    private $_meta;

    /**
     * Application constructor.
     * @param $a    the GIS API response for this application
     * @param $uid  the GIS person id of the applicant
     * @param $log  instance of KLogger
     */
    function __construct($a, $uid, $log) {
        $this->_log = $log;
        $this->_uid = $uid;

        // check application id
        $this->_id = intval($a->id);
        if($this->_id < 1) {
            $log->log(\Psr\Log\LogLevel::INFO, "application id " . $this->_id . " is not valid", (array)$a);
            return false;
        }

        // save programm (needed for the event names)
        $this->_PRG = strtoupper($a->opportunity->programmes[0]->short_name);

        // save created at (needed for event open/applied)
        $this->_created_at = strtotime($a->created_at);

        // construct filename
        $this->_filename = BASE_PATH . '/data/application_' . $this->_id . '.stat';

        // determine integer value of current status
        $this->_status = $this->IntStatus($a->status);
        if($this->_status === false) {
            $this->_log->log(\Psr\Log\LogLevel::ALERT, "We encountered an application with an unknown state. This state has to be implemented.", array($a->status, $a->current_status));
            return false;
        }

        // check if application was already synced and determine old status
        if(file_exists($this->_filename)) {
            $this->_oldstatus = file_get_contents($this->_filename);

            if($this->_oldstatus === false) {
                $this->_log->log(\Psr\Log\LogLevel::ERROR, "Could NOT read old status for application " . $this->_id);
                return false;
            }

            if($this->_oldstatus < -2 || $this->_oldstatus > 5) {
                $this->_log->log(\Psr\Log\LogLevel::EMERGENCY, "Old status is out of specifications for application " . $this->_id);
                return false;
            }

            if($this->_oldstatus == $this->_status) {
                // if the status is still the same we don't need to instantiate this application
                return false;
            }
        }

        // determine if we need the meta information
        if($this->_status != 1) {
            $this->_needMeta = true;
        }

        // collect extraInfo for events
        $this->_extraInfo['oid'] = intval($a->opportunity->id);
    }

    /**
     * @return int
     */
    public function getId() {
        return $this->_id;
    }

    /**
     * @return bool
     */
    public function needMeta() {
        return $this->_needMeta;
    }

    /**
     * @param $meta
     * @return bool
     */
    public function setMeta($meta) {
        if(is_object($meta)) {
            $this->_meta = $meta;
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return bool
     */
    public function hasEvent() {
        if($this->_oldstatus == $this->_status) {
            return false;
        } else {
            return true;
        }
    }


    public function nextEvent($cio) {
        // just to make sure there is really an event to trigger
        if($this->_status != $this->_oldstatus) {
            if($this->_status < 0) {
                $newstatus = $this->_status;
            } else {
                $newstatus = $this->_oldstatus + 1;
            }

            // get timestamp
            $timestamp = 0;
            if($newstatus == 1) {
                $timestamp = $this->_created_at;
            } else if ($newstatus == 2){
                $timestamp = strtotime($this->_meta->date_matched);
            } else {
                $timestamp = strtotime($this->_meta->{'date_' . $this->StrStatus($newstatus)});
            }

            // trigger event
            $event = $this->_PRG . $this->StrStatus($newstatus);
            if($cio->triggerEvent($this->_uid, $event, $this->_extraInfo, $timestamp)) {
                $this->_log->log(\Psr\Log\LogLevel::DEBUG, "triggered event " . $event . " for user " . $this->_uid . " on application " . $this->_id);
                $this->_oldstatus = $newstatus;
                return true;
            } else {
                $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT trigger event " . $event . " for user " . $this->_uid . " on application " . $this->_id . ". Code: " . $cio->errorCode . ", Message: " . $cio->errorMessage);
                return false;
            }
        }
    }

    /**
     * @return bool
     */
    public function saveStatus() {
        if(file_put_contents($this->_filename, $this->_oldstatus)) {
            return true;
        } else {
            $this->_log->log(\Psr\Log\LogLevel::EMERGENCY, "Could NOT save value " . $this->_status . " in file " . $this->_filename);
            return false;
        }
    }

    /**
     * @param $status
     * @param $current_status
     * @return bool|int
     */
    private function IntStatus($status) {
        switch($status) {
            case 'approved_ep_manager':
            case 'applied':
            case 'open':
                return 1;

            case 'accepted':
            case 'matched':
                return 2;

            case 'approved':
                return 3;

            case 'realized':
                return 4;

            case 'completed':
                return 5;

            case 'withdrawn':
                return -1;

            case 'rejected':
                return -2;

            default:
                return false;
        }
    }

    /**
     * @param $intStatus
     * @return bool|string
     */
    private function StrStatus($intStatus) {
        switch(intval($intStatus)) {
            case 1:
                return 'applied';

            case 2:
                return 'accepted';

            case 3:
                return 'approved';

            case 4:
                return 'realized';

            case 5:
                return 'completed';

            case -1:
                return 'withdrawn';

            case -2:
                return 'rejected';

            default:
                return false;
        }
    }
}