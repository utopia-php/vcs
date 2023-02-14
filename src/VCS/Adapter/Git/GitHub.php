<?php

namespace Utopia\VCS\Adapter\Git;

use Utopia\VCS\Adapter\Git;

class GitHub extends Git
{

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
}