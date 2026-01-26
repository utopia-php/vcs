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
    public function initializeVariables(string $installationId, string $privateKey, string $githubAppId): void
    {
        $this->installationId = $installationId;

        $response = $this->cache->load($installationId, 60 * 9); // 10 minutes, but 1 minute earlier to be safe
        if ($response == false) {
            $this->generateAccessToken($privateKey, $githubAppId);

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
     * Search repositories for GitHub App
     * @param string $owner Name of user or org
     * @param int $page page number
     * @param int $per_page number of results per page
     * @param string $search Query to be searched to filter repo names
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        $repositories = [];
        
        $currentPage = 1;
        while (true) {
            $url = '/installation/repositories';
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
                'page' => $currentPage,
                'per_page' => 100, // Maximum allowed by GitHub API
            ]);

            if (!isset($response['body']['repositories'])) {
                break;
            }

            // Filter repositories to only include those that match the search query.
            $filteredRepositories = array_filter($response['body']['repositories'], fn ($repo) => empty($search) || stripos($repo['name'], $search) !== false);

            // Merge with result so far.
            $repositories = array_merge($repositories, $filteredRepositories);

            // If less than 100 repositories are returned, we have fetched all repositories.
            if (\count($filteredRepositories) < 100) {
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

        if (!isset($response['body']['name'])) {
            throw new RepositoryNotFound("Repository not found");
        }

        return $response['body']['name'];
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

        if ($response['headers']['status-code'] == 404) {
            return [];
        }

        return array_column($response['body']['tree'], 'path');
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

        if (isset($response['body'])) {
            return array_keys($response['body']);
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

        if ($response['headers']['status-code'] !== 200) {
            throw new FileNotFound();
        }

        $encoding = $response['body']['encoding'];

        $content = '';
        if ($encoding === 'base64') {
            $content = base64_decode($response['body']['content']);
        } else {
            throw new FileNotFound();
        }

        $output = [
           'sha' => $response['body']['sha'],
           'size' => $response['body']['size'],
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

        if (($response['headers']['status-code'] == 404)) {
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

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $statusCode = $response['headers']['status-code'];

        if ($statusCode >= 400) {
            throw new Exception("Deleting repository $repositoryName failed with status code $statusCode");
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

        if (!isset($response['body']['id'])) {
            throw new Exception("Comment creation response is missing comment ID.");
        }

        $commentId = $response['body']['id'];

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
        $comment = $response['body']['body'] ?? '';

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

        if (!isset($response['body']['id'])) {
            throw new Exception("Comment update response is missing comment ID.");
        }

        $commentId = $response['body']['id'];

        return $commentId;
    }

    /**
     * Generate Access Token
     */
    protected function generateAccessToken(string $privateKey, string $githubAppId): void
    {
        /**
         * @var resource $privateKeyObj
         */
        $privateKeyObj = \openssl_pkey_get_private($privateKey);

        $appIdentifier = $githubAppId;

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
        $res = $this->call(self::METHOD_POST, '/app/installations/' . $this->installationId . '/access_tokens', ['Authorization' => 'Bearer ' . $token]);
        if (!isset($res['body']['token'])) {
            throw new Exception('Failed to retrieve access token from GitHub API.');
        }
        $this->accessToken = $res['body']['token'];
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

        if (!isset($response['body']['account']['login'])) {
            throw new Exception("Owner name retrieval response is missing account login.");
        }

        return $response['body']['account']['login'];
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

        return $response['body'][0] ?? [];
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

        $names = [];
        foreach ($response['body'] as $subarray) {
            $names[] = $subarray['name'];
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

        $body = $response['body'] ?? [];
        $author = $body['author'] ?? [];
        $commit = $body['commit'] ?? [];
        $commitAuthor = $commit['author'] ?? [];

        return [
            'commitAuthor' => $commitAuthor['name'] ?? 'Unknown',
            'commitMessage' => $commit['message'] ?? 'No message',
            'commitAuthorAvatar' => $author['avatar_url'] ?? '',
            'commitAuthorUrl' => $author['html_url'] ?? '',
            'commitHash' => $body['sha'] ?? '',
            'commitUrl' => $body['html_url'] ?? '',
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

        if (
            !isset($response['body']['commit']['author']['name']) ||
            !isset($response['body']['commit']['message']) ||
            !isset($response['body']['sha']) ||
            !isset($response['body']['html_url']) ||
            !isset($response['body']['author']['avatar_url']) ||
            !isset($response['body']['author']['html_url'])
        ) {
            throw new Exception("Latest commit response is missing required information.");
        }

        return [
            'commitAuthor' => $response['body']['commit']['author']['name'],
            'commitMessage' => $response['body']['commit']['message'],
            'commitHash' => $response['body']['sha'],
            'commitUrl' => $response['body']['html_url'],
            'commitAuthorAvatar' => $response['body']['author']['avatar_url'],
            'commitAuthorUrl' => $response['body']['author']['html_url'],
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

        $installationId = strval($payload['installation']['id']);

        switch ($event) {
            case 'push':
                $branchCreated = isset($payload['created']) ? $payload['created'] : false;
                $branchDeleted = isset($payload['deleted']) ? $payload['deleted'] : false;
                $ref = $payload['ref'] ?? '';
                $repositoryId = strval($payload['repository']['id'] ?? '');
                $repositoryName = $payload['repository']['name'] ?? '';
                $branch = str_replace('refs/heads/', '', $ref);
                $branchUrl = $payload['repository']['html_url'] . "/tree/" . $branch;
                $repositoryUrl = $payload['repository']['html_url'];
                $commitHash = $payload['after'] ?? '';
                $owner = $payload['repository']['owner']['name'] ?? '';
                $authorUrl = $payload['sender']['html_url'];
                $authorAvatarUrl = $payload['sender']['avatar_url'] ?? '';
                $headCommitAuthorName = $payload['head_commit']['author']['name'] ?? '';
                $headCommitAuthorEmail = $payload['head_commit']['author']['email'] ?? '';
                $headCommitMessage = $payload['head_commit']['message'] ?? '';
                $headCommitUrl = $payload['head_commit']['url'] ?? '';

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
                $repositoryId = strval($payload['repository']['id'] ?? '');
                $branch = $payload['pull_request']['head']['ref'] ?? '';
                $repositoryName = $payload['repository']['name'] ?? '';
                $repositoryUrl = $payload['repository']['html_url'] ?? '';
                $branchUrl = "$repositoryUrl/tree/$branch";
                $pullRequestNumber = $payload['number'] ?? '';
                $action = $payload['action'] ?? '';
                $owner = $payload['repository']['owner']['login'] ?? '';
                $authorUrl = $payload['sender']['html_url'];
                $authorAvatarUrl = $payload['pull_request']['user']['avatar_url'] ?? '';
                $commitHash = $payload['pull_request']['head']['sha'] ?? '';
                $headCommitUrl = $repositoryUrl . "/commits/" . $commitHash;
                $external = $payload['pull_request']['head']['user']['login'] !== $payload['pull_request']['base']['user']['login'];

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
                $action = $payload['action'] ?? '';
                $userName = $payload['installation']['account']['login'] ?? '';

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
