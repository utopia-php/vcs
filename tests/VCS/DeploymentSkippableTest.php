<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\VCS\DeploymentSkippable;

class DeploymentSkippableTest extends TestCase
{
    public function testKnownSkipDirectivesSkip(): void
    {
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[skip ci] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[ci skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[no ci] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[skip action] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[action skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[no action] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[skip actions] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[actions skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[no actions] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[skip deploy] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[deploy skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[no deploy] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[skip appwrite] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[appwrite skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[no appwrite] update changelog'));
    }

    public function testKnownSkipDirectivesAreCaseInsensitive(): void
    {
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[SKIP CI] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[Skip Deploy] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[SKIP APPWRITE] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[Appwrite Skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('[No Actions] update changelog'));
    }

    public function testMessageWithoutKnownDirectiveProceeds(): void
    {
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage('fix: real bug fix'));
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage('feat: add new feature'));
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage('skip deploy without brackets'));
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage('deploy this please'));
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage('skip-checks:true'));
    }

    public function testDirectiveCanAppearAnywhere(): void
    {
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('docs: update readme [skip deploy]'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('docs: update readme[skip deploy]'));
        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage('prefix[skip deploy]suffix'));
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage('refactor: skip appwrite cache seeding'));
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage('fix: appwrite skip quota check in tests'));
    }

    public function testMultilineCommitMessageSkips(): void
    {
        $message = "feat: add new stuff\n\nMore detail here.\n\n[skip deploy]";

        $this->assertTrue(DeploymentSkippable::isSkippedByCommitMessage($message));
    }

    public function testNonStringCommitMessageProceeds(): void
    {
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage(null));
        $this->assertFalse(DeploymentSkippable::isSkippedByCommitMessage([]));
    }
}
