<?php
defined('MOODLE_INTERNAL') || die();
$tasks=[
 [
  'classname'=>'\local_dbgroupenrol\task\sync_groups',
  'blocking'=>0,
  'minute'=>'*/15',
  'hour'=>'*',
  'day'=>'*',
  'month'=>'*',
  'dayofweek'=>'*'
 ]
];
