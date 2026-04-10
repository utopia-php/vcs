<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;

class GitLab extends Git
{
    public const CONTENTS_FILE = 'file';
    public const CONTENTS_DIRECTORY = 'dir';

    protected string $endpoint = 'http://gitlab:80/api/v4';
    protected string $gitlabUrl = 'http://gitlab:80';
    protected string $accessToken;
    protected Cache $cache;

    /**
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function setEndpoint(string $endpoint): void
    {
        $this->gitlabUrl = rtrim($endpoint, '/');
        $this->endpoint = $this->gitlabUrl . '/api/v4';
    }

    public function getName(): string
    {
        return 'gitlab';
    }

    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if (!empty($accessToken)) {
            $this->accessToken = $accessToken;
            return;
        }
        throw new Exception("accessToken is required for this adapter.");
    }

    protected function generateAccessToken(string $privateKey, string $appId): void
    {
        return;
    }

    /**
     * Create a new group/organization
     * Returns "id:path" format so both numeric ID and path are available
     */
    public function createOrganization(string $orgName): string
    {
        $url = "/groups";

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], [
            'name' => $orgName,
            'path' => $orgName,
            'visibility' => 'public',
        ]);

        $responseBody = $response['body'] ?? [];
        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Creating organization {$orgName} failed with status code {$statusCode}");
        }

        return ($responseBody['id'] ?? '') . ':' . ($responseBody['path'] ?? '');
    }

    /**
     * Extract owner path from "id:path" format
     */
    private function getOwnerPath(string $owner): string
    {
        if (strstr($owner, ':') !== false) {
            return substr($owner, strpos($owner, ':') + 1);
        }
        return $owner;
    }

    /**
     * Extract namespace ID from "id:path" format
     */
    private function getNamespaceId(string $owner): string
    {
        $pos = strpos($owner, ':');
        if ($pos !== false) {
            return substr($owner, 0, $pos);
        }
        return $owner;
    }

    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $namespaceId = (int) $this->getNamespaceId($owner);

        $url = "/projects";

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], [
            'name' => $repositoryName,
            'path' => $repositoryName,
            'namespace_id' => $namespaceId,
            'visibility' => $private ? 'private' : 'public',
        ]);

        $body = $response['body'] ?? [];
        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Creating repository {$repositoryName} failed with status code {$statusCode}");
        }
        $result = is_array($body) ? $body : [];
        $result['pushed_at'] = $result['last_activity_at'] ?? '';
        return $result;
    }

    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}";

        $response = $this->call(self::METHOD_DELETE, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Deleting repository {$repositoryName} failed with status code {$responseHeadersStatusCode}");
        }

        return true;
    }

    public function getRepository(string $owner, string $repositoryName): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}";

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new RepositoryNotFound("Repository not found");
        }

        $result = $response['body'] ?? [];
        $result['pushed_at'] = $result['last_activity_at'] ?? '';
        return $result;
    }


    public function hasAccessToAllRepositories(): bool
    {
        return true;
    }

    public function getInstallationRepository(string $repositoryName): array
    {
        throw new Exception("getInstallationRepository is not applicable for this adapter");
    }

    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        throw new Exception("Not implemented");
    }

    public function getRepositoryName(string $repositoryId): string
    {
        throw new Exception("Not implemented");
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        throw new Exception("Not implemented");
    }

    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        throw new Exception("Not implemented");
    }

    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        throw new Exception("Not implemented");
    }

    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        throw new Exception("Not implemented");
    }

    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array
    {
        throw new Exception("Not implemented");
    }

    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        throw new Exception("Not implemented");
    }

    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        throw new Exception("Not implemented");
    }

    public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): int
    {
        throw new Exception("Not implemented");
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        throw new Exception("Not implemented");
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        throw new Exception("Not implemented");
    }

    public function updateComment(string $owner, string $repositoryName, int $commentId, string $comment): string
    {
        throw new Exception("Not implemented");
    }

    public function getUser(string $username): array
    {
        throw new Exception("Not implemented");
    }

    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        throw new Exception("Not implemented");
    }

    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        throw new Exception("Not implemented");
    }

    public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        throw new Exception("Not implemented");
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        throw new Exception("Not implemented");
    }

    public function listBranches(string $owner, string $repositoryName): array
    {
        throw new Exception("Not implemented");
    }

    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        throw new Exception("Not implemented");
    }

    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        throw new Exception("Not implemented");
    }

    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        throw new Exception("Not implemented");
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        throw new Exception("Not implemented");
    }

    public function getEvent(string $event, string $payload): array
    {
        throw new Exception("Not implemented");
    }

    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        throw new Exception("Not implemented");
    }

    public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array
    {
        throw new Exception("Not implemented");
    }

    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        throw new Exception("Not implemented");
    }
}
