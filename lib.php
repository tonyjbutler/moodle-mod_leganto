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
 * Mandatory public API of leganto module.
 *
 * @package    mod_leganto
 * @copyright  2017 Lancaster University {@link http://www.lancaster.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tony Butler <a.butler4@lancaster.ac.uk>
 */

/** Display leganto contents on a separate page. */
define('LEGANTO_DISPLAY_PAGE', 0);
/** Display leganto contents inline in a course. */
define('LEGANTO_DISPLAY_INLINE_COLLAPSED', 1);
define('LEGANTO_DISPLAY_INLINE_EXPANDED', 2);

/**
 * List of features supported in leganto module.
 *
 * @param string $feature FEATURE_xx constant for requested feature.
 * @return mixed True if module supports feature, false if not, null if doesn't know.
 */
function leganto_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;

        default:
            return null;
    }
}

/**
 * Returns all other caps used in module.
 *
 * @return array
 */
function leganto_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 *
 * @param object $data The data submitted from the reset course.
 * @return array Status array.
 */
function leganto_reset_userdata($data) {
    return [];
}

/**
 * List of view style log actions.
 *
 * @return array
 */
function leganto_get_view_actions() {
    return ['view', 'view all'];
}

/**
 * List of update style log actions.
 *
 * @return array
 */
function leganto_get_post_actions() {
    return ['update', 'add'];
}

/**
 * Add leganto instance.
 *
 * @param object $data
 * @param object $mform
 * @return int New leganto instance id.
 */
function leganto_add_instance($data, $mform) {
    global $DB;

    $cmid = $data->coursemodule;
    $leganto = new leganto(context_module::instance($cmid), null, null);

    $data->timemodified = time();
    $data->citations = $leganto->get_citations($data);
    $data->id = $DB->insert_record('leganto', $data);

    $DB->set_field('course_modules', 'instance', $data->id, ['id' => $cmid]);

    return $data->id;
}

/**
 * Update leganto instance.
 *
 * @param object $data
 * @param object $mform
 * @return bool True.
 */
function leganto_update_instance($data, $mform) {
    global $DB;

    $context = context_module::instance($data->coursemodule);
    $leganto = new leganto($context, null, null);

    $data->id = $data->instance;
    $data->timemodified = time();
    $data->citations = $leganto->get_citations($data);

    $DB->update_record('leganto', $data);

    return true;
}

/**
 * Delete leganto instance.
 *
 * @param int $id
 * @return bool True.
 */
function leganto_delete_instance($id) {
    global $DB;

    if (!$leganto = $DB->get_record('leganto', ['id' => $id])) {
        return false;
    }

    // Note: all context files are deleted automatically.
    $DB->delete_records('leganto', ['id' => $leganto->id]);

    return true;
}

/**
 * Return a list of page types.
 *
 * @param string $pagetype Current page type.
 * @param stdClass $parentcontext Block's parent context.
 * @param stdClass $currentcontext Current context of block.
 * @return array Page types.
 */
function leganto_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $modulepagetype = ['mod-leganto-*' => get_string('page-mod-leganto-x', 'leganto')];

    return $modulepagetype;
}

/**
 * Given a coursemodule object, this function returns the extra
 * information needed to print this activity in various places.
 *
 * If leganto needs to be displayed inline we store additional information
 * in customdata, so functions {@see leganto_cm_info_dynamic()} and
 * {@see leganto_cm_info_view()} do not need to do DB queries.
 *
 * @param cm_info $cm
 * @return cached_cm_info Cached course module info.
 */
function leganto_get_coursemodule_info($cm) {
    global $DB;

    if (!($leganto = $DB->get_record('leganto', ['id' => $cm->instance], 'id, name, intro, introformat, display, citations'))) {
        return null;
    }
    $cminfo = new cached_cm_info();
    $cminfo->name = $leganto->name;
    if ($leganto->display != LEGANTO_DISPLAY_PAGE) {
        // Prepare leganto object to store in customdata.
        $fdata = new stdClass();
        if ($cm->showdescription && strlen(trim($leganto->intro))) {
            $fdata->intro = $leganto->intro;
            if ($leganto->introformat != FORMAT_MOODLE) {
                $fdata->introformat = $leganto->introformat;
            }
        }
        $fdata->display = $leganto->display;
        $fdata->citations = $leganto->citations;
        $cminfo->customdata = $fdata;
    } else {
        if ($cm->showdescription) {
            // Convert intro to html. Do not filter cached version, filters run at display time.
            $cminfo->content = format_module_intro('leganto', $leganto, $cm->id, false);
        }
    }

    return $cminfo;
}

/**
 * Sets dynamic information about a course module.
 *
 * This function is called from cm_info when displaying the module.
 * mod_leganto can be displayed inline on course page and therefore have no course link.
 *
 * @param cm_info $cm
 */
function leganto_cm_info_dynamic(cm_info $cm) {
    if ($cm->get_custom_data()) {
        // The field 'customdata' is not empty IF AND ONLY IF we display contents inline.
        $cm->set_on_click('return false;');

        // Display a visual cue to users that clicking the link toggles visibility.
        $showhidearrow = html_writer::span('', 'showhidearrow fa fa-chevron-down fa-fw', ['id' => 'showhide-' . $cm->id]);
        $linkattributes = [
            'onclick' => 'return false;',
            'title'   => get_string('showhide', 'leganto'),
        ];
        $showhidelink = html_writer::link($cm->url, $showhidearrow, $linkattributes);
        $cm->set_after_link($showhidelink);
    }
}

/**
 * Overwrites the content in the course-module object with the leganto content
 * if leganto.display != LEGANTO_DISPLAY_PAGE.
 *
 * @param cm_info $cm
 */
function leganto_cm_info_view(cm_info $cm) {
    global $PAGE;

    if ($cm->uservisible && $cm->customdata && has_capability('mod/leganto:view', $cm->context)) {
        // Restore leganto object from customdata.
        // Note the field 'customdata' is not empty IF AND ONLY IF we display contents inline.
        // Otherwise the content is default.
        $leganto = $cm->customdata;
        $leganto->id = (int)$cm->instance;
        $leganto->course = (int)$cm->course;
        $leganto->name = $cm->name;
        if (empty($leganto->intro)) {
            $leganto->intro = '';
        }
        if (empty($leganto->introformat)) {
            $leganto->introformat = FORMAT_MOODLE;
        }
        // Display leganto.
        if ($renderer = $PAGE->get_renderer('mod_leganto')) {
            $cm->set_content($renderer->display_leganto($leganto), true);
        }
    }
}
