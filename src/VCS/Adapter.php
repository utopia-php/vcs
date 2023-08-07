<?php

namespace Utopia\VCS;

use Exception;

abstract class Adapter
{
    public const METHOD_GET = 'GET';

    public const METHOD_POST = 'POST';

    public const METHOD_PUT = 'PUT';

    public const METHOD_PATCH = 'PATCH';

    public const METHOD_DELETE = 'DELETE';

    public const METHOD_HEAD = 'HEAD';

    public const METHOD_OPTIONS = 'OPTIONS';

    public const METHOD_CONNECT = 'CONNECT';

    public const METHOD_TRACE = 'TRACE';

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
    abstract public function listRepositoriesForVCSApp($page, $per_page): array;

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
     * Generates a clone command using app access token
     */
    abstract public function generateCloneCommand(string $owner, string $repositoryName, string $branchName, string $directory, string $rootDirectory): string;

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

    /**
     * Get details of a commit
     *
     * @param  string  $owner Owner name of the repository
     * @param  string  $repositoryName Name of the GitHub repository
     * @param  string  $commitHash SHA of the commit
     * @return array<mixed> Details of the commit
     */
    abstract public function getCommit(string $owner, string $repositoryName, string $commitHash): array;

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
