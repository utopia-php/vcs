<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Origin;

class OriginTest extends Base
{
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';
    /** @var array<string> */
    protected static array $supportedWebhookScopes = [Origin::WEBHOOK_SCOPE_INSTALLATION];

    protected static string $eventHeader = 'webhook-event-type';
    protected static string $signatureHeader = 'webhook-signature';

    protected static string $pushEventName = 'repository.pushed';
    protected static string $pullRequestEventName = 'pull_request.created';

    // Origin's partner API has no visibility flags, archive downloads,
    // language statistics, user lookup, per-repository webhooks, or namespace
    // listing. Repository deletion rides the Cursor web app's own API, and
    // commit statuses ride the check run upsert.
    protected static bool $reportsRepositoryVisibility = false;
    protected static bool $supportsRepositoryArchives = false;
    protected static bool $supportsRepositoryLanguages = false;
    protected static bool $supportsUserLookup = false;
    protected static bool $supportsNamespaceListing = false;
    protected static bool $supportsWebhookDelivery = false;
    protected static bool $resolvesOwnerFromRepositoryId = false;

    // Push deliveries carry ref updates, not per-commit file lists, and
    // commits report plain git identities without linked accounts
    protected static bool $reportsAffectedFilesInPushEvent = false;
    protected static bool $reportsCommitAuthorAvatar = false;
    protected static bool $reportsCommitAuthorUrl = false;

    protected function setupAdapter(): void
    {
        $privateKey = \str_replace('\\n', "\n", System::getEnv('TESTS_ORIGIN_PRIVATE_KEY') ?? '');
        $appId = System::getEnv('TESTS_ORIGIN_APP_IDENTIFIER') ?? '';
        static::$installationId = System::getEnv('TESTS_ORIGIN_INSTALLATION_ID') ?? '';

        if (empty($privateKey) || empty($appId) || empty(static::$installationId)) {
            $this->markTestSkipped('Origin app credentials not configured');
        }

        $adapter = new Origin(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: static::$installationId,
            privateKey: $privateKey,
            appId: $appId,
            accessToken: '',
            refreshToken: ''
        );

        if (empty(static::$owner)) {
            static::$owner = $adapter->getOwnerName(static::$installationId);
        }

        $this->vcsAdapter = $adapter;
    }

    /**
     * Origin reports the repository owner as a reference carrying the slug.
     *
     * @param array<string, mixed> $repository
     */
    protected function ownerOf(array $repository): string
    {
        $this->assertArrayHasKey('owner', $repository);
        $this->assertIsArray($repository['owner']);
        $this->assertArrayHasKey('slug', $repository['owner']);

        return (string) $repository['owner']['slug'];
    }

    /**
     * Origin signs webhooks with an Ed25519 key rather than an HMAC secret;
     * $secret carries the base64-encoded libsodium secret key.
     */
    protected function signWebhookPayload(string $payload, string $secret): string
    {
        $secretKey = \base64_decode($secret, true);
        if ($secretKey === false || \strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->fail('The Origin webhook signer needs a base64-encoded Ed25519 secret key.');
        }

        // Origin signs the lowercase hex SHA-256 digest of "<id>.<timestamp>.<body>"
        $signature = \sodium_crypto_sign_detached(\hash('sha256', $payload), $secretKey);

        return 'v1ed,' . \base64_encode($signature);
    }

