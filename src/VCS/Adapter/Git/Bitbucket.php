<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

class Bitbucket extends Git
{
    public const CONTENTS_FILE = 'file';

    public const CONTENTS_DIRECTORY = 'dir';

    /**
     * Largest page size Bitbucket accepts.
     */
    private const PAGE_SIZE = 100;

    /**
     * Depth used to walk a repository tree recursively. Bitbucket has no
     * "unlimited" value for max_depth, so this stands in for one.
     */
    private const MAX_TREE_DEPTH = 100;

    protected string $endpoint = 'https://api.bitbucket.org/2.0';

    /**
     * Browser-facing host. Bitbucket Cloud serves its API from a separate
     * host, so unlike the self-hosted adapters this is tracked on its own.
     */
    protected string $bitbucketUrl = 'https://bitbucket.org';

    protected string $accessToken;

    protected ?string $refreshToken = null;

    protected Cache $cache;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    /**
     * Maps the state vocabulary shared by the other adapters (GitHub's) onto
     * Bitbucket's build states.
     */
    private const COMMIT_STATE_MAP = [
        'pending' => 'INPROGRESS',
        'in_progress' => 'INPROGRESS',
        'success' => 'SUCCESSFUL',
        'failure' => 'FAILED',
        'error' => 'FAILED',
        'cancelled' => 'STOPPED',
    ];

    /**
     * Reverse of COMMIT_STATE_MAP, so getCommitStatuses() reports states in the
     * same vocabulary updateCommitStatus() accepts.
     */
    private const COMMIT_STATE_MAP_REVERSE = [
        'INPROGRESS' => 'pending',
        'SUCCESSFUL' => 'success',
        'FAILED' => 'failure',
        'STOPPED' => 'cancelled',
    ];

