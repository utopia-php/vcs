<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Ahc\Jwt\JWT;
use Utopia\VCS\Adapter\Git;

class GitHub extends Git
{
    /**
     * @var string
     */
    protected $endpoint = 'https://api.github.com';

    /**
     * @var string
     */
    protected $accessToken;

    /**
     * @var string
     */
    protected $jwtToken;

    /**
     * @var string
     */
    protected $installationId;

    const EVENT_PUSH = 'push';

    const EVENT_PULL_REQUEST = 'pull_request';

    const EVENT_INSTALLATION = 'installation';

    /**
     * Global Headers
     *
     * @var array
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct()
    {
    }

    /**
     * GitHub constructor.
     *
     */
    public function initialiseVariables(string $installationId, string $privateKey, string $githubAppId)
    {
        $this->installationId = $installationId;
        $this->generateAccessToken($privateKey, $githubAppId);
    }

    /**
     * Generate Access Token
     *
     * @param string $userName The username of account which has installed GitHub app
     * @param string $installationId Installation ID of the GitHub App
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
        return "github";
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
        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->jwtToken"]);
        return $response['body']['account']['login'];
    }

    /**
     * List repositories for GitHub App
     *
     * @return array
     * @throws Exception
     */
    public function listRepositoriesForGitHubApp($page, $per_page): array
    {
        $url = '/installation/repositories?page=' . $page . '&per_page=' . $per_page;

        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);

