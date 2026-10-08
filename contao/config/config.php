<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */


/**
 * Front end modules
 *
 * DELIBERATELY EMPTY since Step 7. The `zyppy_search` module type is now
 * registered by the #[AsFrontendModule] attribute on
 * Zyppy\Search\Controller\FrontendModule\ZyppySearchController, under the same
 * type name, so existing tl_module rows need no migration.
 *
 * The old line was:
 *
 *   $GLOBALS['FE_MOD']['application']['zyppy_search'] = 'Zyppy\Search\Module\Search';
 *
 * It could not simply be left in place alongside the fragment. Contao merges
 * fragment registrations into $GLOBALS['FE_MOD'] through GlobalsMapListener,
 * and that listener does array_replace_recursive(..., $GLOBALS[$key]) with the
 * EXISTING globals last, so a legacy entry WINS over a priority 0 fragment.
 * Keeping the line would therefore have kept pointing the module type at a class
 * that no longer exists, and Controller::getFrontendModule() would have logged
 * "Module class ... does not exist" and rendered nothing - a failure with no
 * visible cause.
 *
 * This file is kept rather than deleted because Config::initialize() includes
 * contao/config/config.php for every bundle and it is the conventional place
 * for future registrations.
 */
