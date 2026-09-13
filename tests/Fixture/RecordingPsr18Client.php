<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests\Fixture;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records every URI it is asked to send and answers with one fixed response.
 */
final class RecordingPsr18Client implements ClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = (string) $request->getUri();

        return $this->response;
    }
}
