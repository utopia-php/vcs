<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;
use Utopia\VCS\Exception\FileNotFound;

class Gitea extends Git
{
    public const CONTENTS_FILE = 'file';

    public const CONTENTS_DIRECTORY = 'dir';

    protected string $endpoint = 'http://gitea:3000/api/v1';

    protected string $accessToken;

    protected ?string $refreshToken = null;

    protected string $giteaUrl;

    protected Cache $cache;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function setEndpoint(string $endpoint): void
    {
        $this->giteaUrl = rtrim($endpoint, '/');
        $this->endpoint = $this->giteaUrl . '/api/v1';
    }

    /**
     * Get Adapter Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'gitea';
    }

    /**
     * Gitea Initialisation with access token from OAuth2 flow.
     *
     * Note: Gitea uses OAuth2 instead of GitHub's App Installation flow.
     * The parameters are adapted to maintain interface compatibility:
     * - $installationId is used to pass the access token
     * - $privateKey is used to pass the refresh token
     * - $appId is used to pass the Gitea instance URL
     * - $accessToken is used to pass the access token
     * - $refreshToken is used to pass the refresh token
     */
    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if (!empty($accessToken)) {
            $this->accessToken = $accessToken;
            $this->refreshToken = $refreshToken;
            return;
        }

        throw new Exception("accessToken is required for Gitea adapter.");
    }

    /**
     * Generate Access Token
     *
     * Note: This method is required by the Adapter interface but is not used for Gitea.
     * Gitea uses OAuth2 tokens that are provided directly via initializeVariables().
     */
    protected function generateAccessToken(string $privateKey, string $appId): void
    {
        // Not applicable for Gitea - OAuth2 tokens are passed directly
        return;
    }

    /**
     * Create new repository
     *
     * @return array<mixed> Details of new repository
     */
    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/orgs/{$owner}/repos";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], [
            'name' => $repositoryName,
            'private' => $private,
        ]);

        return $response['body'] ?? [];
    }

    public function createOrganization(string $orgName): string
    {
        $url = "/orgs";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], [
            'username' => $orgName,
            'visibility' => 'public',
        ]);

        return $response['body']['name'] ?? '';
    }

    // Stub methods to satisfy abstract class requirements
    // These will be implemented in follow-up PRs

    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $statusCode = $response['headers']['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new RepositoryNotFound("Repository not found");
        }

        return $response['body'];
    }

    public function getRepositoryName(string $repositoryId): string
    {
        $url = "/repositories/{$repositoryId}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        if (!isset($response['body']['name'])) {
            throw new RepositoryNotFound("Repository not found");
        }

        return $response['body']['name'];
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/git/trees/" . urlencode($branch) . ($recursive ? '?recursive=1' : '');

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        if (($response['headers']['status-code'] ?? 0) === 404) {
            return [];
        }

        return array_column($response['body']['tree'] ?? [], 'path');
    }

    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/languages";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        if (isset($response['body'])) {
            return array_keys($response['body']);
        }

        return [];
    }

    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/contents/{$path}";
        if (!empty($ref)) {
            $url .= "?ref=" . urlencode($ref);
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        if (($response['headers']['status-code'] ?? 0) !== 200) {
            throw new FileNotFound();
        }

        $encoding = $response['body']['encoding'] ?? '';
        $content = '';

        if ($encoding === 'base64') {
            $content = base64_decode($response['body']['content'] ?? '');
        } else {
            throw new FileNotFound();
        }

        return [
            'sha' => $response['body']['sha'] ?? '',
            'size' => $response['body']['size'] ?? 0,
            'content' => $content
        ];
    }

    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/contents";
        if (!empty($path)) {
            $url .= "/{$path}";
        }
        if (!empty($ref)) {
            $url .= "?ref=" . urlencode($ref);
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        if (($response['headers']['status-code'] ?? 0) === 404) {
            return [];
        }

        $items = [];
        if (!empty($response['body'][0])) {
            $items = $response['body'];
        } elseif (!empty($response['body'])) {
            $items = [$response['body']];
        }

        $contents = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? 'file';
            $contents[] = [
                'name' => $item['name'] ?? '',
                'size' => $item['size'] ?? 0,
                'type' => $type === 'file' ? self::CONTENTS_FILE : self::CONTENTS_DIRECTORY
            ];
        }

        return $contents;
    }

    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => "token $this->accessToken"]);

        $statusCode = $response['headers']['status-code'];

        if ($statusCode >= 400) {
            throw new Exception("Deleting repository {$repositoryName} failed with status code {$statusCode}");
        }

        return true;
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        throw new Exception("Not implemented yet");
    }

    public function updateComment(string $owner, string $repositoryName, int $commentId, string $comment): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getUser(string $username): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getOwnerName(string $installationId): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        throw new Exception("Not implemented yet");
    }

    public function listBranches(string $owner, string $repositoryName): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        throw new Exception("Not implemented yet");
    }

    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        throw new Exception("Not implemented yet");
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getEvent(string $event, string $payload): array
    {
        throw new Exception("Not implemented yet");
    }

    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        throw new Exception("Not implemented yet");
    }
}
