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
        $ownerPath = $this->getOwnerPath($owner);

        // Try group first, fall back to user namespace
        $url = "/groups/{$ownerPath}/projects?page={$page}&per_page={$per_page}";
        if (!empty($search)) {
            $url .= "&search=" . urlencode($search);
        }

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);
        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;

        // Fall back to user namespace if group not found
        if ($statusCode === 404) {
            $url = "/users/{$ownerPath}/projects?page={$page}&per_page={$per_page}";
            if (!empty($search)) {
                $url .= "&search=" . urlencode($search);
            }
            $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);
            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
        }

        if ($statusCode >= 400) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            return [];
        }

        $repositories = [];
        foreach ($responseBody as $repo) {
            $repositories[] = [
                'id' => $repo['id'] ?? 0,
                'name' => $repo['name'] ?? '',
                'description' => $repo['description'] ?? '',
                'private' => ($repo['visibility'] ?? '') === 'private',
                'pushed_at' => $repo['last_activity_at'] ?? '',
            ];
        }

        return $repositories;
    }

    public function getRepositoryName(string $repositoryId): string
    {
        $url = "/projects/{$repositoryId}";

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Repository {$repositoryId} not found");
        }

        $responseBody = $response['body'] ?? [];
        return $responseBody['path'] ?? '';
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/repository/tree?ref=" . urlencode($branch);

        if ($recursive) {
            $page = 1;
            $allItems = [];
            do {
                $pagedUrl = $url . "&recursive=true&per_page=100&page={$page}";
                $response = $this->call(self::METHOD_GET, $pagedUrl, ['PRIVATE-TOKEN' => $this->accessToken]);
                $responseHeaders = $response['headers'] ?? [];
                $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
                if ($responseHeadersStatusCode >= 400) {
                    return [];
                }
                $responseBody = $response['body'] ?? [];
                if (!is_array($responseBody) || empty($responseBody)) {
                    break;
                }
                $allItems = array_merge($allItems, $responseBody);
                $page++;
            } while (count($responseBody) === 100);
            return array_column($allItems, 'path');
        }

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            return [];
        }

        return array_column($responseBody, 'path');
    }

    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $encodedPath = urlencode($path);
        $url = "/projects/{$projectPath}/repository/files/{$encodedPath}?ref=" . urlencode(empty($ref) ? 'HEAD' : $ref);

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode !== 200) {
            throw new \Utopia\VCS\Exception\FileNotFound();
        }

        $responseBody = $response['body'] ?? [];

        $content = '';
        if (($responseBody['encoding'] ?? '') === 'base64') {
            $rawContent = $responseBody['content'] ?? '';
            $content = base64_decode($rawContent, true);
            if ($content === false) {
                throw new \Utopia\VCS\Exception\FileNotFound();
            }
        } else {
            throw new \Utopia\VCS\Exception\FileNotFound();
        }

        return [
            'sha' => $responseBody['blob_id'] ?? '',
            'size' => $responseBody['size'] ?? 0,
            'content' => $content,
        ];
    }

    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/repository/tree" . (empty($ref) ? '' : '?ref=' . urlencode($ref));

        if (!empty($path)) {
            $url .= (empty($ref) ? '?' : '&') . 'path=' . urlencode($path);
        }

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            return [];
        }

        $contents = [];
        foreach ($responseBody as $item) {
            $type = ($item['type'] ?? '') === 'blob' ? self::CONTENTS_FILE : self::CONTENTS_DIRECTORY;
            $contents[] = [
                'name' => $item['name'] ?? '',
                'size' => 0,
                'type' => $type,
            ];
        }

        return $contents;
    }

    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/languages";

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            return [];
        }

        return array_keys($responseBody);
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
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/repository/branches";

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], [
            'branch' => $newBranchName,
            'ref' => $oldBranchName,
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create branch {$newBranchName}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/merge_requests";

        $payload = [
            'title'         => $title,
            'source_branch' => $head,
            'target_branch' => $base,
            'description'   => $body,
        ];

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create merge request: HTTP {$statusCode}");
        }

        return $response['body'] ?? [];
    }

    public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): int
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $apiUrl = "/projects/{$projectPath}/hooks";

        $payload = [
            'url' => $url,
            'token' => $secret,
            'enable_ssl_verification' => false,
            'push_events' => in_array('push', $events),
            'merge_requests_events' => in_array('pull_request', $events),
        ];

        $response = $this->call(self::METHOD_POST, $apiUrl, ['PRIVATE-TOKEN' => $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            $body = $response['body'] ?? [];
            throw new Exception("Failed to create webhook: HTTP {$responseHeadersStatusCode} - " . json_encode($body));
        }

        $responseBody = $response['body'] ?? [];
        return $responseBody['id'] ?? 0;
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/merge_requests/{$pullRequestNumber}/notes";

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], ['body' => $comment]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create comment: HTTP {$statusCode}");
        }

        $responseBody = $response['body'] ?? [];
        if (!array_key_exists('id', $responseBody)) {
            throw new Exception("Comment creation response is missing comment ID.");
        }

        return $pullRequestNumber . ':' . ($responseBody['id'] ?? '');
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");

        $parts = explode(':', $commentId, 2);
        if (count($parts) !== 2) {
            return '';
        }

        [$mrIid, $noteId] = $parts;
        $url = "/projects/{$projectPath}/merge_requests/{$mrIid}/notes/{$noteId}";
        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        return $response['body']['body'] ?? '';
    }

    public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");

        $parts = explode(':', $commentId, 2);
        if (count($parts) !== 2) {
            throw new Exception("Invalid comment ID format: {$commentId}");
        }

        [$mrIid, $noteId] = $parts;
        $url = "/projects/{$projectPath}/merge_requests/{$mrIid}/notes/{$noteId}";
        $response = $this->call(self::METHOD_PUT, $url, ['PRIVATE-TOKEN' => $this->accessToken], ['body' => $comment]);

        $responseHeaders = $response['headers'] ?? [];
        if (($responseHeaders['status-code'] ?? 0) !== 200) {
            throw new Exception("Failed to update comment: HTTP " . ($responseHeaders['status-code'] ?? 0));
        }

        return $commentId;
    }

    public function getUser(string $username): array
    {
        $url = "/users?username=" . rawurlencode($username);

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get user: HTTP {$statusCode}");
        }

        $body = $response['body'] ?? [];

        // GitLab returns an array of users — return first match
        if (empty($body[0])) {
            throw new Exception("User not found: {$username}");
        }

        return $body[0];
    }

    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        if ($repositoryId !== null) {
            $url = "/projects/{$repositoryId}";
            $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);
            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                throw new Exception("Failed to get owner name for repository {$repositoryId}: HTTP {$statusCode}");
            }
            $responseBody = $response['body'] ?? [];
            $namespace = $responseBody['namespace'] ?? [];
            return $namespace['path'] ?? '';
        }

        $url = "/user";
        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);
        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get current user: HTTP {$statusCode}");
        }
        $responseBody = $response['body'] ?? [];
        return $responseBody['username'] ?? '';
    }

    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/merge_requests/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get merge request: HTTP {$statusCode}");
        }

        $mr = $response['body'] ?? [];

        // Normalize to match expected shape (consistent with Gitea/GitHub)
        return [
            'number'  => $mr['iid'] ?? 0,
            'title'   => $mr['title'] ?? '',
            'state'   => $mr['state'] ?? '',
            'head'    => [
                'ref' => $mr['source_branch'] ?? '',
                'sha' => $mr['sha'] ?? '',
            ],
            'base'    => [
                'ref' => $mr['target_branch'] ?? '',
            ],
        ];
    }

    public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");

        // Poll until diff is ready (patch_id_sha not null)
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $mrResponse = $this->call(
                self::METHOD_GET,
                "/projects/{$projectPath}/merge_requests/{$pullRequestNumber}",
                ['PRIVATE-TOKEN' => $this->accessToken]
            );
            $mrBody = $mrResponse['body'] ?? [];
            if (($mrBody['patch_id_sha'] ?? null) !== null) {
                break;
            }
            usleep(1000000); // 1 second
        }

        // Fetch diffs with pagination
        $allFiles = [];
        $page = 1;
        $perPage = 100;

        while (true) {
            $url = "/projects/{$projectPath}/merge_requests/{$pullRequestNumber}/diffs?page={$page}&per_page={$perPage}";
            $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                throw new Exception("Failed to get merge request files: HTTP {$statusCode}");
            }

            $files = $response['body'] ?? [];
            if (!is_array($files) || empty($files)) {
                break;
            }

            foreach ($files as $diff) {
                $allFiles[] = [
                    'filename' => $diff['new_path'] ?? $diff['old_path'] ?? '',
                ];
            }

            if (count($files) < $perPage) {
                break;
            }
            $page++;
        }

        return $allFiles;
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/merge_requests?state=opened&source_branch=" . urlencode($branch);

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to list merge requests: HTTP {$statusCode}");
        }

        $body = $response['body'] ?? [];
        if (empty($body[0])) {
            return [];
        }

        $mr = $body[0];

        return [
            'number' => $mr['iid'] ?? 0,
            'title'  => $mr['title'] ?? '',
            'state'  => $mr['state'] ?? '',
            'head'   => [
                'ref' => $mr['source_branch'] ?? '',
                'sha' => $mr['sha'] ?? '',
            ],
            'base'   => [
                'ref' => $mr['target_branch'] ?? '',
            ],
        ];
    }

    public function listBranches(string $owner, string $repositoryName): array
    {
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");

        $branches = [];
        $page = 1;
        do {
            $pagedUrl = "/projects/{$projectPath}/repository/branches?per_page=100&page={$page}";
            $response = $this->call(self::METHOD_GET, $pagedUrl, ['PRIVATE-TOKEN' => $this->accessToken]);
            $responseHeaders = $response['headers'] ?? [];
            $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
            if ($responseHeadersStatusCode >= 400) {
                return [];
            }
            $responseBody = $response['body'] ?? [];
            if (!is_array($responseBody) || empty($responseBody)) {
                break;
            }
            foreach ($responseBody as $branch) {
                $branches[] = $branch['name'] ?? '';
            }
            $page++;
        } while (count($responseBody) === 100);

        return $branches;
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
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/statuses/" . urlencode($commitHash);

        // GitLab states: pending, running, success, failed, canceled
        $stateMap = [
            'pending' => 'pending',
            'success' => 'success',
            'failure' => 'failed',
            'error' => 'failed',
            'cancelled' => 'canceled',
        ];

        $gitlabState = $stateMap[$state] ?? $state;

        $payload = [
            'state' => $gitlabState,
        ];

        if (!empty($description)) {
            $payload['description'] = $description;
        }

        if (!empty($target_url)) {
            $payload['target_url'] = $target_url;
        }

        if (!empty($context)) {
            $payload['name'] = $context;
        }

        $response = $this->call(self::METHOD_POST, $url, ['PRIVATE-TOKEN' => $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to update commit status: HTTP {$responseHeadersStatusCode}");
        }
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
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

        $cloneUrl = escapeshellarg("{$baseUrl}/{$ownerPath}/{$repositoryName}.git");
        $directory = escapeshellarg($directory);
        $rootDirectory = escapeshellarg($rootDirectory);

        $commands = [
            "mkdir -p {$directory}",
            "cd {$directory}",
            "git config --global init.defaultBranch main",
            "git init",
            "git remote add origin {$cloneUrl}",
            "git config core.sparseCheckout true",
            "echo {$rootDirectory} >> .git/info/sparse-checkout",
            "git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'",
            "git config remote.origin.tagopt --no-tags",
        ];

        switch ($versionType) {
            case self::CLONE_TYPE_BRANCH:
                $branchName = escapeshellarg($version);
                $commands[] = "if git ls-remote --exit-code --heads origin {$branchName}; then git pull --depth=1 origin {$branchName} && git checkout {$branchName}; else git checkout -b {$branchName}; fi";
                break;
            case self::CLONE_TYPE_COMMIT:
                $commitHash = escapeshellarg($version);
                $commands[] = "git fetch --depth=1 origin {$commitHash} && git checkout {$commitHash}";
                break;
            case self::CLONE_TYPE_TAG:
                $tagName = escapeshellarg($version);
                $commands[] = "git fetch --depth=1 origin refs/tags/{$tagName} && git checkout FETCH_HEAD";
                break;
            default:
                throw new Exception("Unsupported clone type: {$versionType}");
        }

        return implode(' && ', $commands);
    }

    public function getEvent(string $event, string $payload): array
    {
        $payloadArray = json_decode($payload, true);
        if ($payloadArray === null || !is_array($payloadArray)) {
            return [];
        }

        switch ($event) {
            case 'Push Hook':
                $commits = $payloadArray['commits'] ?? [];
                $checkoutSha = $payloadArray['checkout_sha'] ?? '';
                $latestCommit = [];
                foreach ($commits as $c) {
                    if (($c['id'] ?? '') === $checkoutSha) {
                        $latestCommit = $c;
                        break;
                    }
                }
                if (empty($latestCommit) && !empty($commits)) {
                    $latestCommit = $commits[0];
                }
                $ref = $payloadArray['ref'] ?? '';
                // ref format: refs/heads/main
                $branch = str_replace('refs/heads/', '', $ref);

                return [
                    'type' => 'push',
                    'name' => $payloadArray['project']['name'] ?? '',
                    'owner' => $payloadArray['project']['namespace'] ?? '',
                    'branch' => $branch,
                    'commitHash' => $payloadArray['checkout_sha'] ?? '',
                    'commitAuthor' => $latestCommit['author']['name'] ?? '',
                    'commitMessage' => $latestCommit['message'] ?? '',
                    'commitUrl' => $latestCommit['url'] ?? '',
                    'commitAuthorUrl' => '',
                    'commitAuthorAvatar' => '',
                ];

            case 'Merge Request Hook':
                $mr = $payloadArray['object_attributes'] ?? [];
                $action = $mr['action'] ?? '';

                return [
                    'type' => 'pull_request',
                    'name' => $payloadArray['project']['name'] ?? '',
                    'owner' => $payloadArray['project']['namespace'] ?? '',
                    'branch' => $mr['source_branch'] ?? '',
                    'action' => $action,
                    'pullRequestNumber' => $mr['iid'] ?? 0,
                    'pullRequestTitle' => $mr['title'] ?? '',
                    'pullRequestUrl' => $mr['url'] ?? '',
                    'headBranch' => $mr['source_branch'] ?? '',
                    'baseBranch' => $mr['target_branch'] ?? '',
                    'commitHash' => $mr['last_commit']['id'] ?? '',
                    'commitUrl' => $mr['last_commit']['url'] ?? '',
                    'commitMessage' => $mr['last_commit']['message'] ?? '',
                    'commitAuthor' => $mr['last_commit']['author']['name'] ?? '',
                    'commitAuthorUrl' => '',
                    'commitAuthorAvatar' => '',
                ];

            default:
                return [];
        }
    }

    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        return hash_equals($signatureKey, $signature);
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
        $ownerPath = $this->getOwnerPath($owner);
        $projectPath = urlencode("{$ownerPath}/{$repositoryName}");
        $url = "/projects/{$projectPath}/repository/commits/" . urlencode($commitHash) . "/statuses";

        $response = $this->call(self::METHOD_GET, $url, ['PRIVATE-TOKEN' => $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            return [];
        }

        $statuses = [];
        foreach ($responseBody as $status) {
            $statuses[] = [
                'state' => $status['status'] ?? '',
                'description' => $status['description'] ?? '',
                'target_url' => $status['target_url'] ?? '',
                'context' => $status['name'] ?? '',
            ];
        }

        return $statuses;
    }
}
