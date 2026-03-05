<?php

namespace Utopia\Tests\VCS\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\FileNotFound;

class GitHubTest extends Base
{
    protected function createVCSAdapter(): Git
    {
        return new GitHub(new Cache(new None()));
    }

    public function setUp(): void
    {
        $this->vcsAdapter = new GitHub(new Cache(new None()));
        $privateKey = System::getEnv('TESTS_GITHUB_PRIVATE_KEY') ?? '';
        $appId = System::getEnv('TESTS_GITHUB_APP_IDENTIFIER') ?? '';
        $installationId = System::getEnv('TESTS_GITHUB_INSTALLATION_ID') ?? '';
        $this->vcsAdapter->initializeVariables(installationId: $installationId, privateKey: $privateKey, appId: $appId, accessToken: '', refreshToken: '');
    }

    public function testGetEvent(): void
    {
        $payload_push = '{
            "created": false,
            "ref": "refs/heads/main",
            "before": "1234",
            "after": "4567",
            "repository": {
                "id": 603754812,
                "node_id": "R_kgDOI_yRPA",
                "name": "testing-fork",
                "full_name": "vermakhushboo/testing-fork",
                "private": true,
                "html_url": "https://github.com/vermakhushboo/g4-node-function",
                "owner": {
                    "name": "vermakhushboo"
                }
            },
            "installation": {
                "id": 1234
            },
            "head_commit": {
                "author": {
                    "name": "Khushboo Verma"
                },
                "message": "Update index.js",
                "url": "https://github.com/vermakhushboo/g4-node-function/commit/b787f03343171ff5a477627796140bfa1d02da09"
            },
            "commits": [
              {
                "id": "ee8bc1b01518f1e4ec326438231ff2b44e752dd3",
                "tree_id": "589ff083b5cf40f409a085e736da301b2f4f8853",
                "distinct": true,
                "message": "Update main.js",
                "timestamp": "2025-12-16T15:34:43+01:00",
                "url": "https://github.com/Meldiron/starter-function-locally-december/commit/ee8bc1b01518f1e4ec326438231ff2b44e752dd3",
                "author": {
                  "name": "Matej Bačo",
                  "email": "matejbaco2000@gmail.com",
                  "date": "2025-12-16T15:34:43+01:00",
                  "username": "Meldiron"
                },
                "committer": {
                  "name": "GitHub",
                  "email": "noreply@github.com",
                  "date": "2025-12-16T15:34:43+01:00",
                  "username": "web-flow"
                },
                "added": [
                    "src/lib.js"
                ],
                "removed": [
                    "README.md"
                ],
                "modified": [
                  "src/main.js"
                ]
              }
            ],
            "sender": {
                "html_url": "https://github.com/vermakhushboo",
                "avatar_url": "https://avatars.githubusercontent.com/u/43381712?v=4"
            }
        }';

