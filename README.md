# AIESEC customer.io connector
Author: Karl Johann Schubert <karljohann@familieschubi.de>

## Intro
This script syncs all your data from AIESEC Global Information System into your customer.io Account.

It will import some basic user data and trigger an event every time the status of an application changes. With the possibilities of segmentation and triggered emails this will allow to write personalized mails to your customers along the whole customer flow.

At the moment the script only synchronizes people which you can see in the CRM, which means only people from your LC. Support for people from different LCs which apply for an opportunity in your LC will maybe be synced later. The script is not limited to a program at the moment this means it syncs GIP, GCDP, TMP and TLP applications.

## Synced informations
### user profile
- id, email and created at are transfered the first time
- the following profile informations will be update during every run
    - first name
    - last name
    - fill name
    - birthday
    - interviewed
    - photo
    - status
    - cv (link)
    - nps score
    - can apply
    - home lc
    - home lc country

### events
- the event names follow the schema PROGRAMME_SHORT_NAMEeventtype
- at the moment programm short names are:
    - GIP
    - GCDP
    - TMP
    - TLP
- event types are:
    - applied
    - accepted
    - approved
    - realized
    - completed
    - withdrawn
    - rejected

For your GCDP EPs the event can look like:
1. GCDPapplied
2. GCDPaccepted
3. GCDPapproved
4. GCDPrealized
5. GCDPcompleted

## Usage
Please use the wiki to share informations on how to use the script and more importantly to share examples for segments, triggered mails and mail layouts.

## Installation
### Basics
The script is not running infinitely by itself. This design decision was made based on the fact that it's written in PHP, whereby it's a good idea to restart the php process from time to time. With this in mind it's locking it's base directory so no matter how often it's started it will only run ones at a time. Thereby you have different methods to keep it running.

### Installation as cronjob on debian
* Requirements: php-cli, git, cron (`apt-get install php-cli, git, cron`)
* Feel free to replace vi with an editor of your choice
* clone the git repository
```
cd /opt
git clone https://github.com/kjschubert/aiesec-customer.io-connector.git
```
* copy example config and adapt it
```
cd /opt/aiesc-customer.io-sync/
cp config.example.php config.php
vi config.php
```
* create a new user
```
useradd -s /bin/false -r -M -d /opt/aiesec-customer.io-connector ciosync
```
* create a folder for log files and set all rights accordingly
```
mkdir /var/log/aiesec-customer.io-connector
mkdir /opt/aiesec-customer.io-connector/data
chown -R ciosync:ciosync /opt/aiesec-customer.io-connector
chmod 750 /opt/aiesec-customer.io-connector
chmod 750 /opt/aiesec-customer.io-connector/data
chown ciosync:ciosync /var/log/aiesec-customer.io-connector
chmod 755 /var/log/aiesec-customer.io-connector
```
* create cron job
```
echo "*/5 * * * * ciosync cd /opt/aiesec-customer.io-connector && php run.php" > /etc/cron.d/ciosync
service cron reload
```