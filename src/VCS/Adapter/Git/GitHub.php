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
    protected $user;

    /**
     * @var string
     */
    protected $accessToken;

    /**
     * @var string
     */
    protected $installationId;

    /**
     * Global Headers
     *
     * @var array
     */
    protected $headers = ['content-type' => 'application/json'];

    /**
     * GitHub constructor.
     *
     */
    public function __construct(string $userName, string $installationId, string $privateKey, string $githubAppId)
    {
        // Set user name
        $this->user = $userName;

        // Set installation id
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
    public function getUser(): array
    {
        $response = $this->call(self::METHOD_GET, '/users/' . $this->user);
        return $response;
    }

    /**
     * List repositories for GitHub App
     *
     * @return array
     * @throws Exception
     */
    public function listRepositoriesForGitHubApp(): array
    {
        $url = '/installation/repositories';

        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);

        return $response['body']['repositories'];
    }

    /**
     * Add Comment to Pull Request
     *
     * @return array
     * @throws Exception
     */
    public function addComment($repoName, $pullRequestNumber)
    {
        $url = '/repos/' . $this->user . '/' . $repoName . '/issues/' . $pullRequestNumber . '/comments';

        $this->call(self::METHOD_POST, $url, ["Authorization" => "Bearer $this->accessToken"], ["body" => "hello from Utopia!"]);

        return;
    }

    /**
     * Update Pull Request Comment
     *
     * @return array
     * @throws Exception
     */
    public function updateComment($repoName, $commentId)
    {
        $url = '/repos/' . $this->user . '/' . $repoName . '/issues/comments/' . $commentId;

        $this->call(self::METHOD_PATCH, $url, ["Authorization" => "Bearer $this->accessToken"], ["body" => "update from Utopia!"]);

        return;
    }

    /**
     * Downloads a ZIP archive of a repository.
     *
     * @param string $repo The name of the repository.
     * @param string $ref The name of the commit, branch, or tag to download.
     * @param string $path The path of the file or directory to download. Optional.
     * @return string The contents of the ZIP archive as a string.
     */
    public function downloadRepositoryZip(string $repoName, string $ref, string $path = ''): string
    {
        // Build the URL for the API request
        $url = "/repos/" . $this->user . "/{$repoName}/zipball/{$ref}";

        // Add the path parameter to the URL query parameters, if specified
        if (!empty($path)) {
            $url .= "?path={$path}";
        }

        $response = $this->call(self::METHOD_GET, $url, ["Authorization" => "Bearer $this->accessToken"]);

        // Return the contents of the ZIP archive
        return $response['body'];
    }
}