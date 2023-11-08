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
     * Get Adapter Name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Initialize Variables
     *
     * @param string $installationId
     * @param string $privateKey
     * @param string $githubAppId
     * @return void
     */
    abstract public function initializeVariables(string $installationId, string $privateKey, string $githubAppId): void;

    /**
     * Generate Access Token
     *
     * @param string $privateKey
     * @param string $githubAppId
     * @return void
     */
    abstract protected function generateAccessToken(string $privateKey, string $githubAppId): void;

    /**
     * Get user
     *
     * @return array<mixed>
     *
     */
    abstract public function getUser(string $username): array;

    /**
     * Get owner name of the installation
     *
     * @return string
     */
    abstract public function getOwnerName(string $installationId): string;

    /**
     * Search repositories for GitHub App
     * @param string $owner Name of user or org
     * @param int $page page number
     * @param int $per_page number of results per page
     * @param string $search Query to be searched to filter repo names
     * @return array<mixed>
     *
     * @throws Exception
     */
    abstract public function searchRepositories(string $owner, int $page, int $per_page, string $search=''): array;

    /**
     * Get repository
     *
     * @return array<mixed>
     */
    abstract public function getRepository(string $owner, string $repositoryName): array;

    /**
     * Create new repository
     *
     * @return array<mixed> Details of new repository
     */
    abstract public function createRepository(string $owner, string $repositoryName, bool $private): array;

    /**
     * Delete repository
     */
    abstract public function deleteRepository(string $owner, string $repositoryName): void;

    /**
     * Get latest opened pull request with specific base branch
     * @return array<mixed>
     */
    abstract public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array;

    /**
     * Get Pull Request
     *
     * @return array<mixed> The retrieved pull request
     */
    abstract public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array;

    /**
     * Add Comment to Pull Request
     *
     * @return string
     */
    abstract public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string;

    /**
     * Get Comment of Pull Request
     *
     * @param string $owner       The owner of the repository
     * @param string $repositoryName    The name of the repository
     * @param string $commentId   The ID of the comment to retrieve
     * @return string              The retrieved comment
     */
    abstract public function getComment(string $owner, string $repositoryName, string $commentId): string;

    /**
     * Update Pull Request Comment
     *
     * @param string $owner      The owner of the repository
     * @param string $repositoryName   The name of the repository
     * @param int $commentId  The ID of the comment to update
     * @param string $comment    The updated comment content
     * @return string            The ID of the updated comment
     */
    abstract public function updateComment(string $owner, string $repositoryName, int $commentId, string $comment): string;

    /**
     * Generates a clone command using app access token
     */
    abstract public function generateCloneCommand(string $owner, string $repositoryName, string $branchName, string $directory, string $rootDirectory, string $commitHash = null): string;

    /**
     * Parses webhook event payload
     *
     * @param  string  $payload Raw body of HTTP request
     * @param  string  $signature Signature provided by Git provider in header
     * @param  string  $signatureKey Webhook secret configured on Git provider
     * @return bool
     */
    abstract public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool;

    /**
     * Parses webhook event payload
     *
     * @param string $event Type of event: push, pull_request etc
     * @param string $payload The webhook payload received from Git provider
     * @return array<mixed> Parsed payload as a json object
     */
    abstract public function getEvent(string $event, string $payload): array;

    /**
     * Fetches repository name using repository id
     *
     * @param string $repositoryId ID of the repository
     * @return string name of the repository
     */
    abstract public function getRepositoryName(string $repositoryId): string;

    /**
     * Lists branches for a given repository
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @return array<string> List of branch names as array
     */
    abstract public function listBranches(string $owner, string $repositoryName): array;

    /**
     * Updates status check of each commit
     * state can be one of: error, failure, pending, success
     */
    abstract public function updateCommitStatus(string $repositoryName, string $SHA, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void;

    /**
     * Get repository languages
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @return array<mixed> List of repository languages
     */
    abstract public function listRepositoryLanguages(string $owner, string $repositoryName): array;

    /**
     * List contents of the specified root directory.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the repository
     * @param  string  $path Path to list contents from
     * @return array<mixed> List of contents at the specified path
     */
    abstract public function listRepositoryContents(string $owner, string $repositoryName, string $path = ''): array;

    /**
     * Get details of a commit using commit hash
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $commitHash SHA of the commit
     * @return array<mixed> Details of the commit
     */
    abstract public function getCommit(string $owner, string $repositoryName, string $commitHash): array;

    /**
     * Get latest commit of a branch
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $branch Name of the branch
     * @return array<mixed> Details of the commit
     */
    abstract public function getLatestCommit(string $owner, string $repositoryName, string $branch): array;
}
