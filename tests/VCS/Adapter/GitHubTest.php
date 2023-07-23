<?php

namespace Utopia\Tests\VCS\Adapter;

use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

class GitHubTest extends Base
{
    protected function createVCSAdapter(): Git
    {
        return new GitHub(new Cache(new None()));
    }

    public function setUp(): void
    {
        $this->vcsAdapter = new GitHub(new Cache(new None()));
        $privateKey = App::getEnv('PRIVATE_KEY') ?? '';
        $githubAppId = App::getEnv('APP_IDENTIFIER') ?? '';
        $installationId = App::getEnv('INSTALLATION_ID') ?? '';
        $this->vcsAdapter->initialiseVariables($installationId, $privateKey, $githubAppId);
    }

    public function testParseWebhookEvent(): void
    {
        $payload_push = '{
            "ref": "refs/heads/main",
            "before": "1234",
            "after": "4567",
            "repository": {
                "id": 603754812,
                "node_id": "R_kgDOI_yRPA",
                "name": "testing-fork",
                "full_name": "vermakhushboo/testing-fork",
                "private": true,
                "owner": {
                    "name": "vermakhushboo"
                }
            },
            "installation": {
                "id": 1234
            }
        }';

        $payload_pull_request = '{
            "action": "opened",
            "number": 1,
            "pull_request": {
                "id": 1303283688,
                "state": "open",
                "head": {
                    "ref": "test",
                    "sha": "08e857a3ee1d1b0156502239798f558c996a664f",
                    "label": "vermakhushboo:test",
                    "user": {
                        "login": "vermakhushboo"
                    }
                },
                "base": {
                    "label": "vermakhushboo:main",
                    "user": {
                        "login": "vermakhushboo"
                    }
                }
            },
            "repository": {
                "id": 3498,
                "name": "functions-example",
                "owner": {
                    "login": "vermakhushboo"
                }
            },
            "installation": {
                "id": 9876
            }
        }';

        $payload_uninstall = '{
            "action": "deleted",
            "installation": {
                "id": 1234,
                "account": {
                    "login": "vermakhushboo"
                }
            }
        }
        ';

        $pushResult = $this->vcsAdapter->parseWebhookEvent('push', $payload_push);
        $this->assertEquals('main', $pushResult['branch']);
        $this->assertEquals('603754812', $pushResult['repositoryId']);

        $pullRequestResult = $this->vcsAdapter->parseWebhookEvent('pull_request', $payload_pull_request);
        $this->assertEquals('opened', $pullRequestResult['action']);
        $this->assertEquals(1, $pullRequestResult['pullRequestNumber']);

        $uninstallResult = $this->vcsAdapter->parseWebhookEvent('installation', $payload_uninstall);
        $this->assertEquals('deleted', $uninstallResult['action']);
        $this->assertEquals(1234, $uninstallResult['installationId']);
    }

    public function testGetComment(): void
    {
        $owner = 'vermakhushboo';
        $repositoryName = 'basic-js-crud';
        $commentId = '1431560395';

        $result = $this->vcsAdapter->getComment($owner, $repositoryName, $commentId);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = $this->vcsAdapter->getRepositoryName('432284323');
        $this->assertEquals('basic-js-crud', $repositoryName);
    }

    public function testGetPullRequest(): void
    {
        $owner = 'vermakhushboo';
        $repositoryName = 'basic-js-crud';
        $pullRequestNumber = 1;

        $result = $this->vcsAdapter->getPullRequest($owner, $repositoryName, $pullRequestNumber);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertEquals($pullRequestNumber, $result['number']);
        $this->assertEquals($owner, $result['base']['user']['login']);
        $this->assertEquals($repositoryName, $result['base']['repo']['name']);
    }

    public function testGenerateCloneCommand(): void
    {
        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', 'main', '', '');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);
    }

    public function testUpdateComment(): void
    {
        $commentId = $this->vcsAdapter->updateComment('test-kh', 'test2', 1630320767, 'update');
        $this->assertNotEmpty($commentId);
    }
}
