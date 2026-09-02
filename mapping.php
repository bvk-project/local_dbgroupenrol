<?php
// Diagnostic mapping page for local_dbgroupenrol.
// This version preserves the existing Dry run / Apply mappings functionality,
// but adds detailed read-only diagnostics before the mapping table.

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

/**
 * Render a small two-column diagnostics table.
 *
 * @param array $rows Array of [label, value].
 */
function local_dbgroupenrol_diag_table(array $rows): void {
    $table = new \html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = ['Parametrs', 'Vērtība'];
    foreach ($rows as $row) {
        $table->data[] = [s((string)$row[0]), s((string)$row[1])];
    }
    echo \html_writer::table($table);
}

/**
 * Safely convert an external DB value to a trimmed string.
 *
 * @param mixed $value
 * @return string|null
 */
function local_dbgroupenrol_diag_string($value): ?string {
    if ($value === null) {
        return null;
    }
    if (is_scalar($value)) {
        return trim((string)$value);
    }
    return null;
}

/**
 * Get one alias/position value from an ADOdb row.
 *
 * @param array $row
 * @param string $alias
 * @param int $position
 * @return mixed
 */
function local_dbgroupenrol_diag_row_value(array $row, string $alias, int $position) {
    if (array_key_exists($alias, $row)) {
        return $row[$alias];
    }
    if (array_key_exists($position, $row)) {
        return $row[$position];
    }
    return null;
}

// Build mappings from external DB and collect diagnostics.
$mappings = [];
$issues = [];
$maxissues = 50;

$diag = [
    'pluginavailable' => false,
    'configcomplete' => false,
    'dbinitmethod' => false,
    'externalconnection' => false,
    'queryok' => false,
    'rawrows' => 0,
    'validrows' => 0,
    'invalidrows' => 0,
    'matchedusers' => 0,
    'missingusers' => 0,
    'duplicateusers' => 0,
    'matchedcourses' => 0,
    'missingcourses' => 0,
    'duplicatecourses' => 0,
    'validmappings' => 0,
    'error' => '',
    'sql' => '',
];

$config = [
    'remote_table' => '',
    'remote_course' => '',
    'remote_user' => '',
    'local_coursefield' => '',
    'local_userfield' => '',
    'remote_groupname' => '',
];

