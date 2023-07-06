# Utopia VCS

[![Build Status](https://travis-ci.org/utopia-php/vcs.svg?branch=master)](https://travis-ci.com/utopia-php/vcs)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/vcs.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia VCS is a simple and lite library for interacting with version control systems (VCS) in Utopia-PHP using adapters for different providers like GitHub, GitLab etc. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/vcs
```

Init in your application:
```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

// Initialise your adapter
$github = new GitHub(new Cache(new None()));

// Set and read values from environment variables
$privateKey = App::getEnv('GITHUB_PRIVATE_KEY');
$githubAppId = App::getEnv('GITHUB_APP_IDENTIFIER');
$installationId = '1234'; //your GitHub App Installation ID here

// Initialise variables
$github->initialiseVariables($installationId, $privateKey, $githubAppId, 'github-username');

// Perform the actions that you want
$repository = $github->createRepository($owner, $name, $private);
```

### Supported Adapters

VCS Adapters:

| Adapter | Status |
|---------|---------|
| GitHub | ✅ |
| GitLab |  |
| Bitbucket |  |
| Azure DevOps |  |

Detector Adapters:

| Adapter | Status |
|---------|---------|
| CPP | ✅ |
| Dart | ✅ |
| Deno | ✅ |
| Dotnet | ✅ |
| Java | ✅ |
| JavaScript | ✅ |
| PHP | ✅ |
| Python | ✅ |
| Ruby | ✅ |
| Swift | ✅ |

`✅  - supported, 🛠  - work in progress`

## System Requirements

Utopia VCS requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.


## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

You can refer to the [Contributing Guide](CONTRIBUTING.md) for more info.

## Tests

To run tests, you first need to bring up the example Docker stack with the following command:

```bash
docker compose up -d --build
```

To run all unit tests, use the following Docker command:

```bash
docker compose exec tests ./vendor/bin/phpunit
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
