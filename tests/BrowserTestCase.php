<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests;

use Illuminate\Routing\Router;

/**
 * Base for tests/Browser. Pages are served in-process by pest-plugin-browser,
 * so Http::fake(), config()->set() and static state set in a test apply to
 * the pages Chromium loads during that test.
 */
abstract class BrowserTestCase extends TestCase
{
    public const FAKE_API_PATH = '/_hcaptcha-tests/fake-api.js';

    public function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The stub implements the hCaptcha JS API without the network.
        config()->set('hcaptcha.script.url', self::FAKE_API_PATH);

        // Fake siteverify responses report this hostname.
        config()->set('hcaptcha.hostnames', 'localhost');
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get(self::FAKE_API_PATH, static function () {
            $source = file_get_contents(dirname(__DIR__).'/resources/js/fake-api.js');

            return response((string) $source, 200, ['Content-Type' => 'application/javascript']);
        });
    }
}
