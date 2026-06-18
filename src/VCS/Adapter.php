<?php

namespace Utopia\VCS;

use Exception;
use Utopia\VCS\Exception\ProviderRateLimited;
use Utopia\VCS\Exception\ProviderRequestFailed;
use Utopia\VCS\Exception\ProviderServerError;

abstract class Adapter
{
    public const CLONE_TYPE_BRANCH = 'branch';
    public const CLONE_TYPE_TAG = 'tag';
    public const CLONE_TYPE_COMMIT = 'commit';

    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_HEAD = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';
    public const METHOD_CONNECT = 'CONNECT';
    public const METHOD_TRACE = 'TRACE';

    public const TYPE_GIT = 'git';
    public const TYPE_SVN = 'svn';

    protected bool $selfSigned = true;

    protected string $endpoint;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = [];

    /**
     * Get Adapter Name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get Adapter Type
     *
     * @return string
     */
    abstract public function getType(): string;

    /**
     * Initialize Variables
     *
     * @param string $installationId
     * @param string $privateKey
     * @param string|null $appId
     * @param string|null $accessToken
     * @param string|null $refreshToken
     * @return void
     */
    abstract public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void;

    /**
     * Generate Access Token
     *
     * @param string $privateKey
     * @param string $appId
     * @return void
     */
    abstract protected function generateAccessToken(string $privateKey, string $appId): void;

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
     * For GitHub: Uses installationId to identify the GitHub App installation
     * For Gitea: Requires repositoryId since OAuth tokens can access multiple organizations
     *
     * @param string $installationId Installation ID (GitHub) or empty string (Gitea)
     * @param int|null $repositoryId Repository ID (required for Gitea, ignored by GitHub)
     * @return string Owner login/username
     */
    abstract public function getOwnerName(string $installationId, ?int $repositoryId = null): string;

    /**
     * Determines whether the installation has access to all repositories or specific repositories
     *
     * @return bool True if installation has access to all repositories, false if it has access to specific repositories
     *
     * @throws Exception
     */
    abstract public function hasAccessToAllRepositories(): bool;

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
    abstract public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array;

    /**
     * Get repository for the installation
     *
     * @param string $repositoryName
     * @return array<mixed>
     */
    abstract public function getInstallationRepository(string $repositoryName): array;

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
    abstract public function deleteRepository(string $owner, string $repositoryName): bool;

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
     * Get files changed in a pull request
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the repository
     * @param int $pullRequestNumber The pull request number
     * @return array<mixed> List of files changed in the pull request
     */
    abstract public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array;

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
     * @param string $commentId  The ID of the comment to update
     * @param string $comment    The updated comment content
     * @return string            The ID of the updated comment
     */
    abstract public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string;

