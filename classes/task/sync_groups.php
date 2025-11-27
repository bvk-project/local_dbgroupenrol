<?php
namespace local_dbgroupenrol\task;

defined('MOODLE_INTERNAL') || die();

class sync_groups extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_syncgroups', 'local_dbgroupenrol');
    }

    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/enrol/database/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');

        $enrol = enrol_get_plugin('database');
        if (!$enrol) {
            mtrace('local_dbgroupenrol: enrol_database plugin not available.');
            return;
        }

        $remote_table      = $enrol->get_config('remoteenroltable');
        $remote_course     = $enrol->get_config('remotecoursefield');
        $remote_user       = $enrol->get_config('remoteuserfield');
        $local_coursefield = $enrol->get_config('localcoursefield');
        $local_userfield   = $enrol->get_config('localuserfield');
        $remote_groupname  = get_config('enrol_database', 'remotegroupnamefield');

        if (empty($remote_table) || empty($remote_course) || empty($remote_user) ||
            empty($local_coursefield) || empty($local_userfield) || empty($remote_groupname)) {
            mtrace('local_dbgroupenrol: configuration incomplete, skipping.');
            return;
        }

        // Connect to external DB.
        $ref = new \ReflectionClass(get_class($enrol));
        if (!$ref->hasMethod('db_init')) {
            mtrace('local_dbgroupenrol: enrol_database::db_init not accessible.');
            return;
        }
        $method = $ref->getMethod('db_init');
        $method->setAccessible(true);
        $extdb = $method->invoke($enrol);
        if (!$extdb) {
            mtrace('local_dbgroupenrol: cannot connect to external DB.');
            return;
        }

        $sql = "SELECT DISTINCT {$remote_user} AS remoteuser,
                                {$remote_course} AS remotecourse,
                                {$remote_groupname} AS groupname
                  FROM {$remote_table}
                 WHERE {$remote_groupname} IS NOT NULL
                   AND {$remote_groupname} <> ''";

        $rs = $extdb->Execute($sql);
        if (!$rs) {
            mtrace('local_dbgroupenrol: external DB query failed.');
            return;
        }

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

            // Map to local user and course.
            $user = $DB->get_record('user', [$local_userfield => $remoteuser, 'deleted' => 0],
                'id,' . $local_userfield, IGNORE_MISSING);
            if (!$user) {
                continue;
            }

            $course = $DB->get_record('course', [$local_coursefield => $remotecourse],
                'id,' . $local_coursefield, IGNORE_MISSING);
            if (!$course) {
                continue;
            }

            \local_dbgroupenrol\observer::apply_group_for_user_in_course(
                (int)$user->id,
                (int)$course->id,
                $groupname
            );
        }

        $rs->Close();
    }
}
