<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



use Contao\CoreBundle\DataContainer\PaletteManipulator;


/**
 * Palettes
 *
 * The News Reader flag only makes sense on a page type that can actually
 * render a news reader module and be indexed under the article's own URL.
 * Checked against Contao 5.7 core (contao/dca/tl_page.php): `{meta_legend}`
 * also appears in the `forward`, `redirect`, `root`, `rootfallback` and
 * `error_*` palettes, none of which is ever displayed as an article page —
 * a previous revision of this file added the checkbox to all of them.
 */
$arrNewsReaderPalettes = array('regular');

foreach ($arrNewsReaderPalettes as $strPalette)
{
	if (!isset($GLOBALS['TL_DCA']['tl_page']['palettes'][$strPalette]) || !\is_string($GLOBALS['TL_DCA']['tl_page']['palettes'][$strPalette]))
	{
		continue;
	}

	// PaletteManipulator throws PalettePositionException when the legend it is
	// told to append to is absent, so only run it where it can succeed.
	if (!str_contains($GLOBALS['TL_DCA']['tl_page']['palettes'][$strPalette], '{meta_legend}'))
	{
		continue;
	}

	PaletteManipulator::create()
		->addField('zyppy_news', 'meta_legend', PaletteManipulator::POSITION_APPEND)
		->applyToPalette($strPalette, 'tl_page');
}

unset($arrNewsReaderPalettes, $strPalette);


/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['zyppy_news'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_page']['zyppy_news'],
	'exclude'                 => true,
	'inputType'               => 'checkbox',
	'eval'                    => array('tl_class'=>'clr w50 m12'),
	'sql'                     => "char(1) NOT NULL default ''"
);
