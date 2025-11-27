<?php
namespace local_dbgroupenrol;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Sync one user's group for one course when they are enrolled.
     *
     * Triggered by \core\event\user_enrolled.
     */
    public static function user_enrolled(\core\event\user_enrolled $event): bool {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/enrol/database/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');

        $userid   = $event->relateduserid;
        $courseid = $event->courseid;

        $enrol = enrol_get_plugin('database');
        if (!$enrol) {
            return true;
        }

        $remote_table      = $enrol->get_config('remoteenroltable');
        $remote_course     = $enrol->get_config('remotecoursefield');
        $remote_user       = $enrol->get_config('remoteuserfield');
        $local_coursefield = $enrol->get_config('localcoursefield');
        $local_userfield   = $enrol->get_config('localuserfield');
        $remote_groupname  = get_config('enrol_database', 'remotegroupnamefield');

        if (empty($remote_table) || empty($remote_course) || empty($remote_user) ||
            empty($local_coursefield) || empty($local_userfield) || empty($remote_groupname)) {
            return true;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id,' . $local_userfield, IGNORE_MISSING);
        $course = $DB->get_record('course', ['id' => $courseid], 'id,' . $local_coursefield, IGNORE_MISSING);
        if (!$user || !$course) {
            return true;
        }

        $localuservalue   = $user->{$local_userfield};
        $localcoursevalue = $course->{$local_coursefield};

        // Connect to external DB.
        $ref = new \ReflectionClass(get_class($enrol));
        if (!$ref->hasMethod('db_init')) {
            return true;
        }
        $method = $ref->getMethod('db_init');
        $method->setAccessible(true);
        $extdb = $method->invoke($enrol);
        if (!$extdb) {
            return true;
        }

        $lu = $extdb->qstr($localuservalue);
        $lc = $extdb->qstr($localcoursevalue);

        $sql = "SELECT {$remote_groupname} AS groupname
                  FROM {$remote_table}
                 WHERE {$remote_user} = {$lu}
                   AND {$remote_course} = {$lc}";

        $rs = $extdb->Execute($sql);
        if (!$rs) {
            return true;
        }

        $groupname = null;
        while ($row = $rs->FetchRow()) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('groupname', $row) && !empty($row['groupname'])) {
                $groupname = trim($row['groupname']);
                break;
            }
            if (array_key_exists(0, $row) && !empty($row[0])) {
                $groupname = trim($row[0]);
                break;
            }
        }
        $rs->Close();

        if (empty($groupname)) {
            return true;
        }

        self::apply_group_for_user_in_course($userid, $courseid, $groupname);

        return true;
    }

    /**
     * Full sync for a single user when they log in.
     *
     * Triggered by \core\event\user_loggedin.
     */
    public static function user_loggedin(\core\event\user_loggedin $event): bool {
        $userid = $event->relateduserid ?? $event->userid;
        if (empty($userid)) {
            return true;
        }
        self::perform_full_sync_for_user((int)$userid);
        return true;
    }

    /**
     * Resync all user's groups from external DB.
     * Used by login event and can be reused elsewhere.
     */
    public static function perform_full_sync_for_user(int $userid): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/enrol/database/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');

        $enrol = enrol_get_plugin('database');
        if (!$enrol) {
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
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,' . $local_userfield, IGNORE_MISSING);
        if (!$user) {
            return;
        }

        $localuservalue = $user->{$local_userfield};

        // Connect to external DB.
        $ref = new \ReflectionClass(get_class($enrol));
        if (!$ref->hasMethod('db_init')) {
            return;
        }
        $method = $ref->getMethod('db_init');
        $method->setAccessible(true);
        $extdb = $method->invoke($enrol);
        if (!$extdb) {
            return;
        }

        $lu = $extdb->qstr($localuservalue);

        $sql = "SELECT DISTINCT {$remote_course} AS remotecourse,
                                {$remote_groupname} AS groupname
                  FROM {$remote_table}
                 WHERE {$remote_user} = {$lu}
                   AND {$remote_groupname} IS NOT NULL
                   AND {$remote_groupname} <> ''";

        $rs = $extdb->Execute($sql);
        if (!$rs) {
            return;
        }

        while ($row = $rs->FetchRow()) {
            if (!is_array($row)) {
                continue;
            }

            $remotecourse = null;
            $groupname    = null;

            if (array_key_exists('remotecourse', $row)) {
                $remotecourse = $row['remotecourse'];
            } elseif (array_key_exists(0, $row)) {
                $remotecourse = $row[0];
            }

            if (array_key_exists('groupname', $row)) {
                $groupname = $row['groupname'];
            } elseif (array_key_exists(1, $row)) {
                $groupname = $row[1];
            }

            if ($remotecourse === null || $groupname === null) {
                continue;
            }

            $remotecourse = trim($remotecourse);
            $groupname    = trim($groupname);

            if ($remotecourse === '' || $groupname === '') {
                continue;
            }

            $course = $DB->get_record('course', [$local_coursefield => $remotecourse],
                                      'id,' . $local_coursefield, IGNORE_MISSING);
            if (!$course) {
                continue;
            }

            self::apply_group_for_user_in_course($userid, (int)$course->id, $groupname);
        }

        $rs->Close();
    }

    /**
     * Ensure user is member of given group in given course and removed from other groups in that course.
     */
    public static function apply_group_for_user_in_course(int $userid, int $courseid, string $groupname): void {
        global $DB;

        // Ensure group exists.
        $group = $DB->get_record('groups', ['courseid' => $courseid, 'name' => $groupname]);
        if (!$group) {
            $group = (object)[
                'courseid' => $courseid,
                'name'     => $groupname,
            ];
            $group->id = \groups_create_group($group);
        }

        // Add user to this group.
        \groups_add_member($group->id, $userid);

        // Remove from other groups in the same course.
        $groups = \groups_get_all_groups($courseid, $userid);
        foreach ($groups as $g) {
            if ((int)$g->id !== (int)$group->id) {
                \groups_remove_member($g->id, $userid);
            }
        }
    }
}
