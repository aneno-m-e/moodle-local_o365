<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Course sync to team feature.
 *
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @author Lai Wei <lai.wei@enovation.ie>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 */

namespace local_o365\feature\coursesync;

use local_o365\rest\unified;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

define('API_CALL_RETRY_LIMIT', 5);

/**
 * Course sync to team feature class.
 */
class main {
    /**
     * Course full name option.
     */
    const NAME_OPTION_FULL_NAME = 1;
    /**
     * Course short name option.
     */
    const NAME_OPTION_SHORT_NAME = 2;
    /**
     * Course ID option.
     */
    const NAME_OPTION_ID = 3;
    /**
     * Course ID number option.
     */
    const NAME_OPTION_ID_NUMBER = 4;

    /**
     * @var unified a graph API client.
     */
    private $graphclient;
    /**
     * @var bool whether the debug is turned on.
     */
    private $debug;
    /**
     * @var string SQL query to filter sync enabled courses.
     */
    private $coursesinsql = '';
    /**
     * @var array SQL query parameters to filter sync enabled courses.
     */
    private $coursesparams = [];
    /**
     * @var bool whether the tenant has education license.
     */
    private $haseducationlicense = false;

    /**
     * Constructor.
     *
     * @param unified $graphclient A graph API client to use.
     * @param bool $debug Whether to output debug messages.
     */
    public function __construct(unified $graphclient, bool $debug = false) {
        $this->graphclient = $graphclient;
        $this->debug = $debug;
        if ($graphclient->has_education_license()) {
            $this->haseducationlicense = true;
        }
    }

    /**
     * Optionally run mtrace() based on $this->debug setting.
     *
     * @param string $msg The debug message.
     * @param int $level
     * @param string $eol
     */
    protected function mtrace(string $msg, int $level = 0, string $eol = "\n") {
        if ($this->debug === true) {
            if ($level) {
                $msg = str_repeat('...', $level) . ' ' . $msg;
            }
            mtrace($msg, $eol);
        }
    }

    /**
     * Return whether tenant that the Azure app is created in has education license.
     *
     * @return bool
     */
    public function has_education_license() : bool {
        return $this->haseducationlicense;
    }

    /**
     * Create teams and populate membership for all courses that don't have an associated team recorded.
     */
    public function sync_courses() : bool {
        global $DB;

        $this->mtrace('Start syncing courses.');
        $this->mtrace('Tenant has education license: ' . ($this->haseducationlicense ? 'yes' : 'no'));

        // Preparation work - get list of courses that have course sync enabled.
        $coursesyncsetting = get_config('local_o365', 'coursesync');
        if ($coursesyncsetting === 'onall' || $coursesyncsetting === 'oncustom') {
            $coursesenabled = utils::get_enabled_courses();
            if (empty($coursesenabled)) {
                $this->mtrace('Custom group creation is enabled, but no courses are enabled.');
                return false;
            }
        } else {
            $this->mtrace('Group creation is disabled.');
            return false;
        }

        if (is_array($coursesenabled)) {
            [$this->coursesinsql, $this->coursesparams] = $DB->get_in_or_equal($coursesenabled);
        } else {
            $this->coursesinsql = '';
            $this->coursesparams = [];
        }

        // Process courses with groups that have been "soft-deleted".
        $this->restore_soft_deleted_groups();

        // Process courses without an associated group.
        $this->process_courses_without_groups();

        // Process courses having groups but not teams.
        $this->process_courses_without_teams();

        return true;
    }

    /**
     * Restore Microsoft 365 groups that have been soft-deleted.
     */
    private function restore_soft_deleted_groups() {
        global $DB;

        $this->mtrace('Restore groups that have been soft-deleted...');

        $sql = 'SELECT crs.id as courseid, obj.*
                  FROM {course} crs
                  JOIN {local_o365_objects} obj ON obj.type = ? AND obj.subtype = ? AND obj.moodleid = crs.id';
        $params = ['group', 'course'];
        if (!empty($this->coursesinsql)) {
            $sql .= ' WHERE crs.id ' . $this->coursesinsql;
            $params = array_merge($params, $this->coursesparams);
        }

        $objectrecs = $DB->get_recordset_sql($sql, $params);
        foreach ($objectrecs as $objectrec) {
            $metadata = (!empty($objectrec->metadata)) ? @json_decode($objectrec->metadata, true) : [];
            if (is_array($metadata) && !empty($metadata['softdelete'])) {
                $this->mtrace('Attempting to restore group for course #' . $objectrec->courseid, 1);
                $result = $this->restore_group($objectrec->id, $objectrec->objectid, $metadata);
                if ($result === true) {
                    $this->mtrace('success!', 2);
                } else {
                    $this->mtrace('failed. Group may have been deleted for too long.', 2);
                    // TODO do we need to delete group record in object table then?
                }
            }
        }
    }

    /**
     * Create an educationClass group for the given course.
     *
     * @param stdClass $course
     * @return array|false
     */
    private function create_education_group(stdClass $course) {
        global $DB;

        $now = time();

        $this->mtrace('Creating education group for course #' . $course->id, 2);

        $displayname = utils::get_team_display_name($course);
        $mailnickname = utils::get_group_mail_alias($course);
        $description = '';
        if (!empty($course->summary)) {
            $description = strip_tags($course->summary);
            if (strlen($description) > 1024) {
                $description = shorten_text($description, 1024, true, ' ...');
            }
        }
        $externalid = $course->id;
        $externalname = $course->fullname;

        try {
            $response = $this->graphclient->create_educationclass_group($displayname, $mailnickname, $description, $externalid,
                $externalname);
        } catch (moodle_exception $e) {
            $this->mtrace('Could not create educationClass group for course #' . $course->id . '. Reason: ' . $e->getMessage(), 3);
            return false;
        }

        $this->mtrace('Created education group ' . $response['id'] . ' for course #' . $course->id, 3);

        $objectrecord = ['type' => 'group', 'subtype' => 'course', 'objectid' => $response['id'], 'moodleid' => $course->id,
            'o365name' => $displayname, 'timecreated' => $now, 'timemodified' => $now];
        $objectrecord['id'] = $DB->insert_record('local_o365_objects', (object)$objectrecord);
        $this->mtrace('Recorded group object ' . $objectrecord['objectid'] . ' into object table with record ID ' .
            $objectrecord['id'], 3);

        return $objectrecord;
    }

    /**
     * Add LMS attributes to the Education group with the given object ID for the given course.
     *
     * @param string $groupobjectid
     * @param stdClass $course
     * @return bool
     */
    public function set_lti_properties_in_education_group(string $groupobjectid, stdClass $course) : bool {
        $this->mtrace('Setting LMS attributes in group ' . $groupobjectid . ' for course #' . $course->id, 2);

        $lmsattributes = [
            'microsoft_EducationClassLmsExt' => [
                'ltiContextId' => $course->id,
                'lmsCourseId' => $course->id,
                'lmsCourseName' => $course->fullname,
            ],
        ];

        $retrycounter = 0;
        $success = false;
        while ($retrycounter <= API_CALL_RETRY_LIMIT) {
            if ($retrycounter) {
                $this->mtrace('Retry #' . $retrycounter, 3);
            }
            sleep(10);

            try {
                $this->graphclient->update_education_group_with_lms_data($groupobjectid, $lmsattributes);
                $success = true;
                break;
            } catch (moodle_exception $e) {
                $this->mtrace('Error setting LMS attributes in group ' . $groupobjectid . '. Reason: ' . $e->getMessage(), 3);
                $retrycounter++;
            }
        }

        if ($success) {
            $this->mtrace('Successfully setting LMS attributes.', 3);
        } else {
            $this->mtrace('Failed setting LMS attributes.', 3);
        }

        return $success;
    }

