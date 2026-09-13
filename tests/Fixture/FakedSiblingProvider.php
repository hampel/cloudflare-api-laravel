<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests\Fixture;

use Hampel\Cloudflare\Api\Laravel\Http\PendingRequestClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * A sibling API wrapper as it really looked: an adapter over Laravel's HTTP client, bound to the
 * shared PSR-18 key with a timeout of its own.
 *
 * Unlike a recorder answering 418, this sends through the same faked factory as this package, so
 * a request that reached it would still get the faked response and look correct. Only the timeout
 * tells the two adapters apart - which is exactly how the collision showed in an application with
 * three wrappers installed. The resolver reads the application's factory: a fresh Factory would
 * send past the fake, and a regression would error on the network instead of failing on the
 * timeout.
 */
final class FakedSiblingProvider extends ServiceProvider
{
    public const TIMEOUT = 3.0;

    public function register(): void
    {
        $this->app->singleton(ClientInterface::class, static fn (Container $app): ClientInterface => new PendingRequestClient(
            static fn (): Factory => $app->make(Factory::class),
            self::TIMEOUT,
            self::TIMEOUT,
        ));
    }
}
