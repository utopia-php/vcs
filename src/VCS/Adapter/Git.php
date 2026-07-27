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
    abstract public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array;

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

    /**
    * Create a pull request
    *
    * @param  string  $owner  Owner of the repository
    * @param  string  $repositoryName  Name of the repository
    * @param  string  $title  PR title
    * @param  string  $head  Source branch
    * @param  string  $base  Target branch
    * @param  string  $body  PR description (optional)
    * @return array<mixed> Created PR details
    */
    abstract public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array;

    /**
     * Create a webhook on a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $url Webhook URL to send events to
     * @param string $secret Webhook secret for signature validation
     * @param array<string> $events Events to trigger the webhook
     * @return int Webhook ID
     */
    abstract public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): int;


    /**
     * Create a tag in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $tagName Name of the tag (e.g., 'v1.0.0')
     * @param string $target Target commit SHA or branch name
     * @param string $message Tag message (optional)
     * @return array<mixed> Created tag details
     */
    abstract public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array;

    /**
     * Get commit statuses
     *
     * Every adapter reports each status as
     * ['state' => string, 'description' => string, 'target_url' => string, 'context' => string].
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $commitHash SHA of the commit
     * @return array<mixed> List of commit statuses
     */
    abstract public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array;

    /**
     * Filter ref names by a shell glob pattern (e.g. 'v1.*', 'v?.0.0').
     * An empty pattern returns every name unchanged.
     *
     * @param array<string> $names
     * @return array<string>
     */
    protected function matchGlob(array $names, string $pattern): array
    {
        if ($pattern === '') {
            return \array_values($names);
        }

        return \array_values(\array_filter($names, fn (string $name) => \fnmatch($pattern, $name)));
    }
}
