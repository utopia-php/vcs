<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\VCS\DeploymentSkippable;

class DeploymentSkippableTest extends TestCase
{
    public function testKnownSkipDirectivesSkip(): void
    {
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[skip ci] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[ci skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[no ci] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[skip action] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[action skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[no action] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[skip actions] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[actions skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[no actions] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[skip deploy] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[deploy skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[no deploy] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[skip appwrite] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[appwrite skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[no appwrite] update changelog'));
    }

    public function testKnownSkipDirectivesAreCaseInsensitive(): void
    {
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[SKIP CI] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[Skip Deploy] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[SKIP APPWRITE] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[Appwrite Skip] update changelog'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('[No Actions] update changelog'));
    }

    public function testMessageWithoutKnownDirectiveProceeds(): void
    {
        $this->assertFalse(DeploymentSkippable::fromCommitMessage('fix: real bug fix'));
        $this->assertFalse(DeploymentSkippable::fromCommitMessage('feat: add new feature'));
        $this->assertFalse(DeploymentSkippable::fromCommitMessage('skip deploy without brackets'));
        $this->assertFalse(DeploymentSkippable::fromCommitMessage('deploy this please'));
        $this->assertFalse(DeploymentSkippable::fromCommitMessage('skip-checks:true'));
    }

    public function testDirectiveCanAppearAnywhere(): void
    {
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('docs: update readme [skip deploy]'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('docs: update readme[skip deploy]'));
        $this->assertTrue(DeploymentSkippable::fromCommitMessage('prefix[skip deploy]suffix'));
        $this->assertFalse(DeploymentSkippable::fromCommitMessage('refactor: skip appwrite cache seeding'));
        $this->assertFalse(DeploymentSkippable::fromCommitMessage('fix: appwrite skip quota check in tests'));
    }

    public function testMultilineCommitMessageSkips(): void
    {
        $message = "feat: add new stuff\n\nMore detail here.\n\n[skip deploy]";

        $this->assertTrue(DeploymentSkippable::fromCommitMessage($message));
    }

    public function testNonStringCommitMessageProceeds(): void
    {
        $this->assertFalse(DeploymentSkippable::fromCommitMessage(null));
        $this->assertFalse(DeploymentSkippable::fromCommitMessage([]));
    }
}
