<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\VCS\Exception\RepositoryNotFound;

class Gogs extends Gitea
{
    protected string $endpoint = 'http://gogs:3000/api/v1';

    /**
     * Get Adapter Name
     */
    public function getName(): string
    {
        return 'gogs';
    }

    protected function getHookType(): string
    {
        return 'gogs';
    }

    /**
     * Create new repository
     *
     * Gogs uses /org/{org}/repos (singular) instead of Gitea's /orgs/{org}/repos (plural).
     *
     * @return array<mixed> Details of new repository
     */
    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        $url = "/org/{$owner}/repos";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], [
            'name' => $repositoryName,
            'private' => $private,
            'auto_init' => true,
            'readme' => 'Default',
        ]);

        $result = $response['body'] ?? [];
        if (is_array($result)) {
            // Gogs' API does not expose `pushed_at`; surface `updated_at` under that key
            // for parity with the other VCS adapters (GitHub, GitLab).
            $result['pushed_at'] = $result['pushed_at'] ?? ($result['updated_at'] ?? '');
        }
        return is_array($result) ? $result : [];
    }

    /**
     * Create organization for the authenticated user.
     *
     * Gogs uses POST /user/orgs instead of Gitea's POST /orgs.
     */
    public function createOrganization(string $orgName): string
    {
        $url = "/user/orgs";

        $response = $this->call(self::METHOD_POST, $url, ['Authorization' => "token $this->accessToken"], [
            'username' => $orgName,
        ]);

        $responseBody = $response['body'] ?? [];

        return $responseBody['username'] ?? '';
    }

    /**
     * Search repositories in organization
     *
     * When no search query is given, Gogs search API returns empty results,
     * so we fall back to listing org repos directly via /orgs/{org}/repos.
     *
     * @return array<mixed>
     */
    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        if (!empty($search)) {
            return parent::searchRepositories($owner, $page, $per_page, $search);
        }

        // List all repos for the org directly
        $url = "/orgs/{$owner}/repos";
        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseBody = $response['body'] ?? [];
        if (!is_array($responseBody)) {
            $responseBody = [];
        }

        $total = count($responseBody);
        $offset = ($page - 1) * $per_page;
        $pagedRepos = array_slice($responseBody, $offset, $per_page);

        // Gogs' API does not expose `pushed_at`; surface `updated_at` under that key
        // for parity with the other VCS adapters (GitHub, GitLab).
        foreach ($pagedRepos as &$repo) {
            if (is_array($repo)) {
                $repo['pushed_at'] = $repo['pushed_at'] ?? ($repo['updated_at'] ?? '');
            }
        }
        unset($repo);

        return [
            'items' => $pagedRepos,
            'total' => $total,
        ];
    }

    /**
     * Get repository tree
     *
     * Gogs does not support recursive tree listing. For recursive mode,
     * we manually traverse subdirectories.
     *
     * @return array<string>
     */
    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/git/trees/" . urlencode($branch);

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode === 404) {
            return [];
        }

        $responseBody = $response['body'] ?? [];
        $entries = $responseBody['tree'] ?? [];
        $paths = [];

        foreach ($entries as $entry) {
            $paths[] = $entry['path'];

            if ($recursive && ($entry['type'] ?? '') === 'tree') {
                $subPaths = $this->getRepositoryTree($owner, $repositoryName, $entry['sha'], true);
                foreach ($subPaths as $subPath) {
                    $paths[] = $entry['path'] . '/' . $subPath;
                }
            }
        }

        return $paths;
    }

    /**
     * Get repository name by ID
     *
     * Gogs does not have /repositories/{id}. Searches all repos to find by ID.
     */
    public function getRepositoryName(string $repositoryId): string
    {
        $repo = $this->findRepositoryById((int) $repositoryId);

        return $repo['name'];
    }

    /**
     * Get owner name by repository ID
     *
     * Gogs does not have /repositories/{id}. Searches all repos to find by ID.
     */
    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        if ($repositoryId === null || $repositoryId <= 0) {
            throw new Exception("repositoryId is required for this adapter");
        }

        $repo = $this->findRepositoryById($repositoryId);
        $owner = $repo['owner'] ?? [];

        if (empty($owner['login'])) {
            throw new Exception("Owner login missing or empty in response");
        }

        return $owner['login'];
    }

    /**
     * Find a repository by its numeric ID using the search API.
     *
     * @return array<mixed> Repository data
     */
    private function findRepositoryById(int $repositoryId): array
    {
        $page = 1;
        $limit = 50;

        while ($page <= 100) {
            $url = "/repos/search?q=_&limit={$limit}&page={$page}";
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

            $responseBody = $response['body'] ?? [];
            $repos = $responseBody['data'] ?? [];

            if (empty($repos)) {
                break;
            }

            foreach ($repos as $repo) {
                if (($repo['id'] ?? 0) === $repositoryId) {
                    return $repo;
                }
            }

            if (count($repos) < $limit) {
                break;
            }

            $page++;
        }

        throw new RepositoryNotFound("Repository not found");
    }

    /**
     * Get details of a commit
     *
     * Gogs uses /repos/{owner}/{repo}/commits/{sha} (not /git/commits/{sha} like Gitea).
     *
     * @return array<mixed> Details of the commit
     */
    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $url = "/repos/{$owner}/{$repositoryName}/commits/{$commitHash}";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;
        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Commit not found or inaccessible");
        }

        $responseBody = $response['body'] ?? [];
        $commitData = $responseBody['commit'] ?? [];
        $commitAuthor = $commitData['author'] ?? [];
        $author = $responseBody['author'] ?? [];

        return [
            'commitAuthor' => $commitAuthor['name'] ?? 'Unknown',
            'commitMessage' => $commitData['message'] ?? 'No message',
            'commitAuthorAvatar' => $author['avatar_url'] ?? '',
            'commitAuthorUrl' => $author['html_url'] ?? '',
            'commitHash' => $responseBody['sha'] ?? '',
            'commitUrl' => $responseBody['html_url'] ?? '',
        ];
    }

    /**
     * Get latest commit of a branch
     *
     * Gogs ignores the sha query param, so we validate the branch exists first.
     *
     * @return array<mixed> Details of the commit
     */
    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        // Gogs ignores sha param — verify branch exists first
        $branches = $this->listBranches($owner, $repositoryName);
        if (!in_array($branch, $branches, true)) {
            throw new Exception("Branch '{$branch}' not found");
        }

        return parent::getLatestCommit($owner, $repositoryName, $branch);
    }

    /**
     * Create a file in a repository
     *
     * For the default branch (or when no branch is specified), uses the Gogs
     * contents API. For non-default branches, uses git CLI because the Gogs
     * API returns 500 when targeting an existing non-default branch.
     *
     * @return array<mixed>
     */
    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array
    {
        if (!empty($branch)) {
            // Check if branch is the default branch
            $url = "/repos/{$owner}/{$repositoryName}";
            $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);
            $defaultBranch = $response['body']['default_branch'] ?? 'master';

            if ($branch !== $defaultBranch) {
                return $this->createFileViaCli($owner, $repositoryName, $filepath, $content, $message, $branch);
            }
        }

        $url = "/repos/{$owner}/{$repositoryName}/contents/{$filepath}";

        $response = $this->call(
            self::METHOD_PUT,
            $url,
            ['Authorization' => "token $this->accessToken"],
            [
                'content' => base64_encode($content),
                'message' => $message,
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
     * Create a file on a non-default branch using git CLI.
     *
     * @return array<mixed>
     */
    private function createFileViaCli(string $owner, string $repositoryName, string $filepath, string $content, string $message, string $branch): array
    {
        $dir = $this->gitClone($owner, $repositoryName, $branch);

        try {
            $fullPath = $dir . '/' . $filepath;
            $dirPath = dirname($fullPath);
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0777, true);
            }
            file_put_contents($fullPath, $content);

            $this->exec("git -C {$dir} add " . escapeshellarg($filepath));
            $this->exec("git -C {$dir} commit -m " . escapeshellarg($message));
            $this->exec("git -C {$dir} push origin " . escapeshellarg($branch));
        } finally {
            $this->exec("rm -rf {$dir}");
        }

        return ['content' => ['path' => $filepath]];
    }

    /**
     * Create a branch
     *
     * Gogs does not support branch creation via API, so we use git CLI.
     *
     * @return array<mixed>
     */
    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        $dir = $this->gitClone($owner, $repositoryName, $oldBranchName);

        try {
            $this->exec("git -C {$dir} checkout -b " . escapeshellarg($newBranchName));
            $this->exec("git -C {$dir} push origin " . escapeshellarg($newBranchName));
        } finally {
            $this->exec("rm -rf {$dir}");
        }

        return ['name' => $newBranchName];
    }

    /**
     * Clone a repository into a temporary directory and checkout a branch.
     */
    private function gitClone(string $owner, string $repositoryName, string $branch = ''): string
    {
        $cloneUrl = str_replace('://', "://{$owner}:{$this->accessToken}@", $this->giteaUrl) . "/{$owner}/{$repositoryName}.git";

        $dir = escapeshellarg(sys_get_temp_dir() . '/gogs-' . uniqid());

        $branchArg = '';
        if (!empty($branch)) {
            $branchArg = ' -b ' . escapeshellarg($branch);
        }

        $this->exec("git clone --depth=1{$branchArg} " . escapeshellarg($cloneUrl) . " {$dir}");
        $this->exec("git -C {$dir} config user.email 'gogs@test.local'");
        $this->exec("git -C {$dir} config user.name 'Gogs Test'");

        return trim($dir, "'\"");
    }


    /**
     * Execute a shell command and throw on failure.
     */
    private function exec(string $command): string
    {
        $output = [];
        $exitCode = 0;

        \exec($command . ' 2>&1', $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            throw new Exception("Command failed (exit {$exitCode}): {$command}\n{$outputStr}");
        }

        return $outputStr;
    }

    /**
     * List repository languages
     *
     * Gogs does not support the languages endpoint.
     *
     * @return array<string>
     */
    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        throw new Exception("Listing repository languages is not supported by Gogs");
    }

    /**
     * Create a tag
     *
     * Gogs does not support tag creation via API, so we use git CLI.
     *
     * @return array<mixed>
     */
    public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array
    {
        $dir = $this->gitClone($owner, $repositoryName);

        try {
            $this->exec("git -C {$dir} fetch origin " . escapeshellarg($target));
            if (!empty($message)) {
                $this->exec("git -C {$dir} tag -a " . escapeshellarg($tagName) . " " . escapeshellarg($target) . " -m " . escapeshellarg($message));
            } else {
                $this->exec("git -C {$dir} tag " . escapeshellarg($tagName) . " " . escapeshellarg($target));
            }
            $this->exec("git -C {$dir} push origin " . escapeshellarg($tagName));
        } finally {
            $this->exec("rm -rf {$dir}");
        }

        return [
            'name' => $tagName,
            'commit' => [
                'sha' => $target,
            ],
        ];
    }

    /**
     * Create a pull request
     *
     * Gogs does not have a pull request API.
     *
     * @return array<mixed>
     */
    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        throw new Exception("Pull request API is not supported by Gogs");
    }

    /**
     * Get a pull request
     *
     * @return array<mixed>
     */
    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        throw new Exception("Pull request API is not supported by Gogs");
    }

    /**
     * Get pull request from branch
     *
     * @return array<mixed>
     */
    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        throw new Exception("Pull request API is not supported by Gogs");
    }

    /**
     * Update commit status
     *
     * Gogs does not support commit statuses API.
     */
    public function updateCommitStatus(string $repositoryName, string $commitHash, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        throw new Exception("Commit status API is not supported by Gogs");
    }

    /**
     * Get commit statuses
     *
     * Gogs does not support commit statuses API.
     *
     * @return array<mixed>
     */
    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        throw new Exception("Commit status API is not supported by Gogs");
    }

    /**
     * List branches
     *
     * Gogs supports listing branches but without pagination parameters.
     *
     * @return array<string>
     */
    public function listBranches(string $owner, string $repositoryName, int $perPage = 100, int|string|null $page = 1, string $search = ''): array
    {
        $perPage = min(max($perPage, 1), 100);
        $page = is_int($page) ? max($page, 1) : 1;
        $url = "/repos/{$owner}/{$repositoryName}/branches";

        $response = $this->call(self::METHOD_GET, $url, ['Authorization' => "token $this->accessToken"]);

        $responseHeaders = $response['headers'] ?? [];
        $responseHeadersStatusCode = $responseHeaders['status-code'] ?? 0;

        if ($responseHeadersStatusCode === 404) {
            return [];
        }

        if ($responseHeadersStatusCode >= 400) {
            throw new Exception("Failed to list branches: HTTP {$responseHeadersStatusCode}");
        }

        $responseBody = $response['body'] ?? [];

        if (!is_array($responseBody)) {
            return [];
        }

        $branches = [];
        foreach ($responseBody as $branch) {
            if (is_array($branch) && array_key_exists('name', $branch)) {
                $branches[] = $branch['name'];
            }
        }

        if ($search !== '') {
            $branches = array_values(array_filter($branches, fn ($branch) => str_starts_with($branch, $search)));
        }

        return array_slice($branches, ($page - 1) * $perPage, $perPage);
    }
}
