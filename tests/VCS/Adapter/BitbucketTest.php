<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git\Bitbucket;

class BitbucketTest extends Base
{
    // Bitbucket routes by "workspace/slug" rather than a numeric id
    protected const EVENT_REPOSITORY_ID = self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

    private const REPOSITORY_URL = 'https://bitbucket.org/' . self::EVENT_REPOSITORY_ID;

    private const REPOSITORY_UUID = '{11111111-2222-3333-4444-555555555555}';

    private const FORK_UUID = '{99999999-2222-3333-4444-555555555555}';

    protected static string $accessToken = '';
    protected static string $owner = '';
    // Bitbucket names an empty repository's first branch 'master'
    protected static string $defaultBranch = 'master';
    protected static string $eventHeader = 'x-event-key';
    protected static string $signatureHeader = 'x-hub-signature';
    protected static string $pushEventName = 'repo:push';
    protected static string $pullRequestEventName = 'pullrequest:created';

    protected static bool $supportsInstallationRepository = false;
    protected static bool $supportsRepositoryLanguages = false;
    protected static bool $reportsAffectedFilesInPushEvent = false;

    // Bitbucket has no repository to resolve an owner from; getOwnerName()
    // reports the account the token belongs to
    protected static bool $resolvesOwnerFromRepositoryId = false;

    // Workspaces have no personal-vs-team distinction to report as 'kind'
    protected static bool $supportsNamespaceListing = false;

    // Accounts are looked up by uuid, not by handle
    protected static bool $supportsUserLookup = false;

    // Bitbucket Cloud can't reach a local test catcher
    protected static bool $supportsWebhookDelivery = false;

    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return 'https://bitbucket.org/' . $this->ownerPath() . '/' . $repositoryName . '.git';
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

        if (empty(static::$owner)) {
            // Fall back to the token's own workspace when none is configured
            static::$owner = System::getEnv('TESTS_BITBUCKET_WORKSPACE') ?: $adapter->getOwnerName('');
        }

        $this->vcsAdapter = $adapter;
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
     * Bitbucket only names the author in a raw "Name <email>" string; a commit
     * linked to an account is named by the account instead.
     */
    public function testGetEventPushWithLinkedAuthor(): void
    {
        $payload = json_decode($this->pushPayload(static::$defaultBranch), true);
        $this->assertIsArray($payload);
        $payload['push']['changes'][0]['new']['target']['author']['user'] = ['display_name' => 'Linked User'];

        $events = $this->vcsAdapter->getEvents(static::$pushEventName, (string) json_encode($payload));
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

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

        $events = $this->vcsAdapter->getEvents(static::$pushEventName, $payload);

        // The tag between them is left out
        $this->assertCount(2, $events);
        $this->assertSame(['main', 'feature'], array_column($events, 'branch'));
        $this->assertSame(['aaa111', 'ccc333'], array_column($events, 'commitHash'));
        $this->assertTrue($events[1]['branchCreated']);

        // A push carrying nothing but tags has no branch to report
        $tagsOnly = (string) json_encode([
            'repository' => $this->eventRepository(),
            'push' => ['changes' => [['new' => ['type' => 'tag', 'name' => 'v1.0.0', 'target' => ['hash' => 'aaa111']]]]],
        ]);

        $this->assertSame([], $this->vcsAdapter->getEvents(static::$pushEventName, $tagsOnly));
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
            $events = $this->vcsAdapter->getEvents($event, $this->pullRequestPayload());
            $this->assertIsArray($events);
            $this->assertCount(1, $events);
            $result = $events[0];

            $this->assertSame($action, $result['action'], "event '{$event}' should map to '{$action}'");
        }
    }
}
