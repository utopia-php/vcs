# Add new Detector Adapter

To get started with implementing a new detector adapter, start by reviewing the [README](/README.md) to understand the goals of this library.

### Introduction
- A `detector` is a class that defines the files, extensions, and languages that are associated with a specific runtime environment, such as Node.js, PHP, Ruby, or Python. The presence of these files, extensions, or languages can help automatically detect that runtime environment.
- To add a new detector adapter, you need to extend the Adapter parent class and define the following methods:

    - `getFiles()`: This method returns an array of files that are known to be associated with the runtime environment.
    - `getFileExtensions()`: This method returns an array of file extensions that are known to be associated with the runtime environment.
    - `getLanguages()`: This method returns an array of languages that are known to be associated with the runtime environment.
    - `getInstallCommand()`: This method returns the command that can be used to install the runtime environment.
    - `getBuildCommand()`: This method returns the command that can be used to build the project for the runtime environment.
    - `getEntryPoint()`: This method returns the entry point for the project in the runtime environment.


### File Structure

Below are outlined the most useful files for adding a new detector adapter: 

```bash
.
├── src # Source code
│   └── Detector
│       ├── Adapter/ # Where your new adapter goes!
│       ├── Adapter.php # Parent class for individual adapters
│       └── Detector.php # Detector class - calls individual adapter methods
└── tests
    └── Detector
        └── DetectorTest.php # Test class that holds all tests
```


### Extend the Adapter

Create your `NewDetector.php` file in `src/Detector/Adapter/` and extend the parent class:

```php
<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class NewDetector extends Adapter
{
    ...override all relevant methods
}
```

Once you have created a new detector adapter class, you can register it with the Appwrite runtime detector by calling the addDetector() method on the Detector class.

```php
$detector = new Detector();
$detector->addDetector(new NewDetector());
```

Once the `NewDetector` class is registered, the Appwrite runtime detector will be able to detect the new runtime to detect the runtime environment for the given files.

Only include dependencies strictly necessary for the detector, preferably official PHP libraries, if available.

### Testing with Docker 

The existing test suite is helpful when developing a new detector adapter. Use official Docker images from trusted sources. Add new tests for your NewDetector in `tests/Detector/DetectorTest.php` test class. The specific `docker-compose` command for testing can be found in the [README](/README.md#tests).

### Tips and Tricks

- Keep it simple :)
- Prioritize code performance.
