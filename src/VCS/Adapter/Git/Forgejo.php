<?php

namespace Utopia\VCS\Adapter\Git;

class Forgejo extends Gitea
{
    protected string $endpoint = 'http://forgejo:3000/api/v1';

    /**
     * Get Adapter Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'forgejo';
    }

    protected function getHookType(): string
    {
        return 'forgejo';
    }

    public function getEventHeaderName(): string
    {
        return 'x-forgejo-event';
    }

    public function getSignatureHeaderName(): string
    {
        return 'x-forgejo-signature';
    }
}
