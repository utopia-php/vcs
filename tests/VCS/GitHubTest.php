<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\App;
use Utopia\VCS\Adapter\Git\GitHub;

class GitHubTest extends TestCase
{
    protected $github;

    public function setUp(): void
    {
        $this->github = new GitHub();
        $privateKey = App::getEnv("GITHUB_PRIVATE_KEY");
        $githubAppId = App::getEnv("GITHUB_APP_IDENTIFIER");
        $installationId = "1234"; //your GitHub App Installation ID here
        $this->github->initialiseVariables($installationId, $privateKey, $githubAppId, "vermakhushboo");
    }

    public function testGetUser(): void
    {
        $this->github->getUser("vermakhushboo");
    }

    public function testListRepositoriesForGitHubApp(): void
    {
        $repos = $this->github->listRepositoriesForGitHubApp(1, 5);
    }

    public function testGetTotalReposCount(): void
    {
        $count = $this->github->getTotalReposCount();
    }

    public function testAddComment(): void
    {
        $commentId = $this->github->addComment("vermakhushboo", "basic-js-crud", 1, "hello");
    }

    public function testUpdateComment(): void
    {
        $commentId = $this->github->updateComment("vermakhushboo", "basic-js-crud", 1431560395, "update");
    }

    public function testDownloadRepositoryZip(): void
    {
        // download the zip archive of the repo
        $zipContents = $this->github->downloadRepositoryZip("vermakhushboo", "gatsby-ecommerce-theme", "main");

        // Save the ZIP archive to a file
        file_put_contents('./desktop/hello-world.zip', $zipContents);
    }

    public function testDownloadRepositoryTar(): void
    {
        // download the tar archive of the repo
        $tarContents = $this->github->downloadRepositoryTar("vermakhushboo", "gatsby-ecommerce-theme", "main");

        // Save the TAR archive to a file
        file_put_contents('./desktop/hello-world1.tar', $tarContents);
    }

    public function testForkRepository(): void
    {
        // Fork a repository into authenticated user's account with custom name
        $response = $this->github->forkRepository("appwrite", "demos-for-astro", name: "fork-api-test-clone");
    }

    public function testGenerateGitCloneCommand(): string
    {
        $repoId = "155386150";
        $gitCloneCommand = $this->github->generateGitCloneCommand("vermakhushboo", $repoId, "main");
        return $gitCloneCommand;
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
                    "ref": "test"
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

        $this->github->parseWebhookEventPayload("push", $payload_push);
        $this->github->parseWebhookEventPayload("pull_request", $payload_pull_request);
        $this->github->parseWebhookEventPayload("installation", $payload_uninstall);
    }

    public function testGetRepositoryName(): void
    {
        $repoName = $this->github->getRepositoryName("615825784");
    }

    public function testUpdateCommitStatus(): void
    {
        $this->github->updateCommitStatus("functions-example", "a71dc759d5cbe5316c990f91f98de65d99f4ca64", "vermakhushboo", "failure", "build failed", "", "Appwrite Deployment");
    }
}
