<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_dbgroupenrol',
        get_string('pluginname', 'local_dbgroupenrol')
    );

    $settings->add(new admin_setting_configtext(
        'enrol_database/remotegroupnamefield',
        get_string('remotegroupnamefield', 'local_dbgroupenrol'),
        get_string('remotegroupnamefield_desc', 'local_dbgroupenrol'),
        '',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);

    global $CFG;
    $ADMIN->add('users', new admin_externalpage(
        'local_dbgroupenrol_mapping',
        get_string('mappingpage', 'local_dbgroupenrol'),
        $CFG->wwwroot . '/local/dbgroupenrol/mapping.php'
    ));
}
