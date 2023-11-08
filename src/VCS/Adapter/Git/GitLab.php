<?php

namespace Utopia\VCS\Adapter\Git;

use Utopia\VCS\Adapter;
use Exception;

use function PHPUnit\Framework\isEmpty;

class GitLab extends Adapter
{
    protected string $endpoint = '';

    protected string $accessToken;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    /**
     * Get Adapter Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'gitlab';
    }

    /**
     * Generate Access Token
     */
    public function generateAccessToken(string $appIdentifier, string $secret, string $code, string $redirectURI): void
    {
        $response = $this->call(self::METHOD_POST, 'https://gitlab.com/oauth/token?client_id=' . $appIdentifier . '&client_secret=' . $secret . '&code=' . $code . '&grant_type=authorization_code&redirect_uri=' . $redirectURI);

        $this->accessToken = $response['body']['access_token'];
        $refreshToken = $response['body']['refresh_token'];
        var_dump('access token ' . $this->accessToken);
        var_dump('refresh token ' . $response['body']['refresh_token']);
    }

    public function getAccessToken(string $appIdentifier, string $secret, string $redirectURI, string $accessToken, string $refreshToken): array
    {
        if (isEmpty($refreshToken))
            throw new Exception("Refresh token can't be empty.");
        if ($accessToken) {
            // check if access token is valid
            // if yes, return same values of access token and refresh token
        }
        $response = $this->call(self::METHOD_POST, 'https://gitlab.com/oauth/token?client_id=' . $appIdentifier . '&client_secret=' . $secret . '&refresh_token=' . $refreshToken . '&grant_type=refresh_token&redirect_uri=' . $redirectURI);
        $accessToken = $response['body']['access_token'];
        $refreshToken = $response['body']['refresh_token'];
        return [$accessToken, $refreshToken];
    }

    /**
     * Get owner name of the GitLab installation
     *
     * @return string
     */
    public function getOwnerName(): string
    {
        $url = 'https://gitlab.com/api/v4/user';
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);

        $body = json_decode($response['body'], true);

        if ($body !== null) {
            $name = $body['name'];
        } else {
            throw new Exception("Owner name retrieval response is missing account login.");
        }

        return $name;
    }

    public function listRepositories($projectId): void
    {
        $url = 'https://gitlab.com/api/v4/projects/' . $projectId . '/repository/tree';
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "Bearer $this->accessToken"]);
        var_dump($response);
    }
}