    /**
     * Create a standard group for the given course.
     *
     * @param stdClass $course
     * @return array|false
     */
    private function create_standard_group(stdClass $course) {
        global $DB;

        $now = time();

        $this->mtrace('Creating standard group for course #' . $course->id, 2);

        $displayname = utils::get_team_display_name($course);
        $mailnickname = utils::get_group_mail_alias($course);
        $description = '';
        if (!empty($course->summary)) {
            $description = strip_tags($course->summary);
            if (strlen($description) > 1024) {
                $description = shorten_text($description, 1024, true, ' ...');
            }
        }

        try {
            $response = $this->graphclient->create_group($displayname, $mailnickname, ['description' => $description]);
        } catch (moodle_exception $e) {
            $this->mtrace('Could not create standard group for course #' . $course->id . '. Reason: ' . $e->getMessage(), 3);
            return false;
        }

        $this->mtrace('Created standard group ' . $response['id'] . ' for course #' . $course->id, 3);

        $objectrecord = ['type' => 'group', 'subtype' => 'course', 'objectid' => $response['id'], 'moodleid' => $course->id,
            'o365name' => $displayname, 'timecreated' => $now, 'timemodified' => $now];
        $objectrecord['id'] = $DB->insert_record('local_o365_objects', (object)$objectrecord);
        $this->mtrace('Recorded group object (' . $objectrecord['objectid'] . ') into object table with record ID ' .
            $objectrecord['id'], 3);

        return $objectrecord;
    }

    /**
     * Add list of owners and members with the given object IDs to the team with the given object ID.
     *
     * @param string $groupobjectid
     * @param array $owners
     * @param array $members
     * @return bool whether at least one owner was added.
     */
    private function add_group_owners_and_members_to_group(string $groupobjectid, array $owners, array $members) : bool {
        if (empty($owners) && empty($members)) {
            $this->mtrace('Skip adding owners / members to the group. Reason: No users to add.', 2);
            return false;
        }

        // Remove existing owners and members.
        try {
            $existingowners = $this->get_group_owners($groupobjectid);
        } catch (moodle_exception $e) {
            $this->mtrace('Could not get existing owners of group with ID ' . $groupobjectid . '. Reason: ' . $e->getMessage(), 3);
            $existingowners = [];
        }

        try {
            $existingmembers = $this->get_group_members($groupobjectid);
        } catch (moodle_exception $e) {
            $this->mtrace('Could not get existing members of group with ID ' . $groupobjectid . '. Reason: ' . $e->getMessage(), 3);
            $existingmembers = [];
        }
        $existingownerids = array_keys($existingowners);
        $existingmemberids = array_keys($existingmembers);
        $owners = array_diff($owners, $existingownerids);
        $members = array_diff($members, $existingmemberids);

        $this->mtrace('Adding ' . count($owners) . ' owners and ' . count($members) . ' members to group with ID ' .
            $groupobjectid, 2);

        $userchunks = utils::arrange_group_users_in_chunks($owners, $members);

        $owneradded = false;
        foreach ($userchunks as $userchunk) {
            $role = array_keys($userchunk)[0];
            $users = reset($userchunk);
            $retrycounter = 0;
            while ($retrycounter <= API_CALL_RETRY_LIMIT) {
                if ($retrycounter) {
                    $this->mtrace('Retry #' . $retrycounter, 3);
                    sleep(10);
                }
                try {
                    $response = $this->graphclient->add_chunk_users_to_group($groupobjectid, $role, $users);
                    if ($response) {
                        if ($role == 'owner') {
                            $owneradded = true;
                        }
                    } else {
                        $this->mtrace('Invalid bulk group owners/members addition request', 3);
                    }
                    break;
                } catch (moodle_exception $e) {
                    $this->mtrace('Error: ' . $e->getMessage(), 3);
                    $retrycounter++;
                }
            }
        }

        $this->mtrace('Finished adding owners and members to group.', 3);

        return $owneradded;
    }

    /**
     * Create a class team from an education group with the given ID for the course with the given ID.
     *
     * @param string $groupobjectid
     * @param stdClass $course
     * @return array|false
     */
    private function create_class_team_from_education_group(string $groupobjectid, stdClass $course) {
        global $DB;

        $now = time();

        $retrycounter = 0;

        $this->mtrace('Creating class team from education group with ID ' . $groupobjectid . ' for course #' . $course->id, 2);

        $response = null;
        $subtype = '';
        while ($retrycounter <= API_CALL_RETRY_LIMIT) {
            if ($retrycounter) {
                $this->mtrace('Retry #' . $retrycounter, 3);
            }
            sleep(10);

            try {
                $response = $this->graphclient->create_class_team_from_education_group($groupobjectid);
                $this->mtrace('Created class team from class group with ID ' . $groupobjectid, 3);
                $subtype = 'teamfromgroup';
                break;
            } catch (moodle_exception $e) {
                if (strpos($e->a, 'The group is already provisioned') !== false) {
                    $this->mtrace('Found existing team from class group with ID ' . $groupobjectid, 3);
                    $response = true;
                    $subtype = 'courseteam';
                    break;
                } else {
                    $this->mtrace('Could not create class team from education group. Reason: ' . $e->getMessage(), 3);
                    $retrycounter++;
                }
            }
        }

        if (!$response) {
            $this->mtrace('Failed to create class team from education group with ID ' . $groupobjectid . ' for course #' .
                $course->id, 3);
            return false;
        }

        $teamname = utils::get_team_display_name($course);
        $teamobjectrecord = ['type' => 'group', 'subtype' => $subtype, 'objectid' => $groupobjectid,
            'moodleid' => $course->id, 'o365name' => $teamname, 'timecreated' => $now, 'timemodified' => $now];
        $teamobjectrecord['id'] = $DB->insert_record('local_o365_objects', (object)$teamobjectrecord);
        $this->mtrace('Recorded class team object ' . $groupobjectid . ' into object table with record ID ' .
            $teamobjectrecord['id'], 3);

        // Provision app, add app tab to channel.
        $this->install_moodle_app_in_team($groupobjectid, $course->id);

        return $teamobjectrecord;
    }

    /**
     * Create a standard team from a standard group with the given ID for the course with the given ID.
     *
     * @param string $groupobjectid
     * @param stdClass $course
     * @return array|false
     */
    private function create_team_from_standard_group(string $groupobjectid, stdClass $course) {
        global $DB;

        $now = time();

        $retrycounter = 0;

        $this->mtrace('Creating standard team from group with ID ' . $groupobjectid . ' for course #' . $course->id, 2);

        $response = null;
        while ($retrycounter <= API_CALL_RETRY_LIMIT) {
            if ($retrycounter) {
                $this->mtrace('Retry #' . $retrycounter, 3);
                sleep(10);
            }
            try {
                $response = $this->graphclient->create_standard_team_from_group($groupobjectid);
                break;
            } catch (moodle_exception $e) {
                $this->mtrace('Could not create standard team from group. Reason: '. $e->getMessage(), 3);
                $retrycounter++;
            }
        }

        if (!$response) {
            $this->mtrace('Failed to create standard team from group with ID ' . $groupobjectid . ' for course #' . $course->id, 3);
            return false;
        }

        $this->mtrace('Created standard team from group with ID ' . $groupobjectid, 3);
        $teamname = utils::get_team_display_name($course);
        $teamobjectrecord = ['type' => 'group', 'subtype' => 'teamfromgroup', 'objectid' => $groupobjectid,
            'moodleid' => $course->id, 'o365name' => $teamname, 'timecreated' => $now, 'timemodified' => $now];
        $teamobjectrecord['id'] = $DB->insert_record('local_o365_objects', (object)$teamobjectrecord);
        $this->mtrace('Recorded standard team object ' . $groupobjectid . ' into object table with record ID ' .
            $teamobjectrecord['id'], 3);

        // Provision app, add app tab to channel.
        $this->install_moodle_app_in_team($groupobjectid, $course->id);

        return $teamobjectrecord;
    }

