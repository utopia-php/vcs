<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;

class Gitea extends Git
{
    protected string $endpoint = 'http://gitea:3000/api/v1';

    protected string $accessToken;

    protected string $refreshToken;

    protected string $giteaUrl;

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
    public function getName(): string
    {
        return 'gitea';
    }

    /**
     * Gitea Initialisation with access token from OAuth2 flow.
     * 
     * Note: Gitea uses OAuth2 instead of GitHub's App Installation flow.
     * The parameters are adapted to maintain interface compatibility:
     * - $installationId is used to pass the access token
     * - $privateKey is used to pass the refresh token
     * - $githubAppId is used to pass the Gitea instance URL
     */
    public function initializeVariables(string $installationId, string $privateKey, string $githubAppId): void
    {
        $this->accessToken = $installationId;
        $this->refreshToken = $privateKey;
        $this->giteaUrl = rtrim($githubAppId, '/');
        $this->endpoint = $this->giteaUrl . '/api/v1';
    }

    /**
     * Generate Access Token
     * 
     * Note: This method is required by the Adapter interface but is not used for Gitea.
     * Gitea uses OAuth2 tokens that are provided directly via initializeVariables().
     */
    protected function generateAccessToken(string $privateKey, string $githubAppId): void
    {
        // Not applicable for Gitea - OAuth2 tokens are passed directly
        return;
    }

    /**
     * Create new repository
     *
     * @return array<mixed> Details of new repository
     */
    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/orgs/{$owner}/repos";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], [
            'name' => $repositoryName,
            'private' => $private,
        ]);

        return $response['body'] ?? [];
    }

    // Stub methods to satisfy abstract class requirements
    // These will be implemented in follow-up PRs

    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getRepository(string $owner, string $repositoryName): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getRepositoryName(string $repositoryId): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        throw new Exception("Not implemented yet");
    }

    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        throw new Exception("Not implemented yet");
    }

    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        throw new Exception("Not implemented yet");
    }

    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        throw new Exception("Not implemented yet");
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        throw new Exception("Not implemented yet");
    }

    public function updateComment(string $owner, string $repositoryName, int $commentId, string $comment): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getUser(string $username): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getOwnerName(string $installationId): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        throw new Exception("Not implemented yet");
    }

    public function listBranches(string $owner, string $repositoryName): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        throw new Exception("Not implemented yet");
    }

    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        throw new Exception("Not implemented yet");
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        throw new Exception("Not implemented yet");
    }

    public function getEvent(string $event, string $payload): array
    {
        throw new Exception("Not implemented yet");
    }

    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        throw new Exception("Not implemented yet");
    }
}