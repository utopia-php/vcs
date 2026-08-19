<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git\Gitea;

class GiteaTest extends Base
{
    protected static string $accessToken = '';
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';
    protected static string $existingUser = 'utopia';
    protected static string $userHandleField = 'login';
    protected static string $eventHeader = 'x-gitea-event';
    protected static string $signatureHeader = 'x-gitea-signature';

    /** @var array<string> */
    protected static array $pullRequestOpenedActions = ['opened', 'synchronized'];

    protected static string $presignedTarballFragment = '.tar.gz?token=';
    protected static string $presignedZipballFragment = '.zip?token=';

    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
    protected static string $avatarDomain = 'gravatar.com';
    protected static bool $supportsCheckRuns = false;
    protected static bool $supportsNamespaceListing = false;
    protected static bool $supportsInstallationRepository = false;
    protected static bool $reportsCommitAuthorUrl = false;

    protected function setupAdapter(): void
    {
        if (empty(static::$accessToken)) {
            $this->setupGitea();
        }

        $adapter = new Gitea(new Cache(new None()));
        $giteaUrl = System::getEnv('TESTS_GITEA_URL', 'http://gitea:3000');

        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: static::$accessToken,
            refreshToken: ''
        );
        $adapter->setEndpoint($giteaUrl);
        if (empty(static::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            static::$owner = $adapter->createOrganization($orgName);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return System::getEnv('TESTS_GITEA_URL', 'http://gitea:3000') . '/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }

    protected function setupGitea(): void
    {
        $tokenFile = '/data/gitea/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                static::$accessToken = trim($contents);
            }
        }
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string
    {
        $repositoryUrl = 'http://gitea:3000/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

        return (string) json_encode([
            'ref' => 'refs/heads/' . $branch,
            'before' => 'abc123',
            'after' => self::EVENT_COMMIT_HASH,
            'created' => $created,
            'deleted' => $deleted,
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'html_url' => $repositoryUrl,
                'owner' => ['login' => self::EVENT_OWNER],
            ],
            'sender' => [
                'login' => self::EVENT_AUTHOR_NAME,
                'html_url' => 'http://gitea:3000/pusher-user',
                'avatar_url' => 'http://gitea:3000/avatars/pusher',
            ],
            'head_commit' => [
                'id' => self::EVENT_COMMIT_HASH,
                'message' => self::EVENT_COMMIT_MESSAGE,
                'url' => $repositoryUrl . '/commit/' . self::EVENT_COMMIT_HASH,
                'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
            ],
            'commits' => [[
                'id' => self::EVENT_COMMIT_HASH,
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
            ]],
        ]);
    }

    protected function pullRequestPayload(bool $external = false): string
    {
        $repositoryUrl = 'http://gitea:3000/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;
        $headRepository = $external
            ? 'someone-else/forked-repo'
            : self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

        return (string) json_encode([
            'action' => 'opened',
            'number' => self::EVENT_PULL_REQUEST_NUMBER,
            'pull_request' => [
                'id' => 1,
                'number' => self::EVENT_PULL_REQUEST_NUMBER,
                'state' => 'open',
                'title' => 'Test PR',
                'head' => [
                    'ref' => self::EVENT_HEAD_BRANCH,
                    'sha' => self::EVENT_COMMIT_HASH,
                    'repo' => ['full_name' => $headRepository],
                    'user' => ['login' => self::EVENT_OWNER],
                ],
                'base' => [
                    'ref' => static::$defaultBranch,
                    'sha' => 'abc123',
                    'user' => ['login' => self::EVENT_OWNER],
                ],
                'user' => ['login' => self::EVENT_OWNER, 'avatar_url' => 'http://gitea:3000/avatars/pr-author'],
            ],
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'html_url' => $repositoryUrl,
                'owner' => ['login' => self::EVENT_OWNER],
            ],
            'sender' => ['login' => self::EVENT_OWNER, 'html_url' => 'http://gitea:3000/' . self::EVENT_OWNER],
        ]);
    }
}
