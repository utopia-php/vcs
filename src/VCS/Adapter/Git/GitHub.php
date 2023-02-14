<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
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
     * Get user
     *
     * @param string $owner
     * @return array
     * @throws Exception
     */
    public function getUser(string $owner): array
    {
        $response = $this->call(self::METHOD_GET, '/users/' . urlencode($owner), ['content-type' => 'application/json']);

        return $response;
    }

    /**
     * List repositories
     *
     * @param string $owner
     * @return array
     * @throws Exception
     */
    public function listRepositories(string $owner): array
    {
        $response = $this->call(self::METHOD_GET, '/users/'. urlencode($owner) .'/repos', ['content-type' => 'application/json']);

        return $response;
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
        $response = $this->call(self::METHOD_GET, '/repos/' . urlencode($owner) . '/' . urlencode($repo), ['content-type' => 'application/json']);

        return $response;
    }
}