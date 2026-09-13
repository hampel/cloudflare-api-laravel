<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Laravel\CloudflareManager;
use Hampel\Cloudflare\Api\Laravel\CloudflareServiceProvider;
use Hampel\Cloudflare\Api\Laravel\Http\PendingRequestClient;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function the_package_config_is_merged_into_the_application(): void
    {
        $config = $this->container()->make(Config::class);

        foreach (['default', 'accounts', 'page_size', 'base_uri', 'timeout', 'connect_timeout'] as $key) {
            $this->assertTrue($config->has('cloudflare.' . $key), sprintf('cloudflare.%s is not merged', $key));
        }
    }

    #[Test]
    public function the_shipped_config_has_no_version_setting(): void
    {
        // Asserted rather than merely documented. /client/v4 has been the only version of this
        // API since 2014 and the core package holds it as a constant; a settable one here would
        // invite an application to configure it, and the only values it could take are wrong.
        $defaults = require __DIR__ . '/../config/cloudflare.php';

        $this->assertIsArray($defaults);
        $this->assertArrayNotHasKey('version', $defaults);
    }

    #[Test]
    public function the_config_file_is_publishable_under_its_own_tag(): void
    {
        $published = ServiceProvider::pathsToPublish(CloudflareServiceProvider::class, 'cloudflare-config');

        $this->assertSame([config_path('cloudflare.php')], array_values($published));
    }

    #[Test]
    public function the_shipped_config_names_one_account_and_no_credential(): void
    {
        // Read straight from the file rather than the merged config, which the test case has
        // already overridden. An unset environment must leave the account unusable rather than
        // pointing somewhere with something.
        $defaults = require __DIR__ . '/../config/cloudflare.php';
        $this->assertIsArray($defaults);

        $accounts = $defaults['accounts'] ?? null;
        $this->assertIsArray($accounts);

        $this->assertSame('main', $defaults['default'] ?? null);
        $this->assertSame(['main'], array_keys($accounts));
        $this->assertSame(['token' => null], $accounts['main'] ?? null);
        // array_key_exists rather than ??, which cannot tell an absent key from a null one -
        // and null is the value under test here.
        $this->assertArrayHasKey('page_size', $defaults);
        $this->assertNull($defaults['page_size']);
        $this->assertArrayHasKey('base_uri', $defaults);
        $this->assertNull($defaults['base_uri']);
        $this->assertSame(10, $defaults['timeout'] ?? null);
        $this->assertSame(5, $defaults['connect_timeout'] ?? null);
    }

    #[Test]
    public function the_transport_is_bound_by_interface_so_it_can_be_replaced(): void
    {
        $this->assertInstanceOf(PendingRequestClient::class, $this->container()->make(ClientInterface::class));
    }

    #[Test]
    public function psr17_factories_are_bound_so_the_cores_discovery_never_has_to_run(): void
    {
        $this->assertInstanceOf(RequestFactoryInterface::class, $this->container()->make(RequestFactoryInterface::class));
        $this->assertInstanceOf(StreamFactoryInterface::class, $this->container()->make(StreamFactoryInterface::class));
    }

    #[Test]
    public function registering_the_provider_binds_the_manager_and_merges_the_config(): void
    {
        // Registered here, against an application built in the test body, rather than relying on
        // the registration Testbench already did in setUp. Two reasons, and the second is the
        // important one:
        //
        // The bindings are asserted against an application that did not have them, so the
        // assertions depend on this call rather than on setUp's.
        //
        // And register() only runs under PHPUnit's error handler if it runs from here. Laravel's
        // HandleExceptions bootstrapper replaces that handler while the application boots, which
        // in a Testbench suite is during parent::setUp() - before withoutDeprecationHandling()
        // puts it back. So a deprecation raised by the provider's own registration during setUp
        // is discarded, and phpunit.xml's failOnDeprecation never sees it.
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());

        (new CloudflareServiceProvider($app))->register();

        $this->assertTrue($app->bound(CloudflareManager::class));
        $this->assertTrue($app->bound(ClientInterface::class));
        $this->assertTrue($app->bound(RequestFactoryInterface::class));
        $this->assertTrue($app->bound(StreamFactoryInterface::class));
        $this->assertSame('main', $app->make(Config::class)->get('cloudflare.default'));
    }

    /**
     * @return array<string, array{array<string, string>, string|null}>
     */
    public static function tokenVariables(): array
    {
        return [
            'neither set' => [[], null],
            'only the earlier name, as existing .env files have it' => [['CLOUDFLARE_TOKEN' => 'old-token'], 'old-token'],
            'only the documented name' => [['CLOUDFLARE_API_TOKEN' => 'new-token'], 'new-token'],
            'both set, the documented name wins' => [['CLOUDFLARE_API_TOKEN' => 'new-token', 'CLOUDFLARE_TOKEN' => 'old-token'], 'new-token'],
            'documented name present but blank' => [['CLOUDFLARE_API_TOKEN' => '', 'CLOUDFLARE_TOKEN' => 'old-token'], 'old-token'],
        ];
    }

    /**
     * @param  array<string, string>  $environment
     */
    #[Test]
    #[DataProvider('tokenVariables')]
    public function the_shipped_config_reads_the_documented_token_name_and_falls_back_to_the_earlier_one(
        array $environment,
        ?string $expected,
    ): void {
        // The last case is the reason the config uses `?:` and not env()'s default argument. A
        // blank CLOUDFLARE_API_TOKEN= line - an updated .env.example copied in while the real
        // token still sits under the old name - is an empty string rather than null, so the
        // default would never apply and the account would be refused for having no token.
        $names = ['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_TOKEN'];
        $saved = [];

        foreach ($names as $name) {
            $saved[$name] = getenv($name);
            $this->unsetEnvironment($name);
        }

        try {
            foreach ($environment as $name => $value) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            $config = require __DIR__ . '/../config/cloudflare.php';
            $this->assertIsArray($config);

            $accounts = $config['accounts'] ?? null;
            $this->assertIsArray($accounts);
            $main = $accounts['main'] ?? null;
            $this->assertIsArray($main);

            $this->assertSame($expected, $main['token'] ?? null);
        } finally {
            foreach ($names as $name) {
                $this->unsetEnvironment($name);

                if ($saved[$name] !== false) {
                    putenv($name . '=' . $saved[$name]);
                    $_ENV[$name] = $saved[$name];
                    $_SERVER[$name] = $saved[$name];
                }
            }
        }
    }

    private function unsetEnvironment(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
