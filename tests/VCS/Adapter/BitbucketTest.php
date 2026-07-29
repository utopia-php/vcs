<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Bitbucket;

class BitbucketTest extends Base
{
    protected static string $accessToken = '';
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    protected function setupAdapter(): void
    {
        if (empty(static::$accessToken)) {
            static::$accessToken = System::getEnv('TESTS_BITBUCKET_ACCESS_TOKEN') ?? '';
        }

        if (empty(static::$accessToken)) {
            $this->markTestSkipped('Bitbucket access token not configured');
        }

        $adapter = new Bitbucket(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: static::$accessToken,
            refreshToken: ''
        );

        $endpoint = System::getEnv('TESTS_BITBUCKET_ENDPOINT') ?? '';
        if (!empty($endpoint)) {
            $adapter->setEndpoint($endpoint);
        }

        if (empty(static::$owner)) {
            // Fall back to the token's own workspace when none is configured
            static::$owner = System::getEnv('TESTS_BITBUCKET_WORKSPACE') ?: $adapter->getOwnerName('');
        }

        $this->vcsAdapter = $adapter;
    }

    public function testWebhookHeaderNames(): void
    {
        $this->assertSame('x-event-key', $this->vcsAdapter->getEventHeaderName());
        $this->assertSame('x-hub-signature', $this->vcsAdapter->getSignatureHeaderName());
    }

    /**
     * Bitbucket has no numeric repository ids, so the owner always resolves from
     * the account the token belongs to rather than from a repository.
     */
    public function testGetOwnerName(): void
    {
        $owner = $this->vcsAdapter->getOwnerName('');

        $this->assertIsString($owner);
        $this->assertNotEmpty($owner);
        $this->assertSame($owner, $this->vcsAdapter->getOwnerName('', 12345));
    }

    /**
     * Bitbucket looks accounts up by UUID rather than by handle.
     */
    public function testGetUser(): void
    {
        /** @var Bitbucket $adapter */
        $adapter = $this->vcsAdapter;

        $me = $adapter->getAuthenticatedUser();
        $this->assertNotEmpty($me['uuid'] ?? '');

        $result = $adapter->getUser($me['uuid']);

        $this->assertIsArray($result);
        $this->assertSame($me['uuid'], $result['id']);
        // Bitbucket reports the handle as `nickname`, and `username` only for
        // the authenticated account itself
        $this->assertSame($me['username'] ?? ($me['nickname'] ?? ''), $result['username']);
    }

    public function testListRepositoryLanguages(): void
    {
        $this->markTestSkipped('Bitbucket does not compute language statistics; the language field is set by hand');
    }

    public function testListWorkspaces(): void
    {
        /** @var Bitbucket $adapter */
        $adapter = $this->vcsAdapter;

        $result = $adapter->listWorkspaces(1, 20);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertNotEmpty($result['items']);

        foreach ($result['items'] as $workspace) {
            $this->assertArrayHasKey('id', $workspace);
            $this->assertArrayHasKey('name', $workspace);
            $this->assertArrayHasKey('slug', $workspace);
            $this->assertNotEmpty($workspace['slug']);
        }
    }

    public function testSearchRepositoriesWithSearch(): void
    {
        $uniqueId = \uniqid();
        $repositoryName = 'test-search-unique-' . $uniqueId;
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $result = [];
            $this->assertEventually(function () use (&$result, $uniqueId, $repositoryName) {
                $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10, $uniqueId);
                $this->assertContains($repositoryName, array_column($result['items'], 'name'));
            }, 30000, 2000);

            $this->assertArrayHasKey('items', $result);
            $this->assertNotEmpty($result['items']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    /**
     * Bitbucket rejects a build status with no URL, and reports 'pending' as INPROGRESS.
     */
    public function testUpdateCommitStatusDefaultsUrlToCommit(): void
    {
        $repositoryName = 'test-update-commit-status-url-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $this->vcsAdapter->updateCommitStatus(
                $repositoryName,
                $commitHash,
                static::$owner,
                'pending',
                'Build started',
                '',
                'ci/test'
            );

            $written = null;
            foreach ($this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash) as $status) {
                if ($status['context'] === 'ci/test') {
                    $written = $status;
                }
            }

