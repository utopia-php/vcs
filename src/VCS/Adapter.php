<?php

namespace Utopia\VCS;

use Exception;

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
     * @return string
     */
    abstract public function getOwnerName(string $installationId): string;

    /**
     * Search repositories for GitHub App
     * @param string $installationId ID of the installation
     * @param string $owner Name of user or org
     * @param int $page page number
     * @param int $per_page number of results per page
     * @param string $search Query to be searched to filter repo names
     * @return array<mixed>
     *
     * @throws Exception
     */
    abstract public function searchRepositories(string $installationId, string $owner, int $page, int $per_page, string $search = ''): array;

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
     * Call
     *
     * Make an API call
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<mixed>  $params
     * @param  array<string, string>  $headers
     * @param  bool  $decode
     * @return array<mixed>
     *
     * @throws Exception
     */
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true)
    {
        $headers = array_merge($this->headers, $headers);
        $ch = curl_init($this->endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));

        if (!$ch) {
            throw new Exception('Curl failed to initialize');
        }

        $responseHeaders = [];
        $responseStatus = -1;
        $responseType = '';
        $responseBody = '';

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

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_PATH_AS_IS, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
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

        $responseBody = \curl_exec($ch) ?: '';

        if ($responseBody === true) {
            $responseBody = '';
        }

        $responseType = $responseHeaders['content-type'] ?? '';
        $responseStatus = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($decode) {
            $length = strpos($responseType, ';') ?: 0;
            switch (substr($responseType, 0, $length)) {
                case 'application/json':
                    $json = \json_decode($responseBody, true);

                    if ($json === null) {
                        throw new Exception('Failed to parse response: ' . $responseBody);
                    }

                    $responseBody = $json;
                    $json = null;
                    break;
            }
        }

        if ((curl_errno($ch)/* || 200 != $responseStatus*/)) {
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }

        curl_close($ch);

        $responseHeaders['status-code'] = $responseStatus;

        if ($responseStatus === 500) {
            echo 'Server error(' . $method . ': ' . $path . '. Params: ' . json_encode($params) . '): ' . json_encode($responseBody) . "\n";
        }

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
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