        return $response['body']['repositories'];
    }

    public function getTotalReposCount(): int
    {
        $url = '/installation/repositories';

        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);

        return $response['body']['total_count'];
    }

    /**
     * Add Comment to Pull Request
     *
     * @return array
     * @throws Exception
     */
    public function addComment($owner, $repoName, $pullRequestNumber, $comment)
    {
        $url = '/repos/' . $owner . '/' . $repoName . '/issues/' . $pullRequestNumber . '/comments';

        $response = $this->call(self::METHOD_POST, $url, ["Authorization" => "Bearer $this->accessToken"], ["body" => $comment]);
        $commentId = $response["body"]["id"];
        return $commentId;
    }

    /**
     * Update Pull Request Comment
     *
     * @return array
     * @throws Exception
     */
    public function updateComment($owner, $repoName, $commentId, $comment)
    {
        $url = '/repos/' . $owner . '/' . $repoName . '/issues/comments/' . $commentId;

        $response = $this->call(self::METHOD_PATCH, $url, ["Authorization" => "Bearer $this->accessToken"], ["body" => $comment]);
        $commentId = $response["body"]["id"];
        return $commentId;
    }

    /**
     * Downloads a ZIP archive of a repository.
     *
     * @param string $repo The name of the repository.
     * @param string $ref The name of the commit, branch, or tag to download.
     * @param string $path The path of the file or directory to download. Optional.
     * @return string The contents of the ZIP archive as a string.
     */
    public function downloadRepositoryZip(string $owner, string $repoName, string $ref, string $path = ''): string
    {
        // Build the URL for the API request
        $url = "/repos/" . $owner . "/{$repoName}/zipball/{$ref}";

        // Add the path parameter to the URL query parameters, if specified
        if (!empty($path)) {
            $url .= "?path={$path}";
        }

        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);

        // Return the contents of the ZIP archive
        return $response['body'];
    }

    /**
     * Downloads a tar archive of a repository.
     *
     * @param string $repo The name of the repository.
     * @param string $ref The name of the commit, branch, or tag to download.
     * @return string The contents of the tar archive as a string.
     */
    public function downloadRepositoryTar(string $owner, string $repoName, string $ref): string
    {
        // Build the URL for the API request
        $url = "/repos/" . $owner . "/{$repoName}/tarball/{$ref}";

        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);

        // Return the contents of the tar archive
        return $response['body'];
    }

    /**
     * Forks a repository on GitHub.
     *
     * @param string $owner The owner of the repository to fork.
     * @param string $repo The name of the repository to fork.
     * @param string|null $organization The name of the organization to fork the repository into. If not provided, the repository will be forked into the authenticated user's account.
     * @param string|null $name The name of the new forked repository. If not provided, the name will be the same as the original repository.
     * @param bool $defaultBranchOnly Whether to include only the default branch in the forked repository. Defaults to false.
     *
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
        $response = $this->call(self::METHOD_POST, $url, ["Authorization" => "Bearer $this->accessToken"], $data);
        return $response['body'];
    }

    /**
     * Generates a git clone command using app access token
     *
     * @param string $repoId The ID of the repo to be cloned
     *
     * @return string The git clone command as a string
     */
    public function generateGitCloneCommand(string $owner, string $repoID, string $branchName)
    {
        $url = "/repositories/{$repoID}";

        $repoData = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);

        $repoUrl = $repoData["body"]["html_url"];

        // Construct the clone URL with the access token
        $cloneUrl = str_replace("https://", "https://{$owner}:{$this->accessToken}@", $repoUrl);

        // Construct the Git clone command with the clone URL
        $command = "git clone -b " . $branchName . " --depth=1 {$cloneUrl}";

        return $command;
    }

    /**
     * Parses webhook event payload
     *
     * @param string $event Type of event: push, pull_request etc
     * @param string $payload The webhook payload received from GitHub
     *
     * @return json Parsed payload as a json object
     */
    public function parseWebhookEventPayload(string $event, string $payload)
    {
        $payload = json_decode($payload, true);
        $installationId = strval($payload["installation"]["id"]);

        switch ($event) {
            case "push":
                $ref = $payload["ref"];
                $repositoryId = strval($payload["repository"]["id"]);
                $repositoryName = $payload["repository"]["name"];
                $SHA = $payload["after"];
                $owner = $payload["repository"]["owner"]["name"];
                $branch = str_replace("refs/heads/", "", $ref);
                return [
                    "branch" => $branch,
                    "repositoryId" => $repositoryId,
                    "installationId" => $installationId,
                    "repositoryName" => $repositoryName,
                    "SHA" => $SHA,
                    "owner" => $owner
                ];
                break;
            case "pull_request":
                $repositoryId = strval($payload["repository"]["id"]);
                $branch = $payload["pull_request"]["head"]["ref"];
                $repositoryName = $payload["repository"]["name"];
                $pullRequestNumber = $payload["number"];
                $action = $payload["action"];
                $owner = $payload["repository"]["owner"]["login"];
                return [
                    "action" => $action,
                    "branch" => $branch,
                    "repositoryId" => $repositoryId,
                    "installationId" => $installationId,
                    "repositoryName" => $repositoryName,
                    "pullRequestNumber" => $pullRequestNumber,
                    "owner" => $owner
                ];
                break;
            case ("installation" || "installation_repositories"):
                $action = $payload["action"];
                $userName = $payload["installation"]["account"]["login"];
                return [
                    "action" => $action,
                    "installationId" => $installationId,
                    "userName" => $userName
                ];
                break;
        }
        return [];
    }

    /**
     * Fetches repository name using repository id
     *
     * @param string $repoId ID of GitHub Repository
     *
     * @return string name of GitHub repository
     */
    public function getRepositoryName(string $repoId)
    {
        $url = "/repositories/$repoId";
        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);
        return $response['body']['name'];
    }

    /**
     * Lists branches for a given repository
     *
     * @param string $owner Owner name of the repository
     * 
     * @param string $repositoryName Name of the GitHub repository
     *
     * @return array List of branch names as array
     */
    public function listBranches(string $owner, string $repositoryName): array
    {
        $url = "/repos/$owner/$repositoryName/branches";
        
        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);
        
        return $response['body'];
    }

    /**
     * Updates status check of each commit
     * state can be one of: error, failure, pending, success
     */
    public function updateCommitStatus(string $repositoryName, string $SHA, string $owner, string $state, string $description = "", string $target_url = "", string $context = "")
    {
        $url = "/repos/$owner/$repositoryName/statuses/$SHA";

        $body = [
            "state" => $state,
            "target_url" => $target_url,
            "description" => $description,
            "context" => $context
        ];

        $this->call(self::METHOD_POST, $url, ["Authorization" => "Bearer $this->accessToken"], $body);
        return;
    }
}