    /**
     * Install Moodle teams app in the team with the provided ID for the Moodle course with the provided ID.
     *
     * @param string $groupobjectid
     * @param int $courseid
     */
    public function install_moodle_app_in_team(string $groupobjectid, int $courseid) {
        $moodleappid = get_config('local_o365', 'moodle_app_id');

        if (!empty($moodleappid)) {
            // Provision app to the newly created team.
            $this->mtrace('Provision Moodle app in the team', 3);
            $retrycounter = 0;
            $moodleappprovisioned = false;
            while ($retrycounter <= API_CALL_RETRY_LIMIT) {
                if ($retrycounter) {
                    $this->mtrace('Retry #' . $retrycounter, 4);
                }
                sleep(10);

                try {
                    if ($this->graphclient->provision_app($groupobjectid, $moodleappid)) {
                        $this->mtrace('Provisioned Moodle app in the team with object ID ' . $groupobjectid, 4);
                        $moodleappprovisioned = true;
                        break;
                    }
                } catch (moodle_exception $e) {
                    $this->mtrace('Could not add app to team with object ID ' . $groupobjectid . '. Reason: ' . $e->getMessage(),
                        4);
                    $retrycounter++;
                }
            }

            // List all channels.
            if ($moodleappprovisioned) {
                try {
                    $generalchanelid = $this->graphclient->get_general_channel_id($groupobjectid);
                    $this->mtrace('Located general channel in the team with object ID ' . $groupobjectid, 4);
                } catch (moodle_exception $e) {
                    $this->mtrace('Could not list channels of team with object ID ' . $groupobjectid . '. Reason: ' .
                        $e->getMessage(), 4);
                    $generalchanelid = false;
                }

                if ($generalchanelid) {
                    // Add tab to channel.
                    try {
                        $this->add_moodle_tab_to_channel($groupobjectid, $generalchanelid, $moodleappid, $courseid);
                        $this->mtrace('Installed Moodle tab in the general channel of team with object ID ' . $groupobjectid, 4);
                    } catch (moodle_exception $e) {
                        $this->mtrace('Could not add Moodle tab to channel in team with ID ' . $groupobjectid . '. Reason : ' .
                            $e->getMessage(), 4);
                    }
                }
            }
        }
    }

    /**
     * Add a Moodle tab for the Moodle course to a channel.
     *
     * @param string $groupobjectid
     * @param string $channelid
     * @param string $appid
     * @param int $moodlecourseid
     * @return string
     */
    private function add_moodle_tab_to_channel(string $groupobjectid, string $channelid, string $appid,
        int $moodlecourseid) : string {
        global $CFG;

        $tabconfiguration = [
            'entityId' => 'course_' . $moodlecourseid,
            'contentUrl' => $CFG->wwwroot . '/local/o365/teams_tab.php?id=' . $moodlecourseid,
            'websiteUrl' => $CFG->wwwroot . '/course/view.php?id=' . $moodlecourseid,
        ];

        return $this->graphclient->add_tab_to_channel($groupobjectid, $channelid, $appid, $tabconfiguration);
    }

    /**
     * Process courses without groups:
     *  - Create groups,
     *  - Add owners and members,
     *  - Create Teams if appropriate.
     */
    private function process_courses_without_groups() {
        global $DB;

        $this->mtrace('Processing courses without groups...');

        $sql = 'SELECT crs.*
                  FROM {course} crs
             LEFT JOIN {local_o365_objects} obj ON obj.type = ? AND obj.subtype = ? AND obj.moodleid = crs.id
                 WHERE obj.id IS NULL AND crs.id != ? AND crs.visible != 0';
        // The "crs.visible != 0" is used to filter out courses in the process of copy or restore, which may contain incorrect or
        // incomplete contents.
        $params = ['group', 'course', SITEID];
        if (!empty($this->coursesinsql)) {
            $sql .= ' AND crs.id ' . $this->coursesinsql;
            $params = array_merge($params, $this->coursesparams);
        }
        $courselimit = get_config('local_o365', 'courses_per_task');
        if (!$courselimit) {
            $courselimit = 20;
        }
        $courses = $DB->get_recordset_sql($sql, $params);

        $coursesprocessed = 0;
        foreach ($courses as $course) {
            if ($coursesprocessed > $courselimit) {
                $this->mtrace('Course processing limit of ' . $courselimit . ' reached. Exit.');
                break;
            }

            if ($this->create_group_for_course($course)) {
                $coursesprocessed++;
            }
        }

        if (empty($coursesprocessed)) {
            $this->mtrace('All courses have groups created.', 1);
        } else {
            $this->mtrace('Created groups for ' . $coursesprocessed . ' courses.', 1);
        }

        $courses->close();
    }

    /**
     * Try to create a group for the given course.
     *
     * @param stdClass $course
     * @return bool True if group creation succeeds, or False if it fails.
     */
    public function create_group_for_course(stdClass $course) : bool {
        $this->mtrace('Processing course #' . $course->id, 1);

        // Create group.
        if ($this->haseducationlicense) {
            $groupobject = $this->create_education_group($course);

            if ($groupobject) {
                $this->set_lti_properties_in_education_group($groupobject['objectid'], $course);
            } else {
                return false;
            }
        } else {
            $groupobject = $this->create_standard_group($course);
            if (!$groupobject) {
                return false;
            }
        }

        // Add owners / members to the group.
        $ownerobjectids = utils::get_team_owner_object_ids_by_course_id($course->id);
        $memberobjectids = utils::get_team_member_object_ids_by_course_id($course->id, $ownerobjectids);
        $owneradded = $this->add_group_owners_and_members_to_group($groupobject['objectid'], $ownerobjectids, $memberobjectids);

        // If owner exists, create team.
        if ($owneradded) {
            // Owner exists, proceed with Team creation.
            if ($this->haseducationlicense) {
                $this->create_class_team_from_education_group($groupobject['objectid'], $course);
            } else {
                $this->create_team_from_standard_group($groupobject['objectid'], $course);
            }
        }

        return true;
    }

