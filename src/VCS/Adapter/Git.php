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
     * Get Adapter Name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Is Git Flow
     *
     * @return bool
     */
    abstract public function isGitFlow(): bool;

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
     * List repositories for Git App
     * @param int $page page number
     * @param int $per_page number of results per page
     * @return array<mixed>
     */
    abstract public function listRepositoriesForGitApp($page, $per_page): array;

    /**
     * Get latest opened pull request with specific base branch
     * @return array<mixed>
     */
    abstract public function getBranchPullRequest(string $owner, string $repositoryName, string $branch): array;

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
     * Get total repositories count
     *
     * @return int
     */
    abstract public function getTotalReposCount(): int;

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
    abstract public function getComment($owner, $repositoryName, $commentId): string;

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
     * Downloads a ZIP archive of a repository.
     *
     * @param string $owner The owner of the repository.
     * @param string $repositoryName The name of the repository.
     * @param string $ref The name of the commit, branch, or tag to download.
     * @param string $path The path of the file or directory to download. Optional.
     * @return string The contents of the ZIP archive as a string.
     */
    abstract public function downloadRepositoryZip(string $owner, string $repositoryName, string $ref, string $path = ''): string;

    /**
     * Downloads a tar archive of a repository.
     *
     * @param string $owner The owner of the repository.
     * @param string $repositoryName The name of the repository.
     * @param string $ref The name of the commit, branch, or tag to download.
     * @return string The contents of the tar archive as a string.
     */
    abstract public function downloadRepositoryTar(string $owner, string $repositoryName, string $ref): string;

    /**
     * Forks a repository.
     *
     * @param string $owner The owner of the repository to fork.
     * @param string $repo The name of the repository to fork.
     * @param string|null $organization The name of the organization to fork the repository into. If not provided, the repository will be forked into the authenticated user's account.
     * @param string|null $name The name of the new forked repository. If not provided, the name will be the same as the original repository.
     * @param bool $defaultBranchOnly Whether to include only the default branch in the forked repository. Defaults to false.
     * @return string The name of the newly forked repository
     */
    abstract public function forkRepository(string $owner, string $repo, ?string $organization = null, ?string $name = null, bool $defaultBranchOnly = false): ?string;

    /**
     * Generates a git clone command using app access token
     */
    abstract public function generateGitCloneCommand(string $owner, string $repositoryName, string $branchName, string $directory, string $rootDirectory): string;

    /**
     * Parses webhook event payload
     *
     * @param  string  $payload Raw body of HTTP request
     * @param  string  $signature Signature provided by Git provider in header
     * @param  string  $signatureKey Webhook secret configured on Git provider
     * @return bool
     */
    abstract public function validateWebhook(string $payload, string $signature, string $signatureKey): bool;

    /**
     * Parses webhook event payload
     *
     * @param string $event Type of event: push, pull_request etc
     * @param string $payload The webhook payload received from Git provider
     * @return array<mixed> Parsed payload as a json object
     */
    abstract public function parseWebhookEventPayload(string $event, string $payload): array;

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
    abstract public function getRepositoryLanguages(string $owner, string $repositoryName): array;

    /**
     * List contents of the specified root directory.
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the repository
     * @param  string  $path Path to list contents from
     * @return array<mixed> List of contents at the specified path
     */
    abstract public function listRepositoryContents(string $owner, string $repositoryName, string $path = ''): array;
}
