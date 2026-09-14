<?php

declare(strict_types=1);

namespace Utopia\Tests\Fixtures;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitHub;

final class GitHubRepositories extends GitHub
{
    public array $requests = [];

    public function __construct(private array $responses)
    {
        parent::__construct(new Cache(new None()));
        $this->installationId = '1234';
        $this->jwtToken = 'app-token';
        $this->accessToken = 'installation-token';
    }

    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        $this->requests[] = ['path' => $path, 'params' => $params];
        return array_shift($this->responses) ?? throw new \RuntimeException('Unexpected provider request');
    }
}
