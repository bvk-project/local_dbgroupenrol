<?php
defined('MOODLE_INTERNAL') || die();
$observers=[
 [
  'eventname'=>'\core\event\user_enrolled',
  'callback'=>'\local_dbgroupenrol\observer::user_enrolled',
  'includefile'=>'/local/dbgroupenrol/classes/observer.php',
  'priority'=>1000,
 ],
 [
  'eventname'=>'\core\event\user_loggedin',
  'callback'=>'\local_dbgroupenrol\observer::user_loggedin',
  'includefile'=>'/local/dbgroupenrol/classes/observer.php',
  'priority'=>1000,
 ],
];
