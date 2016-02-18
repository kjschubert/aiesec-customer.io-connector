<?php
require_once(BASE_PATH . '/PHP-GIS-Wrapper/gis-wrapper/AuthProviderUser.php');
require_once(BASE_PATH . '/PHP-GIS-Wrapper/gis-wrapper/GIS.php');
require_once(BASE_PATH . '/customerio.php/CustomerIO.php');
require_once(BASE_PATH . '/src/class.person.php');

/**
 * Class Core
 * Core of the AIESEC-Customer.io-Connector
 *
 * @author Karl Johann Schubert <karljohann@familieschubi.de>
 * @version 0.1
 */
class Core {

    private $_GIS;

    private $_CIO;

    private $_log;

    private $_uid;

    private $_user;

    /**
     * @param $log KLogger instance
     */
    function __construct($log) {
        $this->_log = $log;

        // instantiate connection to GIS
        try {
            $this->_user = new \GIS\AuthProviderUser(GIS_USER, GIS_PW);
            $this->_GIS = new \GIS\GIS($this->_user);
        } catch (Exception $e) {
            $log->log(\Psr\Log\LogLevel::ERROR, "Could not connect to GIS: " . $e->getMessage(), (array)$e->getTrace());
            return false;
        }

        // get uid from GIS
        try {
            $this->_uid = intval($this->_GIS->current_person->get()->person->id);
        } catch (InvalidCredentialsException $e) {
            $this->_log->log(\Psr\Log\LogLevel::Error, "Invalid GIS Credentials: " . $e->getMessage());
            return false;
        } catch (Exception $e) {
            $this->_log->log(\Psr\Log\LogLevel::WARNING, "Couldn't get GIS Bot uid:" . $e->getMessage(), (array)$e->getTrace());
            return false;
        }

        // check that uid is valid
        if($this->_uid < 1) {
            $this->_log->log(\Psr\Log\LogLevel::Error, "Got a invalid uid from the GIS API!");
            return false;
        }

        // instantiate customer.io wrapper
        $this->_CIO = new CustomerIO(API_KEY, SITE_ID);
    }

    /**
     * @return void
     */
    function run() {
        $this->_GIS->people->filters->committee_scope = 677;
        foreach($this->_GIS->people as $p) {
            $person = new Person($p, $this->_user, $this->_log);
            if($person) {
                if($person->updateData($this->_CIO)) {
                    // we only trigger the events if we updated the user data completely successfully, but not even if we only could not save the userdata on disk, because thereby the risks occurs that we can not save the events on disk and then we will send them again
                    $person->triggerEvents($this->_CIO, $this->_GIS, $this->_uid);
                }
            } else {
                $this->_log->log(\Psr\Log\LogLevel::DEBUG, "Skipped person", (array)$p);
            }
            unset($person);
        }
    }
}