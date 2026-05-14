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

        throw new Exception("accessToken is required for this adapter.");
    }

    /**
     * Generate Access Token
     *
     * Note: This method is required by the Adapter interface but is not used for this adapter.
     * Gitea uses OAuth2 tokens that are provided directly via initializeVariables().
     */
    protected function generateAccessToken(string $privateKey, string $appId): void
    {
        // Not applicable for this adapter - OAuth2 tokens are passed directly
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

        $result = $response['body'] ?? [];
        if (is_array($result)) {
            // Gitea's API does not expose `pushed_at`; surface `updated_at` under that key
            // for parity with the other VCS adapters (GitHub, GitLab).
            $result['pushed_at'] = $result['pushed_at'] ?? ($result['updated_at'] ?? '');
        }
        return is_array($result) ? $result : [];
    }

    public function createOrganization(string $orgName): string
    {
        $url = "/orgs";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], [
            'username' => $orgName,
            'visibility' => 'public',
        ]);

        $responseBody = $response['body'] ?? [];

        return $responseBody['name'] ?? '';
    }

    /**
     * Determines whether the installation has access to all repositories or specific repositories
     *
     * @return bool True if installation has access to all repositories, false if it has access to specific repositories
     *
     * @throws Exception
     */
    public function hasAccessToAllRepositories(): bool
    {
        return true;
    }

    /**
     * Search repositories in organization
     *
     * @param string $owner Organization or user name
     * @param int $page Page number for pagination
     * @param int $per_page Number of results per page
     * @param string $search Search query to filter repository names
     * @return array<mixed> Array with 'items' (repositories) and 'total' count
     */
    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        $filteredRepos = [];
        $currentPage = 1;
        $maxPages = 50;

        $neededForPage = $page * $per_page;
        $maxToCollect = $neededForPage + $per_page;

        while ($currentPage <= $maxPages) {
            $queryParams = [
                'page' => $currentPage,
                'limit' => 100,
            ];

            if (!empty($search)) {
                $queryParams['q'] = $search;
            }

            $query = http_build_query($queryParams);
            $url = "/repos/search?{$query}";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

            $responseHeaders = $response['headers'] ?? [];
            $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
            if ($responseHeadersStatusCode >= 400) {
                throw new Exception("Repository search failed with status code {$responseHeadersStatusCode}");
            }

            $responseBody = $response['body'] ?? [];

            if (!is_array($responseBody)) {
                throw new Exception('Unexpected response body: ' . json_encode($responseBody));
            }

            if (!array_key_exists('data', $responseBody)) {
                throw new Exception("Repositories list missing in response: " . json_encode($responseBody));
            }

            $repos = $responseBody['data'];

            if (empty($repos)) {
                break;
            }

            foreach ($repos as $repo) {
                $repoOwner = $repo['owner']['login'] ?? '';
                if ($repoOwner === $owner) {
                    $filteredRepos[] = $repo;

                    if (count($filteredRepos) >= $maxToCollect) {
                        break 2;
                    }
                }
            }

            if (count($repos) < 100) {
                break;
            }

            $currentPage++;
        }

        $total = count($filteredRepos);
        $offset = ($page - 1) * $per_page;
        $pagedRepos = array_slice($filteredRepos, $offset, $per_page);

        foreach ($pagedRepos as &$repo) {
            if (is_array($repo)) {
                $repo['pushed_at'] = $repo['pushed_at'] ?? ($repo['updated_at'] ?? '');
            }
        }
        unset($repo);

        return [
            'items' => $pagedRepos,
            'total' => $total,
        ];
    }

    /**
     * Get installation repository
     *
     * Note: Gitea doesn't have GitHub App installations.
     * This method is not applicable and throws an exception.
     *
     * @param string $repositoryName Name of the repository
     * @return array<mixed>
     * @throws Exception Always throws as installations don't exist in Gitea
     */
    public function getInstallationRepository(string $repositoryName): array
    {
        throw new Exception("getInstallationRepository is not applicable for this adapter - use getRepository() with owner and repo name instead");
    }

    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);


        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new RepositoryNotFound("Repository not found");
        }

        $result = $response['body'] ?? [];
        if (is_array($result)) {
            $result['pushed_at'] = $result['pushed_at'] ?? ($result['updated_at'] ?? '');
        }
        return is_array($result) ? $result : [];
    }

    public function getRepositoryName(string $repositoryId): string
    {
        $url = "/repositories/{$repositoryId}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('name', $responseBody)) {
            throw new RepositoryNotFound("Repository not found");
        }

        return $responseBody['name'] ?? '';
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/git/trees/" . urlencode($branch) . ($recursive ? '?recursive=1' : '');

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];

        return array_column($responseBody['tree'] ?? [], 'path');
    }

    /**
     * Create a file in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $filepath Path where file should be created
     * @param string $content Content of the file
     * @param string $message Commit message
     * @return array<mixed> Response from API
     */
    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/contents/{$filepath}";

        $payload = [
            'content' => base64_encode($content),
            'message' => $message
        ];

        // Add branch if specified
        if (!empty($branch)) {
            $payload['branch'] = $branch;
        }

        $response = $this->call(
            self::METHOD_POST,
            $url,
            ['Authorization' => "token $this->accessToken"],
            $payload
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create file {$filepath}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    /**
     * Create a branch in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $newBranchName Name of the new branch
     * @param string $oldBranchName Name of the branch to branch from
     * @return array<mixed> Response from API
     */
    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/branches";

        $response = $this->call(
            self::METHOD_POST,
            $url,
            ['Authorization' => "token $this->accessToken"],
            [
                'new_branch_name' => $newBranchName,
                'old_branch_name' => $oldBranchName
            ]
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create branch {$newBranchName}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/languages";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!empty($responseBody)) {
            return array_keys($responseBody);
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

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode !== 200) {
            throw new FileNotFound();
        }

        $responseBody = $response['body'] ?? [];

        $encoding = $responseBody['encoding'] ?? '';
        $content = '';

        if ($encoding === 'base64') {
            $content = base64_decode($responseBody['content'] ?? '');
        } else {
            throw new FileNotFound();
        }

        return [
            'sha' => $responseBody['sha'] ?? '',
            'size' => $responseBody['size'] ?? 0,
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

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];

        $items = [];
        if (!empty($responseBody[0] ?? [])) {
            $items = $responseBody;
        } elseif (!empty($responseBody)) {
            $items = [$responseBody];
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


        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Deleting repository {$repositoryName} failed with status code {$responseHeadersStatusCode}");
        }

        return true;
    }

    /**
     * Create a pull request
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $title PR title
     * @param string $head Source branch
     * @param string $base Target branch
     * @param string $body PR description (optional)
     * @return array<mixed> Created PR details
     */
    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/pulls";

        $payload = [
            'title' => $title,
            'head' => $head,
            'base' => $base,
        ];

        if (!empty($body)) {
            $payload['body'] = $body;
        }

        $response = $this->call(
            self::METHOD_POST,
            $url,
            ['Authorization' => "token $this->accessToken"],
            $payload
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create pull request: HTTP {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];

        return $responseBody;
    }

    protected function getHookType(): string
    {
        return 'gitea';
    }

    /**
     * Create a webhook on a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $url Webhook URL to send events to
     * @param string $secret Webhook secret for signature validation
     * @param array<string> $events Events to trigger the webhook
     * @return int Webhook ID
     */
    public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): int
    {
        $response = $this->call(
            self::METHOD_POST,
            "/repos/{$owner}/{$repositoryName}/hooks",
            ['Authorization' => "token $this->accessToken"],
            [
                'type' => $this->getHookType(),
                'active' => true,
                'events' => $events,
                'config' => [
                    'url' => $url,
                    'content_type' => 'json',
                    'secret' => $secret,
                ],
            ]
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create webhook: HTTP {$responseHeadersStatusCode}");
        }

        return (int) ($response['body']['id'] ?? 0);
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $url = "/repos/{$owner}/{$repositoryName}/issues/{$pullRequestNumber}/comments";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], ['body' => $comment]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create comment: HTTP {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('id', $responseBody)) {
            throw new Exception("Comment creation response is missing comment ID.");
        }

        return (string) ($responseBody['id'] ?? '');
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        $url = "/repos/{$owner}/{$repositoryName}/issues/comments/{$commentId}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        return $responseBody['body'] ?? '';
    }

    public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string
    {
        $url = "/repos/{$owner}/{$repositoryName}/issues/comments/{$commentId}";

        $response = $this->call(self::METHOD_PATCH, $url, ['Authorization' => "token $this->accessToken"], ['body' => $comment]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to update comment: HTTP {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('id', $responseBody)) {
            throw new Exception("Comment update response is missing comment ID.");
        }

        return (string) ($responseBody['id'] ?? '');
    }

    /**
     * Get user information
     *
     * @param string $username Username to look up
     * @return array<mixed> User information
     */
    public function getUser(string $username): array
    {
        $url = "/users/" . rawurlencode($username);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to get user: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        if ($repositoryId === null || $repositoryId <= 0) {
            throw new Exception("repositoryId is required for this adapter");
        }

        $url = "/repositories/{$repositoryId}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;

        if ($responseHeadersStatusCode === 404) {
            throw new RepositoryNotFound("Repository not found");
        }

        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to get repository: HTTP {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];
        $owner = $responseBody['owner'] ?? [];

        if (empty($owner['login'])) {
            throw new Exception("Owner login missing or empty in response");
        }

        return $owner['login'];
    }

    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/pulls/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to get pull request: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    /**
     * Get files changed in a pull request
     *
     * @return array<mixed> List of files changed in the pull request
     */
    public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $allFiles = [];
        $limit = 30;
        $maxPages = 100;

        for ($currentPage = 1; $currentPage <= $maxPages; $currentPage++) {
            $url = "/repos/{$owner}/{$repositoryName}/pulls/{$pullRequestNumber}/files?page={$currentPage}&limit={$limit}";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

            $responseHeaders = $response['headers'] ?? [];
            $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
            if ($responseHeadersStatusCode >= 400) {
                throw new Exception("Failed to get pull request files: HTTP {$responseHeadersStatusCode}");
            }

            $files = $response['body'] ?? [];
            $allFiles = array_merge($allFiles, $files);

            if (\count($files) < $limit) {
                break;
            }
        }

        return $allFiles;
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {

        $url = "/repos/{$owner}/{$repositoryName}/pulls?state=open&head=" . urlencode($branch);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to list pull requests: HTTP {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];

        return $responseBody[0] ?? [];
    }

    /**
     * List all branches in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @return array<string> Array of branch names
     */
    public function listBranches(string $owner, string $repositoryName, int $perPage = 100, int|string|null $page = 1, string $search = ''): array
    {
        $allBranches = [];
        $requestedPerPage = min(max($perPage, 1), 100);
        $requestedPage = is_int($page) ? max($page, 1) : 1;
        $apiPerPage = 50;
        $maxPages = 100;

        for ($currentPage = 1; $currentPage <= $maxPages; $currentPage++) {
            $url = "/repos/{$owner}/{$repositoryName}/branches?page={$currentPage}&limit={$apiPerPage}";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"], decode: false);

            $responseHeaders = $response['headers'] ?? [];
            $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;

            if ($responseHeadersStatusCode === 404) {
                return [];
            }

            if ($responseHeadersStatusCode >= 400) {
                if ($currentPage === 1) {
                    throw new Exception("Failed to list branches: HTTP {$responseHeadersStatusCode}");
                }
                break;
            }

            $responseBody = \json_decode($response['body'] ?? '', true);

            if (!is_array($responseBody)) {
                break;
            }

            $pageCount = 0;
            foreach ($responseBody as $branch) {
                if (is_array($branch) && array_key_exists('name', $branch)) {
                    $allBranches[] = $branch['name'] ?? '';
                    $pageCount++;
                }
            }

            if ($pageCount < $apiPerPage) {
                break;
            }
        }

        if ($search !== '') {
            $allBranches = array_values(array_filter($allBranches, fn ($branch) => str_starts_with($branch, $search)));
        }

        if ($search === '' && $requestedPage === 1 && $requestedPerPage === 100) {
            return $allBranches;
        }

        return array_slice($allBranches, ($requestedPage - 1) * $requestedPerPage, $requestedPerPage);
    }

    /**
     * Get details of a commit using commit hash
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @param string $commitHash SHA of the commit
     * @return array<mixed> Details of the commit
     */
    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/git/commits/{$commitHash}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Commit not found or inaccessible");
        }

        $responseBody = $response['body'] ?? [];
        $responseBodyCommit = $responseBody['commit'] ?? [];
        $responseBodyCommitAuthor = $responseBodyCommit['author'] ?? [];
        $responseBodyAuthor = $responseBody['author'] ?? [];

        return [
            'commitAuthor' => $responseBodyCommitAuthor['name'] ?? 'Unknown',
            'commitMessage' => $responseBodyCommit['message'] ?? 'No message',
            'commitAuthorAvatar' => $responseBodyAuthor['avatar_url'] ?? '',
            'commitAuthorUrl' => $responseBodyAuthor['html_url'] ?? '',
            'commitHash' => $responseBody['sha'] ?? '',
            'commitUrl' => $responseBody['html_url'] ?? '',
        ];
    }

    /**
     * Get latest commit of a branch
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @param string $branch Name of the branch
     * @return array<mixed> Details of the commit
     */
    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        $query = http_build_query([
            'sha' => $branch,
            'limit' => 1,
        ]);
        $url = "/repos/{$owner}/{$repositoryName}/commits?{$query}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Latest commit response failed with status code {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];

        if (empty($responseBody[0] ?? [])) {
            throw new Exception("Latest commit response is missing required information.");
        }

        $responseBodyFirst = $responseBody[0];
        $responseBodyFirstCommit = $responseBodyFirst['commit'] ?? [];
        $responseBodyFirstCommitAuthor = $responseBodyFirstCommit['author'] ?? [];
        $responseBodyFirstAuthor = $responseBodyFirst['author'] ?? [];

        return [
            'commitAuthor' => $responseBodyFirstCommitAuthor['name'] ?? 'Unknown',
            'commitMessage' => $responseBodyFirstCommit['message'] ?? 'No message',
            'commitHash' => $responseBodyFirst['sha'] ?? '',
            'commitUrl' => $responseBodyFirst['html_url'] ?? '',
            'commitAuthorAvatar' => $responseBodyFirstAuthor['avatar_url'] ?? '',
            'commitAuthorUrl' => $responseBodyFirstAuthor['html_url'] ?? '',
        ];
    }

    /**
     * Update commit status
     *
     * @param string $repositoryName Name of the repository
     * @param string $commitHash SHA of the commit
     * @param string $owner Owner of the repository
     * @param string $state Status: success, error, failure, pending, warning
     * @param string $description Status description
     * @param string $target_url Target URL for status
     * @param string $context Status context/identifier
     * @return void
     */
    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        $url = "/repos/{$owner}/{$repositoryName}/statuses/{$commitHash}";

        $body = [
            'state' => $state,
        ];

        if (!empty($description)) {
            $body['description'] = $description;
        }

        if (!empty($target_url)) {
            $body['target_url'] = $target_url;
        }

        if (!empty($context)) {
            $body['context'] = $context;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], $body);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to update commit status: HTTP {$responseHeadersStatusCode}");
        }
    }

    /**
         * Generate git clone command
         *
         * @param string $owner Owner of the repository
         * @param string $repositoryName Name of the repository
         * @param string $version Branch name, commit hash, or tag
         * @param string $versionType Type: branch, commit, or tag
         * @param string $directory Directory to clone into
         * @param string $rootDirectory Root directory for sparse checkout
         * @return string Shell command to execute
         */
    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        if (empty($rootDirectory)) {
            $rootDirectory = '*';
        }
        $cloneUrl = "{$this->giteaUrl}/{$owner}/{$repositoryName}";
        if (!empty($this->accessToken)) {
            $cloneUrl = str_replace('://', "://{$owner}:{$this->accessToken}@", $this->giteaUrl) . "/{$owner}/{$repositoryName}";
        }

        // SECURITY FIX: Escape clone URL
        $cloneUrl = escapeshellarg($cloneUrl);
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
                $commands[] = "git fetch --depth=1 origin refs/tags/{$version} && git checkout FETCH_HEAD";
                break;
            default:
                throw new Exception("Unsupported clone type: {$versionType}");
        }

        return implode(' && ', $commands);
    }

    /**
     * Parses webhook event payload
     *
     * @param string $event Type of event: push, pull_request, etc
     * @param string $payload The webhook payload received from Gitea
     * @return array<mixed> Parsed payload as an array
     */
    public function getEvent(string $event, string $payload): array
    {
        $payload = json_decode($payload, true);

        if ($payload === null || !is_array($payload)) {
            throw new Exception("Invalid payload.");
        }

        switch ($event) {
            case 'push':
                $payloadRepository = $payload['repository'] ?? [];
                $payloadRepositoryOwner = $payloadRepository['owner'] ?? [];
                $payloadSender = $payload['sender'] ?? [];
                $payloadHeadCommit = $payload['head_commit'] ?? [];
                $payloadHeadCommitAuthor = $payloadHeadCommit['author'] ?? [];

                $branchCreated = $payload['created'] ?? false;
                $branchDeleted = $payload['deleted'] ?? false;
                $repositoryId = strval($payloadRepository['id'] ?? '');
                $repositoryName = $payloadRepository['name'] ?? '';
                $branch = str_replace('refs/heads/', '', $payload['ref'] ?? '');
                $repositoryUrl = $payloadRepository['html_url'] ?? '';
                $branchUrl = !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . "/src/branch/" . $branch : '';
                $commitHash = $payload['after'] ?? '';
                $owner = $payloadRepositoryOwner['login'] ?? '';
                $authorUrl = $payloadSender['html_url'] ?? '';
                $authorAvatarUrl = $payloadSender['avatar_url'] ?? '';
                $headCommitAuthorName = $payloadHeadCommitAuthor['name'] ?? '';
                $headCommitAuthorEmail = $payloadHeadCommitAuthor['email'] ?? '';
                $headCommitMessage = $payloadHeadCommit['message'] ?? '';
                $headCommitUrl = $payloadHeadCommit['url'] ?? '';

                $affectedFiles = [];
                foreach (($payload['commits'] ?? []) as $commit) {
                    foreach (($commit['added'] ?? []) as $added) {
                        $affectedFiles[$added] = true;
                    }

                    foreach (($commit['removed'] ?? []) as $removed) {
                        $affectedFiles[$removed] = true;
                    }

                    foreach (($commit['modified'] ?? []) as $modified) {
                        $affectedFiles[$modified] = true;
                    }
                }

                return [
                    'branchCreated' => $branchCreated,
                    'branchDeleted' => $branchDeleted,
                    'branch' => $branch,
                    'branchUrl' => $branchUrl,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => '',  // Gitea doesn't have installations
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'authorUrl' => $authorUrl,
                    'authorAvatarUrl' => $authorAvatarUrl,
                    'headCommitAuthorName' => $headCommitAuthorName,
                    'headCommitAuthorEmail' => $headCommitAuthorEmail,
                    'headCommitMessage' => $headCommitMessage,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => false,
                    'pullRequestNumber' => '',
                    'action' => '',
                    'affectedFiles' => \array_keys($affectedFiles),
                ];

            case 'pull_request':
                $payloadRepository = $payload['repository'] ?? [];
                $payloadRepositoryOwner = $payloadRepository['owner'] ?? [];
                $payloadSender = $payload['sender'] ?? [];
                $payloadPullRequest = $payload['pull_request'] ?? [];
                $payloadPullRequestHead = $payloadPullRequest['head'] ?? [];
                $payloadPullRequestHeadRepo = $payloadPullRequestHead['repo'] ?? [];
                $payloadPullRequestUser = $payloadPullRequest['user'] ?? [];
                $payloadPullRequestBase = $payloadPullRequest['base'] ?? [];

                $repositoryId = strval($payloadRepository['id'] ?? '');
                $branch = $payloadPullRequestHead['ref'] ?? '';
                $repositoryName = $payloadRepository['name'] ?? '';
                $repositoryUrl = $payloadRepository['html_url'] ?? '';
                $branchUrl = !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . "/src/branch/" . $branch : '';
                $pullRequestNumber = $payload['number'] ?? '';
                $action = $payload['action'] ?? '';
                $owner = $payloadRepositoryOwner['login'] ?? '';
                $authorUrl = $payloadSender['html_url'] ?? '';
                $authorAvatarUrl = $payloadPullRequestUser['avatar_url'] ?? '';
                $commitHash = $payloadPullRequestHead['sha'] ?? '';
                $headCommitUrl = $repositoryUrl ? $repositoryUrl . "/commit/" . $commitHash : '';

                // Check if PR is from a fork (external)
                $headRepoFullName = $payloadPullRequestHeadRepo['full_name'] ?? '';
                $baseRepoFullName = $payloadRepository['full_name'] ?? '';
                $external = !empty($headRepoFullName) && !empty($baseRepoFullName) && $headRepoFullName !== $baseRepoFullName;

                return [
                    'branch' => $branch,
                    'branchUrl' => $branchUrl,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => '',  // Gitea doesn't have installations
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'authorUrl' => $authorUrl,
                    'authorAvatarUrl' => $authorAvatarUrl,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => $external,
                    'pullRequestNumber' => $pullRequestNumber,
                    'action' => $action,
                ];
        }

        return [];
    }

    /**
     * Validate webhook event
     *
     * @param string $payload Raw body of HTTP request
     * @param string $signature Signature provided by Gitea in X-Gitea-Signature header
     * @param string $signatureKey Webhook secret configured on Gitea
     * @return bool
     */
    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        return hash_equals($signature, hash_hmac('sha256', $payload, $signatureKey));
    }

    /**
     * Create a tag in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $tagName Name of the tag (e.g., 'v1.0.0')
     * @param string $target Target commit SHA or branch name
     * @param string $message Tag message (optional)
     * @return array<mixed> Response from API
     */
    public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/tags";

        $payload = [
            'tag_name' => $tagName,
            'target' => $target,
        ];

        if (!empty($message)) {
            $payload['message'] = $message;
        }

        $response = $this->call(
            self::METHOD_POST,
            $url,
            ['Authorization' => "token $this->accessToken"],
            $payload
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create tag {$tagName}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    /**
     * Get commit statuses
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $commitHash SHA of the commit
     * @return array<mixed> List of commit statuses
     */
    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/commits/{$commitHash}/statuses";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to get commit statuses: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }
}
