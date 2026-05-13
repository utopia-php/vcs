<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\VCS\Validator\DeploymentSkippable;

class DeploymentSkippableTest extends TestCase
{
    private DeploymentSkippable $validator;

    protected function setUp(): void
    {
        $this->validator = new DeploymentSkippable();
    }

    public function testKnownSkipDirectivesSkip(): void
    {
        $this->assertTrue($this->validator->isValid('[skip ci] update changelog'));
        $this->assertTrue($this->validator->isValid('[ci skip] update changelog'));
        $this->assertTrue($this->validator->isValid('[no ci] update changelog'));
        $this->assertTrue($this->validator->isValid('[skip action] update changelog'));
        $this->assertTrue($this->validator->isValid('[action skip] update changelog'));
        $this->assertTrue($this->validator->isValid('[no action] update changelog'));
        $this->assertTrue($this->validator->isValid('[skip actions] update changelog'));
        $this->assertTrue($this->validator->isValid('[actions skip] update changelog'));
        $this->assertTrue($this->validator->isValid('[no actions] update changelog'));
        $this->assertTrue($this->validator->isValid('[skip deploy] update changelog'));
        $this->assertTrue($this->validator->isValid('[deploy skip] update changelog'));
        $this->assertTrue($this->validator->isValid('[no deploy] update changelog'));
        $this->assertTrue($this->validator->isValid('[skip appwrite] update changelog'));
        $this->assertTrue($this->validator->isValid('[appwrite skip] update changelog'));
        $this->assertTrue($this->validator->isValid('[no appwrite] update changelog'));
    }

    public function testKnownSkipDirectivesAreCaseInsensitive(): void
    {
        $this->assertTrue($this->validator->isValid('[SKIP CI] update changelog'));
        $this->assertTrue($this->validator->isValid('[Skip Deploy] update changelog'));
        $this->assertTrue($this->validator->isValid('[SKIP APPWRITE] update changelog'));
        $this->assertTrue($this->validator->isValid('[Appwrite Skip] update changelog'));
        $this->assertTrue($this->validator->isValid('[No Actions] update changelog'));
    }

    public function testMessageWithoutKnownDirectiveProceeds(): void
    {
        $this->assertFalse($this->validator->isValid('fix: real bug fix'));
        $this->assertFalse($this->validator->isValid('feat: add new feature'));
        $this->assertFalse($this->validator->isValid('skip deploy without brackets'));
        $this->assertFalse($this->validator->isValid('deploy this please'));
        $this->assertFalse($this->validator->isValid('skip-checks:true'));
    }

    public function testDirectiveCanAppearAnywhere(): void
    {
        $this->assertTrue($this->validator->isValid('docs: update readme [skip deploy]'));
        $this->assertTrue($this->validator->isValid('docs: update readme[skip deploy]'));
        $this->assertTrue($this->validator->isValid('prefix[skip deploy]suffix'));
        $this->assertFalse($this->validator->isValid('refactor: skip appwrite cache seeding'));
        $this->assertFalse($this->validator->isValid('fix: appwrite skip quota check in tests'));
    }

    public function testMultilineCommitMessageSkips(): void
    {
        $message = "feat: add new stuff\n\nMore detail here.\n\n[skip deploy]";

        $this->assertTrue($this->validator->isValid($message));
    }

    public function testNonStringCommitMessageProceeds(): void
    {
        $this->assertFalse($this->validator->isValid(null));
        $this->assertFalse($this->validator->isValid([]));
    }
}
