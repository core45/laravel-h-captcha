<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests;

/**
 * Base for tests/Integration: the tests that genuinely exercise Livewire
 * and/or Filament, as opposed to tests/Unit and tests/Feature, which must
 * pass with neither package's service provider registered.
 */
class IntegrationTestCase extends TestCase
{
    protected bool $withIntegrations = true;
}
