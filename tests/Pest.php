<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 * Feature and Unit tests boot the application (TestCase blocks every real HTTP call).
 * Arch tests only read the source tree and need no application.
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit');
