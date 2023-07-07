# Add new Detector Adapter

To get started with implementing a new adapter, start by reviewing the [README](/README.md) to understand the goals of this library.

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
```

Only include dependencies strictly necessary for the detector, preferably official PHP libraries, if available.

### Testing with Docker 

The existing test suite is helpful when developing a new detector adapter. Use official Docker images from trusted sources. Add new tests for your NewDetector in `tests/Detector/DetectorTest.php` test class. The specific `docker-compose` command for testing can be found in the [README](/README.md#tests).

### Tips and Tricks

- Keep it simple :)
- Prioritize code performance.
