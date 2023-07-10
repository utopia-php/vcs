<?php

namespace Utopia\VCS\Adapter\Git;

use Ahc\Jwt\JWT;
use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;

class GitHub extends Git
{
    const EVENT_PUSH = 'push';

    const EVENT_PULL_REQUEST = 'pull_request';

    const EVENT_INSTALLATION = 'installation';

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
    public function initialiseVariables(string $installationId, string $privateKey, string $githubAppId)
    {
        $this->installationId = $installationId;

        $response = $this->cache->load($installationId, 60 * 9); // 10 minutes, but 1 minute earlier to be safe
        if ($response == false) {
            $this->generateAccessToken($privateKey, $githubAppId);

            $this->cache->save($installationId, \json_encode([
                'jwtToken' => $this->jwtToken,
                'accessToken' => $this->accessToken,
            ]));
        } else {
            $parsed = \json_decode($response, true);
            $this->jwtToken = $parsed['jwtToken'];
            $this->accessToken = $parsed['accessToken'];
        }
    }

    /**
     * Generate Access Token
     *
     * @param  string  $userName The username of account which has installed GitHub app
     * @param  string  $installationId Installation ID of the GitHub App
     */
    protected function generateAccessToken(string $privateKey, string $githubAppId)
    {
        // fetch env variables from .env file
        $privateKeyString = $privateKey;
        $privateKey = openssl_pkey_get_private($privateKeyString);
        $appIdentifier = $githubAppId;

        $iat = time();
        $exp = $iat + 10 * 60;
        $payload = [
            'iat' => $iat,
            'exp' => $exp,
            'iss' => $appIdentifier,
        ];

        // generate access token
        $jwt = new JWT($privateKey, 'RS256');
        $token = $jwt->encode($payload);
        $this->jwtToken = $token;
        $res = $this->call(self::METHOD_POST, '/app/installations/' . $this->installationId . '/access_tokens', ['Authorization' => 'Bearer ' . $token]);
        $this->accessToken = $res['body']['token'];
        var_dump($this->accessToken);
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
     * Is Git Flow
     *
     * @return bool
     */
    public function isGitFlow(): bool
    {
        return true; // false for manual adapter - flow is way simpler. No auth, no branch selecting, ...
    }

    /**
     * Get user
     *
     * @return array
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
    public function getOwnerName($installationId): string
    {
        $url = '/app/installations/' . $installationId;
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->jwtToken"]);

        return $response['body']['account']['login'];
    }

    /**
     * List repositories for GitHub App
     *
     * @return array
     *
     * @throws Exception
     */
    public function listRepositoriesForGitHubApp($page, $per_page): array
    {
        $url = '/installation/repositories?page=' . $page . '&per_page=' . $per_page;

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body']['repositories'];
    }

    /**
     * Get latest opened pull request with specific base branch
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
     * @return array
     *
     * @throws Exception
     */
    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body'];
    }

    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/orgs/{$owner}/repos";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], [
            'name' => $repositoryName,
            'private' => $private,
        ]);

        return $response['body'];
    }

    public function getTotalReposCount(): int
    {
        $url = '/installation/repositories';

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body']['total_count'];
    }

    public function getPullRequest(string $owner, string $repoName, $pullRequestNumber): array
    {
        $url = "/repos/{$owner}/{$repoName}/pulls/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        return $response['body'];
    }

    /**
     * Add Comment to Pull Request
     *
     * @return array
     *
     * @throws Exception
     */
    public function createComment(string $owner, string $repoName, $pullRequestNumber, $comment)
    {
        $url = '/repos/' . $owner . '/' . $repoName . '/issues/' . $pullRequestNumber . '/comments';

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "Bearer $this->accessToken"], ['body' => $comment]);
        $commentId = $response['body']['id'];

        return $commentId;
    }

    /**
     * Get Comment of Pull Request
     *
     * @return string
     *
     * @throws Exception
     */
    public function getComment($owner, $repoName, $commentId): string
    {
        $url = '/repos/' . $owner . '/' . $repoName . '/issues/comments/' . $commentId;

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);
        $comment = $response['body']['body'];

        return $comment;
    }

    /**
     * Update Pull Request Comment
     *
     * @return array
     *
     * @throws Exception
     */
    public function updateComment($owner, $repoName, $commentId, $comment)
    {
        $url = '/repos/' . $owner . '/' . $repoName . '/issues/comments/' . $commentId;

        $response = $this->call(self::METHOD_PATCH, $url, ['Authorization' => "Bearer $this->accessToken"], ['body' => $comment]);
        $commentId = $response['body']['id'];

        return $commentId;
    }

    /**
     * Downloads a ZIP archive of a repository.
     *
     * @param  string  $repo The name of the repository.
     * @param  string  $ref The name of the commit, branch, or tag to download.
     * @param  string  $path The path of the file or directory to download. Optional.
     * @return string The contents of the ZIP archive as a string.
     */
    public function downloadRepositoryZip(string $owner, string $repoName, string $ref, string $path = ''): string
    {
        // Build the URL for the API request
        $url = '/repos/' . $owner . "/{$repoName}/zipball/{$ref}";

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
     * @param  string  $repo The name of the repository.
     * @param  string  $ref The name of the commit, branch, or tag to download.
     * @return string The contents of the tar archive as a string.
     */
    public function downloadRepositoryTar(string $owner, string $repoName, string $ref): string
    {
        // Build the URL for the API request
        $url = '/repos/' . $owner . "/{$repoName}/tarball/{$ref}";

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
     * @return array|null The data of the newly forked repository, or null if the fork operation failed.
     */
    public function forkRepository(string $owner, string $repo, ?string $organization = null, ?string $name = null, bool $defaultBranchOnly = false): ?array
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

        return $response['body'];
    }

    /**
     * Generates a git clone command using app access token
     */
    public function generateGitCloneCommand(string $owner, string $repositoryName, string $branchName, string $directory, string $rootDirectory)
    {
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
    public function validateWebhook(string $payload, string $signature, string $signatureKey)
    {
        return $signature === ('sha256=' . hash_hmac('sha256', $payload, $signatureKey));
    }

    /**
     * Parses webhook event payload
     *
     * @param  string  $event Type of event: push, pull_request etc
     * @param  string  $payload The webhook payload received from GitHub
     * @return json Parsed payload as a json object
     */
    public function parseWebhookEventPayload(string $event, string $payload)
    {
        $payload = json_decode($payload, true);
        $installationId = strval($payload['installation']['id']);

        switch ($event) {
            case 'push':
                $ref = $payload['ref'];
                $repositoryId = strval($payload['repository']['id']);
                $repositoryName = $payload['repository']['name'];
                $SHA = $payload['after'];
                $owner = $payload['repository']['owner']['name'];
                $branch = str_replace('refs/heads/', '', $ref);

                return [
                    'branch' => $branch,
                    'repositoryId' => $repositoryId,
                    'installationId' => $installationId,
                    'repositoryName' => $repositoryName,
                    'SHA' => $SHA,
                    'owner' => $owner,
                    'external' => false,
                    'pullRequestNumber' => '',
                    'action' => '',
                ];
                break;
            case 'pull_request':
                $repositoryId = strval($payload['repository']['id']);
                $branch = $payload['pull_request']['head']['ref'];
                $repositoryName = $payload['repository']['name'];
                $pullRequestNumber = $payload['number'];
                $action = $payload['action'];
                $owner = $payload['repository']['owner']['login'];
                $SHA = $payload['pull_request']['head']['sha'];
                $external = $payload['pull_request']['head']['label'] !== $payload['pull_request']['base']['label'];

                return [
                    'action' => $action,
                    'branch' => $branch,
                    'repositoryId' => $repositoryId,
                    'installationId' => $installationId,
                    'repositoryName' => $repositoryName,
                    'pullRequestNumber' => $pullRequestNumber,
                    'SHA' => $SHA,
                    'owner' => $owner,
                    'external' => $external,
                ];
                break;
            case 'installation' || 'installation_repositories':
                $action = $payload['action'];
                $userName = $payload['installation']['account']['login'];

                return [
                    'action' => $action,
                    'installationId' => $installationId,
                    'userName' => $userName,
                ];
                break;
        }

        return [];
    }

    /**
     * Fetches repository name using repository id
     *
     * @param  string  $repository ID of GitHub Repository
     * @return string name of GitHub repository
     */
    public function getRepositoryName(string $repositoryId)
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
     * @return array List of branch names as array
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
    public function updateCommitStatus(string $repositoryName, string $SHA, string $owner, string $state, string $description = '', string $target_url = '', string $context = '')
    {
        $url = "/repos/$owner/$repositoryName/statuses/$SHA";

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
     * @return array|null List of repository languages or null if the request fails
     */
    public function getRepositoryLanguages(string $owner, string $repositoryName): ?array
    {
        $url = "/repos/$owner/$repositoryName/languages";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        if (isset($response['body'])) {
            return array_keys($response['body']);
        }

        return null;
    }

    /**
     * List contents of the specified root directory.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $path Path to list contents from
     * @return array List of contents at the specified path
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
