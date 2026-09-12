<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Laravel\CloudflareServiceProvider;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Testbench boots a minimal Laravel application from inside this package, so the Laravel
 * version under test comes from Composer resolution rather than from an installed framework.
 * Never install a framework to test a package.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * A zone id of the shape Cloudflare uses: 32 hex characters.
     *
     * Written out rather than generated, because the core package validates the shape and a
     * fixture that only looks plausible is one that stops resembling the API the first time
     * somebody shortens it.
     */
    protected const ZONE_ID = '023e105f4ecef8ad9ca31a8372d0c353';

    protected const RECORD_ID = '372e67954025e0ba6aaa6d586b9e0b59';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CloudflareServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Cloudflare' => Cloudflare::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cloudflare.default', 'main');
        $app['config']->set('cloudflare.accounts', [
            'main' => ['token' => 'token-under-test'],
            'customers' => ['token' => 'customers-token'],
        ]);
        $app['config']->set('cloudflare.base_uri', null);
        $app['config']->set('cloudflare.page_size', null);
    }

    /**
     * The envelope EVERY endpoint on this API answers in, success or failure.
     *
     * Kept here rather than in each test because the envelope is what the core package parses,
     * and a hand-trimmed copy per test is how a fixture stops resembling the API. Note there
     * is no 204 to model: a genuinely empty result is `"result": null` inside this.
     *
     * @param  mixed  $result
     * @param  array<string, mixed>|null  $resultInfo
     * @return array<string, mixed>
     */
    protected static function envelope(mixed $result, ?array $resultInfo = null): array
    {
        $envelope = [
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => $result,
        ];

        if ($resultInfo !== null) {
            $envelope['result_info'] = $resultInfo;
        }

        return $envelope;
    }

    /**
     * A collection envelope, with the `result_info` a paginated endpoint sends.
     *
     * `count` is this page and `total_count` is everything - two numbers that are easy to read
     * as each other, and getting them the wrong way round here would make a walk stop early
     * while looking like it had finished.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected static function collection(
        array $items,
        int $page = 1,
        int $perPage = 20,
        ?int $totalCount = null,
        ?int $totalPages = null,
    ): array {
        $totalCount ??= count($items);

        return self::envelope($items, [
            'page' => $page,
            'per_page' => $perPage,
            'count' => count($items),
            'total_count' => $totalCount,
            'total_pages' => $totalPages ?? max(1, (int) ceil($totalCount / max(1, $perPage))),
        ]);
    }

    /**
     * The API's failure envelope. A failure carries a numeric CODE, which is the thing worth
     * matching on - the message is prose and can be reworded.
     *
     * @return array<string, mixed>
     */
    protected static function failure(int $code, string $message): array
    {
        return [
            'success' => false,
            'errors' => [['code' => $code, 'message' => $message]],
            'messages' => [],
            'result' => null,
        ];
    }

    /**
     * A zone as Cloudflare answers with one.
     *
     * @return array<string, mixed>
     */
    protected static function zone(?string $id = null, string $name = 'example.com'): array
    {
        return [
            'id' => $id ?? self::ZONE_ID,
            'name' => $name,
            'status' => 'active',
            'paused' => false,
            'type' => 'full',
            'name_servers' => ['ana.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
            'original_name_servers' => ['ns1.example.test'],
            'account' => ['id' => '01a7362d577a6c3019a474fd6f485823', 'name' => 'Example Account'],
            'plan' => ['name' => 'Free Website'],
            'created_on' => '2026-01-01T00:00:00.000000Z',
            'modified_on' => '2026-01-02T00:00:00.000000Z',
            'activated_on' => '2026-01-01T12:00:00.000000Z',
        ];
    }

    /**
     * A DNS record as Cloudflare answers with one.
     *
     * @return array<string, mixed>
     */
    protected static function record(
        ?string $id = null,
        string $name = 'www.example.com',
        string $type = 'A',
        string $content = '203.0.113.10',
    ): array {
        return [
            'id' => $id ?? self::RECORD_ID,
            'zone_id' => self::ZONE_ID,
            'zone_name' => 'example.com',
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'proxiable' => true,
            'proxied' => false,
            'ttl' => 300,
            'comment' => null,
            'tags' => [],
            'settings' => [],
            'created_on' => '2026-01-01T00:00:00.000000Z',
            'modified_on' => '2026-01-01T00:00:00.000000Z',
        ];
    }

    /**
     * The application, narrowed.
     *
     * Testbench declares $app as nullable because it does not exist before setUp, so every use
     * of it in a test is otherwise a call on Application|null.
     */
    protected function container(): Application
    {
        $app = $this->app;

        $this->assertNotNull($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's HandleExceptions bootstrapper replaces PHPUnit's error handler when the
        // app boots, and shouldIgnoreDeprecationErrors() discards deprecations outright while
        // running tests - so phpunit.xml's failOnDeprecation never sees one and is inert in any
        // Testbench-based package. This throws on them instead, which is the whole point of the
        // flag: a library should hear about a deprecation before its users do.
        $this->withoutDeprecationHandling();
    }
}
