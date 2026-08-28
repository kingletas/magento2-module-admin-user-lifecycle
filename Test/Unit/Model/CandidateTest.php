<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model;

use Commerce\AdminUserLifecycle\Model\Candidate;
use PHPUnit\Framework\TestCase;

final class CandidateTest extends TestCase
{
    private const CREATED = 1_700_000_000;
    private const LOGGED_IN = 1_700_900_000;

    public function testActivityIsAnchoredOnTheLastSignInWhenThereIsOne(): void
    {
        $candidate = $this->candidate(self::LOGGED_IN);

        self::assertTrue($candidate->hasEverSignedIn());
        self::assertSame(self::LOGGED_IN, $candidate->getActivityAnchor());
    }

    /**
     * `logdate` is NULL until the first sign-in, which is not dormancy since
     * the epoch.
     */
    public function testANeverUsedAccountIsAnchoredOnItsCreationDate(): void
    {
        $candidate = $this->candidate(null);

        self::assertFalse($candidate->hasEverSignedIn());
        self::assertNull($candidate->getLastLoginAt());
        self::assertSame(self::CREATED, $candidate->getActivityAnchor());
    }

    public function testTheDisplayNameFallsBackToTheUsername(): void
    {
        self::assertSame('Ada Lovelace', $this->candidate(null, 'Ada Lovelace')->getName());
        self::assertSame('adminuser', $this->candidate(null, '')->getName());
    }

    private function candidate(?int $lastLogin, string $name = 'Ada Lovelace'): Candidate
    {
        return new Candidate(
            7,
            'adminuser',
            'ada@example.com',
            $name,
            true,
            $lastLogin,
            self::CREATED,
            3
        );
    }
}
