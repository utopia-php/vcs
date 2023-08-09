<?php

namespace Utopia\VCS\Adapter\Git;

use Ahc\Jwt\JWT;
use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;

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
     * GitHub Initialisation with access token generation.
     */
    public function initialiseVariables(string $installationId, string $privateKey, string $githubAppId): void
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
            $this->jwtToken = $parsed['jwtToken'];
            $this->accessToken = $parsed['accessToken'];
        }
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
        $this->accessToken = $res['body']['token'];
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

        return $response['body']['account']['login'];
    }

    /**
     * List repositories for GitHub App
     * @param int $page page number
     * @param int $per_page number of results per page
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function listRepositoriesForVCSApp($page, $per_page): array
    {
        $url = '/installation/repositories?page=' . $page . '&per_page=' . $per_page;

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body']['repositories'];
    }

    /**
     * Get latest opened pull request with specific base branch
     * @return array<mixed>
     */
    public function getBranchPullRequest(string $owner, string $repositoryName, string $branch): array
    {
        $head = "{$owner}:{$branch}";
        $url = "/repos/{$owner}/{$repositoryName}/pulls?head={$head}&state=open&sort=updated&per_page=1";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body'][0] ?? [];
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

        return $response['body'];
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

        return $response['body'];
    }

    public function deleteRepository(string $owner, string $repositoryName): void
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $this->call(self::METHOD_DELETE, $url, ['Authorization' => "Bearer $this->accessToken"]);
    }

    public function getTotalReposCount(): int
    {
        $url = '/installation/repositories';

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body']['total_count'];
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

        return $response['body'];
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
        $comment = $response['body']['body'];

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
        $commentId = $response['body']['id'];

        return $commentId;
    }

    /**
     * Downloads a ZIP archive of a repository.
     *
     * @param  string  $repositoryName The name of the repository.
     * @param  string  $ref The name of the commit, branch, or tag to download.
     * @param  string  $path The path of the file or directory to download. Optional.
     * @return string The contents of the ZIP archive as a string.
     */
    public function downloadRepositoryZip(string $owner, string $repositoryName, string $ref, string $path = ''): string
    {
        // Build the URL for the API request
        $url = '/repos/' . $owner . "/{$repositoryName}/zipball/{$ref}";

        // Add the path parameter to the URL query parameters, if specified
        if (!empty($path)) {
            $url .= "?path={$path}";
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        // Return the contents of the ZIP archive
        return $response['body'];
    }

    /**
     * Downloads a tar archive of a repository.
     *
     * @return string The contents of the tar archive as a string.
     */
    public function downloadRepositoryTar(string $owner, string $repositoryName, string $ref): string
    {
        // Build the URL for the API request
        $url = '/repos/' . $owner . "/{$repositoryName}/tarball/{$ref}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        // Return the contents of the tar archive
        return $response['body'];
    }

    /**
     * Forks a repository on GitHub.
     *
     * @param  string  $owner The owner of the repository to fork.
     * @param  string  $repo The name of the repository to fork.
     * @param  string|null  $organization The name of the organization to fork the repository into. If not provided, the repository will be forked into the authenticated user's account.
     * @param  string|null  $name The name of the new forked repository. If not provided, the name will be the same as the original repository.
     * @param  bool  $defaultBranchOnly Whether to include only the default branch in the forked repository. Defaults to false.
     * @return string The name of the newly forked repository
     */
    public function forkRepository(string $owner, string $repo, ?string $organization = null, ?string $name = null, bool $defaultBranchOnly = false): ?string
    {
        $url = "/repos/$owner/$repo/forks";

        // Create the payload data for the API request
        $data = [
            'organization' => $organization,
            'name' => $name,
            'default_branch_only' => $defaultBranchOnly,
        ];

        // Send the API request to fork the repository
        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], $data);

        return $response['body']['name'];
    }

    /**
     * Generates a clone command using app access token
     */
    public function generateCloneCommand(string $owner, string $repositoryName, string $branchName, string $directory, string $rootDirectory): string
    {
        if (empty($rootDirectory)) {
            $rootDirectory = '*';
        }

        // Construct the clone URL with the access token
        $cloneUrl = "https://{$owner}:{$this->accessToken}@github.com/{$owner}/{$repositoryName}";

        // Construct the Git clone command with the clone URL
        $command = "mkdir -p {$directory} && cd {$directory} && git config --global init.defaultBranch main && git init && git remote add origin {$cloneUrl} && git config core.sparseCheckout true && echo \"{$rootDirectory}\" >> .git/info/sparse-checkout && if git ls-remote --exit-code --heads origin {$branchName}; then git pull origin {$branchName} && git checkout {$branchName}; else git checkout -b {$branchName}; fi";

        return $command;
    }

    /**
     * Parses webhook event payload
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

    /**
     * Get details of a commit
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

        return [
            'commitAuthor' => $response['body']['commit']['author']['name'],
            'commitMessage' => $response['body']['commit']['message'],
        ];
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
        $installationId = strval($payload['installation']['id']);

        switch ($event) {
            case 'push':
                $ref = $payload['ref'];
                $repositoryId = strval($payload['repository']['id']);
                $repositoryName = $payload['repository']['name'];
                $branch = str_replace('refs/heads/', '', $ref);
                $repositoryUrl = $payload['repository']['url'] . "/tree/" . $branch;
                $commitHash = $payload['after'];
                $owner = $payload['repository']['owner']['name'];
                $headCommitAuthor = $payload['head_commit']['author']['name'];
                $headCommitMessage = $payload['head_commit']['message'];
                $headCommitUrl = $payload['head_commit']['url'];

                return [
                    'branch' => $branch,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => $installationId,
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'headCommitAuthor' => $headCommitAuthor,
                    'headCommitMessage' => $headCommitMessage,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => false,
                    'pullRequestNumber' => '',
                    'action' => '',
                ];
                case 'pull_request':
                    $repositoryId = strval($payload['repository']['id']);
                    $branch = $payload['pull_request']['head']['ref'];
                    $repositoryName = $payload['repository']['name'];
                    $repositoryUrl = $payload['pull_request']['html_url'];
                    $pullRequestNumber = $payload['number'];
                    $action = $payload['action'];
                    $owner = $payload['repository']['owner']['login'];
                    $commitHash = $payload['pull_request']['head']['sha'];
                    $headCommitUrl = $repositoryUrl . "/commits/" . $commitHash;
                    $external = $payload['pull_request']['head']['user']['login'] !== $payload['pull_request']['base']['user']['login'];

                return [
                    'branch' => $branch,
                    'repositoryId' => $repositoryId,
                    'repositoryName' => $repositoryName,
                    'repositoryUrl' => $repositoryUrl,
                    'installationId' => $installationId,
                    'commitHash' => $commitHash,
                    'owner' => $owner,
                    'headCommitUrl' => $headCommitUrl,
                    'external' => $external,
                    'pullRequestNumber' => $pullRequestNumber,
                    'action' => $action,
                ];
            case 'installation':
            case 'installation_repositories':
                $action = $payload['action'];
                $userName = $payload['installation']['account']['login'];

                return [
                    'action' => $action,
                    'installationId' => $installationId,
                    'userName' => $userName,
                ];
        }

        return [];
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

        return $response['body']['name'];
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
     * Get repository languages
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @return array<mixed> List of repository languages
     */
    public function getRepositoryLanguages(string $owner, string $repositoryName): array
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

        return array_map(static function ($item) {
            return $item['name'];
        }, $response['body']);
    }
}
