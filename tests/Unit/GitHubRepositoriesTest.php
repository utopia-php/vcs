<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Tests\Fixtures\GitHubRepositories;

final class GitHubRepositoriesTest extends TestCase
{
    public function testSearchPrivateProfile(): void
    {
        $repositories = [['id' => 2, 'name' => 'private-repository']];
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'all']],
            ['headers' => ['status-code' => 422]],
            ['body' => ['account' => ['login' => 'Private-Owner']]],
            ['body' => ['repositories' => $repositories, 'total_count' => 2]],
        ]);

        $result = $adapter->searchRepositories('private-owner', 2, 1);

        $this->assertSame(['items' => $repositories, 'total' => 2], $result);
        $this->assertSame('/installation/repositories', $adapter->requests[3]['path']);
        $this->assertSame(['page' => 2, 'per_page' => 1], $adapter->requests[3]['params']);
    }

    public function testSearchPrivateProfileFiltersBeforePagination(): void
    {
        $firstPage = array_map(fn (int $id) => ['id' => $id, 'name' => 'unrelated-' . $id], range(1, 100));
        $firstPage[99]['name'] = 'match-first';
        $match = ['id' => 101, 'name' => 'MATCH-second'];
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'all']],
            ['headers' => ['status-code' => 422]],
            ['body' => ['account' => ['login' => 'private-owner']]],
            ['body' => ['repositories' => $firstPage]],
            ['body' => ['repositories' => [$match]]],
        ]);

        $result = $adapter->searchRepositories('private-owner', 2, 1, 'match');

        $this->assertSame(['items' => [$match], 'total' => 2], $result);
        $this->assertSame(['page' => 1, 'per_page' => 100], $adapter->requests[3]['params']);
        $this->assertSame(['page' => 2, 'per_page' => 100], $adapter->requests[4]['params']);
    }

    public function testSearchUnknownOwner(): void
    {
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'all']],
            ['headers' => ['status-code' => 422]],
            ['body' => ['account' => ['login' => 'private-owner']]],
        ]);

        $this->assertSame(['items' => [], 'total' => 0], $adapter->searchRepositories('unknown-owner', 1, 10));
    }

    public function testSearchUsesSuccessfulProviderResponse(): void
    {
        $repositories = [['id' => 1, 'name' => 'repository']];
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'all']],
            ['headers' => ['status-code' => 200], 'body' => ['items' => $repositories, 'total_count' => 1]],
        ]);

        $this->assertSame(['items' => $repositories, 'total' => 1], $adapter->searchRepositories('owner', 1, 10));
    }

    #[DataProvider('providerErrors')]
    public function testSearchProviderErrors(int $status): void
    {
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'all']],
            ['headers' => ['status-code' => $status]],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode($status);
        $adapter->searchRepositories('owner', 1, 10);
    }

    public static function providerErrors(): array
    {
        return [[403], [429], [500]];
    }

    public function testPrivateProfileListingError(): void
    {
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'all']],
            ['headers' => ['status-code' => 422]],
            ['body' => ['account' => ['login' => 'private-owner']]],
            ['headers' => ['status-code' => 403]],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        $adapter->searchRepositories('private-owner', 1, 10);
    }
}