    /**
     * Process courses with groups but not teams:
     *  - Create Teams if appropriate.
     */
    private function process_courses_without_teams() {
        global $DB;

        $this->mtrace('Processing courses without teams...');

        $sql = 'SELECT crs.*, obj_group.objectid AS groupobjectid
                  FROM {course} crs
             LEFT JOIN {local_o365_objects} obj_group
                        ON obj_group.type = ? AND obj_group.subtype = ? AND obj_group.moodleid = crs.id
             LEFT JOIN {local_o365_objects} obj_team1
                        ON obj_team1.type = ? AND obj_team1.subtype = ? AND obj_team1.moodleid = crs.id
             LEFT JOIN {local_o365_objects} obj_team2
                        ON obj_team2.type = ? AND obj_team2.subtype = ? AND obj_team2.moodleid = crs.id
             LEFT JOIN {local_o365_objects} obj_sds
                        ON obj_sds.type = ? AND obj_sds.subtype = ? AND obj_sds.moodleid = crs.id
                 WHERE obj_group.id IS NOT NULL
                   AND obj_team1.id IS NULL
                   AND obj_team2.id IS NULL
                   AND obj_sds.id IS NULL
                   AND crs.id != ?';
        $params = ['group', 'course', 'group', 'courseteam', 'group', 'teamfromgroup', 'sdssection', 'course', SITEID];
        if (!empty($this->coursesinsql)) {
            $sql .= ' AND crs.id ' . $this->coursesinsql;
            $params = array_merge($params, $this->coursesparams);
        }
        $courses = $DB->get_recordset_sql($sql, $params);

        $coursesprocessed = 0;

        $courselimit = get_config('local_o365', 'courses_per_task');
        if (!$courselimit) {
            $courselimit = 20;
        }

        foreach ($courses as $course) {
            if ($coursesprocessed > $courselimit) {
                $this->mtrace('Course processing limit of ' . $courselimit . ' reached. Exit.');
                break;
            }

            $this->mtrace('Processing course #' . $course->id, 1);

            // Check if the team has owners.
            $owners = utils::get_team_owner_object_ids_by_course_id($course->id);
            $members = utils::get_team_member_object_ids_by_course_id($course->id, $owners);

            if ($owners) {
                // Resync group owners and members, just in case.
                $this->add_group_owners_and_members_to_group($course->groupobjectid, $owners, $members);
                if ($this->haseducationlicense) {
                    if ($this->create_class_team_from_education_group($course->groupobjectid, $course)) {
                        $coursesprocessed++;
                    }
                } else {
                    if ($this->create_team_from_standard_group($course->groupobjectid, $course)) {
                        $coursesprocessed++;
                    }
                }
            } else {
                $this->mtrace('Skip creating team from group with ID ' . $course->groupobjectid . ' for course #' . $course->id .
                    '. Reason: No owner.', 2);
            }
        }

        if (empty($coursesprocessed)) {
            $this->mtrace('All courses have teams created.', 1);
        } else {
            $this->mtrace('Created teams for ' . $coursesprocessed . ' courses.', 1);
        }

        $courses->close();
    }

    /**
     * Restore a deleted group.
     *
     * @param int $objectrecid The id of the local_o365_objects record.
     * @param string $objectid The Microsoft 365 object id of the group.
     * @param array $objectrecmetadata The metadata of the object database record.
     * @return bool
     */
    private function restore_group(int $objectrecid, string $objectid, array $objectrecmetadata) : bool {
        global $DB;

        $deletedgroups = $this->graphclient->list_deleted_groups();

        foreach ($deletedgroups as $deletedgroup) {
            if (!empty($deletedgroup) && isset($deletedgroup['id']) && $deletedgroup['id'] == $objectid) {
                // Deleted group found.
                $this->graphclient->restore_deleted_group($objectid);
                $updatedobjectrec = new stdClass;
                $updatedobjectrec->id = $objectrecid;
                unset($objectrecmetadata['softdelete']);
                $updatedobjectrec->metadata = json_encode($objectrecmetadata);
                $DB->update_record('local_o365_objects', $updatedobjectrec);
                return true;
            }
        }

        // No deleted group found. May have expired. Delete our record.
        $DB->delete_records('local_o365_objects', ['id' => $objectrecid]);

        return false;
    }

    /**
     * Update Teams cache.
     *
     * @return bool
     */
    public function update_teams_cache() : bool {
        global $DB;

        $this->mtrace('Updating teams cache...');

        $coursesyncsetting = get_config('local_o365', 'coursesync');
        if (!($coursesyncsetting === 'onall' || $coursesyncsetting === 'oncustom')) {
            $this->mtrace('Teams creation is disabled.', 1);
            return false;
        }

        $coursesenabled = utils::get_enabled_courses();
        if ($coursesenabled !== true) {
            if (count($coursesenabled) == 0) {
                $this->mtrace('Teams creation is disabled.', 1);
                return false;
            }
        }

        // Fetch teams from Graph API.
        $teams = $this->graphclient->get_teams();

        // Build existing teams records cache.
        $this->mtrace('Building existing teams cache records', 1);
        $existingcacherecords = $DB->get_records('local_o365_teams_cache');
        $existingcachebyoid = [];
        foreach ($existingcacherecords as $existingcacherecord) {
            $existingcachebyoid[$existingcacherecord->objectid] = $existingcacherecord;
        }

        // Compare, then create, update, or delete cache.
        $this->mtrace('Updating teams cache records', 1);
        foreach ($teams as $team) {
            if (array_key_exists($team['id'], $existingcachebyoid)) {
                // Update existing cache record.
                if (!$existingcachebyoid[$team['id']]->locked) {
                    // Need to update lock status.
                    try {
                        [$rawteam, $teamurl, $lockstatus] = $this->graphclient->get_team($team['id']);
                    } catch (moodle_exception $e) {
                        continue;
                    }
                } else {
                    $lockstatus = $existingcachebyoid[$team['id']]->locked;
                }

                $cacherecord = $existingcachebyoid[$team['id']];
                $cacherecord->name = $team['displayName'];
                $cacherecord->description = $team['description'];
                $cacherecord->locked = $lockstatus;
                $DB->update_record('local_o365_teams_cache', $cacherecord);

                unset($existingcachebyoid[$team['id']]);
            } else {
                try {
                    [$rawteam, $teamurl, $lockstatus] = $this->graphclient->get_team($team['id']);
                } catch (moodle_exception $e) {
                    // Cannot get Team URL or locked status, most likely an invalid Team.
                    continue;
                }

                // Create new cache record.
                $cacherecord = new stdClass();
                $cacherecord->objectid = $team['id'];
                $cacherecord->name = $team['displayName'];
                $cacherecord->description = $team['description'];
                $cacherecord->url = $teamurl;
                $cacherecord->locked = $lockstatus;
                $DB->insert_record('local_o365_teams_cache', $cacherecord);
            }
        }
        $this->mtrace('Deleting old teams cache records', 1);
        foreach ($existingcachebyoid as $oldcacherecord) {
            $DB->delete_records('local_o365_teams_cache', ['id' => $oldcacherecord->id]);
        }

        // Set last updated timestamp.
        set_config('teamscacheupdated', time(), 'local_o365');

        return true;
    }

    /**
     * Update team name for the course with the given ID.
     *
     * @param int $courseid
     *
     * @return bool
     */
    public function update_team_name(int $courseid) : bool {
        global $DB;

        if (!$course = $DB->get_record('course', ['id' => $courseid])) {
            return false;
        }

        if (!$objectrecord = $DB->get_record('local_o365_objects',
            ['type' => 'group', 'subtype' => 'courseteam', 'moodleid' => $courseid])) {
            if (!$objectrecord = $DB->get_record('local_o365_objects',
                ['type' => 'group', 'subtype' => 'teamfromgroup', 'moodleid' => $courseid])) {
                return false;
            }
        }

        $teamname = utils::get_team_display_name($course);
        $this->graphclient->update_team_name($objectrecord->objectid, $teamname);

        $objectrecord->o365name = $teamname;
        $objectrecord->timemodified = time();
        $DB->update_record('local_o365_objects', $objectrecord);

        return true;
    }

