<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Facades;

use Hampel\Cloudflare\Api\Authentication\Authentication;
use Hampel\Cloudflare\Api\Client;
use Hampel\Cloudflare\Api\Config;
use Hampel\Cloudflare\Api\Connection;
use Hampel\Cloudflare\Api\Endpoint\Accounts;
use Hampel\Cloudflare\Api\Endpoint\DnsRecords;
use Hampel\Cloudflare\Api\Endpoint\Endpoint;
use Hampel\Cloudflare\Api\Endpoint\Tokens;
use Hampel\Cloudflare\Api\Endpoint\Zones;
use Hampel\Cloudflare\Api\Result\TokenVerification;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Cloudflare API manager.
 *
 *     Cloudflare::zones()->all();                          // the default account
 *     Cloudflare::client('customers')->zones()->all();     // a named one
 *
 * The operations are one hop further in, so the annotations below cover the hop and the
 * endpoint classes carry the typed signatures from there. Without them every call through the
 * facade is untyped to both the IDE and PHPStan, which is most of what a facade costs you.
 *
 * Everything after configuredAccounts() mirrors a method on the client, reached through the
 * manager's __call(). FacadeConformanceTest asserts that the two lists stay identical, so a
 * method added to the client in a later release of the core package shows up as a failing test
 * rather than as a call that silently loses its type. The core package wraps a dozen of
 * Cloudflare's two thousand endpoints and is expected to grow accessors in minor releases, which
 * makes that cheaper to have from the start than to add after the first one is missed.
 *
 * endpoint() is the one annotation that gives something up: on the client it is generic,
 * returning the class it was handed, and a @method line cannot express that. Reach for
 * `Cloudflare::client()->endpoint(Foo::class)` where the generic return matters.
 *
 * THE ALIAS IS "Cloudflare", WHICH IS GENERIC ENOUGH TO COLLIDE. It is declared under
 * extra.laravel.aliases, so an application that already has one can point its own
 * config/app.php alias wherever it likes, or disable discovery for this package - nothing here
 * has to be forked to resolve it.
 *
 * @method static Client client(?string $name = null)
 * @method static string getDefaultAccount()
 * @method static list<string> configuredAccounts()
 * @method static Config config()
 * @method static Authentication authentication()
 * @method static TokenVerification verify()
 * @method static Client withCredential(Authentication $authentication)
 * @method static Endpoint endpoint(string $class)
 * @method static Tokens tokens()
 * @method static Zones zones()
 * @method static DnsRecords records()
 * @method static Accounts accounts()
 * @method static Connection connection()
 *
 * @see \Hampel\Cloudflare\Api\Laravel\CloudflareManager
 * @see \Hampel\Cloudflare\Api\Client
 */
final class Cloudflare extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hampel\Cloudflare\Api\Laravel\CloudflareManager::class;
    }
}