$enrol = enrol_get_plugin('database');
if ($enrol) {
    $diag['pluginavailable'] = true;

    $config['remote_table']      = (string)$enrol->get_config('remoteenroltable');
    $config['remote_course']     = (string)$enrol->get_config('remotecoursefield');
    $config['remote_user']       = (string)$enrol->get_config('remoteuserfield');
    $config['local_coursefield'] = (string)$enrol->get_config('localcoursefield');
    $config['local_userfield']   = (string)$enrol->get_config('localuserfield');
    $config['remote_groupname']  = (string)$enrol->get_config('remotegroupnamefield');

    $missingconfig = [];
    foreach ($config as $key => $value) {
        if ($value === '') {
            $missingconfig[] = $key;
        }
    }

    if (empty($missingconfig)) {
        $diag['configcomplete'] = true;

        try {
            $ref = new \ReflectionClass(get_class($enrol));
            if ($ref->hasMethod('db_init')) {
                $diag['dbinitmethod'] = true;
                $method = $ref->getMethod('db_init');
                $method->setAccessible(true);
                $extdb = $method->invoke($enrol);

                if ($extdb) {
                    $diag['externalconnection'] = true;

                    $remote_table     = $config['remote_table'];
                    $remote_course    = $config['remote_course'];
                    $remote_user      = $config['remote_user'];
                    $localcoursefield = $config['local_coursefield'];
                    $localuserfield   = $config['local_userfield'];
                    $remotegroupname = $config['remote_groupname'];

                    // Deliberately do NOT filter NULL/empty group values here.
                    // Diagnostics must show whether such rows exist and whether valid rows remain.
                    $sql = "SELECT DISTINCT {$remote_user} AS remoteuser,
                                            {$remote_course} AS remotecourse,
                                            {$remotegroupname} AS groupname
                              FROM {$remote_table}";
                    $diag['sql'] = $sql;

                    $rs = $extdb->Execute($sql);
                    if ($rs) {
                        $diag['queryok'] = true;

                        while ($row = $rs->FetchRow()) {
                            $diag['rawrows']++;

                            if (!is_array($row)) {
                                $diag['invalidrows']++;
                                if (count($issues) < $maxissues) {
                                    $issues[] = (object)[
                                        'remoteuser' => '',
                                        'remotecourse' => '',
                                        'groupname' => '',
                                        'status' => 'Ārējās DB rezultāta rinda nav masīvs',
                                    ];
                                }
                                continue;
                            }

                            // ADOdb drivers may return column aliases in upper/mixed case.
                            $row = array_change_key_case($row, CASE_LOWER);

                            $remoteuser = local_dbgroupenrol_diag_string(
                                local_dbgroupenrol_diag_row_value($row, 'remoteuser', 0)
                            );
                            $remotecourse = local_dbgroupenrol_diag_string(
                                local_dbgroupenrol_diag_row_value($row, 'remotecourse', 1)
                            );
                            $groupname = local_dbgroupenrol_diag_string(
                                local_dbgroupenrol_diag_row_value($row, 'groupname', 2)
                            );

                            if ($remoteuser === null || $remoteuser === '' ||
                                $remotecourse === null || $remotecourse === '' ||
                                $groupname === null || $groupname === '') {
                                $diag['invalidrows']++;
                                if (count($issues) < $maxissues) {
                                    $issues[] = (object)[
                                        'remoteuser' => $remoteuser ?? '[NULL]',
                                        'remotecourse' => $remotecourse ?? '[NULL]',
                                        'groupname' => $groupname ?? '[NULL]',
                                        'status' => 'Nepilnīga ārējās DB rinda (tukšs/NULL user, course vai groupname)',
                                    ];
                                }
                                continue;
                            }

                            $diag['validrows']++;

                            // Use get_records(... limit 2) so diagnostics can identify duplicate local values.
                            $users = $DB->get_records(
                                'user',
                                [$localuserfield => $remoteuser, 'deleted' => 0],
                                'id ASC',
                                'id,username,firstname,lastname',
                                0,
                                2
                            );

                            if (empty($users)) {
                                $diag['missingusers']++;
                                if (count($issues) < $maxissues) {
                                    $issues[] = (object)[
                                        'remoteuser' => $remoteuser,
                                        'remotecourse' => $remotecourse,
                                        'groupname' => $groupname,
                                        'status' => "Moodle lietotājs nav atrasts pēc {$localuserfield}",
                                    ];
                                }
                                continue;
                            }

                            if (count($users) > 1) {
                                $diag['duplicateusers']++;
                                if (count($issues) < $maxissues) {
                                    $issues[] = (object)[
                                        'remoteuser' => $remoteuser,
                                        'remotecourse' => $remotecourse,
                                        'groupname' => $groupname,
                                        'status' => "Atrasti vairāki Moodle lietotāji ar vienādu {$localuserfield}",
                                    ];
                                }
                                continue;
                            }

                            $user = reset($users);
                            $diag['matchedusers']++;

                            $courses = $DB->get_records(
                                'course',
                                [$localcoursefield => $remotecourse],
                                'id ASC',
                                'id,shortname,fullname',
                                0,
                                2
                            );

                            if (empty($courses)) {
                                $diag['missingcourses']++;
                                if (count($issues) < $maxissues) {
                                    $issues[] = (object)[
                                        'remoteuser' => $remoteuser,
                                        'remotecourse' => $remotecourse,
                                        'groupname' => $groupname,
                                        'status' => "Moodle kurss nav atrasts pēc {$localcoursefield}",
                                    ];
                                }
                                continue;
                            }

                            if (count($courses) > 1) {
                                $diag['duplicatecourses']++;
                                if (count($issues) < $maxissues) {
                                    $issues[] = (object)[
                                        'remoteuser' => $remoteuser,
                                        'remotecourse' => $remotecourse,
                                        'groupname' => $groupname,
                                        'status' => "Atrasti vairāki Moodle kursi ar vienādu {$localcoursefield}",
                                    ];
                                }
                                continue;
                            }

                            $course = reset($courses);
                            $diag['matchedcourses']++;
                            $diag['validmappings']++;

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
                    } else {
                        $diag['error'] = 'Ārējās DB SELECT vaicājums neizdevās.';
                    }
                } else {
                    $diag['error'] = 'Neizdevās izveidot savienojumu ar ārējo DB.';
                }
            } else {
                $diag['error'] = 'enrol_database spraudnim nav pieejama db_init() metode.';
            }
        } catch (\Throwable $e) {
            // Show enough information to diagnose the problem, but do not expose credentials.
            $diag['error'] = get_class($e) . ': ' . $e->getMessage();
        }
    } else {
        $diag['error'] = 'Trūkst konfigurācijas lauku: ' . implode(', ', $missingconfig);
    }
} else {
    $diag['error'] = 'Moodle enrol_database spraudnis nav pieejams.';
}

// Diagnostics summary.
echo $OUTPUT->heading('Diagnostika', 3);

local_dbgroupenrol_diag_table([
    ['External database enrolment spraudnis', $diag['pluginavailable'] ? 'OK' : 'NAV'],
    ['Konfigurācija', $diag['configcomplete'] ? 'OK' : 'NEPILNĪGA'],
    ['db_init() metode', $diag['dbinitmethod'] ? 'OK' : 'NAV'],
    ['Savienojums ar ārējo DB', $diag['externalconnection'] ? 'OK' : 'NAV'],
    ['Ārējās DB SELECT', $diag['queryok'] ? 'OK' : 'KĻŪDA / NAV IZPILDĪTS'],
]);

echo $OUTPUT->heading('Izmantotā konfigurācija', 4);
local_dbgroupenrol_diag_table([
    ['External table/view', $config['remote_table']],
    ['Remote user field', $config['remote_user']],
    ['Local user field', $config['local_userfield']],
    ['Remote course field', $config['remote_course']],
    ['Local course field', $config['local_coursefield']],
    ['Remote group name field', $config['remote_groupname']],
]);

if ($diag['sql'] !== '') {
    echo $OUTPUT->heading('Izpildītais ārējās DB vaicājums', 4);
    echo \html_writer::tag('pre', s($diag['sql']), [
        'style' => 'white-space:pre-wrap;overflow-wrap:anywhere;'
    ]);
}

if ($diag['error'] !== '') {
    echo $OUTPUT->notification(s($diag['error']), 'notifyproblem');
}

echo $OUTPUT->heading('Rezultātu statistika', 4);
local_dbgroupenrol_diag_table([
    ['DISTINCT rindas no ārējās DB', $diag['rawrows']],
    ['Derīgas rindas (user + course + group)', $diag['validrows']],
    ['Nederīgas/tukšas rindas', $diag['invalidrows']],
    ['Atrasti Moodle lietotāji', $diag['matchedusers']],
    ['Neatrasti Moodle lietotāji', $diag['missingusers']],
    ['Dublēti Moodle lietotāji', $diag['duplicateusers']],
    ['Atrasti Moodle kursi', $diag['matchedcourses']],
    ['Neatrasti Moodle kursi', $diag['missingcourses']],
    ['Dublēti Moodle kursi', $diag['duplicatecourses']],
    ['Derīgi kartējumi', $diag['validmappings']],
]);

if (!empty($issues)) {
    echo $OUTPUT->heading('Pirmās diagnostikas problēmas (maks. 50)', 4);
    $issuetable = new \html_table();
    $issuetable->attributes['class'] = 'generaltable';
    $issuetable->head = ['Remote user', 'Remote course', 'Group name', 'Rezultāts'];
    foreach ($issues as $issue) {
        $issuetable->data[] = [
            s($issue->remoteuser),
            s($issue->remotecourse),
            s($issue->groupname),
            s($issue->status),
        ];
    }
    echo \html_writer::table($issuetable);
}

echo \html_writer::empty_tag('hr');

// If "apply" -> enforce mappings via observer::apply_group_for_user_in_course.
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

// Existing controls form.
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
