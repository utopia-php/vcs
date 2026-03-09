<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;
use Utopia\VCS\Exception\FileNotFound;

class Gitea extends Git
{
    public const CONTENTS_FILE = 'file';

    public const CONTENTS_DIRECTORY = 'dir';

    protected string $endpoint = 'http://gitea:3000/api/v1';

    protected string $accessToken;

    protected ?string $refreshToken = null;

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

    public function setEndpoint(string $endpoint): void
    {
        $this->giteaUrl = rtrim($endpoint, '/');
        $this->endpoint = $this->giteaUrl . '/api/v1';
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
     * - $appId is used to pass the Gitea instance URL
     * - $accessToken is used to pass the access token
     * - $refreshToken is used to pass the refresh token
     */
    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if (!empty($accessToken)) {
            $this->accessToken = $accessToken;
            $this->refreshToken = $refreshToken;
            return;
        }

        throw new Exception("accessToken is required for Gitea adapter.");
    }

    /**
     * Generate Access Token
     *
     * Note: This method is required by the Adapter interface but is not used for Gitea.
     * Gitea uses OAuth2 tokens that are provided directly via initializeVariables().
     */
    protected function generateAccessToken(string $privateKey, string $appId): void
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

    public function createOrganization(string $orgName): string
    {
        $url = "/orgs";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], [
            'username' => $orgName,
            'visibility' => 'public',
        ]);

        $responseBody = $response['body'] ?? [];

        return $responseBody['name'] ?? '';
    }

    // Stub methods to satisfy abstract class requirements
    // These will be implemented in follow-up PRs

    public function searchRepositories(string $installationId, string $owner, int $page, int $per_page, string $search = ''): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getInstallationRepository(string $repositoryName): array
    {
        throw new Exception("Not implemented yet");
    }

    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);


        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new RepositoryNotFound("Repository not found");
        }

        return $response['body'] ?? [];
    }

    public function getRepositoryName(string $repositoryId): string
    {
        $url = "/repositories/{$repositoryId}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('name', $responseBody)) {
            throw new RepositoryNotFound("Repository not found");
        }

        return $responseBody['name'] ?? '';
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/git/trees/" . urlencode($branch) . ($recursive ? '?recursive=1' : '');

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];

        return array_column($responseBody['tree'] ?? [], 'path');
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
    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file'): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/contents/{$filepath}";

        $response = $this->call(
            self::METHOD_POST,
            $url,
            ['Authorization' => "token $this->accessToken"],
            [
                'content' => base64_encode($content),
                'message' => $message
            ]
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create file {$filepath}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    /**
     * Create a branch in a repository
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $newBranchName Name of the new branch
     * @param string $oldBranchName Name of the branch to branch from
     * @return array<mixed> Response from API
     */
    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/branches";

        $response = $this->call(
            self::METHOD_POST,
            $url,
            ['Authorization' => "token $this->accessToken"],
            [
                'new_branch_name' => $newBranchName,
                'old_branch_name' => $oldBranchName
            ]
        );

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to create branch {$newBranchName}: HTTP {$responseHeadersStatusCode}");
        }

        return $response['body'] ?? [];
    }

    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/languages";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        if (!empty($responseBody)) {
            return array_keys($responseBody);
        }

        return [];
    }

    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/contents/{$path}";
        if (!empty($ref)) {
            $url .= "?ref=" . urlencode($ref);
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode !== 200) {
            throw new FileNotFound();
        }

        $responseBody = $response['body'] ?? [];

        $encoding = $responseBody['encoding'] ?? '';
        $content = '';

        if ($encoding === 'base64') {
            $content = base64_decode($responseBody['content'] ?? '');
        } else {
            throw new FileNotFound();
        }

        return [
            'sha' => $responseBody['sha'] ?? '',
            'size' => $responseBody['size'] ?? 0,
            'content' => $content
        ];
    }

    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/contents";
        if (!empty($path)) {
            $url .= "/{$path}";
        }
        if (!empty($ref)) {
            $url .= "?ref=" . urlencode($ref);
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];

        $items = [];
        if (!empty($responseBody[0] ?? [])) {
            $items = $responseBody;
        } elseif (!empty($responseBody)) {
            $items = [$responseBody];
        }

        $contents = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? 'file';
            $contents[] = [
                'name' => $item['name'] ?? '',
                'size' => $item['size'] ?? 0,
                'type' => $type === 'file' ? self::CONTENTS_FILE : self::CONTENTS_DIRECTORY
            ];
        }

        return $contents;
    }

    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        $url = "/repos/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => "token $this->accessToken"]);


        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Deleting repository {$repositoryName} failed with status code {$responseHeadersStatusCode}");
        }

        return true;
    }

    /**
     * Create a pull request
     *
     * @param string $owner Owner of the repository
     * @param string $repositoryName Name of the repository
     * @param string $title PR title
     * @param string $head Source branch
     * @param string $base Target branch
     * @param string $body PR description (optional)
     * @return array<mixed> Created PR details
     */
    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/pulls";

        $payload = [
            'title' => $title,
            'head' => $head,
            'base' => $base,
        ];

        if (!empty($body)) {
            $payload['body'] = $body;
        }

        $response = $this->call(
            self::METHOD_POST,
            $url,
            ['Authorization' => "token $this->accessToken"],
            $payload
        );

        $responseBody = $response['body'] ?? [];

        return $responseBody;
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $url = "/repos/{$owner}/{$repositoryName}/issues/{$pullRequestNumber}/comments";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], ['body' => $comment]);

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('id', $responseBody)) {
            throw new Exception("Comment creation response is missing comment ID.");
        }

        return (string) ($responseBody['id'] ?? '');
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        $url = "/repos/{$owner}/{$repositoryName}/issues/comments/{$commentId}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        return $responseBody['body'] ?? '';
    }

    public function updateComment(string $owner, string $repositoryName, int $commentId, string $comment): string
    {
        $url = "/repos/{$owner}/{$repositoryName}/issues/comments/{$commentId}";

        $response = $this->call(self::METHOD_PATCH, $url, ['Authorization' => "token $this->accessToken"], ['body' => $comment]);

        $responseBody = $response['body'] ?? [];

        if (!array_key_exists('id', $responseBody)) {
            throw new Exception("Comment update response is missing comment ID.");
        }

        return (string) ($responseBody['id'] ?? '');
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
        $url = "/repos/{$owner}/{$repositoryName}/pulls/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        return $response['body'] ?? [];
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/pulls?state=open&sort=recentupdate&limit=1";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];

        // Filter by head branch (source branch of the PR)
        foreach ($responseBody as $pr) {
            $prHead = $pr['head'] ?? [];
            $prHeadRef = $prHead['ref'] ?? '';
            if ($prHeadRef === $branch) {
                return $pr;
            }
        }

        return [];
    }

    public function listBranches(string $owner, string $repositoryName): array
    {
        throw new Exception("Not implemented yet");
    }

    /**
     * Get details of a commit using commit hash
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @param string $commitHash SHA of the commit
     * @return array<mixed> Details of the commit
     */
    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/git/commits/{$commitHash}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Commit not found or inaccessible");
        }

        $responseBody = $response['body'] ?? [];
        $responseBodyCommit = $responseBody['commit'] ?? [];
        $responseBodyCommitAuthor = $responseBodyCommit['author'] ?? [];
        $responseBodyAuthor = $responseBody['author'] ?? [];

        return [
            'commitAuthor' => $responseBodyCommitAuthor['name'] ?? 'Unknown',
            'commitMessage' => $responseBodyCommit['message'] ?? 'No message',
            'commitAuthorAvatar' => $responseBodyAuthor['avatar_url'] ?? '',
            'commitAuthorUrl' => $responseBodyAuthor['html_url'] ?? '',
            'commitHash' => $responseBody['sha'] ?? '',
            'commitUrl' => $responseBody['html_url'] ?? '',
        ];
    }

    /**
     * Get latest commit of a branch
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @param string $branch Name of the branch
     * @return array<mixed> Details of the commit
     */
    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        $query = http_build_query([
            'sha' => $branch,
            'limit' => 1,
        ]);
        $url = "/repos/{$owner}/{$repositoryName}/commits?{$query}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Latest commit response failed with status code {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];

        if (empty($responseBody[0] ?? [])) {
            throw new Exception("Latest commit response is missing required information.");
        }

        $responseBodyFirst = $responseBody[0];
        $responseBodyFirstCommit = $responseBodyFirst['commit'] ?? [];
        $responseBodyFirstCommitAuthor = $responseBodyFirstCommit['author'] ?? [];
        $responseBodyFirstAuthor = $responseBodyFirst['author'] ?? [];

        return [
            'commitAuthor' => $responseBodyFirstCommitAuthor['name'] ?? 'Unknown',
            'commitMessage' => $responseBodyFirstCommit['message'] ?? 'No message',
            'commitHash' => $responseBodyFirst['sha'] ?? '',
            'commitUrl' => $responseBodyFirst['html_url'] ?? '',
            'commitAuthorAvatar' => $responseBodyFirstAuthor['avatar_url'] ?? '',
            'commitAuthorUrl' => $responseBodyFirstAuthor['html_url'] ?? '',
        ];
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
