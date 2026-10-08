<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\StringUtil;
use Symfony\Bundle\SecurityBundle\Security;


/**
 * The access control that decides whether one indexed row may be shown to the
 * current visitor.
 *
 * This is the Step 2 result-loop access control, lifted out of compile() so it
 * can be PROVEN rather than asserted. It is the whole reason the runner takes
 * Symfony's Security as a constructor argument instead of reaching through
 * System::getContainer(): with it injected, a unit test can grant and deny the
 * MEMBER_IN_GROUPS permission and watch a protected row appear and disappear.
 *
 * Deliberately stronger than the fork in one respect. The fork applied this
 * filter only when the contao.search.index_protected parameter was true. That
 * guard was an optimisation, not a control: when protected indexing is off,
 * tl_search.protected is empty for every row, so filtering unconditionally
 * cannot change a single outcome - it only removes the possibility of the
 * parameter and the index disagreeing. So the parameter is gone and the filter
 * always runs.
 */
final class ProtectedResultFilter
{

	public function __construct(
		private readonly Security $objSecurity,
	)
	{
	}

	/**
	 * @param array<string, mixed> $arrRow A tl_search row.
	 */
	public function isVisible(array $arrRow): bool
	{
		if (empty($arrRow['protected']))
		{
			return true;
		}

		return $this->objSecurity->isGranted(
			ContaoCorePermissions::MEMBER_IN_GROUPS,
			StringUtil::deserialize($arrRow['groups'] ?? null, true),
		);
	}

	/**
	 * SearchResult::applyFilter() takes a \Closure, not a callable.
	 */
	public function asClosure(): \Closure
	{
		return fn (array $arrRow): bool => $this->isVisible($arrRow);
	}

}
