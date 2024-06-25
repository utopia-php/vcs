<?php

namespace Utopia\VCS\Adapter\Git;

use Ahc\Jwt\JWT;
use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;

class GitHub extends Git
{
    public const EVENT_PUSH = 'push';

    public const EVENT_PULL_REQUEST = 'pull_request';

    public const EVENT_INSTALLATION = 'installation';

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

        $response = $this->cache->load($installationId, 60 * 9, $installationId); // 10 minutes, but 1 minute earlier to be safe
        if ($response == false) {
            $this->generateAccessToken($privateKey, $githubAppId);

            $tokens = \json_encode([
                'jwtToken' => $this->jwtToken,
                'accessToken' => $this->accessToken,
            ]) ?: '{}';

            $this->cache->save($installationId, $tokens, $installationId);
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
        $url = '/search/repositories';

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"], [
            'q' => "{$search} user:{$owner} fork:true",
            'per_page' => $per_page,
            'sort' => 'updated'
        ]);

        if (!isset($response['body']['items'])) {
            throw new Exception("Repositories list missing in the response.");
        }

        return $response['body']['items'];
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
     * List contents of the specified root directory.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $path Path to list contents from
     * @return array<mixed> List of contents at the specified path
     */
    public function listRepositoryContents(string $owner, string $repositoryName, string $path = ''): array
    {
        $url = "/repos/$owner/$repositoryName/contents";
        if (!empty($path)) {
            $url .= "/$path";
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        if (($response['headers']['status-code'] == 404)) {
            return [];
        }

        if (isset($response['body'][0])) {
            return array_column($response['body'], 'name');
        }

        if (isset($response['body']['name'])) {
            return [$response['body']['name']];
        }

        return [];
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

        if (!isset($response['body']['commit']['author']['name']) || !isset($response['body']['commit']['message'])) {
            throw new Exception("Commit author or message information missing.");
        }

        return [
            'commitAuthor' => $response['body']['commit']['author']['name'],
            'commitMessage' => $response['body']['commit']['message'],
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
            !isset($response['body']['html_url'])
        ) {
            throw new Exception("Latest commit response is missing required information.");
        }

        return [
            'commitAuthor' => $response['body']['commit']['author']['name'],
            'commitMessage' => $response['body']['commit']['message'],
            'commitHash' => $response['body']['sha'],
            'commitUrl' => $response['body']['html_url']
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
    public function generateCloneCommand(string $owner, string $repositoryName, string $branchName, string $directory, string $rootDirectory, string $commitHash = null): string
    {
        if (empty($rootDirectory)) {
            $rootDirectory = '*';
        }

        // URL encode the components for the clone URL
        $owner = urlencode($owner);
        $repositoryName = urlencode($repositoryName);
        $accessToken = urlencode($this->accessToken);
        $cloneUrl = "https://{$owner}:{$accessToken}@github.com/{$owner}/{$repositoryName}";

        $directory = escapeshellarg($directory);
        $rootDirectory = escapeshellarg($rootDirectory);
        $branchName = escapeshellarg($branchName);
        if (!empty($commitHash)) {
            $commitHash = escapeshellarg($commitHash);
        }

        $commands = [
            "mkdir -p {$directory}",
            "cd {$directory}",
            "git config --global init.defaultBranch main",
            "git init",
            "git remote add origin {$cloneUrl}",
            "git config core.sparseCheckout true",
            "echo {$rootDirectory} >> .git/info/sparse-checkout",
        ];

        if (empty($commitHash)) {
            $commands[] = "if git ls-remote --exit-code --heads origin {$branchName}; then git pull origin {$branchName} && git checkout {$branchName}; else git checkout -b {$branchName}; fi";
        } else {
            $commands[] = "git pull origin {$commitHash}";
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
                $ref = $payload['ref'] ?? '';
                $repositoryId = strval($payload['repository']['id'] ?? '');
                $repositoryName = $payload['repository']['name'] ?? '';
                $branch = str_replace('refs/heads/', '', $ref);
                $branchUrl = $payload['repository']['url'] . "/tree/" . $branch;
                $repositoryUrl = $payload['repository']['url'];
                $commitHash = $payload['after'] ?? '';
                $owner = $payload['repository']['owner']['name'] ?? '';
                $authorUrl = $payload['sender']['html_url'];
                $headCommitAuthor = $payload['head_commit']['author']['name'] ?? '';
                $headCommitMessage = $payload['head_commit']['message'] ?? '';
                $headCommitUrl = $payload['head_commit']['url'] ?? '';

                return [
                    'branchCreated' => $branchCreated,
                    'branch' => $branch,
                    'branchUrl' => $branchUrl,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => $installationId,
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'authorUrl' => $authorUrl,
                    'headCommitAuthor' => $headCommitAuthor,
                    'headCommitMessage' => $headCommitMessage,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => false,
                    'pullRequestNumber' => '',
                    'action' => '',
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