    /**
     * Reset the connection of a course with a Team.
     * The following actions are performed:
     *  - Rename existing group and Team.
     *  - Archive the Team.
     *  - Disconnect the Team from the course.
     *  - Create a new group and connect it to the course if configured.
     *  - Add owners (and not members) to the group.
     *  - Create team from the new group if owners are found.
     *
     * @param stdClass $course
     * @param stdClass $o365object
     * @param bool $teamexists
     * @param bool $createafterreset
     * @return bool
     */
    public function process_course_reset(stdClass $course, stdClass $o365object, bool $teamexists = false,
        bool $createafterreset = true) : bool {
        global $DB;

        // Rename existing group.
        try {
            $existinggroup = $this->graphclient->get_group($o365object->objectid);

            if ($existinggroup) {
                $resetgroupnameprefix = get_config('local_o365', 'reset_group_name_prefix');
                if ($resetgroupnameprefix === false) {
                    $resetgroupnameprefix = 'disconnected-';
                }
                $updatedmailnickname = $resetgroupnameprefix . utils::get_group_mail_alias($course);
                if (strlen($updatedmailnickname) > 59) {
                    $updatedmailnickname = substr($updatedmailnickname, 0, 59);
                }
                $updatedexistinggroup = [
                    'id' => $existinggroup['id'],
                    'mailNickname' => $updatedmailnickname,
                ];
                $this->graphclient->update_group($updatedexistinggroup);
            }
        } catch (moodle_exception $e) {
            // Cannot find existing group. Skip rename.
            $this->mtrace('Could not update mailnickname of the existing group with ID ' . $o365object->objectid . ' for course #' .
                $course->id . '. Reason: ' . $e->getMessage());
        }

        // Rename existing Team.
        if ($teamexists) {
            try {
                $resetteamnameprefix = get_config('local_o365', 'reset_team_name_prefix');
                if ($resetteamnameprefix === false) {
                    $resetteamnameprefix = '(archived) ';
                }
                $existingteamname = utils::get_team_display_name($course, $resetteamnameprefix);
                $this->graphclient->update_team_name($o365object->objectid, $existingteamname);
            } catch (moodle_exception $e) {
                $this->mtrace('Could not update name of the existing Team for course #' . $course->id . '. Reason: ' .
                    $e->getMessage());
            }
        }

        // Archive the Team.
        if ($teamexists) {
            try {
                $this->graphclient->archive_team($o365object->objectid);
            } catch (moodle_exception $e) {
                $this->mtrace('Could not archive Team for course #' . $course->id . '. Reason: ' . $e->getMessage());
            }
        }

        // Disconnect the Team from the course.
        $DB->delete_records('local_o365_objects', ['type' => 'group', 'subtype' => 'course', 'moodleid' => $course->id]);
        $DB->delete_records('local_o365_objects', ['type' => 'group', 'subtype' => 'courseteam', 'moodleid' => $course->id]);
        $DB->delete_records('local_o365_objects', ['type' => 'group', 'subtype' => 'teamfromgroup', 'moodleid' => $course->id]);

        // Create a new group / team and connect it to the course.
        if ($createafterreset) {
            // Create group.
            if ($this->haseducationlicense) {
                $groupobject = $this->create_education_group($course);

                if ($groupobject) {
                    $this->set_lti_properties_in_education_group($groupobject['objectid'], $course);
                } else {
                    $this->mtrace('Could not create education class group for course #' . $course->id);
                    return false;
                }
            } else {
                $groupobject = $this->create_standard_group($course);
                if (!$groupobject) {
                    $this->mtrace('Could not create standard group for course #' . $course->id);
                    return false;
                }
            }

            // Add owners to the group.
            $ownerobjectids = utils::get_team_owner_object_ids_by_course_id($course->id);
            $memberobjectids = utils::get_team_member_object_ids_by_course_id($course->id, $ownerobjectids);
            $owneradded = $this->add_group_owners_and_members_to_group($groupobject['objectid'], $ownerobjectids, $memberobjectids);

            // If owner exists, create team.
            if ($owneradded) {
                // Owner exists, proceed with Team creation.
                if ($this->haseducationlicense) {
                    $this->create_class_team_from_education_group($groupobject['objectid'], $course);
                } else {
                    $this->create_team_from_standard_group($groupobject['objectid'], $course);
                }
            }
        }

        return true;
    }

    /**
     * Resync the membership of a Microsoft 365 group based on the users enrolled in the associated course.
     *
     * @param int $courseid The ID of the course.
     * @param string $groupobjectid The object ID of the Microsoft 365 group.
     * @return array|false
     */
    public function process_course_team_user_sync_from_moodle_to_microsoft(int $courseid, string $groupobjectid = '') {
        global $DB;

        $this->mtrace('Syncing Microsoft group owners / members for course #' . $courseid);

        $skip = false;

        if (!$groupobjectid) {
            $sql = "SELECT distinct objectid
                      FROM {local_o365_objects}
                     WHERE type = :type
                       AND moodleid = :moodleid";
            $teamobjectids = $DB->get_fieldset_sql($sql, ['type' => 'group', 'moodleid' => $courseid]);
            if (empty($teamobjectids)) {
                // Sync is enabled but no Team is connected to the course.
                $this->mtrace('No Team is connected to the course. Skipping.', 2);
                return false;
            }
            $groupobjectid = reset($teamobjectids);
        }

        // Get current group membership.
        $members = [];
        try {
            $members = $this->get_group_members($groupobjectid);
        } catch (moodle_exception $e) {
            $skip = true;
            $this->mtrace('Failed to get group members. Details: ' . $e->getMessage(), 2);
        }

        $owners = [];
        try {
            $owners = $this->get_group_owners($groupobjectid);
        } catch (moodle_exception $e) {
            $skip = true;
            $this->mtrace('Failed to get group owners. Details: ' . $e->getMessage(), 2);
        }

        if ($skip) {
            $this->mtrace('Skipping syncing group owners / members for course');
            return false;
        }

        $currentmembers = array_keys($members);
        $currentowners = array_keys($owners);

        // Get intended group members.
        $intendedteamowners = utils::get_team_owner_object_ids_by_course_id($courseid);
        $intendedteammembers = utils::get_team_member_object_ids_by_course_id($courseid, $intendedteamowners);

        if (!empty($currentowners)) {
            $toaddowners = array_diff($intendedteamowners, $currentowners);
            $toremoveowners = array_diff($currentowners, $intendedteamowners);
        } else {
            $toaddowners = $intendedteamowners;
            $toremoveowners = [];
        }

        if (!empty($currentmembers)) {
            $toaddmembers = array_diff($intendedteammembers, $currentmembers);
            $toremovemembers = array_diff($currentmembers, $intendedteammembers);
        } else {
            $toaddmembers = $intendedteammembers;
            $toremovemembers = [];
        }

        // Check if group object is created.
        $this->mtrace('Check if group is setup', 1);
        $retrycounter = 0;
        while ($retrycounter <= API_CALL_RETRY_LIMIT) {
            try {
                if ($retrycounter) {
                    $this->mtrace('Retry #' . $retrycounter, 2);
                    sleep(10);
                }
                $result = $this->graphclient->get_group($groupobjectid);
                if (!empty($result['id'])) {
                    $this->mtrace('Group found.', 2);
                    break;
                } else {
                    $this->mtrace('Failed. Details: ' . \local_o365\utils::tostring($result), 2);
                    $retrycounter++;
                }
            } catch (moodle_exception $e) {
                $this->mtrace('Failed. Details: ' . $e->getMessage(), 2);
                $retrycounter++;
            }
        }

        // Remove owners.
        $this->mtrace('Owners to remove: ' . count($toremoveowners), 1);
        foreach ($toremoveowners as $userobjectid) {
            $this->mtrace('Removing ' . $userobjectid, 2);

            try {
                $this->remove_owner_from_group($groupobjectid, $userobjectid);
                $this->mtrace('Removed ownership.', 3);
            } catch (moodle_exception $e) {
                $this->mtrace('Error removing ownership. Details: ' . $e->getMessage(), 3);
            }
        }

        // Remove members.
        foreach ($toremovemembers as $key => $userobjectid) {
            if (in_array($userobjectid, $intendedteamowners)) {
                unset($toremovemembers[$key]);
            }
        }
        $this->mtrace('Members to remove: ' . count($toremovemembers), 1);
        foreach ($toremovemembers as $userobjectid) {
            $this->mtrace('Removing ' . $userobjectid, 2);

            try {
                $this->remove_member_from_group($groupobjectid, $userobjectid);
                $this->mtrace('Removed membership.', 3);
            } catch (moodle_exception $e) {
                $this->mtrace('Error removing membership. Details: ' . $e->getMessage(), 3);
            }
        }

        // Add owners and members in bulk.
        $this->mtrace('Add ' . count($toaddowners) . ' owners and ' . count($toaddmembers) . ' members in bulk', 1);
        $this->add_group_owners_and_members_to_group($groupobjectid, $toaddowners, $toaddmembers);

        $this->mtrace('Finished syncing group owners / members for course');

        return [array_unique(array_merge($toaddowners, $toaddmembers)),
            array_unique(array_merge($toremoveowners, $toremovemembers))];
    }

