<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Origin;

/**
 * Exercises everything Origin can prove without credentials: webhook
 * signature verification and delivery parsing, which never touch the
 * network. The live half of the shared adapter suite cannot run at all -
 * see testLiveAdapterSuite().
 */
class OriginTest extends TestCase
{
    protected const REPOSITORY_ID = 'repo_0123456789';
    protected const REPOSITORY_NAME = 'test-repo';
    protected const OWNER = 'test-owner';
    protected const COMMIT_HASH = 'def4567890def4567890def4567890def4567890';
    protected const COMMIT_MESSAGE = 'Test commit message';
    protected const AUTHOR_NAME = 'Test Author';
    protected const AUTHOR_EMAIL = 'author@example.com';
    protected const HEAD_BRANCH = 'feature-branch';
    protected const PULL_REQUEST_NUMBER = 42;

    protected Origin $adapter;

    /**
     * Verification and parsing need no installation, so the adapter stays
     * uninitialized - initializeVariables() would reach for the network.
     */
    protected function setUp(): void
    {
        $this->adapter = new Origin(new Cache(new None()));
    }

    /**
     * Sign a payload the way Origin signs its deliveries: an Ed25519
     * signature over the lowercase hex SHA-256 digest of
     * "<id>.<timestamp>.<body>". $secretKey is a libsodium secret key.
     *
     * @param non-empty-string $secretKey
     */
    protected function signWebhookPayload(string $payload, string $secretKey): string
    {
        return 'v1ed,' . \base64_encode(\sodium_crypto_sign_detached(\hash('sha256', $payload), $secretKey));
    }

