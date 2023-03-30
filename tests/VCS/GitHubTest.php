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
        $this->github->getUser();
    }

    public function testListRepositoriesForGitHubApp(): void
    {
        $repos = $this->github->listRepositoriesForGitHubApp();
    }

    public function testAddComment(): void
    {
        $this->github->addComment("basic-js-crud", 1);
    }

    public function testUpdateComment(): void
    {
        $this->github->updateComment("basic-js-crud", 1431560395);
    }

    public function testDownloadRepositoryZip(): void
    {
        // download the zip archive of the repo
        $zipContents = $this->github->downloadRepositoryZip("gatsby-ecommerce-theme", "main");

        // Save the ZIP archive to a file
        file_put_contents('./desktop/hello-world.zip', $zipContents);
    }

    public function testDownloadRepositoryTar():void
    {
        // download the tar archive of the repo
        $tarContents = $this->github->downloadRepositoryTar("gatsby-ecommerce-theme", "main");

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
        $gitCloneCommand = $this->github->generateGitCloneCommand($repoId);
        return $gitCloneCommand;
    }

    public function testParseWebhookEventPayload(): void
    {
        $payload = '{
            "event": "push",
            "payload": {
                "ref": "refs/heads/main",
                "before": "d1691190ef54f329b41333273722f444edc937ab",
                "after": "d06526f437939d1298f9dea15478665692ee4e69",
                "repository": {
                    "id": 1234,
                    "node_id": "R_kgDOI_yRPA",
                    "name": "testing-fork",
                    "full_name": "vermakhushboo/testing-fork",
                    "private": true,
                    "html_url": "https://github.com/vermakhushboo/testing-fork"
                },
                "pusher": {
                    "name": "vermakhushboo"
                },
                "sender": {
                    "login": "vermakhushboo"
                },
                "installation": {
                    "id": 1234
                }
            }
        }';
        $result = $this->github->parseWebhookEventPayload($payload);
    }
}