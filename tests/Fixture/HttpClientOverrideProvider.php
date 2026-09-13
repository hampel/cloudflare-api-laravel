<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests\Fixture;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * An application overriding this package's transport, as the README documents.
 *
 * Deliberately a provider rather than a container instance set in the test body, so the test can
 * list it BEFORE the package's provider. That is the order a Laravel Zero application gets when
 * its config/app.php lists AppServiceProvider first, and the order in which a singleton() binding
 * in the package would silently replace the application's.
 */
final class HttpClientOverrideProvider extends ServiceProvider
{
    public const KEY = 'cloudflare.http_client';

    public static ?RecordingPsr18Client $client = null;

    public function register(): void
    {
        self::$client = new RecordingPsr18Client(new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"success":true,"errors":[],"messages":[],"result":{"id":"023e105f4ecef8ad9ca31a8372d0c353","name":"override.example.com"}}'
        ));

        $this->app->singleton(self::KEY, static fn (): ClientInterface => self::$client ?? new RecordingPsr18Client(new Response(500)));
    }
}
