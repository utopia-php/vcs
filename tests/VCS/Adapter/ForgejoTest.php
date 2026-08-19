<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\Forgejo;

class ForgejoTest extends GiteaTest
{
    protected static string $accessToken = '';

    protected static string $owner = '';
    protected static string $avatarDomain = '/avatars/';
    protected static string $eventHeader = 'x-forgejo-event';
    protected static string $signatureHeader = 'x-forgejo-signature';

    protected function setupAdapter(): void
    {
        if (empty(static::$accessToken)) {
            $this->setupForgejo();
        }

        $adapter = new Forgejo(new Cache(new None()));
        $forgejoUrl = System::getEnv('TESTS_FORGEJO_URL', 'http://forgejo:3000');

        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: static::$accessToken,
            refreshToken: ''
        );
        $adapter->setEndpoint($forgejoUrl);
        if (empty(static::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            static::$owner = $adapter->createOrganization($orgName);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return System::getEnv('TESTS_FORGEJO_URL', 'http://forgejo:3000') . '/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }

    protected function setupForgejo(): void
    {
        $tokenFile = '/forgejo-data/gitea/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                static::$accessToken = trim($contents);
            }
        }
    }
}
