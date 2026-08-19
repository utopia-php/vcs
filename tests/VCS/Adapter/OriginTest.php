<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;

/**
 * Placeholder for the Origin (Cursor) adapter test suite.
 *
 * The shared adapter tests provision a throwaway repository per test and
 * delete it afterwards. Origin's partner API supports neither side of that
 * lifecycle for app installations: repository creation is denied to apps
 * entirely (only user principals can create repositories), and the API has
 * no repository deletion endpoint. Without a way to provision or clean up
 * fixture repositories, the adapter cannot be exercised automatically.
 */
class OriginTest extends TestCase
{
    public function testOrigin(): void
    {
        $this->markTestSkipped(
            'Origin cannot be tested automatically: the partner API does not allow app installations to create repositories, and it has no repository deletion endpoint, so fixture repositories can neither be provisioned nor cleaned up.'
        );
    }
}
