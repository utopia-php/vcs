<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitHub;

class GitHubTest extends TestCase
{
    protected GitHub $github;

    public function setUp(): void
    {
        $this->github = new GitHub(new Cache(new None()));
        $privateKey = App::getEnv('GITHUB_PRIVATE_KEY') ?? '';
        $githubAppId = App::getEnv('GITHUB_APP_IDENTIFIER') ?? '';
        $installationId = App::getEnv('GITHUB_INSTALLATION_ID') ?? '';
        $this->github->initialiseVariables($installationId, $privateKey, $githubAppId);
    }

    public function testGetUser(): void
    {
        $user = $this->github->getUser('vermakhushboo');
        $this->assertEquals('vermakhushboo', $user['body']['login']);
    }

    public function testGetOwnerName(): void
    {
        $installationId = App::getEnv('GITHUB_INSTALLATION_ID') ?? '';
        $owner = $this->github->getOwnerName($installationId);
        $this->assertEquals('appwrite', $owner);
    }

    public function testListRepositoriesForGitHubApp(): void
    {
        $repos = $this->github->listRepositoriesForGitHubApp(1, 3);
        $this->assertCount(3, $repos);
    }

    public function testGetTotalReposCount(): void
    {
        $count = $this->github->getTotalReposCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCreateComment(): void
    {
        $commentId = $this->github->createComment('vermakhushboo', 'basic-js-crud', 1, 'hello');
        $this->assertNotEmpty($commentId);
    }

    public function testUpdateComment(): void
    {
        $commentId = $this->github->updateComment('vermakhushboo', 'basic-js-crud', 1431560395, 'update');
        $this->assertNotEmpty($commentId);
    }

    public function testDownloadRepositoryZip(): void
    {
        // download the zip archive of the repo
        $zipContents = $this->github->downloadRepositoryZip('appwrite', 'demos-for-react', 'main');

        // Save the ZIP archive to a file
        file_put_contents('./desktop/hello-world.zip', $zipContents);

        // Assert that the file was saved successfully
        $this->assertFileExists('./desktop/hello-world.zip');
    }

    public function testDownloadRepositoryTar(): void
    {
        // download the tar archive of the repo
        $tarContents = $this->github->downloadRepositoryTar('appwrite', 'demos-for-react', 'main');

        // Save the TAR archive to a file
        file_put_contents('./desktop/hello-world1.tar', $tarContents);

        // Assert that the file was saved successfully
        $this->assertFileExists('./desktop/hello-world1.tar');
    }

    public function testForkRepository(): void
    {
        // Fork a repository into authenticated user's account with custom name
        $response = $this->github->forkRepository('appwrite', 'demos-for-astro', name: 'fork-api-test-clone');
        // Assert that the forked repo has the expected name
        $this->assertEquals('fork-api-test-clone', $response['name']);
    }

    public function testGenerateGitCloneCommand(): void
    {
        $gitCloneCommand = $this->github->generateGitCloneCommand('vermakhushboo', 'Amigo', 'main', '', '');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);
    }

    public function testParseWebhookEventPayload(): void
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
                    "label": "vermakhushboo:test"
                },
                "base": {
                    "label": "vermakhushboo:main"
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

        $pushResult = $this->github->parseWebhookEventPayload('push', $payload_push);
        $this->assertEquals('main', $pushResult['branch']);
        $this->assertEquals('603754812', $pushResult['repositoryId']);

        $pullRequestResult = $this->github->parseWebhookEventPayload('pull_request', $payload_pull_request);
        $this->assertEquals('opened', $pullRequestResult['action']);
        $this->assertEquals(1, $pullRequestResult['pullRequestNumber']);

        $uninstallResult = $this->github->parseWebhookEventPayload('installation', $payload_uninstall);
        $this->assertEquals('deleted', $uninstallResult['action']);
        $this->assertEquals(1234, $uninstallResult['installationId']);
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = $this->github->getRepositoryName('432284323');
        $this->assertEquals('basic-js-crud', $repositoryName);
    }

    public function testListBranches(): void
    {
        $branches = $this->github->listBranches('vermakhushboo', 'basic-js-crud');
        $this->assertIsArray($branches);
        $this->assertNotEmpty($branches);
    }

    public function testGetRepositoryLanguages(): void
    {
        $languages = $this->github->getRepositoryLanguages('vermakhushboo', 'basic-js-crud');

        $this->assertIsArray($languages);

        $this->assertContains('JavaScript', $languages);
        $this->assertContains('HTML', $languages);
        $this->assertContains('CSS', $languages);
    }

    public function testListRepositoryContents(): void
    {
        $contents = $this->github->listRepositoryContents('appwrite', 'appwrite', 'src/Appwrite');
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);
    }

    public function testGetBranchPullRequest(): void
    {
        $result = $this->github->getBranchPullRequest('vermakhushboo', 'basic-js-crud', 'test');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGetPullRequest(): void
    {
        $owner = 'vermakhushboo';
        $repositoryName = 'basic-js-crud';
        $pullRequestNumber = 1;

        $result = $this->github->getPullRequest($owner, $repositoryName, $pullRequestNumber);

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

        $result = $this->github->getComment($owner, $repositoryName, $commentId);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }
}
