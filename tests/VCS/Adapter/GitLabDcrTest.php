<?php

namespace Utopia\Tests\Adapter;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitLab;

/**
 * Unit tests for GitLab Dynamic Client Registration (RFC 7591).
 *
 * These run without a real GitLab instance: callDcr() is stubbed on
 * GitLabDcrStub so no network call is ever made.
 */
class GitLabDcrTest extends TestCase
{
    /**
     * @param  array{statusCode: int, body: mixed, raw: string, isJson: bool}|callable  $response
     */
    private function makeAdapter($response): GitLabDcrStub
    {
        $adapter = new GitLabDcrStub(new Cache(new None()));
        $adapter->response = $response;

        return $adapter;
    }

    public function testRegisterDynamicClientSuccess(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 201,
            'body' => [
                'client_id' => 'dcr-client-123',
                'client_name' => 'Appwrite VCS Integration',
                'redirect_uris' => ['https://appwrite.example.com/callback'],
            ],
            'raw' => '{}',
            'isJson' => true,
        ]);

        $result = $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');

        $this->assertSame('dcr-client-123', $result['client_id']);
        $this->assertNotEmpty($result['client_id']);
        $this->assertArrayNotHasKey('client_secret', $result);

        $this->assertCount(1, $adapter->calls);
        $this->assertSame('POST', $adapter->calls[0]['method']);
        $this->assertSame('https://gitlab.example.com/oauth/register', $adapter->calls[0]['url']);
        $this->assertSame('none', $adapter->calls[0]['params']['token_endpoint_auth_method']);
        $this->assertSame('api read_user', $adapter->calls[0]['params']['scope']);
        $this->assertSame(['https://appwrite.example.com/callback'], $adapter->calls[0]['params']['redirect_uris']);
    }

    public function testRegisterDynamicClientThrowsOnUnexpectedClientSecret(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 201,
            'body' => [
                'client_id' => 'dcr-client-456',
                'client_secret' => 'should-never-surface',
                'redirect_uris' => ['https://appwrite.example.com/callback'],
            ],
            'raw' => '{}',
            'isJson' => true,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/client_secret/');
        $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');
    }

    public function testRegisterDynamicClientOldGitLab(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 404,
            'body' => null,
            'raw' => 'Not Found',
            'isJson' => false,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/18\.6/');
        $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');
    }

    public function testRegisterDynamicClientDisabled(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 403,
            'body' => null,
            'raw' => 'Forbidden',
            'isJson' => false,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/admin/');
        $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');
    }

    public function testRegisterDynamicClientRateLimited(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 429,
            'body' => null,
            'raw' => 'Too Many Requests',
            'isJson' => false,
        ]);

        try {
            $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');
            $this->fail('Expected an exception to be thrown');
        } catch (Exception $e) {
            $this->assertStringContainsString('rate limit', strtolower($e->getMessage()));
        }

        $this->assertCount(1, $adapter->calls, 'A 429 response must not be retried automatically');
    }

    public function testRegisterDynamicClientMalformedJson(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 500,
            'body' => null,
            'raw' => '<html>Internal Server Error</html>',
            'isJson' => false,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/non-JSON/');
        $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');
    }

    public function testRegisterDynamicClientMissingClientId(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 201,
            'body' => ['redirect_uris' => ['https://appwrite.example.com/callback']],
            'raw' => '{}',
            'isJson' => true,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/client_id/');
        $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');
    }

    public function testRegisterDynamicClientMismatchedRedirectUri(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 201,
            'body' => [
                'client_id' => 'dcr-client-789',
                'redirect_uris' => ['https://someone-else.example.com/callback'],
            ],
            'raw' => '{}',
            'isJson' => true,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/redirect_uris/');
        $adapter->registerDynamicClient('https://gitlab.example.com', 'https://appwrite.example.com/callback');
    }

    public function testRegisterDynamicClientStripsTrailingSlash(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 201,
            'body' => [
                'client_id' => 'dcr-client-999',
                'redirect_uris' => ['https://appwrite.example.com/callback'],
            ],
            'raw' => '{}',
            'isJson' => true,
        ]);

        $adapter->registerDynamicClient('https://gitlab.example.com/', 'https://appwrite.example.com/callback');

        $this->assertSame('https://gitlab.example.com/oauth/register', $adapter->calls[0]['url']);
    }

    public function testRegisterDynamicClientRejectsInvalidEndpoint(): void
    {
        $adapter = $this->makeAdapter(function () {
            $this->fail('HTTP call should not be made for an invalid endpoint');
        });

        $this->expectException(Exception::class);
        $adapter->registerDynamicClient('not-a-url', 'https://appwrite.example.com/callback');
    }

    public function testRegisterDynamicClientRejectsInvalidRedirectUri(): void
    {
        $adapter = $this->makeAdapter(function () {
            $this->fail('HTTP call should not be made for an invalid redirect URI');
        });

        $this->expectException(Exception::class);
        $adapter->registerDynamicClient('https://gitlab.example.com', 'not-a-url');
    }

    public function testDiscoverDcrEndpointSupported(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 200,
            'body' => ['registration_endpoint' => 'https://gitlab.example.com/oauth/register'],
            'raw' => '{}',
            'isJson' => true,
        ]);

        $result = $adapter->discoverDcrEndpoint('https://gitlab.example.com');

        $this->assertSame('https://gitlab.example.com/oauth/register', $result);
        $this->assertSame('GET', $adapter->calls[0]['method']);
        $this->assertSame(
            'https://gitlab.example.com/.well-known/oauth-authorization-server/api/v4/mcp',
            $adapter->calls[0]['url']
        );
    }

    public function testDiscoverDcrEndpointNotSupported(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 404,
            'body' => null,
            'raw' => 'Not Found',
            'isJson' => false,
        ]);

        $this->assertNull($adapter->discoverDcrEndpoint('https://gitlab.example.com'));
    }

    public function testDiscoverDcrEndpointMissingRegistrationEndpoint(): void
    {
        $adapter = $this->makeAdapter([
            'statusCode' => 200,
            'body' => ['token_endpoint' => 'https://gitlab.example.com/oauth/token'],
            'raw' => '{}',
            'isJson' => true,
        ]);

        $this->assertNull($adapter->discoverDcrEndpoint('https://gitlab.example.com'));
    }

    public function testDiscoverDcrEndpointSwallowsExceptions(): void
    {
        $adapter = $this->makeAdapter(function () {
            throw new Exception('network unreachable');
        });

        $this->assertNull($adapter->discoverDcrEndpoint('https://gitlab.example.com'));
    }

    public function testDiscoverDcrEndpointRejectsInvalidEndpoint(): void
    {
        $adapter = $this->makeAdapter(function () {
            $this->fail('HTTP call should not be made for an invalid endpoint');
        });

        $this->assertNull($adapter->discoverDcrEndpoint('not-a-url'));
    }
}

/**
 * Test double that stubs the network-calling half of the GitLab adapter
 * (callDcr) so registerDynamicClient()/discoverDcrEndpoint() can be
 * exercised without a real GitLab instance.
 */
class GitLabDcrStub extends GitLab
{
    /** @var array{statusCode: int, body: mixed, raw: string, isJson: bool}|callable */
    public $response;

    /** @var array<int, array{method: string, url: string, params: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(Cache $cache)
    {
        parent::__construct($cache);
    }

    protected function callDcr(string $method, string $url, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'params' => $params];

        return is_callable($this->response)
            ? ($this->response)($method, $url, $params)
            : $this->response;
    }
}