            $this->assertNotNull($written, 'No status reported under the context it was written with');
            $this->assertSame('pending', $written['state']);
            $this->assertSame(
                $this->vcsAdapter->getCommitUrl(static::$owner, $repositoryName, $commitHash),
                $written['target_url']
            );
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetCommitStatusesEmptyForNewCommit(): void
    {
        $repositoryName = 'test-get-commit-statuses-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $result = $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash);

            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $repositoryName = 'test-clone-tag-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $directory = '/tmp/test-clone-tag-' . \uniqid();

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v1.0.0', $commitHash);

            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                'v1.0.0',
                Git::CLONE_TYPE_TAG,
                $directory,
                '/'
            );

            $this->assertStringContainsString('refs/tags', $command);
            $this->assertStringContainsString('v1.0.0', $command);

            $output = [];
            \exec($command . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
            $this->assertFileExists($directory . '/README.md');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
            if (\is_dir($directory)) {
                \exec('rm -rf ' . escapeshellarg($directory));
            }
        }
    }

    /**
     * Bitbucket signs no archive URLs, so the adapter leaves the opt-in
     * presigned URL support unimplemented.
     */
    public function testGetRepositoryPresignedUrlIsUnsupported(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepositoryPresignedUrl(static::$owner, 'some-repo', static::$defaultBranch);
    }

    /**
     * Bitbucket Cloud can only deliver webhooks to publicly reachable URLs, so
     * unlike the self-hosted adapters this only covers the API side of the
     * subscription, not a real delivery.
     */
    public function testCreateWebhook(): void
    {
        $repositoryName = 'test-create-webhook-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            /** @var Bitbucket $adapter */
            $adapter = $this->vcsAdapter;

            $uuid = $adapter->createRepositoryWebhook(
                static::$owner,
                $repositoryName,
                'https://example.com/webhook',
                'secret-token',
                ['push', 'pull_request']
            );

            $this->assertIsString($uuid);
            $this->assertNotEmpty($uuid);

            $this->assertTrue($adapter->deleteWebhook(static::$owner, $repositoryName, $uuid));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testValidateWebhookEvent(): void
    {
        $payload = '{"push":{"changes":[]}}';
        $secret = 'my-webhook-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->vcsAdapter->validateWebhookEvent($payload, $signature, $secret));

        // Unprefixed digests and plain secrets are both rejected
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, hash_hmac('sha256', $payload, $secret), $secret));
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, $secret, $secret));
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, 'sha256=wrongsig', $secret));
    }

    public function testGetEventPush(): void
    {
        $result = $this->vcsAdapter->getEvent('repo:push', $this->pushPayload());

        $this->assertIsArray($result);
        $this->assertFalse($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
        $this->assertSame('main', $result['branch']);
        $this->assertSame('https://bitbucket.org/test-workspace/test-repo/branch/main', $result['branchUrl']);
        // The routable identifier, matching what getRepositoryName() resolves
        $this->assertSame('test-workspace/test-repo', $result['repositoryId']);
        $this->assertSame('test-repo', $result['repositoryName']);
        $this->assertSame('https://bitbucket.org/test-workspace/test-repo', $result['repositoryUrl']);
        $this->assertSame('test-workspace', $result['owner']);
        $this->assertSame('abc123', $result['commitHash']);
        $this->assertSame('Test User', $result['headCommitAuthorName']);
        $this->assertSame('test@example.com', $result['headCommitAuthorEmail']);
        $this->assertSame('Test commit', $result['headCommitMessage']);
        $this->assertSame('https://bitbucket.org/test-workspace/test-repo/commits/abc123', $result['headCommitUrl']);
        $this->assertSame('https://bitbucket.org/tester', $result['authorUrl']);
        $this->assertSame('https://bitbucket.org/account/tester/avatar/', $result['authorAvatarUrl']);
        $this->assertFalse($result['external']);
        // Bitbucket's push payload carries no file lists
        $this->assertSame([], $result['affectedFiles']);
    }

    /**
     * Bitbucket only names the author in a raw "Name <email>" string when the
     * commit isn't linked to an account.
     */
    public function testGetEventPushWithLinkedAuthor(): void
    {
        $payload = $this->pushPayload(author: [
            'raw' => 'Test User <test@example.com>',
            'user' => ['display_name' => 'Linked User'],
        ]);

        $result = $this->vcsAdapter->getEvent('repo:push', $payload);

        $this->assertSame('Linked User', $result['headCommitAuthorName']);
        $this->assertSame('test@example.com', $result['headCommitAuthorEmail']);
    }

    public function testGetEventPushDetectsBranchCreated(): void
    {
        $result = $this->vcsAdapter->getEvent('repo:push', $this->pushPayload(created: true));

        $this->assertTrue($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
        $this->assertSame('main', $result['branch']);
    }

    public function testGetEventPushDetectsBranchDeleted(): void
    {
        // A deleted branch is reported with no new state, only the old one
        $payload = json_encode([
            'actor' => ['links' => []],
            'repository' => [
                'uuid' => '{11111111-2222-3333-4444-555555555555}',
                'name' => 'test-repo',
                'full_name' => 'test-workspace/test-repo',
                'workspace' => ['slug' => 'test-workspace'],
                'links' => ['html' => ['href' => 'https://bitbucket.org/test-workspace/test-repo']],
            ],
            'push' => [
                'changes' => [
                    [
                        'new' => null,
                        'old' => ['type' => 'branch', 'name' => 'feature', 'target' => ['hash' => 'abc123']],
                        'created' => false,
                        'closed' => true,
                    ],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('repo:push', $payload);

        $this->assertFalse($result['branchCreated']);
        $this->assertTrue($result['branchDeleted']);
        $this->assertSame('feature', $result['branch']);
        $this->assertSame('', $result['commitHash']);
    }

    /**
     * A repositoryId has to be resolvable by the adapter that reported it.
     */
    public function testGetEventReportsResolvableRepositoryId(): void
    {
        $repositoryName = 'test-event-repository-id-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $payload = json_encode([
                'repository' => [
                    'uuid' => $created['uuid'] ?? '',
                    'name' => $repositoryName,
                    'full_name' => $created['full_name'] ?? '',
                    'workspace' => ['slug' => $this->ownerPath()],
                ],
                'push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'main']]]],
            ]);

            if ($payload === false) {
                $this->fail('Failed to encode JSON payload');
            }

            $event = $this->vcsAdapter->getEvent('repo:push', $payload);

            $this->assertSame($created['id'], $event['repositoryId']);
            $this->assertSame($repositoryName, $this->vcsAdapter->getRepositoryName($event['repositoryId']));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    /**
     * Bitbucket reports every ref a push touched in one delivery.
     */
    public function testGetEventsReportsEveryPushedBranch(): void
    {
        $payload = json_encode([
            'actor' => ['links' => []],
            'repository' => [
                'name' => 'test-repo',
                'full_name' => 'test-workspace/test-repo',
                'workspace' => ['slug' => 'test-workspace'],
                'links' => ['html' => ['href' => 'https://bitbucket.org/test-workspace/test-repo']],
            ],
            'push' => [
                'changes' => [
                    ['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'aaa111']], 'created' => false, 'closed' => false],
                    ['new' => ['type' => 'tag', 'name' => 'v1.0.0', 'target' => ['hash' => 'bbb222']]],
                    ['new' => ['type' => 'branch', 'name' => 'feature', 'target' => ['hash' => 'ccc333']], 'created' => true, 'closed' => false],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        /** @var Bitbucket $adapter */
        $adapter = $this->vcsAdapter;

        $events = $adapter->getEvents('repo:push', $payload);

        // The tag is left out; the shared event shape describes a branch push
        $this->assertCount(2, $events);
        $this->assertSame(['main', 'feature'], array_column($events, 'branch'));
        $this->assertSame(['aaa111', 'ccc333'], array_column($events, 'commitHash'));
        $this->assertTrue($events[1]['branchCreated']);

        // getEvent() reports the first of them
        $this->assertSame($events[0], $adapter->getEvent('repo:push', $payload));
    }

    /**
     * A tag-only push has no branch to report.
     */
    public function testGetEventTagPushIsNotReportedAsBranch(): void
    {
        $payload = json_encode([
            'repository' => ['name' => 'test-repo', 'full_name' => 'test-workspace/test-repo'],
            'push' => ['changes' => [['new' => ['type' => 'tag', 'name' => 'v1.0.0', 'target' => ['hash' => 'aaa111']]]]],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $this->assertSame([], $this->vcsAdapter->getEvent('repo:push', $payload));
    }

    /**
     * The interface's int return can't carry Bitbucket's UUID, so no hook is
     * created through it at all.
     */
    public function testCreateWebhookThroughSharedInterfaceIsRefused(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->createWebhook(static::$owner, 'some-repo', 'https://example.com/webhook', 'secret');
    }

    public function testGetEventPullRequest(): void
    {
        $result = $this->vcsAdapter->getEvent('pullrequest:created', $this->pullRequestPayload());

        $this->assertIsArray($result);
        $this->assertSame('feature', $result['branch']);
        $this->assertSame('https://bitbucket.org/test-workspace/test-repo/branch/feature', $result['branchUrl']);
        $this->assertSame('opened', $result['action']);
        $this->assertFalse($result['external']);
        $this->assertSame(1, $result['pullRequestNumber']);
        // The routable identifier, matching what getRepositoryName() resolves
        $this->assertSame('test-workspace/test-repo', $result['repositoryId']);
        $this->assertSame('test-repo', $result['repositoryName']);
        $this->assertSame('abc123', $result['commitHash']);
        $this->assertSame('https://bitbucket.org/test-workspace/test-repo/commits/abc123', $result['headCommitUrl']);
    }

    public function testGetEventPullRequestActionMapping(): void
    {
        $mapping = [
            'pullrequest:created' => 'opened',
            'pullrequest:updated' => 'synchronize',
            'pullrequest:fulfilled' => 'closed',
            'pullrequest:rejected' => 'closed',
        ];

        foreach ($mapping as $event => $action) {
            $result = $this->vcsAdapter->getEvent($event, $this->pullRequestPayload());

            $this->assertSame($action, $result['action'], "event '{$event}' should map to '{$action}'");
        }
    }

    public function testGetEventPullRequestDetectsExternal(): void
    {
        $result = $this->vcsAdapter->getEvent(
            'pullrequest:created',
            $this->pullRequestPayload(sourceRepositoryId: '{99999999-2222-3333-4444-555555555555}')
        );

        $this->assertTrue($result['external']);
    }

    /**
     * @param array<string, mixed>|null $author
     */
    private function pushPayload(?array $author = null, bool $created = false): string
    {
        $payload = json_encode([
            'actor' => [
                'display_name' => 'Tester',
                'links' => [
                    'html' => ['href' => 'https://bitbucket.org/tester'],
                    'avatar' => ['href' => 'https://bitbucket.org/account/tester/avatar/'],
                ],
            ],
            'repository' => [
                'uuid' => '{11111111-2222-3333-4444-555555555555}',
                'name' => 'test-repo',
                'full_name' => 'test-workspace/test-repo',
                'workspace' => ['slug' => 'test-workspace'],
                'links' => ['html' => ['href' => 'https://bitbucket.org/test-workspace/test-repo']],
            ],
            'push' => [
                'changes' => [
                    [
                        'created' => $created,
                        'closed' => false,
                        'old' => $created ? null : ['type' => 'branch', 'name' => 'main'],
                        'new' => [
                            'type' => 'branch',
                            'name' => 'main',
                            'target' => [
                                'hash' => 'abc123',
                                'message' => 'Test commit',
                                'author' => $author ?? ['raw' => 'Test User <test@example.com>'],
                                'links' => ['html' => ['href' => 'https://bitbucket.org/test-workspace/test-repo/commits/abc123']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        return $payload;
    }

    private function pullRequestPayload(string $sourceRepositoryId = '{11111111-2222-3333-4444-555555555555}'): string
    {
        $payload = json_encode([
            'actor' => [
                'links' => [
                    'html' => ['href' => 'https://bitbucket.org/tester'],
                    'avatar' => ['href' => 'https://bitbucket.org/account/tester/avatar/'],
                ],
            ],
            'repository' => [
                'uuid' => '{11111111-2222-3333-4444-555555555555}',
                'name' => 'test-repo',
                'full_name' => 'test-workspace/test-repo',
                'workspace' => ['slug' => 'test-workspace'],
                'links' => ['html' => ['href' => 'https://bitbucket.org/test-workspace/test-repo']],
            ],
            'pullrequest' => [
                'id' => 1,
                'title' => 'Test PR',
                'state' => 'OPEN',
                'source' => [
                    'branch' => ['name' => 'feature'],
                    'commit' => ['hash' => 'abc123'],
                    'repository' => ['uuid' => $sourceRepositoryId],
                ],
                'destination' => [
                    'branch' => ['name' => 'main'],
                    'repository' => ['uuid' => '{11111111-2222-3333-4444-555555555555}'],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        return $payload;
    }
}
