<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



/**
 * Palettes
 *
 * Removed from this palette (see TODO.md Step 6):
 *  - `ajaxTpl`     : never read by any code.
 *  - `disableAjax` : never read by any code.
 *  - `totalLength` : no field definition anywhere; the value lives in the
 *                    second element of core's serialized `contextLength` field.
 *  - `guests`      : removed from Contao core in version 5.
 */
$GLOBALS['TL_DCA']['tl_module']['palettes']['zyppy_search'] = '{title_legend},name,headline,type;{config_legend},queryType,fuzzy,contextLength,minKeywordLength,perPage,searchType,formatPageTeaser,formatPageDescription,formatNewsTeaser;{redirect_legend:hide},jumpTo;{reference_legend:hide},pages;{template_legend:hide},searchTpl,customTpl;{protected_legend:hide},protected;{image_legend},imgSize;{expert_legend:hide},cssID';


/**
 * Sub Palettes
 */
$GLOBALS['TL_DCA']['tl_module']['palettes']['__selector__'][] = 'formatPageTeaser';
$GLOBALS['TL_DCA']['tl_module']['palettes']['__selector__'][] = 'formatPageDescription';
$GLOBALS['TL_DCA']['tl_module']['palettes']['__selector__'][] = 'formatNewsTeaser';

$GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatPageTeaser'] 		= 'pageTeaserLimit';
$GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatPageDescription'] = 'pageDescriptionLimit';
$GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatNewsTeaser'] 		= 'newsTeaserLimit';


/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_module']['fields']['formatPageTeaser'] = array
(
	'label'					  => &$GLOBALS['TL_LANG']['tl_module']['formatPageTeaser'],
	'inputType'				  => 'checkbox',
	'eval'					  => array('tl_class'=>'clr w50 m12', 'submitOnChange'=>true),
	'sql'					  => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['pageTeaserLimit'] = array
(
	'label'					  => &$GLOBALS['TL_LANG']['tl_module']['pageTeaserLimit'],
	'inputType'				  => 'text',
	'eval'					  => array('maxlength'=>10, 'rgxp'=>'natural', 'tl_class'=>'w50'),
	'sql'					  => "int(10) unsigned NOT NULL default 0"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['formatPageDescription'] = array
(
	'label'					  => &$GLOBALS['TL_LANG']['tl_module']['formatPageDescription'],
	'inputType'				  => 'checkbox',
	'eval'					  => array('tl_class'=>'clr w50 m12', 'submitOnChange'=>true),
	'sql'					  => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['pageDescriptionLimit'] = array
(
	'label'					  => &$GLOBALS['TL_LANG']['tl_module']['pageDescriptionLimit'],
	'inputType'				  => 'text',
	'eval'					  => array('maxlength'=>10, 'rgxp'=>'natural', 'tl_class'=>'w50'),
	'sql'					  => "int(10) unsigned NOT NULL default 0"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['formatNewsTeaser'] = array
(
	'label'					  => &$GLOBALS['TL_LANG']['tl_module']['formatNewsTeaser'],
	'inputType'				  => 'checkbox',
	'eval'					  => array('tl_class'=>'clr w50 m12','submitOnChange'=>true),
	'sql'					  => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['newsTeaserLimit'] = array
(
	'label'					  => &$GLOBALS['TL_LANG']['tl_module']['newsTeaserLimit'],
	'inputType'				  => 'text',
	'eval'					  => array('maxlength'=>10, 'rgxp'=>'natural', 'tl_class'=>'w50'),
	'sql'					  => "int(10) unsigned NOT NULL default 0"
);
