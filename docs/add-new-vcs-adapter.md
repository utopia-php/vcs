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

Once you have created a new VCS adapter class, you can use it with the client by calling the `initialiseVariables()` method on the VCS class.
```php
// Your VCS app private key. You can generate this from your VCS App settings.
$privateKey = 'your-vcs-app-private-key';

// Your VCS App ID. You can usually find this in the VCS App dashboard.
$appId = 'your-vcs-app-id';

// Your VCS App installation ID. You can usually find this in the VCS App installation settings.
$installationId = 'your-vcs-app-installation-id';

// Initialise variables
$vcs->initialiseVariables($installationId, $privateKey, $appId);
```

Only include dependencies strictly necessary for the adapter, preferably official PHP libraries, if available.

### Testing with Docker 🛠️

The existing test suite is helpful when developing a new VCS adapter. Use official Docker images from trusted sources. Add new tests for your new VCS adapter in `tests/VCS/Adapter/VCSTest.php` test class. The specific `docker-compose` command for testing can be found in the [README](/README.md#tests).

### Tips and Tricks 💡

- Keep it simple :)
- Prioritize code performance.
