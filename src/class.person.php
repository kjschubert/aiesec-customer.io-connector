<?php
/**
 * Class Person
 *
 * @author Karl Johann Schubert <karljohann@familieschubi.de>
 * @version 0.2
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

    private $_GIS;

    /**
     * Person constructor.
     * @param $p        object with api response of this person
     * @param $GIS  instance of GISAuthProvider
     * @param $log      instance of KLogger
     */
    function __construct($p, $GIS, $log) {
        $this->_GIS = $GIS;
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
            "nps_score" => $p->nps_score,
            "can_apply" => $p->permissions->can_apply,
            "home_lc" => $p->home_lc->name,
            "home_lc_country" => $p->home_lc->country
        );
        if(isset($p->cv_url)) $this->_userdata["cv"] = $p->cv_url->url;

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
                    $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT update userdata on customer.io! UID: " . $this->_id . ", Code: " . $cio->errorCode . ", Message: " . $cio->errorMessage);
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
                $this->_log->log(\Psr\Log\LogLevel::WARNING, "Could NOT send userdata to customer.io! UID: " . $this->_id . ", Code: " . $cio->errorCode . ", Message: " . $cio->errorMessage);
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
     * @param $uid  uid of GIS Bot
     */
    function triggerEvents($cio, $uid) {
        // instantiate all applications, divide in those who need meta informations and those who don't
        $applications = array();
        $metaapplications = array();
        foreach($this->_GIS->people[$this->_id]->applications as $a) {
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
                if($application->setMeta( $this->_GIS->applications[$application->getId()]->get()->meta )) {
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
            while($application->hasEvent() && !$err) {
                $application->nextEvent($cio);
                $err = !$application->saveStatus();
            }
        }
    }

    /**
     * update the EP managers of this person
     * @param $managers array with the ids of the EP managers
     * @return bool
     */
    private function updateEPmanagers($managers) {
        if(isset($this->_GIS->people[$this->_id])) unset($this->_GIS->people[$this->_id]);

        $this->_GIS->people[$this->_id]->person->manager_ids = $managers;
        try {
            $res = $this->_GIS->people[$this->_id]->person->update();
        } catch(Exception $e) {
            $this->_log->log(\Psr\Log\LogLevel::INFO, "Could not update the EP managers of user " . $this->_id .  ":" . $e);
            return false;
        }
        if($res->managers == $managers) {
            $this->_log->log(\Psr\Log\LogLevel::DEBUG, "Updated the EP managers of user " . $this->_id, (array)$res->managers);
            return true;
        } else {
            $this->_log->log(\Psr\Log\LogLevel::DEBUG, "Could not update the EP managers of user " . $this->_id, (array)$res);
            return false;
        }
    }
}