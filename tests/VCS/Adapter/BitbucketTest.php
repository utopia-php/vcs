<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git\Bitbucket;

class BitbucketTest extends Base
{
    /**
     * Bitbucket has no repository ids, so events report the "workspace/slug"
     * pair its API routes on.
     */
    protected const EVENT_REPOSITORY_ID = self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

    private const REPOSITORY_URL = 'https://bitbucket.org/' . self::EVENT_REPOSITORY_ID;

    private const REPOSITORY_UUID = '{11111111-2222-3333-4444-555555555555}';

    private const FORK_UUID = '{99999999-2222-3333-4444-555555555555}';

    protected static string $accessToken = '';
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';
    protected static string $eventHeader = 'x-event-key';
    protected static string $signatureHeader = 'x-hub-signature';
    protected static string $pushEventName = 'repo:push';
    protected static string $pullRequestEventName = 'pullrequest:created';

    /**
     * Bitbucket has no app installations, and no repository to resolve an owner
     * from either - getOwnerName() reports the account the token belongs to.
     */
    protected static bool $supportsInstallationRepository = false;
    protected static bool $resolvesOwnerFromRepositoryId = false;

    /**
     * Bitbucket signs no archive urls, runs no checks, groups repositories in
     * workspaces rather than namespaces (see testListWorkspaces below), and has
     * a repository's language set by hand instead of computing it.
     */
    protected static bool $supportsPresignedUrls = false;
    protected static bool $supportsCheckRuns = false;
    protected static bool $supportsNamespaceListing = false;
    protected static bool $supportsRepositoryLanguages = false;

    /**
     * Bitbucket looks accounts up by uuid rather than by handle, so the shared
     * lookup does not apply; testGetUser below covers it instead.
     */
    protected static bool $supportsUserLookup = false;

    /**
     * Bitbucket Cloud only delivers webhooks to publicly reachable urls, so it
     * cannot reach the test catcher. testCreateWebhook below covers the API side
     * of a subscription.
     */
    protected static bool $supportsWebhookDelivery = false;

    /**
     * Bitbucket's push payload carries no per-commit file lists.
     */
    protected static bool $reportsAffectedFilesInPushEvent = false;

    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

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

    /**
     * Bitbucket has no repository ids; createRepository() reports the
     * "workspace/slug" pair its API routes on instead.
     *
     * @param array<string, mixed> $repository
     */
    protected function repositoryIdOf(array $repository): string
    {
        $this->assertArrayHasKey('id', $repository);
        $this->assertIsString($repository['id']);
        $this->assertStringContainsString('/', $repository['id']);

        return $repository['id'];
    }

