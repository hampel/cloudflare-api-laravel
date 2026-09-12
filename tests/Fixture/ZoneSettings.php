<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests\Fixture;

use Hampel\Cloudflare\Api\Endpoint\Endpoint;

/**
 * An endpoint the core package does not wrap, reached through this package's container.
 *
 * Cloudflare has some two thousand paths and the core package wraps a dozen, so most consumers
 * will write one of these - zone settings, firewall rules, page rules, cache purging. The point
 * of the fixture is that nothing about it is registered: the class IS the registration, and
 * `Cloudflare::client()->endpoint(ZoneSettings::class)` constructs it over the same faked
 * transport as everything else.
 *
 * The three abstract methods are the page-size limits, which are per endpoint on this API and
 * have no safe default - see Endpoint. Settings are not paginated, so the values here are the
 * widest the base class will accept rather than anything measured.
 */
final class ZoneSettings extends Endpoint
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $zoneId): array
    {
        return $this->apiGet('zones/' . $zoneId . '/settings')->rows();
    }

    protected function minimumPageSize(): int
    {
        return 1;
    }

    protected function maximumPageSize(): int
    {
        return 1000;
    }

    protected function collectionName(): string
    {
        return 'zone settings';
    }
}
