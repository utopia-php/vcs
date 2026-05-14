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
    }

    public function testKnownSkipDirectivesAreCaseInsensitive(): void
    {
        $this->assertTrue($this->validator->isValid('[SKIP CI] update changelog'));
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
        $this->assertTrue($this->validator->isValid('docs: update readme [skip ci]'));
        $this->assertTrue($this->validator->isValid('docs: update readme[skip ci]'));
        $this->assertTrue($this->validator->isValid('prefix[skip ci]suffix'));
        $this->assertFalse($this->validator->isValid('refactor: skip ci cache seeding'));
    }

    public function testMultilineCommitMessageSkips(): void
    {
        $message = "feat: add new stuff\n\nMore detail here.\n\n[skip ci]";

        $this->assertTrue($this->validator->isValid($message));
    }

    public function testNonStringCommitMessageProceeds(): void
    {
        $this->assertFalse($this->validator->isValid(null));
        $this->assertFalse($this->validator->isValid([]));
    }
}
