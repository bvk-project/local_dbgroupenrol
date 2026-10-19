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

        $started = microtime(true);
        $enrol = enrol_get_plugin('database');
        if (!$enrol) {
            mtrace('local_dbgroupenrol: enrol_database plugin not available.');
            return;
        }

        $remotetable = $enrol->get_config('remoteenroltable');
        $remotecourse = $enrol->get_config('remotecoursefield');
        $remoteuser = $enrol->get_config('remoteuserfield');
        $localcoursefield = $enrol->get_config('localcoursefield');
        $localuserfield = $enrol->get_config('localuserfield');
        $remotegroupname = get_config('enrol_database', 'remotegroupnamefield');
        $protectedprefix = trim((string)get_config('local_dbgroupenrol', 'protectedgroupprefix'));

        if (!$remotetable || !$remotecourse || !$remoteuser || !$localcoursefield ||
                !$localuserfield || !$remotegroupname) {
            mtrace('local_dbgroupenrol: configuration incomplete, skipping.');
            return;
        }

        // These identifiers are used in SQL and cannot be bound as query parameters.
        foreach ([$remotetable, $remotecourse, $remoteuser, $remotegroupname] as $identifier) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D', $identifier)) {
                mtrace('local_dbgroupenrol: unsupported external SQL identifier; skipping.');
                return;
            }
        }
        foreach ([$localcoursefield, $localuserfield] as $identifier) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
                mtrace('local_dbgroupenrol: invalid local mapping field; skipping.');
                return;
            }
        }

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

        // Read the source once. Group by local (course,user), but reject ambiguous assignments:
        // arbitrary SQL row ordering must never decide which group wins.
        $sql = "SELECT DISTINCT {$remoteuser} AS remoteuser, {$remotecourse} AS remotecourse,
                       {$remotegroupname} AS groupname FROM {$remotetable}
                 WHERE {$remotegroupname} IS NOT NULL AND {$remotegroupname} <> ''";
        $rs = $extdb->Execute($sql);
        if (!$rs) {
            mtrace('local_dbgroupenrol: external DB query failed; no changes made.');
            return;
        }
        $source = [];
        $sourcerows = 0;
        while ($row = $rs->FetchRow()) {
            if (!is_array($row)) {
                continue;
            }
            $username = trim((string)self::row_value($row, 'remoteuser', 0));
            $coursename = trim((string)self::row_value($row, 'remotecourse', 1));
            $groupname = trim((string)self::row_value($row, 'groupname', 2));
            if ($username === '' || $coursename === '' || $groupname === '') {
                continue;
            }
            $sourcerows++;
            $source[$coursename][$username][$groupname] = true;
        }
        $rs->Close();
        if (!$sourcerows) {
            mtrace('local_dbgroupenrol: no valid source rows; no changes made.');
            return;
        }
        $readtime = microtime(true);

        // One query per mapping table instead of two lookups per source row.
        $users = [];
        $userrecords = $DB->get_recordset_sql("SELECT id, {$localuserfield} AS mapvalue
                                                  FROM {user} WHERE deleted = 0");
        foreach ($userrecords as $user) {
            $value = trim((string)$user->mapvalue);
            if ($value !== '') {
                $users[$value] = (int)$user->id;
            }
        }
        $userrecords->close();

        $courses = [];
        $courserecords = $DB->get_recordset_sql("SELECT id, {$localcoursefield} AS mapvalue FROM {course}");
        foreach ($courserecords as $course) {
            $value = trim((string)$course->mapvalue);
            if ($value !== '') {
                $courses[$value] = (int)$course->id;
            }
        }
        $courserecords->close();

        $desired = [];
        $ambiguous = 0;
        $missing = 0;
        foreach ($source as $coursename => $courseusers) {
            if (!isset($courses[$coursename])) {
                $missing += count($courseusers);
                continue;
            }
            $courseid = $courses[$coursename];
            foreach ($courseusers as $username => $names) {
                if (!isset($users[$username])) {
                    $missing++;
                    continue;
                }
                if (count($names) !== 1) {
                    $ambiguous++;
                    continue;
                }
                $userid = $users[$username];
                $desired[$courseid][$userid] = (string)array_key_first($names);
            }
        }
        if (!$desired) {
            mtrace("local_dbgroupenrol: no mapped memberships (missing={$missing}, ambiguous={$ambiguous}).");
            return;
        }
        $maptime = microtime(true);

        // Cache groups and memberships for the affected courses only.
        $groups = [];        // [courseid][name] => groupid.
        $memberships = [];   // [courseid][userid][groupid] => group name.
        $courseids = array_keys($desired);
        foreach (array_chunk($courseids, 250) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_QM);
            $grouprecords = $DB->get_recordset_sql(
                "SELECT id, courseid, name FROM {groups} WHERE courseid {$insql}", $params);
            foreach ($grouprecords as $group) {
                $groups[(int)$group->courseid][(string)$group->name] = (int)$group->id;
            }
            $grouprecords->close();

            $memberrecords = $DB->get_recordset_sql(
                "SELECT gm.id, gm.groupid, gm.userid, g.courseid, g.name
                   FROM {groups_members} gm
                   JOIN {groups} g ON g.id = gm.groupid
                  WHERE g.courseid {$insql}", $params);
            foreach ($memberrecords as $member) {
                $cid = (int)$member->courseid;
                $uid = (int)$member->userid;
                if (isset($desired[$cid][$uid])) {
                    $memberships[$cid][$uid][(int)$member->groupid] = (string)$member->name;
                }
            }
            $memberrecords->close();
        }
        $cachetime = microtime(true);

        $added = 0;
        $removed = 0;
        $created = 0;
        $unchanged = 0;
        $failed = 0;
        foreach ($desired as $courseid => $courseusers) {
            foreach ($courseusers as $userid => $groupname) {
                $current = $memberships[$courseid][$userid] ?? [];
                $targetid = $groups[$courseid][$groupname] ?? 0;
                $hasmember = $targetid && isset($current[$targetid]);
                $obsolete = [];
                foreach ($current as $groupid => $existingname) {
                    if ($groupid !== $targetid && !self::is_protected($existingname, $protectedprefix)) {
                        $obsolete[] = $groupid;
                    }
                }
                if ($hasmember && !$obsolete) {
                    $unchanged++;
                    continue;
                }

                if (!$targetid) {
                    $newgroup = (object)['courseid' => $courseid, 'name' => $groupname];
                    $targetid = (int)\groups_create_group($newgroup);
                    if (!$targetid) {
                        $failed++;
                        continue;
                    }
                    $groups[$courseid][$groupname] = $targetid;
                    $created++;
                }

                // Never remove other memberships unless the target membership is present.
                if (!$hasmember) {
                    if (!\groups_add_member($targetid, $userid)) {
                        $failed++;
                        continue;
                    }
                    $added++;
                }
                foreach ($obsolete as $groupid) {
                    if (\groups_remove_member($groupid, $userid)) {
                        $removed++;
                    } else {
                        $failed++;
                    }
                }
            }
        }
        mtrace(sprintf(
            'local_dbgroupenrol: source=%d, pairs=%d, unchanged=%d, added=%d, removed=%d, groups_created=%d, missing=%d, ambiguous=%d, failures=%d',
            $sourcerows, array_sum(array_map('count', $desired)), $unchanged, $added, $removed, $created,
            $missing, $ambiguous, $failed
        ));
        mtrace(sprintf('local_dbgroupenrol timings: external=%.2fs, mapping=%.2fs, cache=%.2fs, changes=%.2fs, total=%.2fs',
            $readtime - $started, $maptime - $readtime, $cachetime - $maptime,
            microtime(true) - $cachetime, microtime(true) - $started));
    }

    private static function row_value(array $row, string $name, int $index) {
        if (array_key_exists($name, $row)) {
            return $row[$name];
        }
        foreach ($row as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return $row[$index] ?? null;
    }

    private static function is_protected(string $name, string $prefix): bool {
        return $prefix !== '' && strncmp($name, $prefix, strlen($prefix)) === 0;
    }
}
