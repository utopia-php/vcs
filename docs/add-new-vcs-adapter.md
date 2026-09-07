# Add new VCS Adapter

To get started with implementing a new VCS adapter, start by reviewing the [README](/README.md) to understand the goals of this library. ❤

### Introduction 📝
- A `VCS (version control system)` is a software tool that helps you track changes to your code over time.
- A `VCS adapter` is a class that provides an interface to a specific VCS like GitHub, Bitbucket etc. It provides methods for interacting with the VCS user account and repositories, such as listing repositories, adding a comment on a pull request, cloning the repository etc.
- To add a new VCS adapter, you need to extend the `Adapter` parent class and define the required methods.

### File Structure 📂

Below are outlined the most useful files for adding a new VCS adapter: 

```bash
.
├── src # Source code
│   └── VCS
│       ├── Adapter/ # Where your new adapter goes!
│       │    ├── Git/ # Where your new Git-based adapter goes!
│       │    └── Git.php # Parent class for Git-based adapters
│       └── Adapter.php # Parent class for individual adapters
└── tests
    └── VCS
        ├── Adapter/ # Where tests of your new adapter go!
        └── Base.php # Parent class that holds all tests
```
### Extend the Adapter 💻

Create your Git-based adapter `NewGitAdapter.php` file in `src/VCS/Adapter/Git` and extend the parent class:

```php
<?php

namespace Utopia\VCS\Adapter\Git;

use Ahc\Jwt\JWT;
use Exception;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;

class NewGitAdapter extends Git
{
    ...override and implement all relevant methods
}
```

To add a non-git adapter, create your new adapter `NewVCSAdapter.php` file in `src/VCS/Adapter` and extend the parent class:
```php
<?php

namespace Utopia\VCS\Adapter;

use Utopia\VCS\Adapter;
use Utopia\Cache\Cache;

class NewVCSAdapter extends Adapter
{
    ...override and implement all relevant methods
}
```

Once you have created a new VCS adapter class, you can use it with the client by calling the `initializeVariables()` method on the VCS class.
```php
// Your VCS app private key. You can generate this from your VCS App settings.
$privateKey = 'your-vcs-app-private-key';

// Your VCS App ID. You can usually find this in the VCS App dashboard.
$appId = 'your-vcs-app-id';

// Your VCS App installation ID. You can usually find this in the VCS App installation settings.
$installationId = 'your-vcs-app-installation-id';

// Initialise variables
$vcs->initializeVariables($installationId, $privateKey, $appId);
```

Only include dependencies strictly necessary for the adapter, preferably official PHP libraries, if available.

#### Webhook deliveries describing more than one event

`getEvent()` returns the single event a payload describes, which is all most
providers ever send. A provider that batches several refs into one delivery
should override `getEvents()` as well, so a consumer can read all of them:

```php
public function getEvents(string $event, string $payload): array
{
    // one entry per ref the delivery touched
}
```

The default `getEvents()` wraps `getEvent()`, so an adapter that never batches
needs no override. Consumers that must not miss a ref should call `getEvents()`
rather than `getEvent()` — the latter reports only the first event of a batch.

### Testing with Docker 🛠️

Every adapter runs the same suite. `tests/VCS/Base.php` holds the tests, and each adapter's class under `tests/VCS/Adapter/` declares how its provider differs. To test a new adapter:

1. Extend `Utopia\Tests\Base` in `tests/VCS/Adapter/NewGitAdapterTest.php` and implement its hooks: `setupAdapter()` builds the adapter against the provider, `signWebhookPayload()` signs a payload the way the provider does, and `pushPayload()` and `pullRequestPayload()` build webhook payloads shaped the way the provider sends them.
2. Declare the parts of the contract the provider lacks by overriding the capability flags, such as `$supportsTags` or `$supportsCheckRuns`. The first shared test for each capability then asserts that the adapter refuses with `X() is not supported by <name>`; the tests that need the capability to act on skip.
3. Keep behaviour only this provider has in the adapter's own test class. Anything two providers share belongs in `Base`, behind a declared flag or hook.

Run the provider from an official Docker image, add a Docker Compose profile and a PHPUnit test suite for it, and run the suite as described in [CONTRIBUTING](/CONTRIBUTING.md#running-tests).

### Tips and Tricks 💡

- Keep it simple :)
- Prioritize code performance.
