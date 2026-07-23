<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitLab;

/**
 * Pure unit coverage for listNamespaces()' pagination math, faking the HTTP
 * layer instead of hitting a live GitLab instance. Covers the multi-page
 * boundary case a live-only test can't easily reach: a personal namespace
 * plus a group count exactly divisible by per_page, which previously
 * overflowed page 1 and advertised a final page with no items.
 */
class GitLabNamespacesPaginationTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $groups
     */
    private function makeAdapter(array $groups): GitLab
    {
        return new class ($groups) extends GitLab {
            /** @var array<int, array<string, mixed>> */
            private array $groups;

            /**
             * @param array<int, array<string, mixed>> $groups
             */
            public function __construct(array $groups)
            {
                parent::__construct(new Cache(new None()));
                $this->groups = $groups;
                $this->accessToken = 'fake-token';
            }

            /**
             * @param array<string, mixed> $headers
             * @param array<mixed> $params
             * @return array<string, mixed>
             */
            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true)
            {
                if (str_starts_with($path, '/user')) {
                    return [
                        'headers' => ['status-code' => 200],
                        'body' => [
                            'id' => 1,
                            'username' => 'harsh173',
                            'name' => 'Harsh',
                            'avatar_url' => '',
                        ],
                    ];
                }

                parse_str(explode('?', $path)[1] ?? '', $query);
                $page = (int) ($query['page'] ?? 1);
                $perPage = (int) ($query['per_page'] ?? 20);

                $slice = array_slice($this->groups, ($page - 1) * $perPage, $perPage);

                return [
                    'headers' => [
                        'status-code' => 200,
                        'x-total' => (string) count($this->groups),
                    ],
                    'body' => $slice,
                ];
            }
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function makeGroups(int $count): array
    {
        $groups = [];
        for ($i = 1; $i <= $count; $i++) {
            $groups[] = [
                'id' => $i,
                'name' => "group-{$i}",
                'full_path' => "group-{$i}",
                'avatar_url' => '',
            ];
        }
        return $groups;
    }

    public function testPageOneDoesNotOverflowWhenGroupCountFillsAPage(): void
    {
        $adapter = $this->makeAdapter($this->makeGroups(20));

        $result = $adapter->listNamespaces(1, 10);

        $this->assertCount(10, $result['items']);
        $this->assertSame('user', $result['items'][0]['kind']);
        $this->assertSame(21, $result['total']);
    }

    public function testLastPageIsNotEmptyAndTotalStaysConsistent(): void
    {
        $adapter = $this->makeAdapter($this->makeGroups(20));

        $page1 = $adapter->listNamespaces(1, 10);
        $page2 = $adapter->listNamespaces(2, 10);
        $page3 = $adapter->listNamespaces(3, 10);

        $this->assertCount(10, $page1['items']);
        $this->assertCount(10, $page2['items']);
        $this->assertCount(1, $page3['items']);
        $this->assertNotEmpty($page3['items']);

        $this->assertSame(21, $page1['total']);
        $this->assertSame(21, $page2['total']);
        $this->assertSame(21, $page3['total']);

        $totalItemsAcrossPages = count($page1['items']) + count($page2['items']) + count($page3['items']);
        $this->assertSame($page1['total'], $totalItemsAcrossPages);
    }

    public function testGroupsAreNotDroppedOrDuplicatedAcrossPageBoundary(): void
    {
        $adapter = $this->makeAdapter($this->makeGroups(20));

        $page1 = $adapter->listNamespaces(1, 10);
        $page2 = $adapter->listNamespaces(2, 10);
        $page3 = $adapter->listNamespaces(3, 10);

        $groupPaths = array_column(
            array_filter([...$page1['items'], ...$page2['items'], ...$page3['items']], fn ($n) => $n['kind'] === 'group'),
            'path',
        );

        $this->assertCount(20, $groupPaths);
        $this->assertCount(20, array_unique($groupPaths));
    }
}
