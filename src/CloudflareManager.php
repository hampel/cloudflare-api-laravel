<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel;

use BadMethodCallException;
use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Client;
use Hampel\Cloudflare\Api\Config as ApiConfig;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Cloudflare\Api\Laravel\Exception\UnknownAccount;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One Cloudflare client per configured token.
 *
 *     Cloudflare::zones()->all();                          // the default account
 *     Cloudflare::client('customers')->zones()->all();     // a named one
 *
 * A MANAGER RATHER THAN A SINGLE BINDING, because Cloudflare tokens are scoped. A token is
 * restricted to named permissions and to a list of zones or accounts, so an application that
 * edits DNS in one place and only reads it in another is expected to hold two - and the
 * narrower one is the one worth reaching for first. A single binding would leave the second
 * credential nowhere to go, and adding this later is a breaking change to a published config
 * file.
 *
 * IT IS NAMED client() AND NOT account(). The manager forwards unknown calls to the default
 * client, so anything named here shadows a method of the same name on Client - and
 * `Client::accounts()` is Cloudflare's accounts endpoint. FacadeConformanceTest asserts no
 * manager method shadows a client one, for any name.
 *
 * Clients are memoised per name. The transport underneath them is not - see
 * PendingRequestClient, which resolves Laravel's HTTP factory at the moment of sending so that
 * `Http::fake()` works whenever it is called.
 *
 * The @mixin is what makes $manager->zones() analysable: __call() forwards anything the client
 * answers to, and one line that cannot drift says so. The facade repeats the list explicitly
 * because @method static is the only form __callStatic() can carry, and a test keeps that copy
 * honest.
 *
 * @mixin Client
 */
final class CloudflareManager
{
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * Which API, and how its URLs are built.
     *
     * ONE INSTANCE, SHARED BY EVERY ACCOUNT'S CLIENT, because there is exactly one Cloudflare -
     * the base URI and the default page size describe the API rather than a credential, so
     * they are top-level settings rather than per-account ones. Asserted in ManagerTest,
     * because the core package's constructor invites the opposite assumption.
     */
    private ?ApiConfig $apiConfig = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The client for a configured account, or for the default when no name is given.
     */
    public function client(?string $name = null): Client
    {
        $name ??= $this->getDefaultAccount();

        return $this->clients[$name] ??= $this->build($name);
    }

    public function getDefaultAccount(): string
    {
        $default = $this->config->get('cloudflare.default');

        return is_string($default) && trim($default) !== '' ? trim($default) : 'main';
    }

    /**
     * The configured account names, in the order they were declared.
     *
     * @return list<string>
     */
    public function configuredAccounts(): array
    {
        $accounts = $this->config->get('cloudflare.accounts');

        return is_array($accounts) ? array_values(array_filter(array_keys($accounts), 'is_string')) : [];
    }

    private function build(string $name): Client
    {
        $settings = $this->config->get('cloudflare.accounts.' . $name);

        if (! is_array($settings)) {
            throw UnknownAccount::named($name, $this->configuredAccounts());
        }

        $token = $this->string($settings, 'token');

        if ($token === null) {
            throw InvalidConfiguration::missingToken($name);
        }

        // ApiToken refuses a token containing whitespace, which is a real configuration
        // mistake rather than a theoretical one - a value pasted with its surrounding quotes
        // or a trailing newline reaches Cloudflare as a credential that is not the one that
        // was copied, and comes back as "Invalid API Token". Re-raised here so the message
        // names the account rather than an argument.
        try {
            $credential = new ApiToken($token);
        } catch (InvalidArgumentException $e) {
            throw InvalidConfiguration::token($name, $e);
        }

        return new Client(
            $this->api(),
            $credential,
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
        );
    }

    /**
     * The core package's Config, built from the top-level settings and memoised.
     *
     * Its constructor validates: a page size below 1, and a base URI that is empty or carries
     * no scheme, are refused here rather than on the first request. The refusal is re-raised
     * as InvalidConfiguration so the message names the configuration and not an argument.
     *
     * THERE IS NO VERSION SETTING, and its absence is deliberate rather than an oversight.
     * `/client/v4` has been the only version of this API since 2014; the core package holds it
     * as a constant precisely so that nothing invites an application to make it configurable,
     * because the only values it could take are wrong.
     */
    private function api(): ApiConfig
    {
        if ($this->apiConfig !== null) {
            return $this->apiConfig;
        }

        $baseUri = $this->config->get('cloudflare.base_uri');
        $pageSize = $this->config->get('cloudflare.page_size');

        try {
            return $this->apiConfig = new ApiConfig(
                is_string($baseUri) && trim($baseUri) !== '' ? trim($baseUri) : null,
                is_numeric($pageSize) ? (int) $pageSize : null,
            );
        } catch (InvalidArgumentException $e) {
            throw InvalidConfiguration::api($e);
        }
    }

    /**
     * One setting, as a non-empty string.
     *
     * Empty is treated as absent throughout, because an unset environment variable reaches
     * config as an empty string as readily as it reaches it as null, and "" is never a
     * meaningful token. Without this the client would present `Authorization: Bearer ` and
     * Cloudflare would answer 400 with code 6003, which describes a malformed request header
     * rather than a credential that was never set.
     *
     * @param  array<mixed>  $settings
     */
    private function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Anything else goes to the default account's client, so a single-account application
     * never has to name one: `Cloudflare::zones()` rather than
     * `Cloudflare::client('main')->zones()`.
     *
     * The facade carries a @method line for each of these, which is where the types come from -
     * see Facades\Cloudflare, and the test that keeps the two in step.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->client();

        if (! method_exists($client, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). The manager forwards to %s.',
                self::class,
                $method,
                Client::class
            ));
        }

        return $client->{$method}(...$arguments);
    }
}