    /**
     * The generic HMAC round-trip does not apply: Origin verification takes
     * the delivery's signed content and Origin's Ed25519 public key.
     */
    public function testValidateWebhookEvent(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secret = \base64_encode(\sodium_crypto_sign_secretkey($keyPair));
        $publicKey = \base64_encode(\sodium_crypto_sign_publickey($keyPair));

        $payload = 'whd_0123456789.1755500000.{"deliveryId":"whd_0123456789"}';

        $this->assertTrue(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $secret), $publicKey)
        );
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, 'not-the-signature', $publicKey));

        // A signature by a different key must not verify
        $otherSecret = \base64_encode(\sodium_crypto_sign_secretkey(\sodium_crypto_sign_keypair()));
        $this->assertFalse(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $otherSecret), $publicKey)
        );

        // Tampered content must not verify either
        $this->assertFalse(
            $this->vcsAdapter->validateWebhookEvent($payload . 'tampered', $this->signWebhookPayload($payload, $secret), $publicKey)
        );
    }

    public function testValidateWebhookEventAcceptsPemPublicKey(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secret = \base64_encode(\sodium_crypto_sign_secretkey($keyPair));

        // SPKI wrapping of a raw Ed25519 public key
        $der = \hex2bin('302a300506032b6570032100') . \sodium_crypto_sign_publickey($keyPair);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . \chunk_split(\base64_encode((string) $der), 64, "\n") . '-----END PUBLIC KEY-----';

        $payload = 'whd_0123456789.1755500000.{"deliveryId":"whd_0123456789"}';

        $this->assertTrue(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $secret), $pem)
        );
    }

    /**
     * Build a delivery the way Origin wraps a push: an envelope whose event
     * payload carries the repository reference and one ref update per pushed
     * ref. File lists never travel in push deliveries, so $added, $removed
     * and $modified stay unused.
     *
     * @param array<string> $added
     * @param array<string> $removed
     * @param array<string> $modified
     */
    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string
    {
        return (string) \json_encode([
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'repository.pushed',
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'repository' => [
                        'id' => self::EVENT_REPOSITORY_ID,
                        'name' => self::EVENT_REPOSITORY_NAME,
                        'owner' => ['slug' => self::EVENT_OWNER, 'id' => 'ns_0123456789'],
                    ],
                    'refUpdates' => [[
                        'ref' => 'refs/heads/' . $branch,
                        'before' => $created ? \str_repeat('0', 40) : 'abc123',
                        'after' => $deleted ? \str_repeat('0', 40) : self::EVENT_COMMIT_HASH,
                        'created' => $created,
                        'deleted' => $deleted,
                        'forced' => false,
                        'headCommit' => $deleted ? null : [
                            'sha' => self::EVENT_COMMIT_HASH,
                            'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
                            'committer' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
                            'message' => self::EVENT_COMMIT_MESSAGE,
                        ],
                    ]],
                    'refUpdatesCount' => 1,
                    'pushedAt' => '2026-08-18T10:00:00Z',
                    'pusher' => ['user' => ['id' => 'user_0123456789', 'email' => self::EVENT_AUTHOR_EMAIL]],
                ],
            ],
        ]);
    }

    /**
     * Build a delivery the way Origin announces an opened pull request. The
     * event type carries the action; there is no separate action field.
     */
    protected function pullRequestPayload(bool $external = false): string
    {
        return (string) \json_encode([
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'pull_request.created',
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'pullRequest' => [
                        'id' => 'pr_0123456789',
                        'number' => (string) self::EVENT_PULL_REQUEST_NUMBER,
                        'state' => 'open',
                        'draft' => false,
                        'merged' => false,
                        'title' => 'Test PR',
                        'body' => '',
                        'head' => ['ref' => 'refs/heads/' . self::EVENT_HEAD_BRANCH, 'sha' => self::EVENT_COMMIT_HASH],
                        'base' => ['ref' => 'refs/heads/' . static::$defaultBranch, 'sha' => 'abc123'],
                        'author' => ['user' => ['id' => 'user_0123456789', 'email' => self::EVENT_AUTHOR_EMAIL]],
                    ],
                    'repository' => [
                        'id' => self::EVENT_REPOSITORY_ID,
                        'name' => self::EVENT_REPOSITORY_NAME,
                        'owner' => ['slug' => self::EVENT_OWNER, 'id' => 'ns_0123456789'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Origin pull requests always open from a branch of the same repository -
     * there is no fork model - so no delivery can describe an external one.
     */
    public function testGetEventPullRequestDetectsExternal(): void
    {
        $events = $this->vcsAdapter->getEvents(static::$pullRequestEventName, $this->pullRequestPayload(external: true));
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['external']);
    }

    public function testGetEventPullRequestMapsLifecycleActions(): void
    {
        $payload = \json_decode($this->pullRequestPayload(), true);
        $this->assertIsArray($payload);

        $expected = [
            'pull_request.created' => 'opened',
            'pull_request.head_ref.pushed' => 'synchronize',
            'pull_request.base_ref.updated' => 'edited',
            'pull_request.metadata.updated' => 'edited',
            'pull_request.closed' => 'closed',
            'pull_request.merged' => 'closed',
            'pull_request.reopened' => 'reopened',
            'pull_request.published' => 'ready_for_review',
        ];

        foreach ($expected as $type => $action) {
            $payload['event']['type'] = $type;
            $events = $this->vcsAdapter->getEvents($type, (string) \json_encode($payload));
            $this->assertCount(1, $events);
            $this->assertSame($action, $events[0]['action'], "Unexpected action for {$type}");
        }

        // Comment, review and reviewer deliveries are not lifecycle events
        $payload['event']['type'] = 'pull_request.comment.created';
        $this->assertSame([], $this->vcsAdapter->getEvents('pull_request.comment.created', (string) \json_encode($payload)));
    }

    public function testGetEventPushWithMultipleRefUpdates(): void
    {
        $payload = \json_decode($this->pushPayload(static::$defaultBranch), true);
        $this->assertIsArray($payload);

        $secondRef = $payload['event']['payload']['refUpdates'][0];
        $secondRef['ref'] = 'refs/heads/feature-branch';
        $payload['event']['payload']['refUpdates'][] = $secondRef;
        $payload['event']['payload']['refUpdatesCount'] = 2;

        $events = $this->vcsAdapter->getEvents(static::$pushEventName, (string) \json_encode($payload));

        $this->assertCount(2, $events);
        $this->assertSame(static::$defaultBranch, $events[0]['branch']);
        $this->assertSame('feature-branch', $events[1]['branch']);
    }

    public function testGetEventInstallation(): void
    {
        $payload = (string) \json_encode([
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'installation.deleted',
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'installation' => [
                        'id' => 'i_0123456789',
                        'target' => ['slug' => 'test-workspace', 'id' => 'ns_0123456789'],
                        'repoSelectionMode' => 'all',
                    ],
                    'app' => ['id' => 'app_0123456789', 'slug' => 'test-app'],
                ],
            ],
        ]);

        $events = $this->vcsAdapter->getEvents('installation.deleted', $payload);
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame('deleted', $result['action']);
        $this->assertSame('i_0123456789', $result['installationId']);
        $this->assertSame('test-workspace', $result['userName']);
    }

    /**
     * The adapter under test, with its Origin-specific surface visible.
     */
    private function origin(): Origin
    {
        \assert($this->vcsAdapter instanceof Origin);

        return $this->vcsAdapter;
    }

    /**
     * @return array{string, int} Repository name and pull request number
     */
    private function createRepositoryWithPullRequest(string $prefix): array
    {
        $repositoryName = $prefix . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
        $this->getLatestCommitEventually($repositoryName);
        $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-branch', static::$defaultBranch);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'feature content', 'Add feature', 'feature-branch');

        $pullRequest = $this->vcsAdapter->createPullRequest(
            static::$owner,
            $repositoryName,
            'Test PR',
            'feature-branch',
            static::$defaultBranch,
            'Test PR description'
        );

        return [$repositoryName, $this->pullRequestNumberOf($pullRequest)];
    }

    public function testUpdatePullRequest(): void
    {
        [$repositoryName, $prNumber] = $this->createRepositoryWithPullRequest('test-update-pr-');

        try {
            $updated = $this->origin()->updatePullRequest(static::$owner, $repositoryName, $prNumber, title: 'Updated title', body: 'Updated body');
            $this->assertSame('Updated title', $updated['title']);
            $this->assertSame('Updated body', $updated['body']);

            $closed = $this->origin()->updatePullRequest(static::$owner, $repositoryName, $prNumber, state: 'closed');
            $this->assertSame('closed', $closed['state']);

            $reopened = $this->origin()->updatePullRequest(static::$owner, $repositoryName, $prNumber, state: 'open');
            $this->assertSame('open', $reopened['state']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testMergePullRequest(): void
    {
        [$repositoryName, $prNumber] = $this->createRepositoryWithPullRequest('test-merge-pr-');

        try {
            $result = $this->origin()->mergePullRequest(static::$owner, $repositoryName, $prNumber);

            $this->assertNotEmpty($result['mergeCommitSha']);
            $this->assertContains($prNumber, $result['mergedPullNumbers']);
            $this->assertTrue($result['pullRequest']['merged']);

            // The merged file has to be readable from the base branch
            $this->assertEventually(function () use ($repositoryName) {
                $content = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'feature.txt', static::$defaultBranch);
                $this->assertSame('feature content', $content['content']);
            });
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testListPullRequests(): void
    {
        [$repositoryName, $prNumber] = $this->createRepositoryWithPullRequest('test-list-prs-');

        try {
            $open = $this->origin()->listPullRequests(static::$owner, $repositoryName);
            $this->assertContains($prNumber, \array_column($open, 'number'));

            $this->assertSame([], $this->origin()->listPullRequests(static::$owner, $repositoryName, 'closed'));

            $byHead = $this->origin()->listPullRequests(static::$owner, $repositoryName, 'open', 'feature-branch');
            $this->assertContains($prNumber, \array_column($byHead, 'number'));
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testListPullRequestCommentsAndCommits(): void
    {
        [$repositoryName, $prNumber] = $this->createRepositoryWithPullRequest('test-pr-comments-commits-');

        try {
            $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, 'A listed comment');

            $comments = $this->origin()->listPullRequestComments(static::$owner, $repositoryName, $prNumber);
            $this->assertContains($commentId, \array_column($comments, 'id'));

            $commits = $this->origin()->listPullRequestCommits(static::$owner, $repositoryName, $prNumber);
            $this->assertNotEmpty($commits);
            $messages = \array_map(fn ($commit) => $commit['commit']['message'] ?? '', $commits);
            $this->assertNotEmpty(\array_filter($messages, fn ($message) => \str_starts_with($message, 'Add feature')));
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testPullRequestReviews(): void
    {
        [$repositoryName, $prNumber] = $this->createRepositoryWithPullRequest('test-pr-reviews-');

        try {
            // The app authored the pull request, and authors cannot approve
            // their own change, so exercise request_changes instead
            $review = $this->origin()->createPullRequestReview(static::$owner, $repositoryName, $prNumber, 'request_changes', 'Needs work');
            $this->assertNotEmpty($review['id']);
            $this->assertSame('request_changes', $review['verdict']);

            $reviews = $this->origin()->listPullRequestReviews(static::$owner, $repositoryName, $prNumber);
            $this->assertContains($review['id'], \array_column($reviews, 'id'));

            $updated = $this->origin()->updatePullRequestReview(static::$owner, $repositoryName, $prNumber, $review['id'], 'Needs more work');
            $this->assertSame('Needs more work', $updated['body']);

            $dismissed = $this->origin()->dismissPullRequestReview(static::$owner, $repositoryName, $prNumber, $review['id'], 'Handled offline');
            $this->assertArrayHasKey('dismissal', $dismissed);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testListCommitsAndCommitFiles(): void
    {
        $repositoryName = 'test-list-commits-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test', 'First commit');
            $this->getLatestCommitEventually($repositoryName);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'second.txt', 'second', 'Second commit');

            $commits = [];
            $this->assertEventually(function () use (&$commits, $repositoryName) {
                $commits = $this->origin()->listCommits(static::$owner, $repositoryName);
                $this->assertCount(2, $commits);
            });

            $this->assertStringStartsWith('Second commit', $commits[0]['commitMessage']);
            $this->assertNotEmpty($commits[0]['commitHash']);
            $this->assertNotEmpty($commits[0]['commitUrl']);

            $files = $this->origin()->listCommitFiles(static::$owner, $repositoryName, $commits[0]['commitHash']);
            $this->assertContains('second.txt', \array_column($files, 'filename'));
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testCompareCommits(): void
    {
        $repositoryName = 'test-compare-commits-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test', 'First commit');
            $first = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'second.txt', 'second', 'Second commit');
            $second = '';
            $this->assertEventually(function () use (&$second, $repositoryName, $first) {
                $second = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch)['commitHash'];
                $this->assertNotSame($first, $second);
            });

            $comparison = $this->origin()->compareCommits(static::$owner, $repositoryName, $first, $second);
            $this->assertSame('ahead', $comparison['status']);
            $this->assertSame(1, $comparison['aheadBy']);

            $this->assertSame('identical', $this->origin()->compareCommits(static::$owner, $repositoryName, $first, $first)['status']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetBlob(): void
    {
        $repositoryName = 'test-get-blob-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Blob content');

            $file = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'README.md');
            $blob = $this->origin()->getBlob(static::$owner, $repositoryName, (string) $file['sha']);

            $this->assertSame('# Blob content', $blob['content']);
            $this->assertSame($file['sha'], $blob['sha']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testBatchGetRepositoryContents(): void
    {
        $repositoryName = 'test-batch-get-contents-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php');

            $contents = [];
            $this->assertEventually(function () use (&$contents, $repositoryName) {
                $contents = $this->origin()->batchGetRepositoryContents(
                    static::$owner,
                    $repositoryName,
                    ['README.md', 'src/main.php', 'missing.txt']
                );
                $this->assertSame('# Test', $contents['README.md'] ?? null);
            });

            $this->assertSame('<?php', $contents['src/main.php']);
            $this->assertNull($contents['missing.txt']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testBatchUpsertAndListCheckRuns(): void
    {
        $repositoryName = 'test-batch-check-runs-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $checkRuns = $this->origin()->batchUpsertCheckRuns(static::$owner, $repositoryName, $commitHash, 'ci', [
                ['name' => 'ci/build', 'status' => 'in_progress'],
                ['name' => 'ci/lint', 'conclusion' => 'success', 'title' => 'Lint passed', 'summary' => 'No issues.'],
            ]);

            $this->assertCount(2, $checkRuns);
            $this->assertSame('in_progress', $checkRuns[0]['status']);
            $this->assertSame('success', $checkRuns[1]['conclusion']);

            $forCommit = $this->origin()->listCheckRunsForCommit(static::$owner, $repositoryName, $commitHash);
            $names = \array_column($forCommit, 'name');
            $this->assertContains('ci/build', $names);
            $this->assertContains('ci/lint', $names);

            $suiteId = (string) $checkRuns[0]['check_suite_id'];
            $this->assertNotEmpty($suiteId);
            $this->assertSame('ci', $this->origin()->getCheckSuite(static::$owner, $repositoryName, $suiteId)['key']);

            $forSuite = $this->origin()->listCheckRunsForSuite(static::$owner, $repositoryName, $suiteId);
            $this->assertCount(2, $forSuite);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetRateLimit(): void
    {
        $rateLimit = $this->origin()->getRateLimit();

        $this->assertGreaterThan(0, $rateLimit['limit']);
        $this->assertGreaterThanOrEqual(0, $rateLimit['remaining']);
        $this->assertGreaterThan(0, $rateLimit['reset']);
    }

    public function testGetAuthenticatedApp(): void
    {
        $app = $this->origin()->getAuthenticatedApp();

        $this->assertNotEmpty($app['id']);
        $this->assertNotEmpty($app['slug']);
    }

    public function testListInstallations(): void
    {
        $installations = $this->origin()->listInstallations();

        $this->assertContains(static::$installationId, \array_column($installations, 'id'));
    }

    public function testListWebhookDeliveries(): void
    {
        $page = $this->origin()->listWebhookDeliveries();

        $this->assertIsArray($page['deliveries']);
        $this->assertIsString($page['nextPageToken']);
    }
}
