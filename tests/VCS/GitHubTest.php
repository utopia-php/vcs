<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git\GitHub;

class GitHubTest extends TestCase 
{
    public function testGetUser(): void
    {
        $github = new GitHub();
        $userDetails = $github->getUser("vermakhushboo");
    }

    public function testListRepositories(): void
    {
        $github = new GitHub();
        $repos = $github->listRepositories("vermakhushboo");
    }

    public function testGetRepository(): void
    {
        $github = new GitHub();
        $repoDetails = $github->getRepository("vermakhushboo", "TodoApp");
        var_dump($repoDetails);
    }
}