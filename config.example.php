<?php
// make sure your PHP have a default timezone, else you will get errors
date_default_timezone_set('Europe/Berlin');

// define Username and Passwort of your Bot user on the GIS
define("GIS_USER", "GIS Bot Username");
define("GIS_PW", "GIS Bot Password");

// define your Site ID and API Key from customer.io
define("SITE_ID", "customer.io Site Id");
define("API_KEY", "customer.io API Key");

/*
 * define the folder in which we should write the logs
 * make sure it's writeable by PHP
 * the logs will be written as log_YYY-MM-DD.txt
 */
define("LOG", "/var/log/aiesec-customer.io-connector/");

/*
 * define the log level
 * 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'
 * I recommend info at the beginning and later on warning
 * please be aware that when you use debug there can be personal data in the log file.
 */
define("LOGLEVEL", 'info');
?>