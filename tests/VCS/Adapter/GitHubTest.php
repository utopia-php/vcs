<?php

namespace Utopia\Tests;

use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
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

    public function testGetOwnerName(): void
    {
        $installationId = App::getEnv('INSTALLATION_ID') ?? '';
        $owner = $this->vcsAdapter->getOwnerName($installationId);
        $this->assertEquals('test-kh', $owner);
    }

    public function testListRepositories(): void
    {
        $repos = $this->vcsAdapter->listRepositoriesForVCSApp(1, 2);
        $this->assertCount(2, $repos);
    }

    public function testGetTotalReposCount(): void
    {
        $count = $this->vcsAdapter->getTotalReposCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCreateComment(): void
    {
        $commentId = $this->vcsAdapter->createComment('test-kh', 'test2', 1, 'hello');
        $this->assertNotEmpty($commentId);
    }

    public function testUpdateComment(): void
    {
        $commentId = $this->vcsAdapter->updateComment('test-kh', 'test2', 1630320767, 'update');
        $this->assertNotEmpty($commentId);
    }

    public function testDownloadRepositoryZip(): void
    {
        // download the zip archive of the repo
        $zipContents = $this->vcsAdapter->downloadRepositoryZip('test-kh', 'test2', 'main');

        // Save the ZIP archive to a file
        file_put_contents('./hello-world.zip', $zipContents);

        // Assert that the file was saved successfully
        $this->assertFileExists('./hello-world.zip');
    }

    public function testDownloadRepositoryTar(): void
    {
        // download the tar archive of the repo
        $tarContents = $this->vcsAdapter->downloadRepositoryTar('appwrite', 'demos-for-react', 'main');

        // Save the TAR archive to a file
        file_put_contents('./hello-world.tar', $tarContents);

        // Assert that the file was saved successfully
        $this->assertFileExists('./hello-world.tar');
    }

    public function testForkRepository(): void
    {
        // Fork a repository into authenticated user's account with custom name
        $response = $this->vcsAdapter->forkRepository('appwrite', 'demos-for-astro', name: 'fork-api-test-clone');
        // Assert that the forked repo has the expected name
        $this->assertEquals('fork-api-test-clone', $response);
        $this->vcsAdapter->deleteRepository("test-kh", "fork-api-test-clone");
    }

    public function testGenerateCloneCommand(): void
    {
        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', 'main', '', '');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);
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

    public function testGetRepositoryName(): void
    {
        $repositoryName = $this->vcsAdapter->getRepositoryName('432284323');
        $this->assertEquals('basic-js-crud', $repositoryName);
    }

    public function testListBranches(): void
    {
        $branches = $this->vcsAdapter->listBranches('vermakhushboo', 'basic-js-crud');
        $this->assertIsArray($branches);
        $this->assertNotEmpty($branches);
    }

    public function testGetRepositoryLanguages(): void
    {
        $languages = $this->vcsAdapter->getRepositoryLanguages('vermakhushboo', 'basic-js-crud');

        $this->assertIsArray($languages);

        $this->assertContains('JavaScript', $languages);
        $this->assertContains('HTML', $languages);
        $this->assertContains('CSS', $languages);
    }

    public function testListRepositoryContents(): void
    {
        $contents = $this->vcsAdapter->listRepositoryContents('appwrite', 'appwrite', 'src/Appwrite');
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);
    }

    public function testGetBranchPullRequest(): void
    {
        $result = $this->vcsAdapter->getBranchPullRequest('vermakhushboo', 'basic-js-crud', 'test');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
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

    public function testGetComment(): void
    {
        $owner = 'vermakhushboo';
        $repositoryName = 'basic-js-crud';
        $commentId = '1431560395';

        $result = $this->vcsAdapter->getComment($owner, $repositoryName, $commentId);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }
}
