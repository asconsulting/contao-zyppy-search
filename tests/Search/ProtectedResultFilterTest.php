<?php

declare(strict_types=1);

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Tests\Search;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\SearchResult;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Zyppy\Search\Search\ProtectedResultFilter;


/**
 * THE ACCESS CONTROL PROOF.
 *
 * Step 2 put a member-group check in the result loop, and moving the search onto
 * a standalone route is exactly the kind of change that quietly loses one. The
 * whole reason ZyppySearchRunner takes Symfony's Security as a constructor
 * argument - instead of reaching through System::getContainer() the way the
 * ModuleSearch fork did - is so that this file can grant and deny the permission
 * and watch a protected row appear and disappear.
 *
 * The last test runs the filter through Contao's REAL SearchResult::applyFilter()
 * rather than calling isVisible() directly, because applyFilter() is the call
 * site that actually decides what a visitor sees. SearchResult's constructor
 * only indexes the rows by id, so it needs no database; getResults() would, and
 * is deliberately not called.
 */
class ProtectedResultFilterTest extends TestCase
{

	/**
	 * A mock, because the call COUNT is part of what is being asserted.
	 */
	private function security(bool $blnGranted, int $intExpectedCalls): Security
	{
		$objSecurity = $this->createMock(Security::class);

		$objSecurity
			->expects($this->exactly($intExpectedCalls))
			->method('isGranted')
			->willReturn($blnGranted)
		;

		return $objSecurity;
	}

	/**
	 * A stub, for the tests that only care about the OUTCOME of the decision.
	 * PHPUnit 12 raises a notice for a mock with no configured expectation.
	 */
	private function securityStub(bool $blnGranted): Security
	{
		$objSecurity = $this->createStub(Security::class);
		$objSecurity->method('isGranted')->willReturn($blnGranted);

		return $objSecurity;
	}

	public function testAnUnprotectedRowIsVisibleWithoutConsultingSecurityAtAll(): void
	{
		$objFilter = new ProtectedResultFilter($this->security(false, 0));

		$this->assertTrue($objFilter->isVisible(array('id' => 1, 'protected' => '', 'groups' => '')));
	}

	public function testAProtectedRowIsHiddenWhenTheMemberIsNotInItsGroups(): void
	{
		$objFilter = new ProtectedResultFilter($this->security(false, 1));

		$this->assertFalse($objFilter->isVisible(array(
			'id' => 2,
			'protected' => '1',
			'groups' => serialize(array('3')),
		)));
	}

	public function testAProtectedRowIsVisibleWhenTheMemberIsInItsGroups(): void
	{
		$objFilter = new ProtectedResultFilter($this->security(true, 1));

		$this->assertTrue($objFilter->isVisible(array(
			'id' => 2,
			'protected' => '1',
			'groups' => serialize(array('3')),
		)));
	}

	/**
	 * The permission and the deserialised group list must reach the voter
	 * exactly as Contao's own Controller::isVisibleElement() sends them; a
	 * serialised blob or the wrong attribute would make every voter abstain,
	 * which reads as "denied" and hides everything - or, with a permissive
	 * default, as "granted" and hides nothing.
	 */
	public function testTheGroupListReachesTheVoterDeserialised(): void
	{
		$objSecurity = $this->createMock(Security::class);

		$objSecurity
			->expects($this->once())
			->method('isGranted')
			->with(ContaoCorePermissions::MEMBER_IN_GROUPS, array('3', '7'))
			->willReturn(true)
		;

		$objFilter = new ProtectedResultFilter($objSecurity);

		$this->assertTrue($objFilter->isVisible(array(
			'protected' => '1',
			'groups' => serialize(array('3', '7')),
		)));
	}

	/**
	 * A protected row with no groups at all still has to ask - the voter, not
	 * this class, decides what an empty group list means.
	 */
	public function testAProtectedRowWithNoGroupsStillAsksTheVoter(): void
	{
		$objSecurity = $this->createMock(Security::class);

		$objSecurity
			->expects($this->once())
			->method('isGranted')
			->with(ContaoCorePermissions::MEMBER_IN_GROUPS, array())
			->willReturn(false)
		;

		$this->assertFalse((new ProtectedResultFilter($objSecurity))->isVisible(array('protected' => '1')));
	}

	/**
	 * End to end through core's own filtering call: two rows in, one protected,
	 * permission denied, one row survives.
	 */
	public function testApplyFilterRemovesTheProtectedRowFromARealSearchResult(): void
	{
		$objResult = new SearchResult(array(
			array('id' => 1, 'protected' => '', 'groups' => '', 'relevance' => 1.0),
			array('id' => 2, 'protected' => '1', 'groups' => serialize(array('3')), 'relevance' => 0.5),
		));

		$this->assertSame(2, $objResult->getCount());

		$objResult->applyFilter((new ProtectedResultFilter($this->securityStub(false)))->asClosure());

		$this->assertSame(1, $objResult->getCount(), 'the protected row must be gone');
	}

	public function testApplyFilterKeepsTheProtectedRowForAMemberOfItsGroups(): void
	{
		$objResult = new SearchResult(array(
			array('id' => 1, 'protected' => '', 'groups' => '', 'relevance' => 1.0),
			array('id' => 2, 'protected' => '1', 'groups' => serialize(array('3')), 'relevance' => 0.5),
		));

		$objResult->applyFilter((new ProtectedResultFilter($this->securityStub(true)))->asClosure());

		$this->assertSame(2, $objResult->getCount());
	}

}
