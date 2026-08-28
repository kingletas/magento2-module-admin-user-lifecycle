<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Policy;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ConfigBuilder;
use PHPUnit\Framework\TestCase;

class ProtectionPolicyTest extends TestCase
{
    public function testAnActiveAccountWithNothingAgainstItMayBeDeactivated(): void
    {
        $policy = $this->policy();

        $this->assertNull($policy->blockDeactivation($this->candidate(), 10));
    }

    public function testAProtectedUsernameIsNeverTouchedAtEitherStage(): void
    {
        $policy = $this->policy(['protect/usernames' => 'break-glass, integration']);
        $active = $this->candidate(username: 'Integration');
        $inactive = $this->candidate(username: 'INTEGRATION', active: false);

        $this->assertSame(ProtectionPolicy::REASON_PROTECTED_USERNAME, $policy->blockDeactivation($active, 10));
        $this->assertSame(ProtectionPolicy::REASON_PROTECTED_USERNAME, $policy->blockDeletion($inactive));
    }

    public function testAProtectedRoleIsNeverTouched(): void
    {
        $policy = $this->policy(['protect/role_ids' => '4,9']);

        $this->assertSame(
            ProtectionPolicy::REASON_PROTECTED_ROLE,
            $policy->blockDeactivation($this->candidate(roleId: 9), 10)
        );
        $this->assertNull($policy->blockDeactivation($this->candidate(roleId: 5), 10));
    }

    /**
     * The rule that stops the module locking everybody out of the store.
     */
    public function testDeactivationStopsAtTheMinimumActiveAdministratorFloor(): void
    {
        $policy = $this->policy(['protect/min_active_admins' => '2']);

        $this->assertNull($policy->blockDeactivation($this->candidate(), 3));
        $this->assertSame(
            ProtectionPolicy::REASON_MIN_ACTIVE_ADMINS,
            $policy->blockDeactivation($this->candidate(), 2)
        );
        $this->assertSame(
            ProtectionPolicy::REASON_MIN_ACTIVE_ADMINS,
            $policy->blockDeactivation($this->candidate(), 1)
        );
    }

    /**
     * Even a configured minimum of zero cannot leave nobody able to sign in.
     */
    public function testTheFloorCannotBeConfiguredAwayEntirely(): void
    {
        $policy = $this->policy(['protect/min_active_admins' => '0']);

        $this->assertSame(
            ProtectionPolicy::REASON_MIN_ACTIVE_ADMINS,
            $policy->blockDeactivation($this->candidate(), 1)
        );
    }

    /**
     * Deletion targets accounts already switched off, so the administrator
     * floor does not apply.
     */
    public function testTheActiveAdministratorFloorDoesNotBlockDeletion(): void
    {
        $policy = $this->policy(['protect/min_active_admins' => '50']);

        $this->assertNull($policy->blockDeletion($this->candidate(active: false)));
    }

    /**
     * Guards the window between selecting an account and deleting it.
     */
    public function testAnAccountReactivatedSinceSelectionIsNotDeleted(): void
    {
        $this->assertNotNull($this->policy()->blockDeletion($this->candidate(active: true)));
    }

    public function testAnAlreadyInactiveAccountIsNotDeactivatedAgain(): void
    {
        $this->assertSame(
            ProtectionPolicy::REASON_ALREADY_INACTIVE,
            $this->policy()->blockDeactivation($this->candidate(active: false), 10)
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function policy(array $overrides = []): ProtectionPolicy
    {
        return new ProtectionPolicy(ConfigBuilder::build($overrides));
    }

    private function candidate(
        string $username = 'adminuser',
        bool $active = true,
        ?int $roleId = 3
    ): Candidate {
        return new Candidate(1, $username, 'ada@example.com', 'Ada', $active, 1_000, 500, $roleId);
    }
}
