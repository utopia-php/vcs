<?php

namespace Utopia\VCS\Adapter\Git;

use Ahc\Jwt\JWT;
use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

class GitHub extends Git
{
    public const EVENT_PUSH = 'push';

    public const EVENT_PULL_REQUEST = 'pull_request';

    public const EVENT_INSTALLATION = 'installation';

    public const CONTENTS_DIRECTORY = 'dir';

    public const CONTENTS_FILE = 'file';

    protected string $endpoint = 'https://api.github.com';

    protected string $accessToken;

    protected string $jwtToken;

    protected string $installationId;

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

    /**
     * Get Adapter Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'github';
    }

    /**
     * GitHub Initialisation with access token generation.
     */
    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        $this->installationId = $installationId;

        $response = $this->cache->load($installationId, 60 * 9); // 10 minutes, but 1 minute earlier to be safe
        if ($response == false) {
            $this->generateAccessToken($privateKey, $appId);

            $tokens = \json_encode([
                'jwtToken' => $this->jwtToken,
                'accessToken' => $this->accessToken,
            ]) ?: '{}';

            $this->cache->save($installationId, $tokens);
        } else {
            $parsed = \json_decode($response, true);
            $this->jwtToken = $parsed['jwtToken'] ?? '';
            $this->accessToken = $parsed['accessToken'] ?? '';
        }
    }

    /**
     * Create new repository
     *
     * @return array<mixed> Details of new repository
     */
    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/orgs/{$owner}/repos";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], [
            'name' => $repositoryName,
            'private' => $private,
        ]);

        return $response['body'] ?? [];
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
    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file'): array
    {
        throw new Exception("Not implemented");
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
        throw new Exception("Not implemented");
    }

    /**
     * Search repositories for GitHub App
     * @param string $installationId ID of the installation
     * @param string $owner Name of user or org
     * @param int $page page number
     * @param int $per_page number of results per page
     * @param string $search Query to be searched to filter repo names
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function searchRepositories(string $installationId, string $owner, int $page, int $per_page, string $search = ''): array
    {
        // Find whether installation has access to all (or) specific repositories
        $url = '/app/installations/' . $installationId;
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->jwtToken"]);
        $responseBody = $response['body'] ?? [];
        $hasAccessToAllRepositories = ($responseBody['repository_selection'] ?? '') === 'all';

        // Installation has access to all repositories, use the search API which supports filtering.
        if ($hasAccessToAllRepositories) {
            $url = '/search/repositories';

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'q' => "{$search} user:{$owner} fork:true",
                'page' => $page,
                'per_page' => $per_page,
                'sort' => 'updated'
            ]);
            $responseBody = $response['body'] ?? [];

            if (!array_key_exists('items', $responseBody)) {
                throw new Exception("Repositories list missing in the response.");
            }

            return [
                'items' => $responseBody['items'] ?? [],
                'total' => $responseBody['total_count'] ?? 0,
            ];
        }

        // Installation has access to specific repositories, we need to perform client-side filtering.
        $url = '/installation/repositories';
        $repositories = [];

        // When no search query is provided, delegate pagination to the GitHub API.
        if (empty($search)) {
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'page' => $page,
                'per_page' => $per_page,
            ]);

            $responseBody = $response['body'] ?? [];

            if (!array_key_exists('repositories', $responseBody)) {
                throw new Exception("Repositories list missing in the response.");
            }

            return [
                'items' => $responseBody['repositories'] ?? [],
                'total' => $responseBody['total_count'] ?? 0,
            ];
        }

        // When search query is provided, fetch all repositories accessible by the installation and filter them locally.
        $currentPage = 1;
        while (true) {
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'page' => $currentPage,
                'per_page' => 100, // Maximum allowed by GitHub API
            ]);

            $responseBody = $response['body'] ?? [];

            if (!array_key_exists('repositories', $responseBody)) {
                throw new Exception("Repositories list missing in the response.");
            }

            // Filter repositories to only include those that match the search query.
            $filteredRepositories = array_filter($responseBody['repositories'] ?? [], fn ($repo) => stripos($repo['name'] ?? '', $search) !== false);

            // Merge with result so far.
            $repositories = array_merge($repositories, $filteredRepositories);

            // If less than 100 repositories are returned, we have fetched all repositories.
            if (\count($responseBody['repositories'] ?? []) < 100) {
                break;
            }

            // Increment page number to fetch next page.
            $currentPage++;
        }

        $repositoriesInRequestedPage = \array_slice($repositories, ($page - 1) * $per_page, $per_page);

        return [
            'items' => $repositoriesInRequestedPage,
            'total' => \count($repositories),
        ];
    }

    public function getInstallationRepository(string $repositoryName): array
    {
        $currentPage = 1;
        $perPage = 100;
        $totalRepositories = 0;
        $maxRepositories = 1000;
        $url = '/installation/repositories';

        while ($totalRepositories < $maxRepositories) {
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'page' => $currentPage,
                'per_page' => $perPage,
            ]);

            $responseBody = $response['body'] ?? [];

            if (!array_key_exists('repositories', $responseBody)) {
                throw new Exception("Repositories list missing in the response.");
            }

            foreach (($responseBody['repositories'] ?? []) as $repo) {
                if (\strtolower($repo['name'] ?? '') === \strtolower($repositoryName)) {
                    return $repo;
                }
            }

            if (\count($responseBody['repositories'] ?? []) < $perPage) {
                break;
            }

            $currentPage++;
            $totalRepositories += $perPage;
        }

        throw new RepositoryNotFound("Repository not found.");
    }

    /**
     * Get GitHub repository
     *
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body'] ?? [];
    }

    /**
     * Fetches repository name using repository id
     *
     * @param  string  $repositoryId ID of GitHub Repository
     * @return string name of GitHub repository
     */
    public function getRepositoryName(string $repositoryId): string
    {
        $url = "/repositories/$repositoryId";
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('name', $responseBody)) {
            throw new RepositoryNotFound("Repository not found");
        }

        return $responseBody['name'] ?? '';
    }

    /**
     * Get repository tree
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the GitHub repository
     * @param string $branch Name of the branch
     * @param bool $recursive Whether to fetch the tree recursively
     * @return array<string> List of files in the repository
     */
    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        // if recursive is true, add optional query param to url
        $url = "/repos/$owner/$repositoryName/git/trees/$branch" . ($recursive ? '?recursive=1' : '');
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode == 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];

        return array_column($responseBody['tree'] ?? [], 'path');
    }

    /**
     * Get repository languages
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @return array<mixed> List of repository languages
     */
    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        $url = "/repos/$owner/$repositoryName/languages";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!empty($responseBody)) {
            return array_keys($responseBody);
        }

        return [];
    }

    /**
     * Get contents of the specified file.
     *
     * @param  string  $owner Owner name
     * @param  string  $repositoryName Name of the repository
     * @param  string  $path Path to the file
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<string, mixed> File details
     */
    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        $url = "/repos/$owner/$repositoryName/contents/" . $path;
        if (!empty($ref)) {
            $url .= "?ref=$ref";
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

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

        $output = [
           'sha' => $responseBody['sha'] ?? '',
           'size' => $responseBody['size'] ?? 0,
           'content' => $content
        ];

        return $output;
    }

    /**
     * List contents of the specified root directory.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $path Path to list contents from
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<mixed> List of contents at the specified path
     */
    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        $url = "/repos/$owner/$repositoryName/contents";
        if (!empty($path)) {
            $url .= "/$path";
        }
        if (!empty($ref)) {
            $url .= "?ref=$ref";
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode == 404) {
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

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Deleting repository $repositoryName failed with status code $responseHeadersStatusCode");
        }
        return true;
    }

    /**
     * Add Comment to Pull Request
     *
     * @return string
     *
     * @throws Exception
     */
    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $url = '/repos/' . $owner . '/' . $repositoryName . '/issues/' . $pullRequestNumber . '/comments';

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], ['body' => $comment]);

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('id', $responseBody)) {
            throw new Exception("Comment creation response is missing comment ID.");
        }

        $commentId = $responseBody['id'] ?? '';

        return $commentId;
    }

    /**
     * Get Comment of Pull Request
     *
     * @param string $owner       The owner of the repository
     * @param string $repositoryName    The name of the repository
     * @param string $commentId   The ID of the comment to retrieve
     * @return string              The retrieved comment
     *
     * @throws Exception
     */
    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        $url = '/repos/' . $owner . '/' . $repositoryName . '/issues/comments/' . $commentId;

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        $comment = $responseBody['body'] ?? '';

        return $comment;
    }

    /**
     * Update Pull Request Comment
     *
     * @param string $owner      The owner of the repository
     * @param string $repositoryName   The name of the repository
     * @param int $commentId  The ID of the comment to update
     * @param string $comment    The updated comment content
     * @return string            The ID of the updated comment
     *
     * @throws Exception
     */
    public function updateComment(string $owner, string $repositoryName, int $commentId, string $comment): string
    {
        $url = '/repos/' . $owner . '/' . $repositoryName . '/issues/comments/' . $commentId;

        $response = $this->call(self::METHOD_PATCH, $url, ['Authorization' => "Bearer $this->accessToken"], ['body' => $comment]);

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('id', $responseBody)) {
            throw new Exception("Comment update response is missing comment ID.");
        }

        $commentId = $responseBody['id'] ?? '';

        return $commentId;
    }

    /**
     * Generate Access Token
     */
    protected function generateAccessToken(string $privateKey, ?string $appId): void
    {
        /**
         * @var resource $privateKeyObj
         */
        $privateKeyObj = \openssl_pkey_get_private($privateKey);

        $appIdentifier = $appId;

        $iat = time();
        $exp = $iat + 10 * 60;
        $payload = [
            'iat' => $iat,
            'exp' => $exp,
            'iss' => $appIdentifier,
        ];

        // generate access token
        $jwt = new JWT($privateKeyObj, 'RS256');
        $token = $jwt->encode($payload);
        $this->jwtToken = $token;
        $response = $this->call(self::METHOD_POST, '/app/installations/' . $this->installationId . '/access_tokens', ['Authorization' => 'Bearer ' . $token]);
        $responseBody = $response['body'] ?? [];
        if (!array_key_exists('token', $responseBody)) {
            throw new Exception('Failed to retrieve access token from GitHub API.');
        }
        $this->accessToken = $responseBody['token'] ?? '';
    }

    /**
     * Get user
     *
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function getUser(string $username): array
    {
        $response = $this->call(self::METHOD_GET, '/users/' . $username);

        return $response;
    }

    /**
     * Get owner name of the GitHub installation
     *
     * @return string
     */
    public function getOwnerName(string $installationId): string
    {
        $url = '/app/installations/' . $installationId;
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->jwtToken"]);

        $responseBody = $response['body'] ?? [];
        $responseBodyAccount = $responseBody['account'] ?? [];

        if (!array_key_exists('login', $responseBodyAccount)) {
            throw new Exception("Owner name retrieval response is missing account login.");
        }

        return $responseBodyAccount['login'] ?? '';
    }

    /**
     * Get Pull Request
     *
     * @return array<mixed> The retrieved pull request
     */
    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/pulls/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body'] ?? [];
    }

    /**
     * Get latest opened pull request with specific base branch
     * @return array<mixed>
     */
    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        $head = "{$owner}:{$branch}";
        $url = "/repos/{$owner}/{$repositoryName}/pulls?head={$head}&state=open&sort=updated&per_page=1";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        return $responseBody[0] ?? [];
    }

    /**
     * Lists branches for a given repository
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @return array<string> List of branch names as array
     */
    public function listBranches(string $owner, string $repositoryName): array
    {
        $url = "/repos/$owner/$repositoryName/branches";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        $names = [];
        foreach ($responseBody as $subarray) {
            $names[] = $subarray['name'] ?? '';
        }

        return $names;
    }

    /**
     * Get details of a commit using commit hash
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $commitHash SHA of the commit
     * @return array<mixed> Details of the commit
     */
    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repos/$owner/$repositoryName/commits/$commitHash";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];
        $responseBodyAuthor = $responseBody['author'] ?? [];
        $responseBodyCommit = $responseBody['commit'] ?? [];
        $responseBodyCommitAuthor = $responseBodyCommit['author'] ?? [];

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
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $branch Name of the branch
     * @return array<mixed> Details of the commit
     */
    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        $url = "/repos/$owner/$repositoryName/commits/$branch?per_page=1";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $responseBody = $response['body'] ?? [];
        $responseBodyCommit = $responseBody['commit'] ?? [];
        $responseBodyCommitAuthor = $responseBodyCommit['author'] ?? [];
        $responseBodyAuthor = $responseBody['author'] ?? [];

        if (
            !array_key_exists('name', $responseBodyCommitAuthor) ||
            !array_key_exists('message', $responseBodyCommit) ||
            !array_key_exists('sha', $responseBody) ||
            !array_key_exists('html_url', $responseBody) ||
            !array_key_exists('avatar_url', $responseBodyAuthor) ||
            !array_key_exists('html_url', $responseBodyAuthor)
        ) {
            throw new Exception("Latest commit response is missing required information.");
        }

        return [
            'commitAuthor' => $responseBodyCommitAuthor['name'] ?? '',
            'commitMessage' => $responseBodyCommit['message'] ?? '',
            'commitHash' => $responseBody['sha'] ?? '',
            'commitUrl' => $responseBody['html_url'] ?? '',
            'commitAuthorAvatar' => $responseBodyAuthor['avatar_url'] ?? '',
            'commitAuthorUrl' => $responseBodyAuthor['html_url'] ?? '',
        ];
    }

    /**
     * Updates status check of each commit
     * state can be one of: error, failure, pending, success
     */
    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        $url = "/repos/$owner/$repositoryName/statuses/$commitHash";

        $body = [
            'state' => $state,
            'target_url' => $target_url,
            'description' => $description,
            'context' => $context,
        ];

        $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], $body);
    }

    /**
     * Generates a clone command using app access token
     */
    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        if (empty($rootDirectory)) {
            $rootDirectory = '*';
        }

        // URL encode the components for the clone URL
        $owner = urlencode($owner);
        $repositoryName = urlencode($repositoryName);
        $accessToken = !empty($this->accessToken) ? ':' . urlencode($this->accessToken) : '';

        $cloneUrl = "https://{$owner}{$accessToken}@github.com/{$owner}/{$repositoryName}";

        $directory = escapeshellarg($directory);
        $rootDirectory = escapeshellarg($rootDirectory);

        $commands = [
            "mkdir -p {$directory}",
            "cd {$directory}",
            "git config --global init.defaultBranch main",
            "git init",
            "git remote add origin {$cloneUrl}",
            // Enable sparse checkout
            "git config core.sparseCheckout true",
            "echo {$rootDirectory} >> .git/info/sparse-checkout",
            // Disable fetching of refs we don't need
            "git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'",
            // Disable fetching of tags
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
                $commands[] = "git fetch --depth=1 origin refs/tags/$(git ls-remote --tags origin {$tagName} | tail -n 1 | awk -F '/' '{print $3}') && git checkout FETCH_HEAD";
                break;
        }

        $fullCommand = implode(" && ", $commands);

        return $fullCommand;
    }

    /**
     * Parses webhook event payload
     *
     * @param  string  $event Type of event: push, pull_request etc
     * @param  string  $payload The webhook payload received from GitHub
     * @return array<mixed> Parsed payload as a json object
     */
    public function getEvent(string $event, string $payload): array
    {
        $payload = json_decode($payload, true);

        if ($payload === null || !is_array($payload)) {
            throw new Exception("Invalid payload.");
        }

        $payloadInstallation = $payload['installation'] ?? [];

        $installationId = strval($payloadInstallation['id'] ?? '');

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
                $branchUrl = !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . "/tree/" . $branch : '';
                $commitHash = $payload['after'] ?? '';
                $owner = $payloadRepositoryOwner['name'] ?? '';
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
                    'installationId' => $installationId,
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
                $payloadPullRequestHeadUser = $payloadPullRequestHead['user'] ?? [];
                $payloadPullRequestUser = $payloadPullRequest['user'] ?? [];
                $payloadPullRequestBase = $payloadPullRequest['base'] ?? [];
                $payloadPullRequestBaseUser = $payloadPullRequestBase['user'] ?? [];

                $repositoryId = strval($payloadRepository['id'] ?? '');
                $branch = $payloadPullRequestHead['ref'] ?? '';
                $repositoryName = $payloadRepository['name'] ?? '';
                $repositoryUrl = $payloadRepository['html_url'] ?? '';
                $branchUrl = !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . "/tree/" . $branch : '';
                $pullRequestNumber = $payload['number'] ?? '';
                $action = $payload['action'] ?? '';
                $owner = $payloadRepositoryOwner['login'] ?? '';
                $authorUrl = $payloadSender['html_url'] ?? '';
                $authorAvatarUrl = $payloadPullRequestUser['avatar_url'] ?? '';
                $commitHash = $payloadPullRequestHead['sha'] ?? '';
                $headCommitUrl = $repositoryUrl ? $repositoryUrl . "/commits/" . $commitHash : '';
                $headLogin = $payloadPullRequestHeadUser['login'] ?? '';
                $baseLogin = $payloadPullRequestBaseUser['login'] ?? '';
                $external = $headLogin !== $baseLogin;

                return [
                    'branch' => $branch,
                    'branchUrl' => $branchUrl,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => $installationId,
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'authorUrl' => $authorUrl,
                    'authorAvatarUrl' => $authorAvatarUrl,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => $external,
                    'pullRequestNumber' => $pullRequestNumber,
                    'action' => $action,
                ];
            case 'installation':
            case 'installation_repositories':
                $payloadInstallation = $payload['installation'] ?? [];
                $payloadInstallationAccount = $payloadInstallation['account'] ?? [];

                $action = $payload['action'] ?? '';
                $userName = $payloadInstallationAccount['login'] ?? '';

                return [
                    'action' => $action,
                    'installationId' => $installationId,
                    'userName' => $userName,
                ];
        }

        return [];
    }


    /**
     * Validate webhook event
     *
     * @param  string  $payload Raw body of HTTP request
     * @param  string  $signature Signature provided by GitHub in header
     * @param  string  $signatureKey Webhook secret configured on GitHub
     * @return bool
     */
    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        return $signature === ('sha256=' . hash_hmac('sha256', $payload, $signatureKey));
    }
}
