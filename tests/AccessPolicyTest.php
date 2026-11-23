<?php

declare(strict_types=1);

namespace FreshTracker\Tests;

use FreshTracker\Access\AccessPolicy;
use FreshTracker\Access\EditSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AccessPolicyTest extends TestCase
{
    public function testLocalNetworkWithoutPasswordCanEdit(): void
    {
        $context = $this->policy([
            'local' => ['networks' => ['127.0.0.1'], 'visible' => true, 'password' => ''],
        ])->context('127.0.0.1', $this->session());

        self::assertSame('local', $context->group);
        self::assertTrue($context->canView);
        self::assertTrue($context->canEdit);
        self::assertSame('', $context->editPasswordScope);
    }

    public function testLocalPasswordLocksEditingUntilGranted(): void
    {
        $policy = $this->policy([
            'local' => ['networks' => ['127.0.0.1'], 'visible' => true, 'password' => 'secret'],
        ]);
        $context = $policy->context('127.0.0.1', $this->session());

        self::assertTrue($context->canView);
        self::assertFalse($context->canEdit);
        self::assertSame('local', $context->editPasswordScope);
        self::assertTrue($policy->canRequestPassword('local'));
        self::assertTrue($policy->verifyPassword('local', 'secret'));
        self::assertFalse($policy->verifyPassword('local', 'nope'));
    }

    public function testLocalVisibleFalseDeniesEverything(): void
    {
        $context = $this->policy([
            'local' => ['networks' => ['127.0.0.1'], 'visible' => false, 'password' => ''],
        ])->context('127.0.0.1', $this->session());

        self::assertSame('none', $context->group);
        self::assertFalse($context->canView);
        self::assertFalse($context->canEdit);
    }

    public function testPublicIsReadOnlyByDefault(): void
    {
        $context = $this->policy([])->context('8.8.8.8', $this->session());

        self::assertSame('public', $context->group);
        self::assertTrue($context->canView);
        self::assertFalse($context->canEdit);
    }

    public function testPublicEditableAlwaysAllowsEditing(): void
    {
        $context = $this->policy([
            'public' => ['visible' => 'always', 'password' => '', 'editable' => 'always'],
        ])->context('8.8.8.8', $this->session());

        self::assertTrue($context->canEdit);
    }

    public function testPublicEditablePasswordRequiresAPassword(): void
    {
        $policy = $this->policy([
            'public' => ['visible' => 'always', 'password' => 'letmein', 'editable' => 'password'],
        ]);
        $context = $policy->context('8.8.8.8', $this->session());

        self::assertFalse($context->canEdit);
        self::assertSame('public', $context->editPasswordScope);
        self::assertTrue($policy->canRequestPassword('public'));
        self::assertTrue($policy->verifyPassword('public', 'letmein'));
    }

    public function testPublicEditablePasswordWithEmptyPasswordFailsClosed(): void
    {
        $policy = $this->policy([
            'public' => ['visible' => 'always', 'password' => '', 'editable' => 'password'],
        ]);
        $context = $policy->context('8.8.8.8', $this->session());

        self::assertFalse($context->canEdit);
        self::assertFalse($policy->canRequestPassword('public'));
    }

    public function testPublicCanBeHiddenCompletely(): void
    {
        $policy = $this->policy([
            'public' => ['visible' => false, 'password' => '', 'editable' => 'never'],
        ]);
        $context = $policy->context('8.8.8.8', $this->session());

        self::assertSame('none', $context->group);
        self::assertFalse($context->canView);
        self::assertFalse($context->canEdit);
        self::assertFalse($policy->canRequestPassword('public'));
    }

    public function testIpv6LocalhostIsLocal(): void
    {
        $policy = $this->policy([
            'local' => ['networks' => ['::1'], 'visible' => true, 'password' => ''],
        ]);

        self::assertTrue($policy->isLocal('::1'));
        self::assertFalse($policy->isLocal('::2'));
    }

    public function testLegacyAdminIsMappedToLocalNetworks(): void
    {
        $context = $this->policy(['admin' => ['127.0.0.1']])
            ->context('127.0.0.1', $this->session());

        self::assertSame('local', $context->group);
    }

    private function policy(array $access): AccessPolicy
    {
        return AccessPolicy::fromConfig($access, new NullLogger());
    }

    private function session(): EditSession
    {
        return new EditSession('test-secret');
    }
}
