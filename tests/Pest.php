<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Tests\TestCase;

// Binds the TestCase when the package is tested on its own (the split repo);
// inside the monorepo, tests/Pest.php at the root does the same.
uses(TestCase::class)->in(__DIR__);