        $payload_pull_request = '{
            "action": "opened",
            "number": 1,
            "pull_request": {
                "id": 1303283688,
                "state": "open",
                "html_url": "https://github.com/vermakhushboo/g4-node-function/pull/17",
                "head": {
                    "ref": "test",
                    "sha": "a27dbe54b17032ee35a16c24bac151e5c2b33328",
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
                },
                "user" : {
                    "login": "vermakhushboo",
                    "avatar_url": "https://avatars.githubusercontent.com/u/43381712?v=4"
                }
            },
            "repository": {
                "id": 3498,
                "name": "functions-example",
                "owner": {
                    "login": "vermakhushboo"
                },
                "html_url": "https://github.com/vermakhushboo/g4-node-function"
            },
            "installation": {
                "id": 9876
            },
            "sender": {
                "html_url": "https://github.com/vermakhushboo"
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

        $pushResult = $this->vcsAdapter->getEvent('push', $payload_push);
        $this->assertSame('main', $pushResult['branch']);
        $this->assertSame('603754812', $pushResult['repositoryId']);
        $this->assertCount(3, $pushResult['affectedFiles']);
        $this->assertSame('src/lib.js', $pushResult['affectedFiles'][0]);
        $this->assertSame('README.md', $pushResult['affectedFiles'][1]);
        $this->assertSame('src/main.js', $pushResult['affectedFiles'][2]);

        $pullRequestResult = $this->vcsAdapter->getEvent('pull_request', $payload_pull_request);
        $this->assertSame('opened', $pullRequestResult['action']);
        $this->assertSame(1, $pullRequestResult['pullRequestNumber']);

        $uninstallResult = $this->vcsAdapter->getEvent('installation', $payload_uninstall);
        $this->assertSame('deleted', $uninstallResult['action']);
        $this->assertSame('1234', $uninstallResult['installationId']);
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

    public function testGetInstallationRepository(): void
    {
        $repositoryName = 'astro-starter';
        $repo = $this->vcsAdapter->getInstallationRepository($repositoryName);
        $this->assertIsArray($repo);
        $this->assertSame($repositoryName, $repo['name']);
    }

    public function testGetRepository(): void
    {
        $owner = 'vermakhushboo';
        $repositoryName = 'basic-js-crud';
        $repo = $this->vcsAdapter->getRepository($owner, $repositoryName);
        $this->assertIsArray($repo);
        $this->assertSame($repositoryName, $repo['name']);
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = $this->vcsAdapter->getRepositoryName('432284323');
        $this->assertSame('basic-js-crud', $repositoryName);
    }

    public function testGetRepositoryTree(): void
    {
        $owner = 'test-kh';
        $repositoryName = 'test1';
        $branch = 'main';
        $tree = $this->vcsAdapter->getRepositoryTree($owner, $repositoryName, $branch);

        $this->assertIsArray($tree);
        $this->assertNotEmpty($tree);

        // test for an invalid repo
        $repositoryName = 'test3';
        $tree = $this->vcsAdapter->getRepositoryTree($owner, $repositoryName, $branch);
        $this->assertIsArray($tree);
        $this->assertEmpty($tree);

        // test for an empty repository
        $repositoryName = 'test2';
        $tree = $this->vcsAdapter->getRepositoryTree($owner, $repositoryName, $branch);
        $this->assertIsArray($tree);
        $this->assertEmpty($tree);

        // test for recursive tree
        $repositoryName = 'test4';
        $tree = $this->vcsAdapter->getRepositoryTree($owner, $repositoryName, $branch, true);
        $this->assertIsArray($tree);
        $this->assertNotEmpty($tree);
        $this->assertSame('src/folder/README.md', $tree[2]);

        // test for recursive false
        $repositoryName = 'test4';
        $tree = $this->vcsAdapter->getRepositoryTree($owner, $repositoryName, $branch);
        $this->assertIsArray($tree);
        $this->assertNotEmpty($tree);
        $this->assertSame(1, count($tree));
    }

    public function testGetRepositoryContent(): void
    {
        $owner = 'test-kh';
        $repositoryName = 'test1';

        // Basic usage
        $response = $this->vcsAdapter->getRepositoryContent($owner, $repositoryName, 'README.md');
        $this->assertSame('# test1', $response['content']);

        $sha = \hash('sha1', "blob " . $response['size'] . "\0" .  $response['content']);
        $this->assertSame(7, $response['size']);
        $this->assertSame($sha, $response['sha']);

        $response = $this->vcsAdapter->getRepositoryContent($owner, $repositoryName, 'src/index.md');
        $this->assertSame("Hello\n", $response['content']);

        // Branches
        $response = $this->vcsAdapter->getRepositoryContent($owner, $repositoryName, 'README.md', 'main');
        $this->assertSame('# test1', $response['content']);

        $response = $this->vcsAdapter->getRepositoryContent($owner, $repositoryName, 'README.md', 'test');
        $this->assertSame("# test1 from test branch\n", $response['content']);

        $threw = false;
        try {
            $response = $this->vcsAdapter->getRepositoryContent($owner, $repositoryName, 'README.md', 'non-existing-branch');
        } catch (FileNotFound $e) {
            $threw = true;
        }
        $this->assertTrue($threw);

        // Missing files
        $threw = false;
        try {
            $response = $this->vcsAdapter->getRepositoryContent($owner, $repositoryName, 'readme.md');
        } catch (FileNotFound $e) {
            $threw = true;
        }
        $this->assertTrue($threw);

        $threw = false;
        try {
            $response = $this->vcsAdapter->getRepositoryContent($owner, $repositoryName, 'non-existing.md');
        } catch (FileNotFound $e) {
            $threw = true;
        }
        $this->assertTrue($threw);

    }

    public function testListRepositoryContents(): void
    {
        $owner = 'test-kh';
        $repositoryName = 'test1';
        $path = '';
        $contents = $this->vcsAdapter->listRepositoryContents($owner, $repositoryName, $path);

        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);

        // test for non-existent path
        $path = 'non-existent-path';
        $contents = $this->vcsAdapter->listRepositoryContents($owner, $repositoryName, $path);
        $this->assertIsArray($contents);
        $this->assertEmpty($contents);

        // test for a valid folder
        $path = 'src';
        $contents = $this->vcsAdapter->listRepositoryContents($owner, $repositoryName, $path);
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);

        // test for an invalid repo
        $repositoryName = 'test3';
        $path = '';
        $contents = $this->vcsAdapter->listRepositoryContents($owner, $repositoryName, $path);
        $this->assertIsArray($contents);
        $this->assertEmpty($contents);

        // test for an empty repository
        $repositoryName = 'test2';
        $path = '';
        $contents = $this->vcsAdapter->listRepositoryContents($owner, $repositoryName, $path);
        $this->assertIsArray($contents);
        $this->assertEmpty($contents);

