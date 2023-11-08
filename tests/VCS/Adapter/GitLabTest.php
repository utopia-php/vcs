<?php

namespace Utopia\Tests\VCS\Adapter;

use PHPUnit\Framework\TestCase;

use Utopia\VCS\Adapter\Git\GitLab;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

class GitLabTest extends TestCase
{

    protected GitLab $gitlab;

    public function setUp(): void
    {
        $this->gitlab = new GitLab(new Cache(new None()));
        $privateKey = 'gloas-6ce589ad86ad846bd29de6222898023d29c5ed4b7a3c330882bc53cd8894f2bb';
        $gitlabAppId = '18c2c704c65bb97fbb64eb618c8a3b9b858bc829c3b3656c099c87d17e2d8a99';
        $code = '29cd702c3f0cc282909d0d72a8a019752e63e70baf45b184de41d36e3a45771a';
        $redirectUri = 'http://localhost/v1/vcs/gitlab/callback';
        $this->gitlab->generateAccessToken($gitlabAppId, $privateKey, $code, $redirectUri);
    }

    // public function testGetOwnerName(): void
    // {
    //     $ownerName = $this->gitlab->getOwnerName();
    //     $this->assertEquals('Khushboo Verma', $ownerName);
    // }

    public function testListRepositories(): void
    {
        $this->gitlab->listRepositories('test9062040%2Ftest');
    }
}