    /**
     * Return all group members for the group with the object ID.
     *
     * @param string $groupobjectid
     * @return array
     * @throws moodle_exception
     */
    public function get_group_members(string $groupobjectid) : array {
        $groupmembers = [];

        $memberrecords = $this->graphclient->get_group_members($groupobjectid);
        foreach ($memberrecords as $memberrecord) {
            $groupmembers[$memberrecord['id']] = $memberrecord;
        }

        return $groupmembers;
    }

    /**
     * Return all group owners for the group with the object ID.
     *
     * @param string $groupobjectid
     * @return array
     * @throws moodle_exception
     */
    public function get_group_owners(string $groupobjectid) : array {
        $groupowners = [];

        $ownerresults = $this->graphclient->get_group_owners($groupobjectid);
        foreach ($ownerresults as $ownerresult) {
            $groupowners[$ownerresult['id']] = $ownerresult;
        }

        return $groupowners;
    }

    /**
     * Get the IDs of all present groups.
     *
     * @return array An array of group IDs.
     */
    public function get_all_group_ids() : array {
        $groupids = [];

        $groups = $this->graphclient->get_groups();
        foreach ($groups as $group) {
            $groupids[] = $group['id'];
        }

        return $groupids;
    }

    /**
     * Check the lock status of the team with the given object ID.
     *
     * @param string $groupobjectid
     * @return bool
     */
    public function is_team_locked(string $groupobjectid) {
        global $DB;

        if ($teamcacherecord = $DB->get_record('local_o365_teams_cache', ['objectid' => $groupobjectid])) {
            switch ($teamcacherecord->locked) {
                case TEAM_LOCKED_STATUS_UNKNOWN:
                    try {
                        [$team, $teamurl, $lockstatus] = $this->graphclient->get_team($groupobjectid);
                        $DB->set_field('local_o365_teams_cache', 'locked', $lockstatus, ['objectid' => $groupobjectid]);
                    } catch (moodle_exception $e) {
                        $lockstatus = TEAM_UNLOCKED;
                    }

                    break;
                case TEAM_LOCKED:
                    try {
                        [$team, $teamurl, $lockstatus] = $this->graphclient->get_team($groupobjectid);
                        if ($lockstatus == TEAM_UNLOCKED) {
                            $DB->set_field('local_o365_teams_cache', 'locked', $lockstatus, ['objectid' => $groupobjectid]);
                        }
                    } catch (moodle_exception $e) {
                        $lockstatus = TEAM_UNLOCKED;
                    }

                    break;
                case TEAM_UNLOCKED:
                    $lockstatus = TEAM_UNLOCKED;

                    break;
            }
        } else {
            // Team cache record doesn't exist, we need to create one.
            try {
                [$team, $teamurl, $lockstatus] = $this->graphclient->get_team($groupobjectid);
            } catch (moodle_exception $e) {
                $lockstatus = TEAM_UNLOCKED;
            }
        }

        return ($lockstatus == TEAM_LOCKED);
    }

    /**
     * Add a user as member to a Microsoft 365 group using Moodle course and user object IDs.
     * The function will determine whether to use group API or teams API depending on the nature of the group.
     *
     * @param string $groupobjectid
     * @param string $userobjectid
     * @return void
     * @throws moodle_exception
     */
    public function add_member_to_group(string $groupobjectid, string $userobjectid) {
        if (utils::is_team_created_from_group($groupobjectid)) {
            if ($this->is_team_locked($groupobjectid)) {
                $this->graphclient->add_member_to_group_using_group_api($groupobjectid, $userobjectid);
            } else {
                try {
                    $this->graphclient->add_member_to_group_using_teams_api($groupobjectid, $userobjectid);
                } catch (moodle_exception $e) {
                    // Fallback to use group API to add members.
                    $this->graphclient->add_member_to_group_using_group_api($groupobjectid, $userobjectid);
                }
            }
        } else {
            $this->graphclient->add_member_to_group_using_group_api($groupobjectid, $userobjectid);
        }
    }

    /**
     * Add a user as owner to a Microsoft 365 group using Moodle course and user object IDs.
     * The function will determine whether to use group API or teams API depending on the nature of the group.
     *
     * @param string $groupobjectid
     * @param string $userobjectid
     * @throws moodle_exception
     */
    public function add_owner_to_group(string $groupobjectid, string $userobjectid) {
        if (utils::is_team_created_from_group($groupobjectid)) {
            try {
                $this->graphclient->add_owner_to_group_using_teams_api($groupobjectid, $userobjectid);
            } catch (moodle_exception $e) {
                // Fallback to use group API to add owners.
                $this->graphclient->add_owner_to_group_using_group_api($groupobjectid, $userobjectid);
                $this->graphclient->add_member_to_group_using_group_api($groupobjectid, $userobjectid);
            }
        } else {
            $this->graphclient->add_owner_to_group_using_group_api($groupobjectid, $userobjectid);
            $this->graphclient->add_member_to_group_using_group_api($groupobjectid, $userobjectid);
        }
    }

    /**
     * Remove a user from a Microsoft 365 group by Moodle course and user object IDs.
     * The function will determine whether to use group API or teams API depending on the nature of the group and the membership.
     *
     * @param string $groupobjectid
     * @param string $userobjectid
     */
    public function remove_member_from_group(string $groupobjectid, string $userobjectid) {
        $removed = false;
        if (utils::is_team_created_from_group($groupobjectid)) {
            if (!$this->is_team_locked($groupobjectid)) {
                $aaduserconversationmemberid = '';
                try {
                    $aaduserconversationmemberid =
                        $this->graphclient->get_aad_user_conversation_member_id($groupobjectid, $userobjectid);
                } catch (moodle_exception $e) {
                    // Do nothing.
                }
                if ($aaduserconversationmemberid) {
                    try {
                        $this->graphclient->remove_owner_and_member_from_group_using_teams_api($groupobjectid,
                            $aaduserconversationmemberid);
                        $removed = true;
                    } catch (moodle_exception $e) {
                        // Do nothing.
                    }
                }
            }
        }
        if (!$removed) {
            $this->graphclient->remove_member_from_group_using_group_api($groupobjectid, $userobjectid);
        }
    }