        // test for an absolute path
        $repositoryName = 'test1';
        $path = 'README.md';
        $contents = $this->vcsAdapter->listRepositoryContents($owner, $repositoryName, $path);
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);
    }

    public function testGetPullRequest(): void
    {
        $owner = 'vermakhushboo';
        $repositoryName = 'basic-js-crud';
        $pullRequestNumber = 1;

        $result = $this->vcsAdapter->getPullRequest($owner, $repositoryName, $pullRequestNumber);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame($pullRequestNumber, $result['number']);
        $this->assertSame($owner, $result['base']['user']['login']);
        $this->assertSame($repositoryName, $result['base']['repo']['name']);
    }

    public function testGenerateCloneCommand(): void
    {
        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', 'test', GitHub::CLONE_TYPE_BRANCH, '/tmp/clone-branch', '*');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);

        $output = '';
        $resultCode = null;
        \exec($gitCloneCommand, $output, $resultCode);
        $this->assertSame(0, $resultCode);

        $this->assertFileExists('/tmp/clone-branch/README.md');
    }

    public function testGenerateCloneCommandWithCommitHash(): void
    {
        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', '4fb10447faea8a55c5cad7b5ebdfdbedca349fe4', GitHub::CLONE_TYPE_COMMIT, '/tmp/clone-commit', '*');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);

        $output = '';
        $resultCode = null;
        \exec($gitCloneCommand, $output, $resultCode);
        $this->assertSame(0, $resultCode);

        $this->assertFileExists('/tmp/clone-commit/README.md');
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', '0.1.0', GitHub::CLONE_TYPE_TAG, '/tmp/clone-tag', '*');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);

        $output = '';
        $resultCode = null;
        \exec($gitCloneCommand, $output, $resultCode);
        $this->assertSame(0, $resultCode);

        $this->assertFileExists('/tmp/clone-tag/README.md');

        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', '0.1.*', GitHub::CLONE_TYPE_TAG, '/tmp/clone-tag2', '*');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);

        $output = '';
        $resultCode = null;
        \exec($gitCloneCommand, $output, $resultCode);
        $this->assertSame(0, $resultCode);

        $this->assertFileExists('/tmp/clone-tag2/README.md');

        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', '0.*.*', GitHub::CLONE_TYPE_TAG, '/tmp/clone-tag3', '*');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);

        $output = '';
        $resultCode = null;
        \exec($gitCloneCommand, $output, $resultCode);
        $this->assertSame(0, $resultCode);

        $this->assertFileExists('/tmp/clone-tag3/README.md');


        $gitCloneCommand = $this->vcsAdapter->generateCloneCommand('test-kh', 'test2', '0.2.*', GitHub::CLONE_TYPE_TAG, '/tmp/clone-tag4', '*');
        $this->assertNotEmpty($gitCloneCommand);
        $this->assertStringContainsString('sparse-checkout', $gitCloneCommand);

        $output = '';
        $resultCode = null;
        \exec($gitCloneCommand, $output, $resultCode);
        $this->assertNotEquals(0, $resultCode);

        $this->assertFileDoesNotExist('/tmp/clone-tag4/README.md');
    }

    public function testUpdateComment(): void
    {
        $commentId = $this->vcsAdapter->updateComment('test-kh', 'test2', 1630320767, 'update');
        $this->assertNotEmpty($commentId);
    }

    public function testGetCommit(): void
    {
        $commitDetails = $this->vcsAdapter->getCommit('test-kh', 'test1', '7ae65094d56edafc48596ffbb77950e741e56412');
        $this->assertIsArray($commitDetails);
        $this->assertSame('https://avatars.githubusercontent.com/u/43381712?v=4', $commitDetails['commitAuthorAvatar']);
        $this->assertSame('https://github.com/vermakhushboo', $commitDetails['commitAuthorUrl']);
        $this->assertSame('Khushboo Verma', $commitDetails['commitAuthor']);
        $this->assertSame('Initial commit', $commitDetails['commitMessage']);
        $this->assertSame('https://github.com/test-kh/test1/commit/7ae65094d56edafc48596ffbb77950e741e56412', $commitDetails['commitUrl']);
        $this->assertSame('7ae65094d56edafc48596ffbb77950e741e56412', $commitDetails['commitHash']);
    }

    public function testGetLatestCommit(): void
    {
        $commitDetails = $this->vcsAdapter->getLatestCommit('test-kh', 'test1', 'test');
        $this->assertSame('Khushboo Verma', $commitDetails['commitAuthor']);
        $this->assertSame('https://avatars.githubusercontent.com/u/43381712?v=4', $commitDetails['commitAuthorAvatar']);
        $this->assertSame('https://github.com/vermakhushboo', $commitDetails['commitAuthorUrl']);
    }
}
