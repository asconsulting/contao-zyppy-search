<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\StringUtil;
use Psr\Log\LoggerInterface;


/**
 * The Contao-backed ResultImageResolverInterface: contao.image.studio.
 *
 * This is what finally makes tl_module.imgSize do something. The field has sat
 * in the zyppy_search palette doing nothing for as long as the palette has
 * existed, because the fork emitted FilesModel::findByUuid()->path - the
 * full-size original, with no dimensions, no srcset and no alt.
 *
 * buildIfResourceExists() is the guard that keeps a deleted or non-image file
 * from becoming an exception: it returns null rather than throwing, which is
 * the same degrade-don't-fatal contract the old FilesModel null check gave.
 * Everything else is wrapped too, because image processing touches the
 * filesystem and one unreadable file must not take out a whole result set.
 *
 * `alt` prefers the FILE's own metadata over the caller's fallback. A caption
 * an editor wrote for the image is a better description of it than the title of
 * the page the image happens to sit on.
 */
final class ContaoImageResolver implements ResultImageResolverInterface
{

	public function __construct(
		private readonly ContaoFramework $objFramework,
		private readonly Studio $objStudio,
		private readonly LoggerInterface|null $objLogger = null,
	)
	{
	}

	public function resolve(mixed $varUuid, mixed $varSize = null, string|null $strFallbackAlt = null): array|null
	{
		if (empty($varUuid))
		{
			return null;
		}

		$this->objFramework->initialize();

		try
		{
			$strUuid = StringUtil::binToUuid($varUuid);

			if ('' === $strUuid)
			{
				return null;
			}

			$objFigure = $this->objStudio
				->createFigureBuilder()
				->fromUuid($strUuid)
				->setSize($varSize)
				->buildIfResourceExists()
			;

			if (null === $objFigure)
			{
				return null;
			}

			// getImg() is Contao\Image\Picture::getImg(), whose keys are set by
			// PictureGenerator: src, srcset, and - when the dimensions are known
			// and absolute - width, height and sizes.
			$arrImg = $objFigure->getImage()->getImg();

			if (empty($arrImg['src']))
			{
				return null;
			}

			$strAlt = (string) ($objFigure->hasMetadata() ? $objFigure->getMetadata()?->getAlt() : '');

			return array(
				'src' => (string) $arrImg['src'],
				'srcset' => (string) ($arrImg['srcset'] ?? ''),
				'sizes' => (string) ($arrImg['sizes'] ?? ''),
				'width' => isset($arrImg['width']) ? (int) $arrImg['width'] : null,
				'height' => isset($arrImg['height']) ? (int) $arrImg['height'] : null,
				'alt' => '' !== $strAlt ? $strAlt : (string) $strFallbackAlt,
			);
		}
		catch (\Throwable $objException)
		{
			// One unreadable or unresizable file must not abort the result set.
			$this->objLogger?->warning('Zyppy Search could not build a result image: ' . $objException->getMessage());

			return null;
		}
	}

}
