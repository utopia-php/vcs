<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\VCS\Adapter\Git;

class GitHub extends Git
{
    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $apiUrl = 'https://api.github.com/';

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var string
     */
    protected $accessToken;

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
     * Set access token
     *
     * @param string $accessToken
     */
    public function setAccessToken(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Get access token
     *
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Get HTTP client
     *
     * @return HttpClient
     */
    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }

    /**
     * Set HTTP client
     *
     * @param HttpClient $httpClient
     */
    public function setHttpClient(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Send a request to the GitHub API
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array|null $body
     * @return HttpClientResponse
     * @throws Exception
     */
    public function request(string $method, string $url, array $headers = [], ?array $body = null): HttpClientResponse
    {
        if (!$this->accessToken) {
            throw new Exception('Access token not set');
        }

        $headers['Authorization'] = 'token ' . $this->accessToken;

        try {
            $request = new HttpClientRequest($method, $url, $headers, $body);
            $response = $this->httpClient->send($request);
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode(), $e);
        }

        if ($response->getStatusCode() >= 400) {
            throw new Exception($response->getBody(), $response->getStatusCode());
        }

        return $response;
    }


    /**
     * List repositories
     *
     * @return array
     * @throws Exception
     */
    public function listRepositories(): array
    {
        $response = $this->request('GET', 'https://api.github.com/user/repos');

        return json_decode($response->getBody(), true);
    }

    /**
     * Get repository
     *
     * @param string $owner
     * @param string $repo
     * @return array
     * @throws Exception
     */
    public function getRepository(string $owner, string $repo): array
    {
        $response = $this->request('GET', 'https://api.github.com/repos/' . urlencode($owner) . '/' . urlencode($repo));

        return json_decode($response->getBody(), true);
    }
}