    /**
     * Bitbucket reports a repository's owner as the workspace holding it.
     *
     * @param array<string, mixed> $repository
     */
    protected function ownerOf(array $repository): string
    {
        $this->assertArrayHasKey('workspace', $repository);
        $this->assertIsArray($repository['workspace']);
        $this->assertArrayHasKey('slug', $repository['workspace']);

        return (string) $repository['workspace']['slug'];
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string
    {
        $ref = [
            'type' => 'branch',
            'name' => $branch,
            'target' => [
                'hash' => static::EVENT_COMMIT_HASH,
                'message' => static::EVENT_COMMIT_MESSAGE,
                'author' => ['raw' => static::EVENT_AUTHOR_NAME . ' <' . static::EVENT_AUTHOR_EMAIL . '>'],
                'links' => ['html' => ['href' => self::REPOSITORY_URL . '/commits/' . static::EVENT_COMMIT_HASH]],
            ],
        ];

        // A created branch has no old state and a deleted one no new state. The
        // file lists go unused, Bitbucket naming no files in a push.
        return (string) json_encode([
            'actor' => $this->eventActor(),
            'repository' => $this->eventRepository(),
            'push' => [
                'changes' => [[
                    'created' => $created,
                    'closed' => $deleted,
                    'old' => $created ? null : $ref,
                    'new' => $deleted ? null : $ref,
                ]],
            ],
        ]);
    }

    protected function pullRequestPayload(bool $external = false): string
    {
        return (string) json_encode([
            'actor' => $this->eventActor(),
            'repository' => $this->eventRepository(),
            'pullrequest' => [
                'id' => static::EVENT_PULL_REQUEST_NUMBER,
                'title' => 'Test PR',
                'state' => 'OPEN',
                'source' => [
                    'branch' => ['name' => static::EVENT_HEAD_BRANCH],
                    'commit' => ['hash' => static::EVENT_COMMIT_HASH],
                    // A source in another repository is a fork-based contribution
                    'repository' => ['uuid' => $external ? self::FORK_UUID : self::REPOSITORY_UUID],
                ],
                'destination' => [
                    'branch' => ['name' => static::$defaultBranch],
                    'repository' => ['uuid' => self::REPOSITORY_UUID],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRepository(): array
    {
        return [
            'uuid' => self::REPOSITORY_UUID,
            'name' => static::EVENT_REPOSITORY_NAME,
            'full_name' => static::EVENT_REPOSITORY_ID,
            'workspace' => ['slug' => static::EVENT_OWNER],
            'links' => ['html' => ['href' => self::REPOSITORY_URL]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventActor(): array
    {
        return [
            'display_name' => 'Tester',
            'links' => [
                'html' => ['href' => 'https://bitbucket.org/tester'],
                'avatar' => ['href' => 'https://bitbucket.org/account/tester/avatar/'],
            ],
        ];
    }

    /**
     * Bitbucket reads the owner off the account its token belongs to, so no
     * repository - not even one that does not exist - changes the answer.
     */
    public function testGetOwnerNameIgnoresRepositoryId(): void
    {
        $this->assertSame($this->ownerPath(), $this->vcsAdapter->getOwnerName('', 999999999));
    }

    /**
     * Bitbucket looks accounts up by uuid, and reports the handle as `nickname`
     * for every account but the authenticated one.
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
        $this->assertSame($me['username'] ?? ($me['nickname'] ?? ''), $result['username']);
    }

    /**
     * Workspaces are Bitbucket's grouping of repositories, in place of the
     * namespaces the other providers list.
     */
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
            $this->assertArrayHasKey('slug', $workspace);
            $this->assertNotEmpty($workspace['slug']);
        }
    }

    /**
     * Bitbucket rejects a build status with no url, so the adapter points one
     * that was written without a url at the commit it describes.
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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetRepositoryPresignedUrlIsUnsupported(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepositoryPresignedUrl(static::$owner, 'some-repo', static::$defaultBranch);
    }

    /**
     * Bitbucket identifies a webhook by uuid rather than by a numeric id.
     */
    public function testCreateWebhook(): void
    {
        $repositoryName = 'test-create-webhook-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            /** @var Bitbucket $adapter */
            $adapter = $this->vcsAdapter;

            $uuid = $adapter->createWebhook(
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
            $this->discardRepositories($repositoryName);
        }
    }

    /**
     * Bitbucket only names the author in a raw "Name <email>" string; a commit
     * linked to an account is named by the account instead.
     */
    public function testGetEventPushWithLinkedAuthor(): void
    {
        $payload = (string) json_encode([
            'actor' => $this->eventActor(),
            'repository' => $this->eventRepository(),
            'push' => [
                'changes' => [[
                    'new' => [
                        'type' => 'branch',
                        'name' => static::$defaultBranch,
                        'target' => [
                            'hash' => static::EVENT_COMMIT_HASH,
                            'author' => [
                                'raw' => static::EVENT_AUTHOR_NAME . ' <' . static::EVENT_AUTHOR_EMAIL . '>',
                                'user' => ['display_name' => 'Linked User'],
                            ],
                        ],
                    ],
                ]],
            ],
        ]);

        $result = $this->vcsAdapter->getEvent(static::$pushEventName, $payload);

        $this->assertSame('Linked User', $result['headCommitAuthorName']);
        $this->assertSame(static::EVENT_AUTHOR_EMAIL, $result['headCommitAuthorEmail']);
    }

    /**
     * Bitbucket batches every ref a push touched into one delivery, and the
     * shared event shape describes a branch push, so tags are left out.
     */
    public function testGetEventsReportsEveryPushedBranch(): void
    {
        $payload = (string) json_encode([
            'actor' => $this->eventActor(),
            'repository' => $this->eventRepository(),
            'push' => [
                'changes' => [
                    ['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'aaa111']], 'created' => false, 'closed' => false],
                    ['new' => ['type' => 'tag', 'name' => 'v1.0.0', 'target' => ['hash' => 'bbb222']]],
                    ['new' => ['type' => 'branch', 'name' => 'feature', 'target' => ['hash' => 'ccc333']], 'created' => true, 'closed' => false],
                ],
            ],
        ]);

        /** @var Bitbucket $adapter */
        $adapter = $this->vcsAdapter;

        $events = $adapter->getEvents(static::$pushEventName, $payload);

        $this->assertCount(2, $events);
        $this->assertSame(['main', 'feature'], array_column($events, 'branch'));
        $this->assertSame(['aaa111', 'ccc333'], array_column($events, 'commitHash'));
        $this->assertTrue($events[1]['branchCreated']);

        // getEvent() reports the first of them
        $this->assertSame($events[0], $adapter->getEvent(static::$pushEventName, $payload));
    }

    /**
     * A tag-only push has no branch to report at all.
     */
    public function testGetEventTagPushIsNotReportedAsBranch(): void
    {
        $payload = (string) json_encode([
            'repository' => $this->eventRepository(),
            'push' => ['changes' => [['new' => ['type' => 'tag', 'name' => 'v1.0.0', 'target' => ['hash' => 'aaa111']]]]],
        ]);

        $this->assertSame([], $this->vcsAdapter->getEvent(static::$pushEventName, $payload));
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

    /**
     * The repository id an event reports has to be one the adapter can resolve,
     * which for Bitbucket means the pair it routes on rather than the uuid the
     * payload also carries.
     */
    public function testGetEventReportsResolvableRepositoryId(): void
    {
        $repositoryName = 'test-event-repository-id-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $payload = (string) json_encode([
                'repository' => [
                    'uuid' => $created['uuid'] ?? '',
                    'name' => $repositoryName,
                    'full_name' => $created['full_name'] ?? '',
                    'workspace' => ['slug' => $this->ownerPath()],
                ],
                'push' => ['changes' => [['new' => ['type' => 'branch', 'name' => static::$defaultBranch]]]],
            ]);

            $event = $this->vcsAdapter->getEvent(static::$pushEventName, $payload);

            $this->assertSame($this->repositoryIdOf($created), $event['repositoryId']);
            $this->assertSame($repositoryName, $this->vcsAdapter->getRepositoryName($event['repositoryId']));
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }
}
