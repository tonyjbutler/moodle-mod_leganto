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
 * Leganto module renderer.
 *
 * @package    mod_leganto
 * @copyright  2017 Lancaster University {@link http://www.lancaster.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tony Butler <a.butler4@lancaster.ac.uk>
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Leganto module renderer class.
 *
 * @package    mod_leganto
 * @copyright  2017 Lancaster University {@link http://www.lancaster.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tony Butler <a.butler4@lancaster.ac.uk>
 */
class mod_leganto_renderer extends plugin_renderer_base {

    /**
     * Return the HTML to display the content of the customised reading list.
     *
     * @param stdClass $leganto Record from 'leganto' table.
     * @return string
     */
    public function display_leganto(stdClass $leganto) {
        $output = '';
        $legantoinstances = get_fast_modinfo($leganto->course)->get_instances_of('leganto');
        if (!isset($legantoinstances[$leganto->id]) ||
                !($cm = $legantoinstances[$leganto->id]) ||
                !($context = context_module::instance($cm->id))) {
            // Some error in parameters.
            // Don't throw any errors in renderer, just return empty string.
            // Capability to view module must be checked before calling renderer.
            return $output;
        }

        if (trim($leganto->intro)) {
            if ($leganto->display == LEGANTO_DISPLAY_PAGE) {
                $output .= $this->output->box(format_module_intro('leganto', $leganto, $cm->id),
                        'generalbox', 'intro');
            } else if ($cm->showdescription) {
                // For 'display inline' do not filter, filters run at display time.
                $output .= format_module_intro('leganto', $leganto, $cm->id, false);
            }
        }

        $legantolist = new leganto_list($leganto, $cm);
        if ($leganto->display != LEGANTO_DISPLAY_PAGE) {
            $viewlink = (string) $cm->url;
            $expanded = $leganto->display == LEGANTO_DISPLAY_INLINE_EXPANDED;
            $listid = $cm->modname . '-' . $cm->id;

            // YUI function to hide inline reading list until user clicks 'view' link.
            $this->page->requires->js_init_call('M.mod_leganto.initList', array($cm->id, $viewlink, $expanded));
            $output .= $this->output->box($this->render($legantolist), 'generalbox legantobox', $listid);
        } else {
            $output .= $this->output->box($this->render($legantolist), 'generalbox', 'leganto');
        }

        return $output;
    }

    /**
     * Render the HTML for the customised reading list.
     *
     * @param \leganto_list $list The list renderable.
     * @return string The HTML to render the list.
     */
    public function render_leganto_list(leganto_list $list) {
        global $CFG;

        require_once($CFG->dirroot . '/mod/leganto/locallib.php');

        $leganto = new leganto($list->context, $list->cm, null);
        $output = $leganto->get_list_html($list->leganto->citations, $list->leganto->display);

        return $output;
    }
}

/**
 * Leganto list renderable class.
 *
 * @package    mod_leganto
 * @copyright  2017 Lancaster University {@link http://www.lancaster.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tony Butler <a.butler4@lancaster.ac.uk>
 */
class leganto_list implements renderable {

    /** @var context The context of the course module for this leganto_list instance. */
    public $context;

    /** @var stdClass The leganto database record for this leganto_list instance. */
    public $leganto;

    /** @var cm_info The course module info object for this leganto_list instance. */
    public $cm;

    /**
     * Constructor for the leganto_list class.
     *
     * @param stdClass $leganto The leganto record.
     * @param cm_info $cm The course module info.
     */
    public function __construct($leganto, $cm) {
        $this->leganto = $leganto;
        $this->cm = $cm;
        $this->context = context_module::instance($cm->id);
    }
}
