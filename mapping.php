<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

admin_externalpage_setup('local_dbgroupenrol_mapping');
require_login();
require_capability('moodle/site:config', \context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new \moodle_url('/local/dbgroupenrol/mapping.php'));
$PAGE->set_title(get_string('mappingpage', 'local_dbgroupenrol'));
$PAGE->set_heading(get_string('mapping_heading', 'local_dbgroupenrol'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mapping_heading', 'local_dbgroupenrol'));
echo \html_writer::tag('p', get_string('mapping_intro', 'local_dbgroupenrol'));

if (!empty($action) && !confirm_sesskey()) {
    print_error('invalidsesskey');
}

// Build mappings from external DB (preview).
$mappings = [];

$enrol = enrol_get_plugin('database');
if ($enrol) {
    $remote_table      = $enrol->get_config('remoteenroltable');
    $remote_course     = $enrol->get_config('remotecoursefield');
    $remote_user       = $enrol->get_config('remoteuserfield');
    $local_coursefield = $enrol->get_config('localcoursefield');
    $local_userfield   = $enrol->get_config('localuserfield');
    $remote_groupname  = get_config('enrol_database', 'remotegroupnamefield');

    if (!empty($remote_table) && !empty($remote_course) && !empty($remote_user) &&
        !empty($local_coursefield) && !empty($local_userfield) && !empty($remote_groupname)) {

        $ref = new \ReflectionClass(get_class($enrol));
        if ($ref->hasMethod('db_init')) {
            $method = $ref->getMethod('db_init');
            $method->setAccessible(true);
            $extdb = $method->invoke($enrol);
            if ($extdb) {
                $sql = "SELECT DISTINCT {$remote_user} AS remoteuser,
                                        {$remote_course} AS remotecourse,
                                        {$remote_groupname} AS groupname
                          FROM {$remote_table}
                         WHERE {$remote_groupname} IS NOT NULL
                           AND {$remote_groupname} <> ''";
                $rs = $extdb->Execute($sql);
                if ($rs) {
                    global $DB;
                    while ($row = $rs->FetchRow()) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $remoteuser   = null;
                        $remotecourse = null;
                        $groupname    = null;

                        if (array_key_exists('remoteuser', $row)) {
                            $remoteuser = $row['remoteuser'];
                        } elseif (array_key_exists(0, $row)) {
                            $remoteuser = $row[0];
                        }

                        if (array_key_exists('remotecourse', $row)) {
                            $remotecourse = $row['remotecourse'];
                        } elseif (array_key_exists(1, $row)) {
                            $remotecourse = $row[1];
                        }

                        if (array_key_exists('groupname', $row)) {
                            $groupname = $row['groupname'];
                        } elseif (array_key_exists(2, $row)) {
                            $groupname = $row[2];
                        }

                        if ($remoteuser === null || $remotecourse === null || $groupname === null) {
                            continue;
                        }

                        $remoteuser   = trim($remoteuser);
                        $remotecourse = trim($remotecourse);
                        $groupname    = trim($groupname);

                        if ($remoteuser === '' || $remotecourse === '' || $groupname === '') {
                            continue;
                        }

                        $user = $DB->get_record('user', [$local_userfield => $remoteuser, 'deleted' => 0],
                            'id,username,firstname,lastname', IGNORE_MISSING);
                        if (!$user) {
                            continue;
                        }

                        $course = $DB->get_record('course', [$local_coursefield => $remotecourse],
                            'id,shortname,fullname', IGNORE_MISSING);
                        if (!$course) {
                            continue;
                        }

                        $mappings[] = (object)[
                            'userid'      => (int)$user->id,
                            'username'    => $user->username,
                            'fullname'    => fullname($user),
                            'courseid'    => (int)$course->id,
                            'courseshort' => $course->shortname,
                            'coursefull'  => $course->fullname,
                            'groupname'   => $groupname,
                        ];
                    }
                    $rs->Close();
                }
            }
        }
    }
}

// If "apply" → enforce mappings via observer::apply_group_for_user_in_course.
if ($action === 'apply' && !empty($mappings)) {
    foreach ($mappings as $map) {
        \local_dbgroupenrol\observer::apply_group_for_user_in_course(
            $map->userid,
            $map->courseid,
            $map->groupname
        );
    }
    echo $OUTPUT->notification(get_string('mapping_applied', 'local_dbgroupenrol'), 'notifysuccess');
}

// Controls form.
echo \html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new \moodle_url('/local/dbgroupenrol/mapping.php')
]);
echo \html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'sesskey',
    'value' => sesskey()
]);
echo \html_writer::start_div();
echo \html_writer::tag(
    'button',
    get_string('mapping_run_dry', 'local_dbgroupenrol'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'dry', 'class' => 'btn btn-secondary']
);
echo ' ';
echo \html_writer::tag(
    'button',
    get_string('mapping_apply', 'local_dbgroupenrol'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'apply', 'class' => 'btn btn-primary']
);
echo \html_writer::end_div();
echo \html_writer::end_tag('form');

echo \html_writer::empty_tag('hr');

if (empty($mappings)) {
    echo $OUTPUT->notification(get_string('mapping_nomappings', 'local_dbgroupenrol'), 'notifyproblem');
} else {
    $count = count($mappings);
    echo \html_writer::tag('p', get_string('mapping_rows_count', 'local_dbgroupenrol', $count));

    $table = new \flexible_table('local-dbgroupenrol-mapping-table');
    $table->define_baseurl(new \moodle_url('/local/dbgroupenrol/mapping.php'));
    $table->define_columns(['username', 'fullname', 'courseshort', 'coursefull', 'groupname']);
    $table->define_headers([
        get_string('mapping_username', 'local_dbgroupenrol'),
        get_string('mapping_fullname', 'local_dbgroupenrol'),
        get_string('mapping_course_shortname', 'local_dbgroupenrol'),
        get_string('mapping_course_fullname', 'local_dbgroupenrol'),
        get_string('mapping_groupname', 'local_dbgroupenrol'),
    ]);
    $table->setup();

    foreach ($mappings as $map) {
        $table->add_data([
            s($map->username),
            s($map->fullname),
            s($map->courseshort),
            s($map->coursefull),
            s($map->groupname),
        ]);
    }

    $table->finish_output();
}

echo $OUTPUT->footer();
