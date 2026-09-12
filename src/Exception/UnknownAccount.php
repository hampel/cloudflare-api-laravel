<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Exception;

use Hampel\Cloudflare\Api\Exception\CloudflareException;

/**
 * An account was asked for by a name that is not in the configuration.
 *
 * Extends the core package's base exception, so an application already catching
 * CloudflareException - or ExceptionInterface - catches a misconfigured account name alongside
 * every other way a call can fail.
 */
final class UnknownAccount extends CloudflareException
{
    /**
     * @param  list<string>  $configured
     */
    public static function named(string $name, array $configured): self
    {
        return new self(sprintf(
            'Cloudflare account "%s" is not configured. %s',
            $name,
            $configured === []
                ? 'No accounts are configured; add one under cloudflare.accounts.'
                : 'Configured accounts: ' . implode(', ', $configured) . '.'
        ));
    }
}