    /**
     * Maps Bitbucket's event keys to the pull request verbs consumers key off
     * of; both a merge and a decline count as 'closed'.
     */
    private const PULL_REQUEST_ACTION_MAP = [
        'pullrequest:created' => 'opened',
        'pullrequest:updated' => 'synchronize',
        'pullrequest:fulfilled' => 'closed',
        'pullrequest:rejected' => 'closed',
    ];

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Point the adapter at a different API base, e.g. a proxy in front of
     * Bitbucket Cloud. Expects a full base URL such as
     * 'https://api.bitbucket.org/2.0'.
     */
    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = rtrim($endpoint, '/');
    }

    /**
     * Point the adapter at a different browser-facing host, e.g.
     * 'https://bitbucket.org'.
     */
    public function setBitbucketUrl(string $bitbucketUrl): void
    {
        $this->bitbucketUrl = rtrim($bitbucketUrl, '/');
    }

    public function getName(): string
    {
        return 'bitbucket';
    }

    public function getEventHeaderName(): string
    {
        return 'x-event-key';
    }

    public function getSignatureHeaderName(): string
    {
        return 'x-hub-signature';
    }

    public function getSupportedWebhookScopes(): array
    {
        return [self::WEBHOOK_SCOPE_REPOSITORY];
    }

    public function getRepositoryUrl(string $owner, string $repositoryName): string
    {
        return "{$this->bitbucketUrl}/{$owner}/{$repositoryName}";
    }

    public function getBranchUrl(string $owner, string $repositoryName, string $branch): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/branch/{$branch}";
    }

    public function getCommitUrl(string $owner, string $repositoryName, string $commitHash): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/commits/{$commitHash}";
    }

    public function getFileUrl(string $owner, string $repositoryName, string $reference): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/src/{$reference}";
    }

    /**
     * Bitbucket has no app installation flow; it authenticates with an OAuth 2.0
     * access token (or a workspace/repository access token), passed as
     * $accessToken. $installationId, $privateKey and $appId are unused.
     */
    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if (!empty($accessToken)) {
            $this->accessToken = $accessToken;
            $this->refreshToken = $refreshToken;

            return;
        }

        throw new Exception("accessToken is required for this adapter.");
    }

    /**
     * Not applicable for this adapter - OAuth2 tokens are passed directly.
     */
    protected function generateAccessToken(string $privateKey, string $appId): void
    {
        return;
    }

    /**
     * Bitbucket paths are literal URL segments, so unlike GitHub it never
     * resolves './' or '.' to the repository root on its own.
     */
    private function normalizeRepositoryPath(string $path): string
    {
        $segments = array_filter(
            explode('/', $path),
            fn (string $segment): bool => $segment !== '' && $segment !== '.'
        );

        return implode('/', $segments);
    }

    /**
     * Encode a repository path for use in a URL while keeping its separators.
     */
    private function encodeRepositoryPath(string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', $path));

        return implode('/', $segments);
    }

    /**
     * Bitbucket's source endpoints always want an explicit ref, so fall back to
     * the repository's main branch when the caller didn't name one.
     */
    private function resolveRef(string $owner, string $repositoryName, string $ref): string
    {
        if (!empty($ref)) {
            return $ref;
        }

        $repository = $this->getRepository($owner, $repositoryName);
        $mainbranch = $repository['mainbranch'] ?? [];
        $name = is_array($mainbranch) ? ($mainbranch['name'] ?? '') : '';

        if (empty($name)) {
            throw new Exception("Unable to resolve the main branch of {$owner}/{$repositoryName}.");
        }

        return (string) $name;
    }

    /**
     * Repository responses carry Bitbucket's own field names; surface the keys
     * the other adapters report under so consumers can treat them alike.
     *
     * @param array<mixed> $repository
     * @return array<mixed>
     */
    private function normalizeRepository(array $repository): array
    {
        $fullName = (string) ($repository['full_name'] ?? '');

        // Bitbucket has no numeric repository ids; "workspace/slug" is the
        // identifier its API routes on, so that is what getRepositoryName()
        // and getOwnerName() expect to receive back.
        $repository['id'] = $fullName;
        $repository['private'] = ($repository['is_private'] ?? false) === true;
        $repository['pushed_at'] = $repository['updated_on'] ?? '';

        if (empty($repository['workspace']['slug']) && strpos($fullName, '/') !== false) {
            $repository['workspace'] = ['slug' => explode('/', $fullName)[0]];
        }

        return $repository;
    }

    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => 'Bearer ' . $this->accessToken], [
            'scm' => 'git',
            'name' => $repositoryName,
            'is_private' => $private,
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Creating repository {$repositoryName} failed with status code {$statusCode}", $statusCode);
        }

        $body = $response['body'] ?? [];

        return $this->normalizeRepository(is_array($body) ? $body : []);
    }

    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        $url = "/repositories/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Deleting repository {$repositoryName} failed with status code {$statusCode}", $statusCode);
        }

        return true;
    }

    public function getRepository(string $owner, string $repositoryName): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new RepositoryNotFound("Repository not found");
        }

        $body = $response['body'] ?? [];

        return $this->normalizeRepository(is_array($body) ? $body : []);
    }

    public function getRepositoryName(string $repositoryId): string
    {
        // Bitbucket has no numeric repository ids, so $repositoryId is the
        // "workspace/slug" pair reported as `id` by createRepository().
        $url = "/repositories/{$repositoryId}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new RepositoryNotFound("Repository {$repositoryId} not found");
        }

        $responseBody = $response['body'] ?? [];

        return $responseBody['name'] ?? '';
    }

    public function hasAccessToAllRepositories(): bool
    {
        return true;
    }

    public function getInstallationRepository(string $repositoryName): array
    {
        throw new Exception("getInstallationRepository is not applicable for this adapter");
    }

    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        $url = "/repositories/{$owner}?page={$page}&pagelen={$per_page}";

        if (!empty($search)) {
            // Bitbucket's filter grammar quotes string literals, so escape the
            // characters that would otherwise break out of the quoted value.
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $search);
            $url .= '&q=' . urlencode("name~\"{$escaped}\"");
        }

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            return ['items' => [], 'total' => 0];
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            return ['items' => [], 'total' => 0];
        }

        $repositories = [];
        foreach (($responseBody['values'] ?? []) as $repository) {
            $repositories[] = [
                'id' => $repository['full_name'] ?? '',
                'name' => $repository['name'] ?? '',
                'description' => $repository['description'] ?? '',
                'private' => ($repository['is_private'] ?? false) === true,
                'pushed_at' => $repository['updated_on'] ?? '',
            ];
        }

        // `size` is optional on Bitbucket's paginated responses, so fall back to
        // what this page actually carried.
        return [
            'items' => $repositories,
            'total' => (int) ($responseBody['size'] ?? \count($repositories)),
        ];
    }

    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        $suffix = $recursive ? '&max_depth=' . self::MAX_TREE_DEPTH : '';

        $items = $this->listSource($owner, $repositoryName, '', $branch, $suffix);

        return array_column($items, 'path');
    }

    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        $items = $this->listSource($owner, $repositoryName, $path, $ref);

        $contents = [];
        foreach ($items as $item) {
            $itemPath = (string) ($item['path'] ?? '');
            $contents[] = [
                'name' => basename($itemPath),
                'size' => $item['size'] ?? 0,
                'type' => ($item['type'] ?? '') === 'commit_directory' ? self::CONTENTS_DIRECTORY : self::CONTENTS_FILE,
            ];
        }

        return $contents;
    }

    /**
     * List entries of a directory in the repository, following pagination.
     * Returns an empty list when the ref or path doesn't exist.
     *
     * @param string $suffix Extra query string to append, e.g. '&max_depth=100'
     * @return array<mixed>
     */
    private function listSource(string $owner, string $repositoryName, string $path, string $ref, string $suffix = ''): array
    {
        try {
            $ref = $this->resolveRef($owner, $repositoryName, $ref);
        } catch (Exception $e) {
            return [];
        }

        $path = $this->normalizeRepositoryPath($path);
        $base = "/repositories/{$owner}/{$repositoryName}/src/" . rawurlencode($ref) . '/' . $this->encodeRepositoryPath($path);

        $items = [];
        $page = 1;
        do {
            $url = $base . '?pagelen=' . self::PAGE_SIZE . "&page={$page}" . $suffix;

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                return $page === 1 ? [] : $items;
            }

            $responseBody = $response['body'] ?? [];
            if (!is_array($responseBody)) {
                break;
            }

            $values = $responseBody['values'] ?? [];
            if (!is_array($values)) {
                break;
            }

            $items = array_merge($items, $values);
            $page++;
        } while (!empty($responseBody['next']));

        return $items;
    }

    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        try {
            $ref = $this->resolveRef($owner, $repositoryName, $ref);
        } catch (Exception $e) {
            throw new FileNotFound();
        }

        $path = $this->normalizeRepositoryPath($path);
        $url = "/repositories/{$owner}/{$repositoryName}/src/" . rawurlencode($ref) . '/' . $this->encodeRepositoryPath($path);

        $metaResponse = $this->call(self::METHOD_GET, $url . '?format=meta', ['Authorization' => 'Bearer ' . $this->accessToken]);

        $metaHeaders = $metaResponse['headers'] ?? [];
        if (($metaHeaders['status-code'] ?? 0) !== 200) {
            throw new FileNotFound();
        }

        $meta = $metaResponse['body'] ?? [];
        if (!is_array($meta) || ($meta['type'] ?? '') !== 'commit_file') {
            throw new FileNotFound();
        }

        // Bitbucket serves file contents raw, typed after the file extension, so
        // don't let the response be decoded as JSON.
        $contentResponse = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken], [], false);

        $contentHeaders = $contentResponse['headers'] ?? [];
        if (($contentHeaders['status-code'] ?? 0) !== 200) {
            throw new FileNotFound();
        }

        $content = $contentResponse['body'] ?? '';
        $content = is_string($content) ? $content : '';

        $commit = $meta['commit'] ?? [];

        return [
            // Bitbucket exposes no blob id, so report the commit the file was
            // last changed in — the closest stable identifier it gives us.
            'sha' => is_array($commit) ? ($commit['hash'] ?? '') : '',
            'size' => $meta['size'] ?? \strlen($content),
            'content' => $content,
        ];
    }

    /**
     * Bitbucket doesn't compute language statistics; a repository carries a
     * single, manually set `language` field, which this reports when present.
     */
    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        $repository = $this->getRepository($owner, $repositoryName);

        $language = (string) ($repository['language'] ?? '');

        return empty($language) ? [] : [$language];
    }

    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array
    {
        if (empty($branch)) {
            $branch = $this->resolveDefaultBranch($owner, $repositoryName);
        }

        $url = "/repositories/{$owner}/{$repositoryName}/src";

        // File paths are sent as form field names. A leading slash keeps a file
        // called e.g. 'message' from being read as commit metadata; Bitbucket
        // treats every path as absolute from the repository root either way.
        $payload = [
            'message' => $message,
            'branch' => $branch,
            '/' . $this->normalizeRepositoryPath($filepath) => $content,
        ];

        $response = $this->call(
            self::METHOD_POST,
            $url,
            [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'content-type' => 'application/x-www-form-urlencoded',
            ],
            $payload
        );

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create file {$filepath}: HTTP {$statusCode}", $statusCode);
        }

        // The response body is empty; the new commit is named by the Location header.
        $location = (string) ($responseHeaders['location'] ?? '');
        $commitHash = '';
        if (\preg_match('#/commit/([0-9a-f]+)#', $location, $matches) === 1) {
            $commitHash = $matches[1];
        }

        return [
            'path' => $filepath,
            'branch' => $branch,
            'commitHash' => $commitHash,
        ];
    }

    /**
     * Name of the branch a commit lands on when the caller didn't pick one.
     * An empty repository has no main branch yet, and Bitbucket names the
     * branch of its root commit after whatever we ask for, so default to 'main'.
     */
    private function resolveDefaultBranch(string $owner, string $repositoryName): string
    {
        $repository = $this->getRepository($owner, $repositoryName);
        $mainbranch = $repository['mainbranch'] ?? [];
        $name = is_array($mainbranch) ? ($mainbranch['name'] ?? '') : '';

        return empty($name) ? 'main' : (string) $name;
    }

    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/refs/branches";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => 'Bearer ' . $this->accessToken], [
            'name' => $newBranchName,
            'target' => ['hash' => $oldBranchName],
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create branch {$newBranchName}: HTTP {$statusCode}", $statusCode);
        }

        return $response['body'] ?? [];
    }

    public function listBranches(string $owner, string $repositoryName): array
    {
        return $this->listRefs($owner, $repositoryName, 'branches');
    }

    public function listTags(string $owner, string $repositoryName, string $search = ''): array
    {
        return $this->matchGlob($this->listRefs($owner, $repositoryName, 'tags'), $search);
    }

    /**
     * @param string $type 'branches' or 'tags'
     * @return array<string>
     */
    private function listRefs(string $owner, string $repositoryName, string $type): array
    {
        $names = [];
        $page = 1;
        do {
            $url = "/repositories/{$owner}/{$repositoryName}/refs/{$type}?pagelen=" . self::PAGE_SIZE . "&page={$page}";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                return $page === 1 ? [] : $names;
            }

            $responseBody = $response['body'] ?? [];
            if (!is_array($responseBody)) {
                break;
            }

            $values = $responseBody['values'] ?? [];
            if (!is_array($values)) {
                break;
            }

            foreach ($values as $ref) {
                $names[] = $ref['name'] ?? '';
            }

            $page++;
        } while (!empty($responseBody['next']));

        return $names;
    }

    public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/refs/tags";

        $payload = [
            'name' => $tagName,
            'target' => ['hash' => $target],
        ];

        if (!empty($message)) {
            $payload['message'] = $message;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => 'Bearer ' . $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create tag {$tagName}: HTTP {$statusCode}", $statusCode);
        }

        return $response['body'] ?? [];
    }

    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($commitHash);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Commit not found or inaccessible");
        }

        $commit = $response['body'] ?? [];

        return $this->parseCommit(is_array($commit) ? $commit : []);
    }

    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commits/" . rawurlencode($branch) . '?pagelen=1';

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get latest commit: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        $values = is_array($responseBody) ? ($responseBody['values'] ?? []) : [];

        if (empty($values[0])) {
            throw new Exception("Latest commit response is missing required information.");
        }

        return $this->parseCommit($values[0]);
    }

    /**
     * Normalize a Bitbucket commit into the shape every adapter reports.
     *
     * @param array<mixed> $commit
     * @return array<mixed>
     */
    private function parseCommit(array $commit): array
    {
        $author = $commit['author'] ?? [];
        $author = is_array($author) ? $author : [];
        $user = $author['user'] ?? [];
        $user = is_array($user) ? $user : [];
        $userLinks = is_array($user['links'] ?? null) ? $user['links'] : [];

        // Unlinked authors are only described by a raw "Name <email>" string.
        $name = $user['display_name'] ?? '';
        if (empty($name)) {
            $name = \trim(\preg_replace('/<[^>]*>/', '', (string) ($author['raw'] ?? '')) ?? '');
        }

        return [
            'commitAuthor' => empty($name) ? 'Unknown' : $name,
            'commitMessage' => $commit['message'] ?? 'No message',
            'commitHash' => $commit['hash'] ?? '',
            'commitUrl' => $commit['links']['html']['href'] ?? '',
            'commitAuthorAvatar' => $userLinks['avatar']['href'] ?? '',
            'commitAuthorUrl' => $userLinks['html']['href'] ?? '',
        ];
    }

    public function updateCommitStatus(string $repositoryName, string $SHA, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($SHA) . '/statuses/build';

        // Bitbucket identifies a status by its key and overwrites a status
        // posted under a key it already has, so the context doubles as the key.
        $key = empty($context) ? $this->getName() : $context;

        $payload = [
            'key' => $key,
            'name' => $key,
            'state' => self::COMMIT_STATE_MAP[$state] ?? $state,
            // A build status without a URL is rejected, so point at the commit
            // itself when the caller has nowhere better to link.
            'url' => empty($target_url) ? $this->getCommitUrl($owner, $repositoryName, $SHA) : $target_url,
        ];

        if (!empty($description)) {
            $payload['description'] = $description;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => 'Bearer ' . $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to update commit status: HTTP {$statusCode}", $statusCode);
        }
    }

    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/commit/" . rawurlencode($commitHash) . '/statuses?pagelen=' . self::PAGE_SIZE;

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            return [];
        }

        $statuses = [];
        foreach (($responseBody['values'] ?? []) as $status) {
            $state = (string) ($status['state'] ?? '');
            $statuses[] = [
                'state' => self::COMMIT_STATE_MAP_REVERSE[$state] ?? $state,
                'description' => $status['description'] ?? '',
                'target_url' => $status['url'] ?? '',
                'context' => $status['key'] ?? '',
            ];
        }

        return $statuses;
    }

    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests";

        $payload = [
            'title' => $title,
            'source' => ['branch' => ['name' => $head]],
            'destination' => ['branch' => ['name' => $base]],
        ];

        if (!empty($body)) {
            $payload['description'] = $body;
        }

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => 'Bearer ' . $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create pull request: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        $responseBody = is_array($responseBody) ? $responseBody : [];

        // Bitbucket calls it `id`; report it under `number` too, as the other
        // adapters do.
        $responseBody['number'] = $responseBody['id'] ?? 0;

        return $responseBody;
    }

    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get pull request: HTTP {$statusCode}", $statusCode);
        }

        $pullRequest = $response['body'] ?? [];

        return $this->parsePullRequest(is_array($pullRequest) ? $pullRequest : []);
    }

    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        $query = urlencode("source.branch.name=\"{$branch}\"");
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests?state=OPEN&q={$query}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to list pull requests: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        $values = is_array($responseBody) ? ($responseBody['values'] ?? []) : [];

        if (empty($values[0])) {
            return [];
        }

        return $this->parsePullRequest($values[0]);
    }

    /**
     * Normalize a Bitbucket pull request into the shape every adapter reports.
     *
     * @param array<mixed> $pullRequest
     * @return array<mixed>
     */
    private function parsePullRequest(array $pullRequest): array
    {
        $source = is_array($pullRequest['source'] ?? null) ? $pullRequest['source'] : [];
        $destination = is_array($pullRequest['destination'] ?? null) ? $pullRequest['destination'] : [];

        return [
            'number' => $pullRequest['id'] ?? 0,
            'title' => $pullRequest['title'] ?? '',
            // Bitbucket reports OPEN/MERGED/DECLINED/SUPERSEDED
            'state' => \strtolower((string) ($pullRequest['state'] ?? '')),
            'head' => [
                'ref' => $source['branch']['name'] ?? '',
                'sha' => $source['commit']['hash'] ?? '',
            ],
            'base' => [
                'ref' => $destination['branch']['name'] ?? '',
            ],
        ];
    }

    public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $files = [];
        $page = 1;
        do {
            $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/diffstat?pagelen=" . self::PAGE_SIZE . "&page={$page}";

            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

            $responseHeaders = $response['headers'] ?? [];
            $statusCode = $responseHeaders['status-code'] ?? 0;
            if ($statusCode >= 400) {
                throw new Exception("Failed to get pull request files: HTTP {$statusCode}", $statusCode);
            }

            $responseBody = $response['body'] ?? [];
            if (!is_array($responseBody)) {
                break;
            }

            $values = $responseBody['values'] ?? [];
            if (!is_array($values)) {
                break;
            }

            foreach ($values as $diff) {
                $new = is_array($diff['new'] ?? null) ? $diff['new'] : [];
                $old = is_array($diff['old'] ?? null) ? $diff['old'] : [];

                $files[] = [
                    'filename' => $new['path'] ?? $old['path'] ?? '',
                ];
            }

            $page++;
        } while (!empty($responseBody['next']));

        return $files;
    }

    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/comments";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => 'Bearer ' . $this->accessToken], [
            'content' => ['raw' => $comment],
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create comment: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody) || !array_key_exists('id', $responseBody)) {
            throw new Exception("Comment creation response is missing comment ID.");
        }

        // Comment ids are only addressable through their pull request, so carry both.
        return $pullRequestNumber . ':' . $responseBody['id'];
    }

    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        $parts = explode(':', $commentId, 2);
        if (count($parts) !== 2) {
            return '';
        }

        [$pullRequestNumber, $id] = $parts;
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/comments/{$id}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        return $response['body']['content']['raw'] ?? '';
    }

    public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string
    {
        $parts = explode(':', $commentId, 2);
        if (count($parts) !== 2) {
            throw new Exception("Invalid comment ID format: {$commentId}");
        }

        [$pullRequestNumber, $id] = $parts;
        $url = "/repositories/{$owner}/{$repositoryName}/pullrequests/{$pullRequestNumber}/comments/{$id}";

        $response = $this->call(self::METHOD_PUT, $url, ['Authorization' => 'Bearer ' . $this->accessToken], [
            'content' => ['raw' => $comment],
        ]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to update comment: HTTP {$statusCode}", $statusCode);
        }

        return $commentId;
    }

    /**
     * Create a webhook on a repository. Bitbucket identifies hooks by UUID
     * rather than by number, so this returns the UUID that deleteWebhook()
     * takes.
     *
     * @param array<string> $events Event names, either this library's ('push',
     *                              'pull_request') or Bitbucket's own keys
     *                              (e.g. 'repo:push')
     */
    public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): string
    {
        $apiUrl = "/repositories/{$owner}/{$repositoryName}/hooks";

        $payload = [
            'description' => 'utopia',
            'url' => $url,
            'active' => true,
            'events' => $this->mapWebhookEvents($events),
        ];

        if (!empty($secret)) {
            $payload['secret'] = $secret;
        }

        $response = $this->call(self::METHOD_POST, $apiUrl, ['Authorization' => 'Bearer ' . $this->accessToken], $payload);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to create webhook: HTTP {$statusCode} - " . json_encode($response['body'] ?? []), $statusCode);
        }

        $responseBody = $response['body'] ?? [];

        return $responseBody['uuid'] ?? '';
    }

    /**
     * Delete a webhook from a repository.
     */
    public function deleteWebhook(string $owner, string $repositoryName, string $webhookUuid): bool
    {
        $url = "/repositories/{$owner}/{$repositoryName}/hooks/" . rawurlencode($webhookUuid);

        $response = $this->call(self::METHOD_DELETE, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to delete webhook: HTTP {$statusCode}", $statusCode);
        }

        return true;
    }

    /**
     * Translate this library's event names into Bitbucket's event keys. A pull
     * request maps to several of them, since Bitbucket splits open, update,
     * merge and decline into separate events.
     *
     * @param array<string> $events
     * @return array<string>
     */
    private function mapWebhookEvents(array $events): array
    {
        $keys = [];
        foreach ($events as $event) {
            // Native Bitbucket keys pass through untouched
            if (\strpos($event, ':') !== false) {
                $keys[] = $event;
                continue;
            }

            $keys = match ($event) {
                'push' => \array_merge($keys, ['repo:push']),
                'pull_request' => \array_merge($keys, \array_keys(self::PULL_REQUEST_ACTION_MAP)),
                default => $keys,
            };
        }

        return \array_values(\array_unique($keys));
    }

    public function getUser(string $username): array
    {
        // Bitbucket looks accounts up by UUID or Atlassian account id; only some
        // accounts still resolve by name.
        $url = '/users/' . rawurlencode($username);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get user: HTTP {$statusCode}", $statusCode);
        }

        $body = $response['body'] ?? [];
        if (!is_array($body) || empty($body['uuid'])) {
            throw new Exception("User not found: {$username}");
        }

        // Bitbucket has no numeric user ids, and reports the handle as
        // `nickname`; surface both under the shared keys.
        $body['id'] = $body['uuid'];
        $body['username'] = $body['username'] ?? ($body['nickname'] ?? '');

        return $body;
    }

    /**
     * Account the access token belongs to.
     *
     * @return array<mixed>
     */
    public function getAuthenticatedUser(): array
    {
        $response = $this->call(self::METHOD_GET, '/user', ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to get current user: HTTP {$statusCode}", $statusCode);
        }

        $body = $response['body'] ?? [];

        return is_array($body) ? $body : [];
    }

    /**
     * Workspaces the access token can act on.
     *
     * @return array{items: array<array{id: string, name: string, slug: string}>, total: int}
     */
    public function listWorkspaces(int $page, int $per_page): array
    {
        $url = "/workspaces?page={$page}&pagelen={$per_page}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => 'Bearer ' . $this->accessToken]);

        $responseHeaders = $response['headers'] ?? [];
        $statusCode = $responseHeaders['status-code'] ?? 0;
        if ($statusCode >= 400) {
            throw new Exception("Failed to list workspaces: HTTP {$statusCode}", $statusCode);
        }

        $responseBody = $response['body'] ?? [];
        $values = is_array($responseBody) ? ($responseBody['values'] ?? []) : [];

        $workspaces = [];
        foreach ($values as $workspace) {
            $workspaces[] = [
                'id' => (string) ($workspace['uuid'] ?? ''),
                'name' => $workspace['name'] ?? ($workspace['slug'] ?? ''),
                'slug' => $workspace['slug'] ?? '',
            ];
        }

        return [
            'items' => $workspaces,
            'total' => (int) ($responseBody['size'] ?? \count($workspaces)),
        ];
    }

    /**
     * Bitbucket has no installations and no numeric repository ids, so
     * $installationId and $repositoryId are both unused: the owner is always
     * the workspace of the account the token belongs to.
     */
    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        $user = $this->getAuthenticatedUser();

        // A personal workspace is named after its account. `username` is only
        // reported for the authenticated account itself, hence the fallback.
        return (string) ($user['username'] ?? ($user['nickname'] ?? ''));
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string
    {
        if (empty($rootDirectory) || $rootDirectory === '/') {
            $rootDirectory = '*';
        }

        // Bitbucket clone URL format: https://x-token-auth:{token}@host/owner/repo.git
        $baseUrl = $this->bitbucketUrl;
        if (!empty($this->accessToken)) {
            $baseUrl = str_replace('://', '://x-token-auth:' . urlencode($this->accessToken) . '@', $this->bitbucketUrl);
        }

        $cloneUrl = escapeshellarg("{$baseUrl}/{$owner}/{$repositoryName}.git");
        $directory = escapeshellarg($directory);
        $rootDirectory = escapeshellarg($rootDirectory);

        $commands = [
            "mkdir -p {$directory}",
            "cd {$directory}",
            "git config --global init.defaultBranch main",
            "git init",
            "git remote add origin {$cloneUrl}",
            "git config core.sparseCheckout true",
            "echo {$rootDirectory} >> .git/info/sparse-checkout",
            "git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'",
            "git config remote.origin.tagopt --no-tags",
        ];

        switch ($versionType) {
            case self::CLONE_TYPE_BRANCH:
                $branchName = escapeshellarg($version);
                $commands[] = "if git ls-remote --exit-code --heads origin {$branchName}; then git pull --depth=1 origin {$branchName} && git checkout {$branchName}; else git checkout -b {$branchName}; fi";
                break;
            case self::CLONE_TYPE_COMMIT:
                $commitHash = escapeshellarg($version);
                $commands[] = "git fetch --depth=1 origin {$commitHash} && git checkout {$commitHash}";
                break;
            case self::CLONE_TYPE_TAG:
                $tagName = escapeshellarg($version);
                $commands[] = "git fetch --depth=1 origin refs/tags/{$tagName} && git checkout FETCH_HEAD";
                break;
            default:
                throw new Exception("Unsupported clone type: {$versionType}");
        }

        return implode(' && ', $commands);
    }

    public function getEvent(string $event, string $payload): array
    {
        return $this->getEvents($event, $payload)[0] ?? [];
    }

    /**
     * Bitbucket batches every ref a push touched into a single delivery, so one
     * payload can describe several branches, of which getEvent() only reports
     * the first. This reports all of them, in the order the payload lists them.
     *
     * @return array<array<mixed>>
     */
    public function getEvents(string $event, string $payload): array
    {
        $payloadArray = json_decode($payload, true);
        if ($payloadArray === null || !is_array($payloadArray)) {
            return [];
        }

        $repository = is_array($payloadArray['repository'] ?? null) ? $payloadArray['repository'] : [];
        $actor = is_array($payloadArray['actor'] ?? null) ? $payloadArray['actor'] : [];

        switch ($event) {
            case 'repo:push':
                $push = is_array($payloadArray['push'] ?? null) ? $payloadArray['push'] : [];
                $changes = is_array($push['changes'] ?? null) ? $push['changes'] : [];

                $events = [];
                foreach ($changes as $change) {
                    if (!is_array($change) || !$this->isBranchChange($change)) {
                        continue;
                    }

                    $events[] = $this->parsePushChange($change, $repository, $actor);
                }

                return $events;

            case 'pullrequest:created':
            case 'pullrequest:updated':
            case 'pullrequest:fulfilled':
            case 'pullrequest:rejected':
                return [$this->parsePullRequestEvent($event, $payloadArray, $repository, $actor)];

            default:
                return [];
        }
    }

    /**
     * A push carries tag changes alongside branch ones, and the shared event
     * shape describes a branch push, so tags are left out rather than reported
     * as a branch. A change with no type at all is taken to be a branch.
     *
     * @param array<mixed> $change
     */
    private function isBranchChange(array $change): bool
    {
        $new = is_array($change['new'] ?? null) ? $change['new'] : [];
        $old = is_array($change['old'] ?? null) ? $change['old'] : [];

        $type = $new['type'] ?? ($old['type'] ?? 'branch');

        return \in_array($type, ['branch', 'named_branch'], true);
    }

    /**
     * Identifier of the repository a webhook payload describes. Bitbucket's
     * repository UUID isn't routable on its own, so this reports the
     * "workspace/slug" pair getRepositoryName() resolves, matching the `id`
     * createRepository() and getRepository() report.
     *
     * @param array<mixed> $repository
     */
    private function getEventRepositoryId(array $repository): string
    {
        return strval($repository['full_name'] ?? '');
    }

    /**
     * @param array<mixed> $repository
     * @return array{owner: string, url: string}
     */
    private function getEventRepositoryOwner(array $repository): array
    {
        $url = (string) ($repository['links']['html']['href'] ?? '');

        $workspace = is_array($repository['workspace'] ?? null) ? $repository['workspace'] : [];
        $owner = (string) ($workspace['slug'] ?? '');

        $fullName = (string) ($repository['full_name'] ?? '');
        if (empty($owner) && strpos($fullName, '/') !== false) {
            $owner = explode('/', $fullName)[0];
        }

        return ['owner' => $owner, 'url' => $url];
    }

    /**
     * @param array<mixed> $change
     * @param array<mixed> $repository
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    private function parsePushChange(array $change, array $repository, array $actor): array
    {
        $actorLinks = is_array($actor['links'] ?? null) ? $actor['links'] : [];
        ['owner' => $owner, 'url' => $repositoryUrl] = $this->getEventRepositoryOwner($repository);

        $new = is_array($change['new'] ?? null) ? $change['new'] : [];
        $old = is_array($change['old'] ?? null) ? $change['old'] : [];

        // A deleted branch is reported as a change with no new state
        $branch = $new['name'] ?? ($old['name'] ?? '');
        $target = is_array($new['target'] ?? null) ? $new['target'] : [];
        $author = is_array($target['author'] ?? null) ? $target['author'] : [];
        $raw = (string) ($author['raw'] ?? '');

        $authorName = $author['user']['display_name'] ?? '';
        if (empty($authorName)) {
            $authorName = \trim(\preg_replace('/<[^>]*>/', '', $raw) ?? '');
        }

        $authorEmail = '';
        if (\preg_match('/<([^>]*)>/', $raw, $matches) === 1) {
            $authorEmail = $matches[1];
        }

        return [
            'branchCreated' => ($change['created'] ?? false) === true,
            'branchDeleted' => ($change['closed'] ?? false) === true,
            'branch' => $branch,
            'branchUrl' => !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . '/branch/' . $branch : '',
            'repositoryId' => $this->getEventRepositoryId($repository),
            'repositoryName' => $repository['name'] ?? '',
            'repositoryUrl' => $repositoryUrl,
            'installationId' => '', // Bitbucket has no installations
            'commitHash' => $target['hash'] ?? '',
            'owner' => $owner,
            'authorUrl' => $actorLinks['html']['href'] ?? '',
            'authorAvatarUrl' => $actorLinks['avatar']['href'] ?? '',
            'headCommitAuthorName' => $authorName,
            'headCommitAuthorEmail' => $authorEmail,
            'headCommitMessage' => $target['message'] ?? '',
            'headCommitUrl' => $target['links']['html']['href'] ?? '',
            'external' => false,
            'pullRequestNumber' => '',
            'action' => '',
            // Bitbucket's push payload carries no per-commit file lists
            'affectedFiles' => [],
        ];
    }

    /**
     * @param array<mixed> $payloadArray
     * @param array<mixed> $repository
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    private function parsePullRequestEvent(string $event, array $payloadArray, array $repository, array $actor): array
    {
        $actorLinks = is_array($actor['links'] ?? null) ? $actor['links'] : [];
        ['owner' => $owner, 'url' => $repositoryUrl] = $this->getEventRepositoryOwner($repository);

        $pullRequest = is_array($payloadArray['pullrequest'] ?? null) ? $payloadArray['pullrequest'] : [];
        $source = is_array($pullRequest['source'] ?? null) ? $pullRequest['source'] : [];
        $destination = is_array($pullRequest['destination'] ?? null) ? $pullRequest['destination'] : [];

        $branch = $source['branch']['name'] ?? '';
        $commitHash = $source['commit']['hash'] ?? '';

        // A pull request whose source lives in another repository is a
        // fork-based external contribution; defaults to false (intentional) if
        // either uuid is missing.
        $sourceRepositoryId = $source['repository']['uuid'] ?? null;
        $destinationRepositoryId = $destination['repository']['uuid'] ?? null;
        $external = $sourceRepositoryId !== null
            && $destinationRepositoryId !== null
            && $sourceRepositoryId !== $destinationRepositoryId;

        return [
            'branch' => $branch,
            'branchUrl' => !empty($repositoryUrl) && !empty($branch) ? $repositoryUrl . '/branch/' . $branch : '',
            'repositoryId' => $this->getEventRepositoryId($repository),
            'repositoryName' => $repository['name'] ?? '',
            'repositoryUrl' => $repositoryUrl,
            'installationId' => '',
            'commitHash' => $commitHash,
            'owner' => $owner,
            'authorUrl' => $actorLinks['html']['href'] ?? '',
            'authorAvatarUrl' => $actorLinks['avatar']['href'] ?? '',
            'headCommitUrl' => !empty($repositoryUrl) && !empty($commitHash) ? $repositoryUrl . '/commits/' . $commitHash : '',
            'external' => $external,
            'pullRequestNumber' => $pullRequest['id'] ?? '',
            'action' => self::PULL_REQUEST_ACTION_MAP[$event],
        ];
    }

    /**
     * Bitbucket prefixes the digest with the algorithm it used, as GitHub does.
     */
    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $signatureKey);

        return hash_equals($expected, $signature);
    }
}
