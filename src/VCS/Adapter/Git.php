<?php

namespace Utopia\VCS\Adapter;

use Utopia\VCS\Adapter;
use Utopia\Cache\Cache;

abstract class Git extends Adapter
{
    protected string $endpoint;

    protected string $accessToken;

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

    /**
     * Get Adapter Type
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_GIT;
    }
}
