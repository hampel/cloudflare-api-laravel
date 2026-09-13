<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Exception;

use Hampel\Cloudflare\Api\Exception\CloudflareException;

/**
 * The configuration cannot be turned into a client.
 *
 * Raised rather than letting a half-configured account through, and all three cases it covers
 * are raised HERE rather than where they would otherwise surface. A missing token reaches
 * Cloudflare as `Authorization: Bearer ` and comes back as a 400 naming a malformed request
 * header - which reads as a bug in the client rather than as an unset environment variable. A
 * token with a newline in it comes back as "Invalid API Token", which sends whoever reads it
 * to the dashboard to make another one rather than to the variable they set. And an unusable
 * base URI or page size is rejected by the core package with a message about an argument,
 * which is accurate and says nothing about where to go and fix it.
 */
final class InvalidConfiguration extends CloudflareException
{
    public static function missingToken(string $account): self
    {
        return new self(sprintf(
            'Cloudflare account "%s" has no token. Set it in config/cloudflare.php, or in the '
            . 'environment if the shipped config is in use. Every Cloudflare endpoint needs a '
            . 'credential, so there is no anonymous request to fall back to.',
            $account
        ));
    }

    /**
     * The core package refused the token itself - which in practice means whitespace in it.
     */
    public static function token(string $account, \Throwable $previous): self
    {
        return new self(
            sprintf('Cloudflare account "%s" has a token that cannot be used. ', $account)
                . $previous->getMessage(),
            0,
            $previous
        );
    }

    /**
     * The container binding for this package's transport resolved to something that cannot send.
     */
    public static function httpClient(string $key, string $type): self
    {
        return new self(sprintf(
            'The container binding %s must be a Psr\\Http\\Client\\ClientInterface; it resolved to %s.',
            $key,
            $type
        ));
    }

    /**
     * The core package refused the base URI or the page size.
     *
     * Wrapped rather than passed through, so the message names the configuration rather than an
     * argument - and kept as the previous exception, so the original reason is still readable.
     */
    public static function api(\Throwable $previous): self
    {
        return new self(
            'The cloudflare.base_uri and cloudflare.page_size settings do not describe an API '
            . 'this package can talk to. ' . $previous->getMessage(),
            0,
            $previous
        );
    }
}
