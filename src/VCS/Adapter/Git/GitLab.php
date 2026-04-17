<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Command;
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
        return is_array($body) ? $body : [];
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

        return $response['body'] ?? [];
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
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $encodedFilepath = urlencode($filepath);
        $url = "/projects/{$projectPath}/repository/files/{$encodedFilepath}";

        $payload = [
            'branch' => empty($branch) ? 'main' : $branch,
            'content' => base64_encode($content),
            'encoding' => 'base64',
            'commit_message' => $message,
            'author_name' => 'utopia',
            'author_email' => 'utopia@example.com',
        ];

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create file {$filepath}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
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
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/repository/commits/" . urlencode($commitHash);

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Commit not found or inaccessible");
        }

        $commit = $response['body'] ?? [];

        return [
            'commitAuthor' => $commit['author_name'] ?? 'Unknown',
            'commitMessage' => $commit['message'] ?? 'No message',
            'commitHash' => $commit['id'] ?? '',
            'commitUrl' => $commit['web_url'] ?? '',
            'commitAuthorAvatar' => '',
            'commitAuthorUrl' => '',
        ];
    }

    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/repository/commits?ref_name=" . urlencode($branch) . "&per_page=1";

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to get latest commit: HTTP {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];
        if (empty($responseBody[0])) {
            throw new Exception("Latest commit response is missing required information.");
        }

        $commit = $responseBody[0];

        return [
            'commitAuthor' => $commit['author_name'] ?? 'Unknown',
            'commitMessage' => $commit['message'] ?? 'No message',
            'commitHash' => $commit['id'] ?? '',
            'commitUrl' => $commit['web_url'] ?? '',
            'commitAuthorAvatar' => '',
            'commitAuthorUrl' => '',
        ];
    }

    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        throw new Exception("Not implemented");
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): Command
    {
        if (empty($rootDirectory) || $rootDirectory === '/') {
            $rootDirectory = '*';
        }

        $ownerPath = $this->getOwnerPath($owner);

        // GitLab clone URL format: http://oauth2:{token}@host/owner/repo.git
        $baseUrl = $this->gitlabUrl;
        if (!empty($this->accessToken)) {
            $baseUrl = str_replace('://', '://oauth2:' . urlencode($this->accessToken) . '@', $this->gitlabUrl);
        }

        $commands = [
            (new Command('mkdir'))
                ->flag('-p')
                ->argument($directory),
            (new Command('git'))
                ->argument('config')
                ->argument('--global')
                ->argument('init.defaultBranch')
                ->argument('main'),
            (new Command('git'))
                ->argument('init')
                ->argument($directory),
            (new Command('git'))
                ->option('-C', $directory)
                ->argument('remote')
                ->argument('add')
                ->argument('origin')
                ->argument("{$baseUrl}/{$ownerPath}/{$repositoryName}.git"),
            (new Command('git'))
                ->option('-C', $directory)
                ->argument('config')
                ->argument('--add')
                ->argument('remote.origin.fetch')
                ->argument('+refs/heads/*:refs/remotes/origin/*'),
            (new Command('git'))
                ->option('-C', $directory)
                ->argument('config')
                ->argument('remote.origin.tagopt')
                ->argument('--no-tags'),
            (new Command('git'))
                ->option('-C', $directory)
                ->argument('sparse-checkout')
                ->argument('set')
                ->argument('--no-cone')
                ->argument($rootDirectory),
        ];

        switch ($versionType) {
            case self::CLONE_TYPE_BRANCH:
                $commands[] = Command::or(
                    Command::and(
                        (new Command('git'))
                            ->option('-C', $directory)
                            ->argument('ls-remote')
                            ->argument('--exit-code')
                            ->argument('--heads')
                            ->argument('origin')
                            ->argument($version),
                        (new Command('git'))
                            ->option('-C', $directory)
                            ->argument('pull')
                            ->argument('--depth=1')
                            ->argument('origin')
                            ->argument($version),
                        (new Command('git'))
                            ->option('-C', $directory)
                            ->argument('checkout')
                            ->argument($version)
                    ),
                    (new Command('git'))
                        ->option('-C', $directory)
                        ->argument('checkout')
                        ->argument('-b')
                        ->argument($version)
                );
                break;
            case self::CLONE_TYPE_COMMIT:
                $commands[] = (new Command('git'))
                    ->option('-C', $directory)
                    ->argument('fetch')
                    ->argument('--depth=1')
                    ->argument('origin')
                    ->argument($version);
                $commands[] = (new Command('git'))
                    ->option('-C', $directory)
                    ->argument('checkout')
                    ->argument($version);
                break;
            case self::CLONE_TYPE_TAG:
                $commands[] = (new Command('git'))
                    ->option('-C', $directory)
                    ->argument('fetch')
                    ->argument('--depth=1')
                    ->argument('origin')
                    ->argument('refs/tags/' . $version);
                $commands[] = (new Command('git'))
                    ->option('-C', $directory)
                    ->argument('checkout')
                    ->argument('FETCH_HEAD');
                break;
            default:
                throw new Exception("Unsupported clone type: {$versionType}");
        }

        return Command::and(...$commands);
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
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/repository/tags";

        $payload = [
            'tag_name' => $tagName,
            'ref' => $target,
        ];

        if (!empty($message)) {
            $payload['message'] = $message;
        }

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create tag {$tagName}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        throw new Exception("Not implemented");
    }
}
