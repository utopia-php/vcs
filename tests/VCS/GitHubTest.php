<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\App;
use Utopia\VCS\Adapter\Git\GitHub;

class GitHubTest extends TestCase
{
    protected $github;

    public function setUp(): void
    {
        $privateKey = App::getEnv("GITHUB_PRIVATE_KEY");
        $githubAppId = App::getEnv("GITHUB_APP_IDENTIFIER");
        $installationId = "1234";
        $this->github = new GitHub("vermakhushboo", $installationId, $privateKey, $githubAppId);
    }

    public function testGetUser(): void
    {
        $this->github->getUser();
    }

    public function testListRepositoriesForGitHubApp(): void
    {
        $this->github->listRepositoriesForGitHubApp();
    }

    public function testGetRepository(): void
    {
        $this->github->getRepository("TodoApp");
    }

    public function testAddComment(): void
    {
        $this->github->addComment("basic-js-crud", 1);
    }

    public function testUpdateComment(): void
    {
        $this->github->updateComment("basic-js-crud", 1431560395);
    }
}