<?php

namespace Utopia\VCS\Adapter;

use Utopia\VCS\Adapter;
use Utopia\Cache\Cache;

abstract class Git extends Adapter
{
    protected string $endpoint;

    protected string $accessToken;

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
     * Get Adapter Type
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_GIT;
    }

    /**
     * Create a file in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $filepath Path where file should be created
     * @param string $content Content of the file
     * @param string $message Commit message
     * @return array<mixed> Response from API
     */
    abstract public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file'): array;

    /**
     * Create a branch in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $newBranchName Name of the new branch
     * @param string $oldBranchName Name of the branch to branch from
     * @return array<mixed> Response from API
     */
    abstract public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array;
}