    /**
     * Generates a clone command using app access token
     */
    abstract public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): string;

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
     * Creates a check run for a commit.
     * status can be one of: queued, in_progress
     * Use updateCheckRun() to set conclusion and mark the run as completed.
     *
     * @param array<mixed> $annotations
     * @param array<mixed> $images
     * @param array<mixed> $actions
     * @return array<mixed>
     */
    public function createCheckRun(
        string $owner,
        string $repositoryName,
        string $headSha,
        string $name,
        string $status = 'queued',
        string $conclusion = '',
        string $title = '',
        string $summary = '',
        string $text = '',
        array $annotations = [],
        array $images = [],
        array $actions = [],
        string $detailsUrl = '',
        string $externalId = '',
        string $startedAt = '',
        string $completedAt = '',
    ): array {
        throw new \Exception('createCheckRun() is not implemented for ' . $this->getName());
    }

    /**
     * Gets a check run by ID.
     *
     * @return array<mixed>
     */
    public function getCheckRun(string $owner, string $repositoryName, int $checkRunId): array
    {
        throw new \Exception('getCheckRun() is not implemented for ' . $this->getName());
    }

    /**
     * Updates an existing check run.
     * status can be one of: queued, in_progress, completed
     * conclusion (required when status=completed) can be one of: action_required, cancelled, failure, neutral, success, skipped, timed_out
     *
     * @param array<mixed> $annotations
     * @param array<mixed> $images
     * @param array<mixed> $actions
     * @return array<mixed>
     */
    public function updateCheckRun(
        string $owner,
        string $repositoryName,
        int $checkRunId,
        string $name = '',
        string $status = '',
        string $conclusion = '',
        string $title = '',
        string $summary = '',
        string $text = '',
        array $annotations = [],
        array $images = [],
        array $actions = [],
        string $detailsUrl = '',
        string $externalId = '',
        string $startedAt = '',
        string $completedAt = '',
    ): array {
        throw new \Exception('updateCheckRun() is not implemented for ' . $this->getName());
    }

    /**
     * Get repository tree
     *
     * @param string $owner Owner name of the repository
     * @param string $repositoryName Name of the GitHub repository
     * @param string $branch Name of the branch
     * @param bool $recursive Whether to fetch the tree recursively
     * @return array<string> List of files in the repository
     */
    abstract public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array;

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
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<mixed> List of contents at the specified path
     */
    abstract public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array;

    /**
     * Get contents of the specified file.
     *
     * @param  string  $owner Owner name
     * @param  string  $repositoryName Name of the repository
     * @param  string  $path Path to the file
     * @param  string  $ref The name of the commit/branch/tag
     * @return array<string, mixed> File details
     */
    abstract public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array;

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

    /**
     * Maximum number of attempts (1 original + retries) for transient failures
     */
    protected int $maxAttempts = 3;

    /**
     * Maximum seconds we will honor from a server-provided Retry-After header
     * before falling back to our own exponential backoff. Prevents a single
     * unusually long server-side cooldown (e.g. GitHub secondary rate limits
     * returning Retry-After: 3600) from blocking a build indefinitely, while
     * still allowing typical Retry-After: 60 values through unchanged.
     */
    protected int $maxRetryAfterSeconds = 300;

    /**
     * Call
     *
     * Make an API call with automatic retries for transient failures.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<mixed>  $headers
     * @param  array<mixed>  $params
     * @param  bool  $decode
     * @return array<mixed>
     *
     * @throws Exception
     * @throws ProviderServerError
     * @throws ProviderRateLimited
     * @throws ProviderRequestFailed
     */
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true)
    {
        $headers = array_merge($this->headers, $headers);

        switch ($headers['content-type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $query = $this->flatten($params);
                break;

            case 'application/graphql':
                $query = $params[0];
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        $formattedHeaders = [];
        foreach ($headers as $i => $header) {
            $formattedHeaders[] = $i . ':' . $header;
        }

        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $responseHeaders = [];
            $ch = curl_init($this->endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));

            if (!$ch) {
                throw new Exception('Curl failed to initialize');
            }

            curl_setopt($ch, CURLOPT_PATH_AS_IS, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36');
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
            // 5s connect / 15s total: fail fast for build pipelines where a hung TCP
            // handshake (previously unbounded with CONNECTTIMEOUT=0) could pin a build
            // worker until the kernel's TCP timeout (~2 min) elapsed.
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);

                if (count($header) < 2) { // ignore invalid headers
                    return $len;
                }

                $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

                return $len;
            });

            if ($method != self::METHOD_GET) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
            }

            // Allow self signed certificates
            if ($this->selfSigned) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            }

            $rawResponse = \curl_exec($ch);
            $responseBody = is_string($rawResponse) ? $rawResponse : '';

            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            $responseStatus = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Handle curl-level network errors. Only retry idempotent methods —
            // a POST that errored at the transport layer may already have been
            // received and processed by the server.
            if ($curlErrno) {
                $lastException = new ProviderRequestFailed($curlError . ' with status code ' . $responseStatus, $responseStatus);
                if ($attempt < $this->maxAttempts && $this->isIdempotent($method)) {
                    \usleep($this->getRetryDelay($attempt));
                    continue;
                }
                throw $lastException;
            }

            $responseHeaders['status-code'] = $responseStatus;

            // Rate limited. Safe to retry any method: the server explicitly
            // rejected the request before processing. Detection is delegated to
            // isRateLimited() so providers can override with their own conventions.
            if ($this->isRateLimited($responseStatus, $responseHeaders)) {
                if ($attempt < $this->maxAttempts) {
                    $retryAfter = isset($responseHeaders['retry-after']) ? $this->parseRetryAfter((string) $responseHeaders['retry-after']) : null;
                    $delay = $retryAfter !== null ? min($retryAfter, $this->maxRetryAfterSeconds) * 1_000_000 : $this->getRetryDelay($attempt);
                    \usleep($delay);
                    continue;
                }
                throw new ProviderRateLimited('Rate limited by provider (HTTP ' . $responseStatus . ')', $responseStatus);
            }

            // Server errors (5xx) — retry idempotent methods only. Any 5xx
            // (including gateway codes like 502/504) may have been partially
            // processed by the backend before the failure surfaced, so
            // retrying non-idempotent methods risks duplicate side effects.
            if ($responseStatus >= 500) {
                $lastException = new ProviderServerError(
                    'Provider returned server error (HTTP ' . $responseStatus . ') for ' . $method . ' ' . $path,
                    $responseStatus
                );
                if ($attempt < $this->maxAttempts && $this->isIdempotent($method)) {
                    \usleep($this->getRetryDelay($attempt));
                    continue;
                }
                throw $lastException;
            }

            // Decode body only for success / 4xx responses. Doing this *after* the
            // 5xx branch ensures a transient 5xx with a non-JSON or empty body
            // (common from gateways/proxies during outages) still triggers retry
            // instead of being mis-classified as a JSON parse failure.
            if ($decode) {
                $responseType = (string) ($responseHeaders['content-type'] ?? '');
                $length = strpos($responseType, ';') ?: strlen($responseType);
                switch (substr($responseType, 0, $length)) {
                    case 'application/json':
                        $json = \json_decode($responseBody, true);

                        if ($json === null) {
                            throw new ProviderRequestFailed('Failed to parse response: ' . $responseBody, $responseStatus);
                        }

                        $responseBody = $json;
                        break;
                }
            }

            // Success or client error (4xx) — return immediately, no retry
            return [
                'headers' => $responseHeaders,
                'body' => $responseBody,
            ];
        }

        // Every branch in the loop above returns, throws, or continues, so this
        // is only reachable defensively (e.g. if maxAttempts is ever set to 0).
        throw $lastException ?? new ProviderServerError('All retry attempts exhausted for ' . $method . ' ' . $path, 0);
    }

    /**
     * Get retry delay in microseconds using exponential backoff with jitter
     *
     * @param  int  $attempt Current attempt number (1-based)
     * @return int Delay in microseconds
     */
    protected function getRetryDelay(int $attempt): int
    {
        // Exponential backoff (1s, 2s, 4s base) with ±50% jitter, producing a
        // multiplier in [0.5, 1.5] so concurrent callers spread out instead of
        // re-synchronising on the same backoff schedule.
        $baseDelay = pow(2, $attempt - 1) * 1_000_000;
        $jitter = 0.5 + (mt_rand() / mt_getrandmax());
        return (int) ($baseDelay * $jitter);
    }

    /**
     * Whether a response should be treated as rate-limited and therefore
     * retried. Defaults to the standard 429. Providers that signal rate limits
     * differently (e.g. GitHub's 403 + x-ratelimit-remaining: 0) should
     * override this method rather than expanding the base heuristic, so other
     * providers' 403s aren't misclassified as rate limits.
     *
     * @param  int  $status HTTP status code
     * @param  array<string, mixed>  $headers Response headers (keys lowercased)
     */
    protected function isRateLimited(int $status, array $headers): bool
    {
        return $status === 429;
    }

    /**
     * Whether the given HTTP method is safe to retry automatically on transport
     * or 5xx failures. RFC 7231 idempotent methods only — POST and PATCH may
     * have non-idempotent side effects and are excluded.
     *
     * @param  string  $method HTTP method (uppercase)
     */
    protected function isIdempotent(string $method): bool
    {
        return in_array($method, [
            self::METHOD_GET,
            self::METHOD_HEAD,
            self::METHOD_PUT,
            self::METHOD_DELETE,
            self::METHOD_OPTIONS,
        ], true);
    }

    /**
     * Parse Retry-After header value which can be either delta-seconds or an HTTP-date (RFC 7231)
     *
     * @param  string  $value Raw Retry-After header value
     * @return int Delay in seconds, minimum 1
     */
    protected function parseRetryAfter(string $value): int
    {
        $value = trim($value);

        // If it's a pure integer, treat as delta-seconds
        if (ctype_digit($value)) {
            return max((int) $value, 1);
        }

        // Try to parse as HTTP-date
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return max($timestamp - time(), 1);
        }

        // Fallback: treat as seconds, (int) cast handles edge cases
        return max((int) $value, 1);
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param  array<mixed>  $data
     * @param  string  $prefix
     * @return array<mixed>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
