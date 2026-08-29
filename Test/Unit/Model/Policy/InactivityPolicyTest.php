<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Policy;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use PHPUnit\Framework\TestCase;

class InactivityPolicyTest extends TestCase
{
    use ShippedConfig;

    private const DAY = 86400;
    private const NOW = 1_760_000_000;

    public function testAnAccountIsDueForDeactivationOnceItPassesTheThreshold(): void
    {
        $policy = $this->policy(['deactivate/inactive_days' => '90']);

        $this->assertFalse($policy->isDueForDeactivation($this->signedInDaysAgo(89), self::NOW));
        $this->assertTrue($policy->isDueForDeactivation($this->signedInDaysAgo(90), self::NOW));
        $this->assertTrue($policy->isDueForDeactivation($this->signedInDaysAgo(400), self::NOW));
    }

    /**
     * Regression.
     */
    public function testANewAccountThatHasNeverBeenUsedIsLeftAloneDuringItsGrace(): void
    {
        $policy = $this->policy([
            'deactivate/inactive_days' => '90',
            'deactivate/new_account_grace_days' => '30',
        ]);

        $this->assertTrue($policy->isInCreationGrace($this->neverUsedCreatedDaysAgo(1), self::NOW));
        $this->assertFalse($policy->isDueForDeactivation($this->neverUsedCreatedDaysAgo(1), self::NOW));
        $this->assertFalse($policy->isDueForDeactivation($this->neverUsedCreatedDaysAgo(29), self::NOW));
    }

    /**
     * The other half of the same regression: an abandoned account that was
     * created and never used must still be retired eventually.
     */
    public function testANeverUsedAccountIsStillRetiredOnceItIsOldEnough(): void
    {
        $policy = $this->policy([
            'deactivate/inactive_days' => '90',
            'deactivate/new_account_grace_days' => '30',
        ]);

        $this->assertFalse($policy->isDueForDeactivation($this->neverUsedCreatedDaysAgo(60), self::NOW));
        $this->assertTrue($policy->isDueForDeactivation($this->neverUsedCreatedDaysAgo(91), self::NOW));
    }

    public function testAWarningIsOnlySentInsideTheNoticeWindow(): void
    {
        $policy = $this->policy([
            'deactivate/inactive_days' => '90',
            'warn/days_before' => '7',
        ]);

        $this->assertFalse(
            $policy->isDueForWarning($this->signedInDaysAgo(82), self::NOW),
            'Too early: the notice would be stale by the time it mattered.'
        );
        $this->assertTrue($policy->isDueForWarning($this->signedInDaysAgo(84), self::NOW));
        $this->assertTrue($policy->isDueForWarning($this->signedInDaysAgo(89), self::NOW));
        $this->assertFalse(
            $policy->isDueForWarning($this->signedInDaysAgo(91), self::NOW),
            'Already past due: a warning after the fact is worse than none.'
        );
    }

    public function testNoWarningIsSentWhenNothingIsGoingToHappen(): void
    {
        $policy = $this->policy([
            'deactivate/enabled' => '0',
            'deactivate/inactive_days' => '90',
        ]);

        $this->assertFalse($policy->isDueForWarning($this->signedInDaysAgo(85), self::NOW));
    }

    /**
     * A user who signs in after being warned moves their own due date and is
     * warned again.
     */
    public function testSigningInAfterAWarningInvalidatesIt(): void
    {
        $policy = $this->policy(['deactivate/inactive_days' => '90', 'warn/days_before' => '7']);

        $warnedAt = self::NOW - (10 * self::DAY);
        $signedInSince = $this->signedInDaysAgo(1);

        $this->assertFalse($policy->isWarningStillValid($signedInSince, $warnedAt));
        $this->assertTrue($policy->isWarningStillValid($this->signedInDaysAgo(85), self::NOW - 3600));
    }

    /**
     * The whole reason deletion is measured from the journal.
     */
    public function testDeletionIsMeasuredFromDeactivationNotFromTheLastSignIn(): void
    {
        $policy = $this->policy([
            'deactivate/inactive_days' => '90',
            'delete/deactivated_days' => '180',
        ]);

        $deactivatedYesterday = self::NOW - self::DAY;

        $this->assertFalse(
            $policy->isDueForDeletion($deactivatedYesterday, self::NOW),
            'An account deactivated yesterday cannot be deleted today, whatever its sign-in date.'
        );
        $this->assertTrue($policy->isDueForDeletion(self::NOW - (180 * self::DAY), self::NOW));
    }

    /**
     * "I have no record of when this was switched off" must not resolve to
     * "long enough ago".
     */
    public function testAnAccountWithNoRecordedDeactivationIsNeverDeletable(): void
    {
        $policy = $this->policy(['delete/deactivated_days' => '1']);

        $this->assertFalse($policy->isDueForDeletion(null, self::NOW));
    }

    public function testDormantSecondsNeverGoNegative(): void
    {
        $policy = $this->policy();
        $future = new Candidate(1, 'u', 'e@x.test', 'n', true, self::NOW + 5000, self::NOW);

        $this->assertSame(0, $policy->getDormantSeconds($future, self::NOW));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function policy(array $overrides = []): InactivityPolicy
    {
        return new InactivityPolicy($this->config($overrides));
    }

    private function signedInDaysAgo(int $days): Candidate
    {
        return new Candidate(
            1,
            'adminuser',
            'ada@example.com',
            'Ada',
            true,
            self::NOW - ($days * self::DAY),
            self::NOW - (900 * self::DAY)
        );
    }

    private function neverUsedCreatedDaysAgo(int $days): Candidate
    {
        return new Candidate(
            2,
            'newuser',
            'new@example.com',
            'New',
            true,
            null,
            self::NOW - ($days * self::DAY)
        );
    }
}