    /**
     * Remove an owner from a Microsoft 365 group by Moodle course and user IDs.
     * The function will determine whether to use group API or teams API depending on the nature of the group and the ownership.
     *
     * @param string $groupobjectid
     * @param string $userobjectid
     */
    public function remove_owner_from_group(string $groupobjectid, string $userobjectid) {
        $removed = false;
        if (utils::is_team_created_from_group($groupobjectid)) {
            $aaduserconversationmemberid = '';
            try {
                $aaduserconversationmemberid =
                    $this->graphclient->get_aad_user_conversation_member_id($groupobjectid, $userobjectid);
            } catch (moodle_exception $e) {
                // Do nothing.
            }
            if ($aaduserconversationmemberid) {
                try {
                    $this->graphclient->remove_owner_and_member_from_group_using_teams_api($groupobjectid,
                        $aaduserconversationmemberid);
                    $removed = true;
                } catch (moodle_exception $e) {
                    // Do nothing.
                }
            }
        }
        if (!$removed) {
            $this->graphclient->remove_member_from_group_using_group_api($groupobjectid, $userobjectid);
            $this->graphclient->remove_owner_from_group_using_group_api($groupobjectid, $userobjectid);
        }
    }

    /**
     * Resync the membership of a Moodle course based on the users enrolled in the associated Microsoft 365 group.
     *
     * @param int $courseid The ID of the course.
     * @param string $groupobjectid The object ID of the Microsoft 365 group.
     * @param array|null $connectedusers An array of Moodle user IDs as keys and Microsoft 365 user object IDs as values.
     * @return bool
     */
    public function process_course_team_user_sync_from_microsoft_to_moodle(int $courseid, string $groupobjectid = '',
        array $connectedusers = null) : bool {
        global $DB;

        $coursecontext = context_course::instance($courseid);

        $this->mtrace('Sync course teachers and students from Teams to Moodle for course #' . $courseid . ' ...', 1);

        if (is_null($connectedusers)) {
            $moodletomicrosoftusermappings = \local_o365\utils::get_connected_users();
        } else {
            $moodletomicrosoftusermappings = $connectedusers;
        }
        $microsofttomoodleusermappings = array_flip($moodletomicrosoftusermappings);

        if (!$groupobjectid) {
            $sql = "SELECT distinct objectid
                      FROM {local_o365_objects}
                     WHERE type = :type
                       AND moodleid = :moodleid";
            $teamobjectids = $DB->get_fieldset_sql($sql, ['type' => 'group', 'moodleid' => $courseid]);
            if (empty($teamobjectids)) {
                // Sync is enabled but no Team is connected to the course.
                $this->mtrace('No Team is connected to the course. Skipping.', 2);
                return false;
            }
            $groupobjectid = reset($teamobjectids);
        }

        // Get current group membership.
        $skip = false;
        try {
            $groupmembers = $this->get_group_members($groupobjectid);
        } catch (moodle_exception $e) {
            $skip = true;
            $this->mtrace('Failed to get group members. Details: ' . $e->getMessage(), 2);
        }

        try {
            $groupowners = $this->get_group_owners($groupobjectid);
        } catch (moodle_exception $e) {
            $skip = true;
            $this->mtrace('Failed to get group owners. Details: ' . $e->getMessage(), 2);
        }

        if ($skip) {
            $this->mtrace('Skipping syncing group owners / members for course');
            return false;
        }

        // Get Moodle IDs of connected group members.
        $connectedgroupmembers = [];
        foreach ($groupmembers as $objectid => $value) {
            if (array_key_exists($objectid, $microsofttomoodleusermappings)) {
                $connectedgroupmembers[$microsofttomoodleusermappings[$objectid]] = $value;
            }
        }
        $connectedintendedcoursestudents = array_keys($connectedgroupmembers);

        // Get Moodle IDs of connected group owners.
        $connectedgroupowners = [];
        foreach ($groupowners as $objectid => $value) {
            if (array_key_exists($objectid, $microsofttomoodleusermappings)) {
                $connectedgroupowners[$microsofttomoodleusermappings[$objectid]] = $value;
            }
        }
        $connectedintendedcourseteachers = array_keys($connectedgroupowners);

        // Remove owners from members list.
        $connectedintendedcoursestudents = array_diff($connectedintendedcoursestudents, $connectedintendedcourseteachers);

        // Get role IDs from config.
        $ownerroleid = get_config('local_o365', 'coursesyncownerrole');
        $memberroleid = get_config('local_o365', 'coursesyncmemberrole');

        // Get Moodle IDs of connected course teachers.
        $courseenrolleduserids = array_keys(get_enrolled_users($coursecontext));
        $courseteacherids = array_keys(get_role_users($ownerroleid, $coursecontext));
        $connectedcurrentcourseteachers = array_intersect($courseenrolleduserids, $courseteacherids,
            array_keys($moodletomicrosoftusermappings));

        // Get Moodle IDs of connected course students.
        $coursestudentids = array_keys(get_role_users($memberroleid, $coursecontext));
        $connectedcurrentcoursestudents = array_intersect($courseenrolleduserids, $coursestudentids,
            array_keys($moodletomicrosoftusermappings));

        // Sync teachers.
        //  - $connectedcurrentcourseteachers contains the current teachers in the course.
        //  - $connectedintendedcourseteachers contains the teachers that should be in the course.
        $teacherstoenrol = array_diff($connectedintendedcourseteachers, $connectedcurrentcourseteachers);
        if ($teacherstoenrol) {
            $this->mtrace('Adding teacher role to ' . count($teacherstoenrol) . ' users...', 2);
            foreach ($teacherstoenrol as $userid) {
                $this->assign_role_by_user_id_role_id_and_course_context($userid, $ownerroleid, $coursecontext);
            }
        } else {
            $this->mtrace('No user to have teacher role added.', 2);
        }

        $teacherstounenrol = array_diff($connectedcurrentcourseteachers, $connectedintendedcourseteachers);
        if ($teacherstounenrol) {
            $this->mtrace('Removing teacher role from ' . count($teacherstounenrol) . ' users...', 2);
            foreach ($teacherstounenrol as $userid) {
                $this->unassign_role_by_user_id_role_id_and_course_context($userid, $ownerroleid, $coursecontext,
                    in_array($userid, $connectedintendedcoursestudents));
            }
        } else {
            $this->mtrace('No user to have teacher role removed.', 2);
        }

        // Sync students.
        //  - $connectedcurrentcoursestudents contains the current students in the course.
        //  - $connectedintendedcoursestudents contains the students that should be in the course.
        $studentstoenrol = array_diff($connectedintendedcoursestudents, $connectedcurrentcoursestudents);
        if ($studentstoenrol) {
            $this->mtrace('Adding student role to ' . count($studentstoenrol) . ' users...', 2);
            foreach ($studentstoenrol as $userid) {
                $this->assign_role_by_user_id_role_id_and_course_context($userid, $memberroleid, $coursecontext);
            }
        } else {
            $this->mtrace('No user to have student role added.', 2);
        }

        $studentstounenrol = array_diff($connectedcurrentcoursestudents, $connectedintendedcoursestudents);
        if ($studentstounenrol) {
            $this->mtrace('Removing student role from ' . count($studentstounenrol) . ' users...', 2);
            foreach ($studentstounenrol as $userid) {
                $this->unassign_role_by_user_id_role_id_and_course_context($userid, $memberroleid, $coursecontext,
                    in_array($userid, $connectedintendedcourseteachers));
            }
        } else {
            $this->mtrace('No user to have student role removed.', 2);
        }

        return true;
    }

    /**
     * Assign the role with the given ID to the user with the given ID in the course with the given context.
     *
     * @param int $userid The Moodle ID of the user.
     * @param int $roleid The ID of the role.
     * @param context_course $context The context of the course.
     */
    private function assign_role_by_user_id_role_id_and_course_context(int $userid, int $roleid, context_course $context) : void {
        enrol_try_internal_enrol($context->instanceid, $userid, $roleid);
        $this->mtrace('Assigned role #' . $roleid . ' to user #' . $userid . '.', 3);
    }