    public function testValidateWebhookEvent(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secretKey = \sodium_crypto_sign_secretkey($keyPair);
        $publicKey = \base64_encode(\sodium_crypto_sign_publickey($keyPair));

        $payload = 'whd_0123456789.1755500000.{"deliveryId":"whd_0123456789"}';
        $signature = $this->signWebhookPayload($payload, $secretKey);

        $this->assertTrue($this->adapter->validateWebhookEvent($payload, $signature, $publicKey));
        $this->assertFalse($this->adapter->validateWebhookEvent($payload, 'not-the-signature', $publicKey));

        // A signature by a different key must not verify
        $otherSecretKey = \sodium_crypto_sign_secretkey(\sodium_crypto_sign_keypair());
        $this->assertFalse(
            $this->adapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $otherSecretKey), $publicKey)
        );

        // Tampered content must not verify either
        $this->assertFalse($this->adapter->validateWebhookEvent($payload . 'tampered', $signature, $publicKey));

        // During key rotation the header carries several space-separated
        // signatures, and any one of them verifying is enough
        $this->assertTrue($this->adapter->validateWebhookEvent(
            $payload,
            $this->signWebhookPayload($payload, $otherSecretKey) . ' ' . $signature,
            $publicKey
        ));
    }

    public function testValidateWebhookEventAcceptsPemPublicKey(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();

        // SPKI wrapping of a raw Ed25519 public key
        $der = \hex2bin('302a300506032b6570032100') . \sodium_crypto_sign_publickey($keyPair);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . \chunk_split(\base64_encode((string) $der), 64, "\n") . '-----END PUBLIC KEY-----';

        $payload = 'whd_0123456789.1755500000.{"deliveryId":"whd_0123456789"}';
        $signature = $this->signWebhookPayload($payload, \sodium_crypto_sign_secretkey($keyPair));

        $this->assertTrue($this->adapter->validateWebhookEvent($payload, $signature, $pem));
    }

    /**
     * Build a delivery the way Origin wraps a push: an envelope whose event
     * payload carries the repository reference and one ref update per
     * pushed ref.
     *
     * @return array<string, mixed>
     */
    protected function pushPayload(string $branch, bool $created = false, bool $deleted = false): array
    {
        return [
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'repository.pushed',
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'repository' => [
                        'id' => self::REPOSITORY_ID,
                        'name' => self::REPOSITORY_NAME,
                        'owner' => ['slug' => self::OWNER, 'id' => 'ns_0123456789'],
                    ],
                    'refUpdates' => [[
                        'ref' => 'refs/heads/' . $branch,
                        'before' => $created ? \str_repeat('0', 40) : 'abc123',
                        'after' => $deleted ? \str_repeat('0', 40) : self::COMMIT_HASH,
                        'created' => $created,
                        'deleted' => $deleted,
                        'forced' => false,
                        'headCommit' => $deleted ? null : [
                            'sha' => self::COMMIT_HASH,
                            'author' => ['name' => self::AUTHOR_NAME, 'email' => self::AUTHOR_EMAIL],
                            'committer' => ['name' => self::AUTHOR_NAME, 'email' => self::AUTHOR_EMAIL],
                            'message' => self::COMMIT_MESSAGE,
                        ],
                    ]],
                    'refUpdatesCount' => 1,
                    'pushedAt' => '2026-08-18T10:00:00Z',
                    'pusher' => ['user' => ['id' => 'user_0123456789', 'email' => self::AUTHOR_EMAIL]],
                ],
            ],
        ];
    }

    /**
     * Build a delivery the way Origin announces a pull request event. The
     * event type carries the action; there is no separate action field.
     *
     * @return array<string, mixed>
     */
    protected function pullRequestPayload(string $type = 'pull_request.created'): array
    {
        return [
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => $type,
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'pullRequest' => [
                        'id' => 'pr_0123456789',
                        'number' => (string) self::PULL_REQUEST_NUMBER,
                        'state' => 'open',
                        'draft' => false,
                        'merged' => false,
                        'title' => 'Test PR',
                        'body' => '',
                        'head' => ['ref' => 'refs/heads/' . self::HEAD_BRANCH, 'sha' => self::COMMIT_HASH],
                        'base' => ['ref' => 'refs/heads/main', 'sha' => 'abc123'],
                        'author' => ['user' => ['id' => 'user_0123456789', 'email' => self::AUTHOR_EMAIL]],
                    ],
                    'repository' => [
                        'id' => self::REPOSITORY_ID,
                        'name' => self::REPOSITORY_NAME,
                        'owner' => ['slug' => self::OWNER, 'id' => 'ns_0123456789'],
                    ],
                ],
            ],
        ];
    }

    public function testGetEventPush(): void
    {
        $events = $this->adapter->getEvents('repository.pushed', (string) \json_encode($this->pushPayload('main')));

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('main', $event['branch']);
        $this->assertSame(self::REPOSITORY_ID, $event['repositoryId']);
        $this->assertSame(self::REPOSITORY_NAME, $event['repositoryName']);
        $this->assertSame(self::OWNER, $event['owner']);
        $this->assertSame('i_0123456789', $event['installationId']);
        $this->assertSame(self::COMMIT_HASH, $event['commitHash']);
        $this->assertSame(self::COMMIT_MESSAGE, $event['headCommitMessage']);
        $this->assertSame(self::AUTHOR_NAME, $event['headCommitAuthorName']);
        $this->assertSame(self::AUTHOR_EMAIL, $event['headCommitAuthorEmail']);
        $this->assertFalse($event['branchCreated']);
        $this->assertFalse($event['branchDeleted']);
        $this->assertFalse($event['external']);
        // Origin push deliveries carry no per-commit file lists
        $this->assertSame([], $event['affectedFiles']);
        $this->assertStringContainsString(self::REPOSITORY_NAME, \strval($event['repositoryUrl']));
        $this->assertNotEmpty($event['branchUrl']);
        $this->assertNotEmpty($event['headCommitUrl']);
    }

    public function testGetEventPushBranchLifecycle(): void
    {
        $created = $this->adapter->getEvents('repository.pushed', (string) \json_encode($this->pushPayload('new-branch', created: true)));
        $this->assertTrue($created[0]['branchCreated']);
        $this->assertFalse($created[0]['branchDeleted']);

        $deleted = $this->adapter->getEvents('repository.pushed', (string) \json_encode($this->pushPayload('old-branch', deleted: true)));
        $this->assertFalse($deleted[0]['branchCreated']);
        $this->assertTrue($deleted[0]['branchDeleted']);
    }

    public function testGetEventPushWithMultipleRefUpdates(): void
    {
        $payload = $this->pushPayload('main');

        $secondRef = $payload['event']['payload']['refUpdates'][0];
        $secondRef['ref'] = 'refs/heads/feature-branch';
        $payload['event']['payload']['refUpdates'][] = $secondRef;
        $payload['event']['payload']['refUpdatesCount'] = 2;

        $events = $this->adapter->getEvents('repository.pushed', (string) \json_encode($payload));

        $this->assertCount(2, $events);
        $this->assertSame('main', $events[0]['branch']);
        $this->assertSame('feature-branch', $events[1]['branch']);
    }

    public function testGetEventPullRequest(): void
    {
        $events = $this->adapter->getEvents('pull_request.created', (string) \json_encode($this->pullRequestPayload()));

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('opened', $event['action']);
        $this->assertSame(self::PULL_REQUEST_NUMBER, $event['pullRequestNumber']);
        $this->assertSame(self::HEAD_BRANCH, $event['branch']);
        $this->assertSame(self::REPOSITORY_ID, $event['repositoryId']);
        $this->assertSame(self::OWNER, $event['owner']);
        $this->assertSame(self::COMMIT_HASH, $event['commitHash']);

        // Origin pull requests always open from a branch of the same
        // repository - there is no fork model - so none is ever external
        $this->assertFalse($event['external']);
    }

    public function testGetEventPullRequestMapsLifecycleActions(): void
    {
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
            $events = $this->adapter->getEvents($type, (string) \json_encode($this->pullRequestPayload($type)));
            $this->assertCount(1, $events);
            $this->assertSame($action, $events[0]['action'], "Unexpected action for {$type}");
        }

        // Comment, review and reviewer deliveries are not lifecycle events
        $type = 'pull_request.comment.created';
        $this->assertSame([], $this->adapter->getEvents($type, (string) \json_encode($this->pullRequestPayload($type))));
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

        $events = $this->adapter->getEvents('installation.deleted', $payload);

        $this->assertCount(1, $events);
        $this->assertSame('deleted', $events[0]['action']);
        $this->assertSame('i_0123456789', $events[0]['installationId']);
        $this->assertSame('test-workspace', $events[0]['userName']);
    }

    public function testGetEventsRejectsInvalidPayload(): void
    {
        $this->expectException(\Exception::class);
        $this->adapter->getEvents('repository.pushed', 'not json');
    }

    public function testLiveAdapterSuite(): void
    {
        $this->markTestSkipped(
            'Origin cannot run the shared live suite: the partner API does not allow app installations to create repositories, and it has no repository deletion endpoint, so fixture repositories can neither be provisioned nor cleaned up.'
        );
    }
}
