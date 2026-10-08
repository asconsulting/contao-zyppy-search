<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;


/**
 * Turns a binary DBAFS UUID into a ready-to-render <img> attribute set.
 *
 * Replaces the Step 7 FilePathResolverInterface, which returned a bare path.
 * That bare path is why tl_module.imgSize did nothing: no resize was ever
 * requested, so full-size originals were emitted with no dimensions, no
 * srcset and no alt.
 *
 * A one-method seam, so ResultEnricher stays testable with a small fake. The
 * Contao implementation is ContaoImageResolver, which goes through
 * contao.image.studio.
 */
interface ResultImageResolverInterface
{

	/**
	 * @param mixed       $varUuid        A binary UUID as stored in tl_page.page_image / tl_news.singleSRC.
	 * @param mixed       $varSize        tl_module.imgSize, deserialised (an [w, h, mode] array or a size id).
	 * @param string|null $strFallbackAlt Used only when the file carries no alt metadata of its own.
	 *
	 * @return array{src: string, srcset: string, sizes: string, width: int|null, height: int|null, alt: string}|null
	 *         null when the UUID is empty, orphaned, or not a resizable image.
	 */
	public function resolve(mixed $varUuid, mixed $varSize = null, string|null $strFallbackAlt = null): array|null;

}
