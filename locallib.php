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
 * Private leganto module utility functions.
 *
 * @package    mod_leganto
 * @copyright  2017 Lancaster University {@link http://www.lancaster.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tony Butler <a.butler4@lancaster.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

define('ALMA_GET_COURSES', 0);
define('ALMA_GET_COURSE', 1);
define('ALMA_GET_LIST', 2);
define('ALMA_GET_CITATION', 3);

require_once($CFG->dirroot . '/mod/leganto/lib.php');

/**
 * Standard base class for mod_leganto.
 *
 * @package    mod_leganto
 * @copyright  2017 Lancaster University {@link http://www.lancaster.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tony Butler <a.butler4@lancaster.ac.uk>
 */
class leganto {
    /** @var stdClass The leganto record that contains the global settings for this leganto instance. */
    private $instance;

    /** @var context The context of the course module for this leganto instance
     *               (or just the course if we are creating a new one).
     */
    private $context;

    /** @var stdClass The course this leganto instance belongs to. */
    private $course;

    /** @var stdClass The admin config for all leganto instances. */
    private $adminconfig;

    /** @var leganto_renderer The custom renderer for this module. */
    private $output;

    /** @var stdClass The course module for this leganto instance. */
    private $coursemodule;

    /** @var array Cache for things like the coursemodule name or the scale menu - only lives for a single request. */
    private $cache;

    /** @var string Action to be used to return to this page (without repeating any form submissions etc). */
    private $returnaction = 'view';

    /** @var array Params to be used to return to this page. */
    private $returnparams = [];

    /** @var string modulename Prevents excessive calls to get_string. */
    private static $modulename = null;

    /** @var string modulenameplural Prevents excessive calls to get_string. */
    private static $modulenameplural = null;

    /** @var array List of suspended user ids in form of ([id1] => id1). */
    public $susers = null;

    /** @var bool Whether or not the Alma API has been fully configured. */
    private $apiconfigured = false;

    /** @var string Regular expression matching a leganto section id. */
    private $sectionidregex = '/^section-[0-9]{16,}$/';

    /** @var string Regular expression matching a leganto citation id. */
    private $citationidregex = '/^citation-[0-9]{16,}$/';

    /**
     * Constructor for the base leganto class.
     *
     * @param mixed $coursemodulecontext context|null The course module context
     *                                   (or the course context if the coursemodule has not been
     *                                   created yet).
     * @param mixed $coursemodule The current course module if it was already loaded,
     *                            otherwise this class will load one from the context as required.
     * @param mixed $course The current course  if it was already loaded,
     *                      otherwise this class will load one from the context as required.
     */
    public function __construct($coursemodulecontext, $coursemodule, $course) {
        $this->context = $coursemodulecontext;
        $this->coursemodule = $coursemodule;
        $this->course = $course;

        // Temporary cache only lives for a single request - used to reduce db lookups.
        $this->cache = [];
    }

    /**
     * Set the action and parameters that can be used to return to the current page.
     *
     * @param string $action The action for the current page.
     * @param array $params An array of name value pairs which form the parameters to return to the current page.
     * @return void
     */
    public function register_return_link($action, $params) {
        global $PAGE;

        $params['action'] = $action;
        $currenturl = $PAGE->url;

        $currenturl->params($params);
        $PAGE->set_url($currenturl);
    }

    /**
     * Return an action that can be used to get back to the current page.
     *
     * @return string Action.
     */
    public function get_return_action() {
        global $PAGE;

        $params = $PAGE->url->params();

        if (!empty($params['action'])) {
            return $params['action'];
        }

        return '';
    }

    /**
     * Return a list of parameters that can be used to get back to the current page.
     *
     * @return array Params.
     */
    public function get_return_params() {
        global $PAGE;

        $params = $PAGE->url->params();
        unset($params['id']);
        unset($params['action']);

        return $params;
    }

    /**
     * Set the submitted form data.
     *
     * @param stdClass $data The form data (instance).
     */
    public function set_instance(stdClass $data) {
        $this->instance = $data;
    }

    /**
     * Set the context.
     *
     * @param context $context The new context.
     */
    public function set_context(context $context) {
        $this->context = $context;
    }

    /**
     * Set the course data.
     *
     * @param stdClass $course The course data.
     */
    public function set_course(stdClass $course) {
        $this->course = $course;
    }

    /**
     * Has this leganto been constructed from an instance?
     *
     * @return bool
     */
    public function has_instance() {
        return $this->instance || $this->get_course_module();
    }

    /**
     * Get the settings for the current instance of this leganto.
     *
     * @return stdClass The settings.
     */
    public function get_instance() {
        global $DB;

        if ($this->instance) {
            return $this->instance;
        }
        if ($this->get_course_module()) {
            $params = ['id' => $this->get_course_module()->instance];
            $this->instance = $DB->get_record('leganto', $params, '*', MUST_EXIST);
        }
        if (!$this->instance) {
            throw new coding_exception('Improper use of the leganto class. ' .
                                       'Cannot load the leganto record.');
        }

        return $this->instance;
    }

    /**
     * Get the context of the current course.
     *
     * @return context|null The course context.
     */
    public function get_course_context() {
        if (!$this->context && !$this->course) {
            throw new coding_exception('Improper use of the leganto class. Cannot load the course context.');
        }
        if ($this->context) {
            return $this->context->get_course_context();
        }

        return context_course::instance($this->course->id);
    }


    /**
     * Get the current course module.
     *
     * @return stdClass|null The course module.
     */
    public function get_course_module() {
        if ($this->coursemodule) {
            return $this->coursemodule;
        }
        if (!$this->context) {
            return null;
        }
        if ($this->context->contextlevel == CONTEXT_MODULE) {
            $this->coursemodule = get_coursemodule_from_id('leganto', $this->context->instanceid, 0, false, MUST_EXIST);
            return $this->coursemodule;
        }

        return null;
    }

    /**
     * Get context module.
     *
     * @return context
     */
    public function get_context() {
        return $this->context;
    }

    /**
     * Get the current course.
     *
     * @return mixed stdClass|null The course.
     */
    public function get_course() {
        global $DB;

        if ($this->course) {
            return $this->course;
        }

        if (!$this->context) {
            return null;
        }
        $params = ['id' => $this->get_course_context()->instanceid];
        $this->course = $DB->get_record('course', $params, '*', MUST_EXIST);

        return $this->course;
    }

    /**
     * Get the name of the current module.
     *
     * @return string The module name (Leganto reading list).
     */
    protected function get_module_name() {
        if (isset(self::$modulename)) {
            return self::$modulename;
        }
        self::$modulename = get_string('modulename', 'leganto');

        return self::$modulename;
    }

    /**
     * Get the plural name of the current module.
     *
     * @return string The module name plural (Leganto reading lists).
     */
    protected function get_module_name_plural() {
        if (isset(self::$modulenameplural)) {
            return self::$modulenameplural;
        }
        self::$modulenameplural = get_string('modulenameplural', 'leganto');

        return self::$modulenameplural;
    }

    /**
     * View a link to go back to the previous page. Uses url parameters returnaction and returnparams.
     *
     * @return string
     */
    protected function view_return_links() {
        $returnaction = optional_param('returnaction', '', PARAM_ALPHA);
        $returnparams = optional_param('returnparams', '', PARAM_TEXT);

        $params = [];
        $returnparams = str_replace('&amp;', '&', $returnparams);
        parse_str($returnparams, $params);
        $newparams = ['id' => $this->get_course_module()->id, 'action' => $returnaction];
        $params = array_merge($newparams, $params);

        $url = new moodle_url('/mod/leganto/view.php', $params);

        return $this->get_renderer()->single_button($url, get_string('back'), 'get');
    }

    /**
     * Load and cache the admin config for the leganto module.
     *
     * @return stdClass The plugin config.
     */
    public function get_admin_config() {
        global $CFG;

        if ($this->adminconfig) {
            return $this->adminconfig;
        }

        $this->adminconfig = get_config('leganto');

        // Clean up Alma API URL if necessary.
        $search = ['http://', 'https://', get_string('apiurl_default', 'leganto')];
        $baseurl = trim(str_ireplace($search, '', $this->adminconfig->apiurl), '/');
        if (!empty($baseurl)) {
            $slashpos = strpos($baseurl, '/');
            if ($slashpos !== false) {
                $baseurl = substr_replace($baseurl, '', $slashpos);
            }
            $this->adminconfig->apiurl = 'https://' . $baseurl;
        } else {
            $this->adminconfig->apiurl = '';
        }

        // Remove database prefix from Alma course code table name if present.
        if (isset($this->adminconfig->codetable)) {
            $this->adminconfig->codetable = str_replace($CFG->prefix, '', $this->adminconfig->codetable);
        }

        return $this->adminconfig;
    }

    /**
     * Check whether the Alma API has been fully configured.
     *
     * @return bool True if fully configured, else false.
     */
    private function is_api_configured() {
        if ($this->apiconfigured) {
            return true;
        }

        $adminconfig = $this->get_admin_config();

        $settings = [
            'apiurl',
            'apikey',
        ];
        $message = [];

        foreach ($settings as $setting) {
            if (empty($adminconfig->$setting)) {
                $message[] = get_string('settingnotconfigured', 'leganto', $setting);
            }
        }

        if (!empty($message)) {
            $message[] = get_string('apinotconfigured', 'leganto');
            mtrace(implode("\n", $message));
            return false;
        }

        return $this->apiconfigured = true;
    }

    /**
     * Call a specified Alma API method, passing the parameters provided.
     *
     * @param int $method The API method to call.
     * @param string $q An Alma course search query string.
     * @param string $courseid The identifier of an Alma course.
     * @param string $listid The identifier of a reading list.
     * @param string $citationid The identifier of a citation.
     * @param array $params An array of additional params to pass.
     * @param bool $cached Whether to return cached data instead (if available).
     * @return stdClass|bool The decoded JSON response, or false.
     */
    private function call_api($method, $q = '', $courseid = '', $listid = '', $citationid = '', $params = [], $cached = false) {
        // Start by checking that the API is configured.
        if (!$this->is_api_configured()) {
            return false;
        }

        // Make sure we have all the required data.
        $debugdata = [];
        if ($method == ALMA_GET_COURSES) {
            if (empty($q)) {
                $debugdata['method'] = 'Retrieve courses';
                $debugdata['params'][] = 'search query';
            }
        } else if ($method == ALMA_GET_COURSE || $method == ALMA_GET_LIST || $method == ALMA_GET_CITATION) {
            if (empty($courseid)) {
                $debugdata['method'] = 'Retrieve course';
                $debugdata['params'][] = 'course identifier';
            }
            if ($method == ALMA_GET_LIST || $method == ALMA_GET_CITATION) {
                if (empty($listid)) {
                    $debugdata['method'] = 'Retrieve reading list';
                    $debugdata['params'][] = 'reading list identifier';
                }
                if ($method == ALMA_GET_CITATION) {
                    if (empty($citationid)) {
                        $debugdata['method'] = 'Retrieve citation';
                        $debugdata['params'][] = 'citation identifier';
                    }
                }
            }
        } else {
            debugging(get_string('invalidapimethod', 'leganto', $method), DEBUG_DEVELOPER);
        }
        if (!empty($debugdata)) {
            $debugdata['params'] = implode(', ', $debugdata['params']);
            debugging(get_string('insufficientapidata', 'leganto', $debugdata), DEBUG_DEVELOPER);
            return false;
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $adminconfig = $this->get_admin_config();
        $path = '/almaws/v1/courses';

        // Create a cache object to store Alma data.
        $cache = cache::make('mod_leganto', 'listdata');

        if ($method == ALMA_GET_COURSES) {
            $params['q'] = $q;
            $cachedid = $q;
        } else {
            $path .= '/' . $courseid;
            $cachedid = $courseid;

            if ($method == ALMA_GET_LIST || $method == ALMA_GET_CITATION) {
                $path .= '/reading-lists/' . $listid;
                $cachedid = $listid;

                if ($method == ALMA_GET_CITATION) {
                    $path .= '/citations/' . $citationid;
                    $cachedid = $citationid;
                }
            }
        }

        if ($cached && ($json = $cache->get($cachedid))) {
            return $json;
        }

        // Prepare cURL request data.
        $curl = new curl();
        $header = [
            'Accept: application/json',
            'Authorization: apikey ' . $adminconfig->apikey,
        ];
        $options = [
            'CURLOPT_TIMEOUT' => 30,
        ];
        $curl->setHeader($header);
        $curl->setopt($options);
        $url = new moodle_url($adminconfig->apiurl . $path);

        // Submit request to Alma API.
        $response = $curl->get($url->out(), $params);

        // Check response and log any errors.
        $curlinfo = $curl->get_info();
        $json = json_decode($response);

        // If all is well, return data.
        if ($curlinfo['http_code'] == 200 && !empty($json)) {
            if ($method != ALMA_GET_COURSES) {
                if (!$cache->set($cachedid, $json)) {
                    debugging('Unable to cache data retrieved from Alma API.', DEBUG_DEVELOPER);
                }
            }
            return $json;
        }

        // Check for invalid JSON and/or API errors, and log.
        if (empty($json)) {
            debugging('Invalid JSON response.', DEBUG_DEVELOPER);
        } else {
            debugging('Unknown error.', DEBUG_DEVELOPER);
        }
        debugging('HTTP code: ' . $curlinfo['http_code'], DEBUG_DEVELOPER);
        debugging('API response: ' . $response, DEBUG_DEVELOPER);

        // Fall back on cached data if available.
        debugging('Attempting to use cached data (if available).', DEBUG_DEVELOPER);
        if ($json = $cache->get($cachedid)) {
            return $json;
        }
        debugging('Alma API and cached data unavailable.', DEBUG_DEVELOPER);

        return false;
    }

    /**
     * Fetch all Alma course codes associated with a given Moodle course.
     *
     * @param stdClass $course The data object for the course.
     * @param bool $child Whether or not this is a meta child course.
     * @return array An array of Alma course codes.
     */
    private function get_codes($course, $child = false) {
        global $DB;

        $adminconfig = $this->get_admin_config();

        if ($adminconfig->codesource == 'codetable') {
            $codetable = $adminconfig->codetable;
            $codecolumn = $adminconfig->codecolumn;
            $coursecolumn = $adminconfig->coursecolumn;
            $courseattribute = $course->{$adminconfig->courseattribute};

            $codes = [];
            if ($records = $DB->get_records($codetable, [$coursecolumn => $courseattribute])) {
                foreach ($records as $record) {
                    $codes[] = $record->$codecolumn;
                }
            }
        } else if ($adminconfig->codesource == 'shortname') {
            $codes = $this->extract_codes($course->shortname);
        }

        // Try ID number as fallback if no code found so far, regardless of code source specified in admin config.
        if ($adminconfig->codesource == 'idnumber' || empty($codes)) {
            $codes = $this->extract_codes($course->idnumber);
        }

        // Check for additional codes in meta child courses (if enabled in site config).
        if ($adminconfig->includechildcodes && !$child) {
            if ($childcourses = $this->get_child_courses($course->id)) {
                foreach ($childcourses as $childcourse) {
                    $codes = array_merge($codes, $this->get_codes($childcourse, true));
                }
            }
        }

        return array_filter($codes);
    }

    /**
     * Extract one or more Alma course codes from a given source string.
     *
     * @param string $source A string containing one or more codes.
     * @return array An array of Alma course codes.
     */
    private function extract_codes($source) {
        $adminconfig = $this->get_admin_config();

        if ($coderegex = $adminconfig->coderegex) {
            preg_match_all($coderegex, $source, $codes, PREG_PATTERN_ORDER);
            $codes = (!empty($codes[1])) ? $codes[1] : $codes[0];
        } else {
            $codes = [$source];
        }
        $codes = array_unique($codes);

        return $codes;
    }

    /**
     * Determine whether the current course has any course meta link enrolment instances,
     * and if it does, fetch the child courses.
     *
     * @param int $courseid The id of the current course.
     * @return array An array of meta child course objects.
     */
    private function get_child_courses($courseid) {
        global $DB;

        $childcourses = [];
        $select = "enrol = 'meta' AND status = 0 AND courseid = $courseid";

        if ($childcourseids = $DB->get_fieldset_select('enrol', 'customint1', $select)) {
            foreach ($childcourseids as $childcourseid) {
                $childcourses[] = get_course($childcourseid);
            }
        }

        return $childcourses;
    }

    /**
     * Retrieve all Leganto reading lists associated with the current course.
     *
     * @param stdClass $course The data for the current course.
     * @param bool $resetcache Whether to reset cached lists.
     * @return array An array of Leganto reading list objects.
     */
    public function get_lists($course, $resetcache = false) {
        $adminconfig = $this->get_admin_config();
        $codes = $this->get_codes($course);

        // Check if the course idnumber or shortname contains a year reference.
        $year = '';
        if ($yearregex = $adminconfig->yearregex) {
            if (preg_match($yearregex, $course->idnumber, $year) || preg_match($yearregex, $course->shortname, $year)) {
                $year = (!empty($year[1])) ? $year[1] : $year[0];
            }
        }

        // Initialise the cache if necessary.
        if ($resetcache) {
            $cache = cache::make('mod_leganto', 'listdata');
        }

        $lists = [];

        foreach ($codes as $code) {
            // Build the course search query string for the Alma API request.
            $query = 'code~' . $code;
            if (!empty($year)) {
                $query .= '%20AND%20year~' . $year;
            }
            if (!($courses = $this->call_api(ALMA_GET_COURSES, $query)) || $courses->total_record_count == 0) {
                continue;
            }

            foreach ($courses->course as $almacourse) {
                $courseid = $almacourse->id;
                if (!$coursedata = $this->call_api(ALMA_GET_COURSE, '', $courseid, '', '', ['view' => 'full'])) {
                    continue;
                }

                if (empty($coursedata->reading_lists->reading_list)) {
                    continue;
                }

                foreach ($coursedata->reading_lists->reading_list as $list) {
                    $list->courseid = $courseid;
                    $listname = trim($list->name);
                    $lists[$listname] = $list;
                    if ($resetcache) {
                        if (!$cache->set($list->id, $list)) {
                            debugging('Unable to reset cache, list data may be stale.', DEBUG_DEVELOPER);
                        }
                    }
                }
            }
        }

        // Sort the lists by name.
        ksort($lists, SORT_NATURAL | SORT_FLAG_CASE);

        return $lists;
    }

    /**
     * Retrieve the specified Leganto reading list data from the Alma API.
     *
     * @param string $courseid The identifier of the parent Alma course.
     * @param string $listid The identifier of the required Leganto list.
     * @param bool $cached Whether to return cached data if available.
     * @return stdClass|bool A JSON object containing the data, or false.
     */
    private function get_list_data($courseid, $listid, $cached = false) {
        if (!$list = $this->call_api(ALMA_GET_LIST, '', $courseid, $listid, '', ['view' => 'full'], $cached)) {
            return false;
        }

        return $list;
    }

    /**
     * Fetch the data for a Leganto reading list section, given a list object and a section identifier.
     *
     * @param stdClass $list A JSON object containing the reading list data.
     * @param string $sectionid The identifier of the required section.
     * @return stdClass|bool An object containing the section data, or false.
     */
    public function get_section_data($list, $sectionid) {
        $citationcount = 0;

        if (empty($list->citations->citation)) {
            return false;
        }

        foreach ($list->citations->citation as $citation) {
            if ($citation->section_info->id != $sectionid) {
                continue;
            }

            // Get the section details (for the first match only).
            if ($citationcount < 1) {
                $section = $citation->section_info;
                if (!empty($section->description)) {
                    $section->description = html_writer::div($section->description, 'sectiondesc');
                }
            }
            $citationcount++;
        }

        if (!empty($section)) {
            $section->citationcount = $citationcount;
            return $section;
        }

        return false;
    }

    /**
     * Fetch the data for a Leganto citation, given a reading list object and a citation identifier.
     *
     * @param stdClass $list A JSON object containing the reading list data.
     * @param string $citationid The identifier of the required citation.
     * @param string $parentpath A path comprising the course, list and section identifiers.
     * @param int $display The list display mode (inline or separate page).
     * @return stdClass|bool An object containing the citation data, or false.
     */
    public function get_citation_data($list, $citationid, $parentpath = '', $display = null) {
        global $OUTPUT;

        if (empty($list->citations->citation)) {
            return false;
        }

        foreach ($list->citations->citation as $citation) {
            if ($citation->id != $citationid) {
                continue;
            }

            if (!empty($citation->metadata->chapter_title)) {
                $title = $citation->metadata->chapter_title;
                if (!empty($citation->metadata->chapter_author)) {
                    $citation->author = html_writer::span($citation->metadata->chapter_author, 'citationauthor');
                    if (!empty($citation->metadata->author)) {
                        $citation->bookauthor = html_writer::span($citation->metadata->author, 'citationbookauthor');
                    }
                }
                if (!empty($citation->metadata->title)) {
                    $citation->booktitle = html_writer::span($citation->metadata->title, 'citationbooktitle');
                }
            } else {
                $title = !empty($citation->metadata->title) ? $citation->metadata->title : $citation->metadata->article_title;
            }
            $headinglevel = !is_null($display) && $display == LEGANTO_DISPLAY_PAGE ? 3 : 5;
            $citation->title = $OUTPUT->heading($title, $headinglevel, 'citationtitle h' . ($headinglevel + 1));
            if (!empty($citation->leganto_permalink)) {
                $permalink = str_replace('auth=local', 'auth=SAML', $citation->leganto_permalink);
                if (!empty($this->adminconfig->usenewui)) {
                    $permalink = str_replace('readinglist', 'nui', $permalink);
                }
                $linkaction = new popup_action('click', $permalink, 'popup', ['width' => 1024, 'height' => 768]);
                $linktitle = get_string('viewcitation', 'leganto');
                $linkclass = !is_null($display) && $display == LEGANTO_DISPLAY_PAGE ? ' fa-lg' : '';
                $citation->permalink = $OUTPUT->action_link(
                    $permalink,
                    ' ',
                    $linkaction,
                    ['class' => 'fa fa-external-link citationlink' . $linkclass, 'title' => $linktitle]
                );
            }

            if (empty($citation->author) && !empty($citation->metadata->author)) {
                $citation->author = html_writer::span($citation->metadata->author, 'citationauthor');
            }

            if (!empty($citation->metadata->edition)) {
                $citation->edition = html_writer::span($citation->metadata->edition, 'citationedition');
            }

            if (!empty($citation->metadata->publisher)) {
                $citation->publisher = html_writer::span($citation->metadata->publisher, 'citationpublisher');
            }

            if (!empty($citation->metadata->publication_date)) {
                $citation->published = html_writer::span($citation->metadata->publication_date, 'citationpublished');
            }

            if (empty($citation->booktitle) && !empty($citation->metadata->chapter)) {
                $citation->chapter = html_writer::span($citation->metadata->chapter, 'citationchapter');
            }

            if (!empty($citation->secondary_type->desc)) {
                $citation->resourcetype = html_writer::span(
                    $citation->secondary_type->desc,
                    'citationresourcetype badge text-bg-primary'
                );
            }

            if (!empty($citation->citation_tags->citation_tag)) {
                $citation->tags = [];
                foreach ($citation->citation_tags->citation_tag as $tag) {
                    if (!empty($tag->type->value) && $tag->type->value == 'PUBLIC' && !empty($tag->value->desc)) {
                        $citation->tags[] = html_writer::span($tag->value->desc, 'citationtag badge text-bg-secondary');
                    }
                }
            }

            if (!empty($citation->public_note)) {
                $citation->note = html_writer::span(ucfirst(trim($citation->public_note)), 'citationnote');
            }

            if (!empty($citation->metadata->source)) {
                $buttonhref = $citation->metadata->source;
                $buttonlabel = get_string('viewonline', 'leganto');
                $buttonaction = new popup_action('click', $buttonhref, 'popup', ['width' => 1024, 'height' => 768]);
                // The URL in the popup_action object is encoded, but needs to be un-encoded!
                $buttonaction->jsfunctionargs['url'] = $buttonhref;
                $buttonattributes = ['class' => 'citationsource btn btn-secondary', 'title' => $title];
                $citation->source = $OUTPUT->action_link($buttonhref, $buttonlabel, $buttonaction, $buttonattributes);
            }

            if (!empty($parentpath)) {
                $citation->path = $parentpath . '_citation-' . $citation->id;
            }

            return $citation;
        }

        return false;
    }

    /**
     * Extract all selected citations from submitted leganto module config form data and return as a comma separated list.
     *
     * @param stdClass $formdata The config form data submitted.
     * @return string A JSON encoded list of selected citations.
     */
    public function get_citations($formdata) {
        $citationpathregex = '/^course-[0-9]{16,}_list-[0-9]{16,}_section-[0-9]{16,}_citation-[0-9]{16,}$/';

        $selected = [];
        foreach ($formdata as $name => $value) {
            if (preg_match($citationpathregex, $name) && $value == 1) {
                $path = $this->explode_citation_path($name);
                $selected = array_merge_recursive($selected, $path);
            }
        }
        if (!$citations = json_encode($selected)) {
            return '';
        }

        return $citations;
    }

    /**
     * Fetch the list of previously selected citations for the current leganto instance.
     *
     * @return array A list of citation paths.
     */
    public function get_selected_citations() {
        if (!$this->has_instance()) {
            return [];
        }
        if (!$config = $this->get_instance()->citations) {
            return [];
        }
        if (!$tree = json_decode($config)) {
            return [];
        }

        $paths = [];
        foreach ($tree as $coursekey => $coursetree) {
            foreach ($coursetree as $listkey => $listtree) {
                foreach ($listtree as $sectionkey => $citations) {
                    foreach ($citations as $citation) {
                        $paths[] = $coursekey . '_' . $listkey . '_' . $sectionkey . '_' . $citation;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * Given a JSON encoded list of selected citations, construct an array representing a tree
     * structure of the selection, and use this to generate the HTML output to display the custom list.
     *
     * @param string $citations A JSON encoded list of selected citations.
     * @param int $display The list display mode (inline or separate page).
     * @return string The final HTML output to display the custom reading list.
     */
    public function get_list_html($citations, $display) {
        if (empty($citations) || !($tree = json_decode($citations))) {
            return '';
        }

        $html = '';
        foreach ($tree as $coursekey => $coursetree) {
            $courseid = str_replace('course-', '', $coursekey);
            foreach ($coursetree as $listkey => $listtree) {
                $listid = str_replace('list-', '', $listkey);

                // Fetch list data, from cache if available.
                $listdata = $this->get_list_data($courseid, $listid, true);
                $html .= $this->build_list_elements($listdata, $listtree, $display);
            }
        }

        return $this->condense_whitespace($html);
    }

    /**
     * Return an array representing the full path (i.e. course => list => section => citation) for a given citation.
     *
     * @param string $citationpath The path string of a selected citation.
     * @return array A partial tree structure representation of the path components.
     */
    private function explode_citation_path($citationpath) {
        $parts = explode('_', $citationpath);
        $partscount = count($parts);

        $path = [$parts[$partscount - 1]];

        for ($i = $partscount - 2; $i >= 0; $i--) {
            $path = [$parts[$i] => $path];
        }

        return $path;
    }

    /**
     * Given a list's JSON object and an array representing a tree structure of the selected citations
     * and their parent sections, recursively assemble the HTML to display the custom list.
     *
     * @param stdClass $list A JSON object containing the reading list data.
     * @param mixed $elements A tree structure representing the selected citations by section.
     * @param int $display The configured display mode for the list (inline or separate page).
     * @param string $html The HTML output that has been generated from previous iterations.
     * @param bool $wascitation Whether or not the previous element was a citation.
     * @return string The HTML output for the custom list.
     */
    private function build_list_elements($list, $elements, $display, &$html = '', $wascitation = false) {
        foreach ($elements as $elementkey => $element) {
            if (preg_match($this->sectionidregex, $elementkey)) {
                // This is a section.
                $sectionid = str_replace('section-', '', $elementkey);
                if ($wascitation) {
                    // If previous element was a citation, close the unordered list.
                    $html .= html_writer::end_tag('ul');
                }

                // Open a section container and output the heading details.
                $html .= html_writer::start_div('listsection', ['id' => $sectionid]);
                $html .= $this->get_section_html($list, $sectionid, $display, count($element));

                // Remember that this was a section heading.
                $wascitation = false;

                // Then process any sub-elements it contains.
                if (is_array($element)) {
                    $this->build_list_elements($list, $element, $display, $html, $wascitation);
                }

                // Close the section container.
                $html .= html_writer::end_div();
            } else if (preg_match($this->citationidregex, $element)) {
                // This is a citation.
                $citationid = str_replace('citation-', '', $element);
                if (!$wascitation) {
                    // If previous element was a section heading, open an unordered list.
                    $html .= html_writer::start_tag('ul', ['class' => 'citations']);
                }

                // Output the citation details.
                $html .= $this->get_citation_html($list, $citationid, $display);

                // Remember that this was a citation.
                $wascitation = true;
            }
        }

        if ($wascitation) {
            // If the last element was a citation, close the unordered list.
            $html .= html_writer::end_tag('ul');
        }

        return $html;
    }

    /**
     * Given a list object and a section identifier, return the section heading and details as HTML.
     *
     * @param stdClass $list A JSON object containing the reading list data.
     * @param string $sectionid The identifier of the required section.
     * @param int $display The list display mode (inline or separate page).
     * @param int $citationcount A count of citations belonging to the section.
     * @return string The HTML output for the section heading and details.
     */
    public function get_section_html($list, $sectionid, $display = null, $citationcount = null) {
        global $OUTPUT;

        if (!$section = $this->get_section_data($list, $sectionid)) {
            return '';
        }

        if ($citationcount === null) {
            $citationcount = $section->citationcount;
        }
        if ($citationcount > 0) {
            $plural = $citationcount > 1 ? 'plural' : '';
            $countstr = get_string('citationcount' . $plural, 'leganto', $citationcount);
            $countspan = html_writer::span($countstr, 'citationcount dimmed_text');
        } else {
            $countspan = '';
        }

        $headingstr = get_string('sectionheading', 'leganto', ['name' => $section->name, 'count' => $countspan]);
        $headinglevel = !is_null($display) && $display == LEGANTO_DISPLAY_PAGE ? 2 : 4;
        $heading = $OUTPUT->heading($headingstr, $headinglevel, 'sectionheading h' . ($headinglevel + 1));
        $description = !empty($section->description) ? $section->description : '';
        $html = $heading . $description;

        return $html;
    }

    /**
     * Given a list object and a citation identifier, return the citation link and details as HTML.
     *
     * @param stdClass $list A JSON object containing the reading list data.
     * @param string $citationid The identifier of the required citation.
     * @param int $display The list display mode (inline or separate page).
     * @return string An HTML list item containing the citation link and details.
     */
    private function get_citation_html($list, $citationid, $display) {
        if (!$citation = $this->get_citation_data($list, $citationid, '', $display)) {
            return '';
        }

        $html = html_writer::start_tag('li', ['id' => $citation->id, 'class' => 'citation']);
        if (!empty($citation->source)) {
            $html .= $citation->source;
        }
        $html .= $citation->title;
        if (!empty($citation->permalink)) {
            $html .= ' ' . $citation->permalink;
        }

        $html .= html_writer::start_div();
        if (!empty($citation->author)) {
            $html .= $citation->author . ', ';
        }
        if (!empty($citation->edition)) {
            $html .= $citation->edition . ', ';
        }
        if (!empty($citation->publisher)) {
            $html .= $citation->publisher . ', ';
        }
        if (!empty($citation->published)) {
            $html .= $citation->published;
        } else {
            $html = rtrim($html, ', ');
        }
        $html .= html_writer::end_div();

        if (!empty($citation->booktitle)) {
            $html .= html_writer::div(get_string('citationbooktitle', 'leganto', $citation->booktitle));
            if (!empty($citation->bookauthor)) {
                $html .= html_writer::div(get_string('citationbookauthor', 'leganto', $citation->bookauthor));
            }
        } else if (!empty($citation->chapter)) {
            $html .= html_writer::div(get_string('citationchapter', 'leganto', $citation->chapter));
        }

        $html .= html_writer::start_div();
        if (!empty($citation->resourcetype)) {
            $html .= $citation->resourcetype . ' ';
        }
        if (!empty($citation->tags)) {
            $html .= implode(' ', $citation->tags);
        } else {
            $html = rtrim($html);
        }
        $html .= html_writer::end_div();

        if (!empty($this->get_instance()->displaynotes) && !empty($citation->note)) {
            $html .= html_writer::div(get_string('citationnote', 'leganto', $citation->note));
        }
        $html .= html_writer::end_tag('li');

        return $html;
    }

    /**
     * Return a given string with multiple consecutive whitespace characters condensed to a single space.
     *
     * @param string $string The original string to process.
     * @return string The output string with excess whitespace removed.
     */
    private function condense_whitespace($string) {
        return preg_replace('/\s+/', ' ', $string);
    }
}