    /**
     * Unassign the role with the given ID from the user with the given ID in the course with the given context.
     * If the user doesn't have any other role assignment in the course, attempt to unenrol the user too.
     *
     * @param int $userid The Moodle ID of the user.
     * @param int $roleid The ID of the role.
     * @param context_course $context The context of the course.
     * @param bool $hasotherrole Whether the user has other role.
     */
    private function unassign_role_by_user_id_role_id_and_course_context(int $userid, int $roleid, context_course $context,
        bool $hasotherrole) : void {
        role_unassign($roleid, $userid, $context->id);
        $this->mtrace('Removed role #' . $roleid . ' from user #' . $userid . '.', 3);

        // Check if the user has any other roles in the course.
        $userroles = get_user_roles($context, $userid, false);
        if (!$hasotherrole && empty($userroles)) {
            $this->unenrol_user_by_user_id_and_course_id($userid, $context->instanceid);
        }
    }

    /**
     * Unenrol the user with the given ID from the course with the given ID.
     *
     * @param int $userid The Moodle ID of the user.
     * @param int $courseid The ID of the course.
     * @return bool
     */
    private function unenrol_user_by_user_id_and_course_id(int $userid, int $courseid) : bool {
        global $DB;

        $sql = "SELECT *
                  FROM {user_enrolments} ue
            INNER JOIN {enrol} e ON ue.enrolid = e.id
                 WHERE ue.userid = :userid
                   AND e.courseid = :courseid";
        $userenrolments = $DB->get_records_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);

        if (empty($userenrolments)) {
            return false;
        }

        $unenrolled = false;
        foreach ($userenrolments as $userenrolment) {
            $enrolinstance = $DB->get_record('enrol', ['id' => $userenrolment->enrolid]);
            $plugin = enrol_get_plugin($enrolinstance->enrol);

            if ($plugin->allow_unenrol($enrolinstance)) {
                $plugin->unenrol_user($enrolinstance, $userid);
                $unenrolled = true;
            }
        }

        if ($unenrolled) {
            $this->mtrace('Unenroled user #' . $userid . ' from course #' . $courseid . '.', 4);
        }

        return true;
    }

    /**
     * Perform initial dual way Moodle course and Microsoft Teams user sync between course and Teams.
     * In this mode:
     *  - Moodle users with configured teacher role will be added as Team owners;
     *  - Moodle users with configured student role will be added as Team members;
     *  - Team owners will be added as Moodle users with configured teacher role;
     *  - Team members will be added as Moodle users with configured student role.
     * No role unassignment or Team owner/member removal will be performed.
     *
     * @param int $courseid The ID of the course.
     * @param string $groupobjectid The object ID of the Microsoft 365 group.
     */
    public function process_initial_course_team_user_sync(int $courseid, string $groupobjectid) : void {
        $coursecontext = context_course::instance($courseid);

        $this->mtrace('Perform initial Moodle course and Microsoft Teams user sync between course #' . $courseid .
            ' and Teams ' . $groupobjectid  . ' ...', 1);

        $moodletomicrosoftusermappings = \local_o365\utils::get_connected_users();
        $microsofttomoodleusermappings = array_flip($moodletomicrosoftusermappings);

        try {
            $groupowners = $this->get_group_owners($groupobjectid);
            $groupmembers = $this->get_group_members($groupobjectid);
        } catch (moodle_exception $e) {
            $this->mtrace('Error getting owners and members from Teams ' . $groupobjectid  . '. Exit...', 1);
            return;
        }

        // Get Moodle IDs of connected group owners.
        $connectedgroupowners = [];
        foreach ($groupowners as $objectid => $value) {
            if (array_key_exists($objectid, $microsofttomoodleusermappings)) {
                $connectedgroupowners[$microsofttomoodleusermappings[$objectid]] = $value;
            }
        }
        $connectedintendedcourseteachers = array_keys($connectedgroupowners);

        // Get Moodle IDs of connected group members.
        $connectedgroupmembers = [];
        foreach ($groupmembers as $objectid => $value) {
            if (array_key_exists($objectid, $microsofttomoodleusermappings)) {
                $connectedgroupmembers[$microsofttomoodleusermappings[$objectid]] = $value;
            }
        }
        $connectedintendedcoursestudents = array_keys($connectedgroupmembers);

        // Remove owners from members list.
        $connectedintendedcoursestudents = array_diff($connectedintendedcoursestudents, $connectedintendedcourseteachers);

        // Get role IDs from config.
        $ownerroleid = get_config('local_o365', 'coursesyncownerrole');
        $memberroleid = get_config('local_o365', 'coursesyncmemberrole');

        // Get Moodle IDs of connected course teachers.
        $courseteachers = get_role_users($ownerroleid, $coursecontext, true);
        $connectedcurrentcourseteachers = [];
        foreach ($courseteachers as $courseteacher) {
            if (array_key_exists($courseteacher->id, $moodletomicrosoftusermappings)) {
                $connectedcurrentcourseteachers[] = (int) $courseteacher->id;
            }
        }

        // Get Moodle IDs of connected course students.
        $coursestudents = get_role_users($memberroleid, $coursecontext, true);
        $connectedcurrentcoursestudents = [];
        foreach ($coursestudents as $coursestudent) {
            if (array_key_exists($coursestudent->id, $moodletomicrosoftusermappings)) {
                $connectedcurrentcoursestudents[] = (int) $coursestudent->id;
            }
        }

        // Sync teachers from Microsoft Teams to Moodle course.
        //  - $connectedcurrentcourseteachers contains the current teachers in the course.
        //  - $connectedintendedcourseteachers contains the teachers that should be in the course.
        $teacherstoenrol = array_diff($connectedintendedcourseteachers, $connectedcurrentcourseteachers);
        if ($teacherstoenrol) {
            $this->mtrace('Adding teacher role to ' . count($teacherstoenrol) . ' users...', 2);
            foreach ($teacherstoenrol as $userid) {
                $this->assign_role_by_user_id_role_id_and_course_context($userid, $ownerroleid, $coursecontext);
            }
        } else {
            $this->mtrace('No user to have teacher role added.', 2);
        }

        // Sync students from Microsoft Teams to Moodle course.
        //  - $connectedcurrentcoursestudents contains the current students in the course.
        //  - $connectedintendedcoursestudents contains the students that should be in the course.
        $studentstoenrol = array_diff($connectedintendedcoursestudents, $connectedcurrentcoursestudents);
        if ($studentstoenrol) {
            $this->mtrace('Adding student role to ' . count($studentstoenrol) . ' users...', 2);
            foreach ($studentstoenrol as $userid) {
                $this->assign_role_by_user_id_role_id_and_course_context($userid, $memberroleid, $coursecontext);
            }
        } else {
            $this->mtrace('No user to have student role added.', 2);
        }

        // Sync owners and members from Moodle course to Microsoft Teams.
        $ownerstoadd = array_diff($connectedcurrentcourseteachers, $connectedintendedcourseteachers);
        $memberstoadd = array_diff($connectedcurrentcoursestudents, $connectedintendedcoursestudents);
        $owneroids = [];
        foreach ($ownerstoadd as $owneruid) {
            $owneroids[] = $moodletomicrosoftusermappings[$owneruid];
        }
        $memberoids = [];
        foreach ($memberstoadd as $memberuid) {
            $memberoids[] = $moodletomicrosoftusermappings[$memberuid];
        }
        $this->mtrace('Add ' . count($owneroids) . ' owners and ' . count($memberoids) . ' members in bulk', 2);
        $this->add_group_owners_and_members_to_group($groupobjectid, $owneroids, $memberoids);
    }
}
