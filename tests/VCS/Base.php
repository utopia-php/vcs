<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git;

abstract class Base extends TestCase
{
    protected Git $vcsAdapter;

    protected function setUp(): void
    {
        $this->vcsAdapter = $this->createVCSAdapter();
    }

    abstract protected function createVCSAdapter(): Git;

    abstract public function testGetOwnerName(): void;

    abstract public function testListRepositories(): void;

    abstract public function testGetTotalReposCount(): void;

    abstract public function testCreateComment(): void;

    abstract public function testUpdateComment(): void;

    abstract public function testDownloadRepositoryZip(): void;

    abstract public function testDownloadRepositoryTar(): void;

    abstract public function testForkRepository(): void;

    abstract public function testGenerateCloneCommand(): void;

    abstract public function testParseWebhookEventPayload(): void;

    abstract public function testGetRepositoryName(): void;

    abstract public function testListBranches(): void;

    abstract public function testGetRepositoryLanguages(): void;

    abstract public function testListRepositoryContents(): void;

    abstract public function testGetBranchPullRequest(): void;

    abstract public function testGetPullRequest(): void;

    abstract public function testGetComment(): void;
}
