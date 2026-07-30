<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\Gogs;

class GogsTest extends GiteaTest
{
    protected static string $accessToken = '';
    protected static string $owner = '';

    protected static string $defaultBranch = 'master';
    protected static bool $supportsPullRequestCreation = false;
    protected static bool $supportsPullRequestLookup = false;
    protected static bool $supportsCommitStatuses = false;
    protected static bool $supportsCommitStatusLookup = false;
    protected static bool $supportsRepositoryLanguages = false;
    protected static string $eventHeader = 'x-gogs-event';
    protected static string $signatureHeader = 'x-gogs-signature';

    protected function setupAdapter(): void
    {
        if (empty(static::$accessToken)) {
            $this->setupGogs();
        }

        $adapter = new Gogs(new Cache(new None()));
        $gogsUrl = System::getEnv('TESTS_GOGS_URL', 'http://gogs:3000');

        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: static::$accessToken,
            refreshToken: ''
        );
        $adapter->setEndpoint($gogsUrl);

        if (empty(static::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            static::$owner = $adapter->createOrganization($orgName);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function setupGogs(): void
    {
        $tokenFile = '/gogs-data/gogs/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                static::$accessToken = trim($contents);
            }
        }
    }


    // --- Skip tests for unsupported Gogs features ---

    // Pull request API

    // Commit status



    // Repository languages

    public function testListBranchesEmptyRepository(): void
    {
        // The Gogs adapter creates repositories with `auto_init: true` (plus a
        // default README), so a default branch always exists on creation —
        // an empty repository is not reachable through this adapter. This
        // also avoids Gogs' HTTP 500 response from `/branches` on commit-less
        // repos.
        $this->markTestSkipped('Gogs adapter creates repositories with auto_init, so a default branch always exists');
    }
}
