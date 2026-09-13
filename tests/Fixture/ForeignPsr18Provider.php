<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests\Fixture;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * Stands in for any other package - another API wrapper, an unrelated library - that binds the
 * shared PSR-18 key for itself.
 *
 * Every Laravel API wrapper once did exactly this, so in an application with two installed the
 * last provider registered supplied the transport for all of them. The recorder answers 418, so a
 * Cloudflare call that reached it would fail loudly rather than pass on a plausible response.
 */
final class ForeignPsr18Provider extends ServiceProvider
{
    public static ?RecordingPsr18Client $client = null;

    public function register(): void
    {
        self::$client = new RecordingPsr18Client(new Response(418, [], 'a foreign transport answered'));

        $this->app->singleton(ClientInterface::class, static fn (): ClientInterface => self::$client ?? new RecordingPsr18Client(new Response(418)));
    }
}
