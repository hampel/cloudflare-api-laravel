<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Laravel\Tests;

use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Client;
use Hampel\Cloudflare\Api\Exception\ExceptionInterface;
use Hampel\Cloudflare\Api\Laravel\CloudflareManager;
use Hampel\Cloudflare\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Cloudflare\Api\Laravel\Exception\UnknownAccount;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;
use Illuminate\Contracts\Config\Repository as Config;
use PHPUnit\Framework\Attributes\Test;

final class ManagerTest extends TestCase
{
    #[Test]
    public function it_resolves_a_client_for_a_named_account(): void
    {
        $client = $this->manager()->client('customers');

        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(ApiToken::class, $client->authentication());
    }

    #[Test]
    public function it_defaults_to_the_configured_account(): void
    {
        $this->assertSame('main', $this->manager()->getDefaultAccount());
        $this->assertSame(
            $this->manager()->client('main'),
            $this->manager()->client(),
        );
    }

    #[Test]
    public function clients_are_memoised_per_account(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->client('main'), $manager->client('main'));
        $this->assertNotSame($manager->client('main'), $manager->client('customers'));
    }

    #[Test]
    public function the_manager_is_a_singleton_and_the_facade_resolves_it(): void
    {
        $this->assertSame($this->manager(), $this->container()->make(CloudflareManager::class));
        $this->assertSame($this->manager(), Cloudflare::getFacadeRoot());
    }

    #[Test]
    public function it_lists_the_configured_accounts(): void
    {
        $this->assertSame(['main', 'customers'], $this->manager()->configuredAccounts());
    }

    #[Test]
    public function an_unknown_account_names_the_ones_that_are_configured(): void
    {
        $this->expectException(UnknownAccount::class);
        $this->expectExceptionMessage('Configured accounts: main, customers.');

        $this->manager()->client('nope');
    }

    #[Test]
    public function the_packages_exceptions_are_catchable_alongside_the_cores(): void
    {
        // Extending the core's base exception rather than declaring a hierarchy of our own, so
        // an application already catching ExceptionInterface catches misconfiguration too rather
        // than meeting it as an unhandled error.
        $this->expectException(ExceptionInterface::class);

        $this->manager()->client('nope');
    }

    #[Test]
    public function an_account_without_a_token_is_refused(): void
    {
        // Refused here rather than allowed to fail on first use. An empty credential reaches
        // Cloudflare as `Authorization: Bearer ` and comes back as a 400 naming a malformed
        // request header, which reads as a bug in the client rather than as an unset variable.
        $this->configure('nowhere', []);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no token');

        $this->manager()->client('nowhere');
    }

    #[Test]
    public function an_empty_token_is_treated_as_absent(): void
    {
        // An unset environment variable reaches config as "" as readily as it reaches it as null.
        $this->configure('blank', ['token' => '  ']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no token');

        $this->manager()->client('blank');
    }

    #[Test]
    public function a_token_with_whitespace_in_it_names_the_account(): void
    {
        // The mistake this one exists for is real rather than theoretical: a token pasted out of
        // the dashboard with its surrounding quotes, or with a trailing newline from a here-doc,
        // reaches Cloudflare as a credential that is not the one that was copied - and comes back
        // as "Invalid API Token", which sends whoever reads it to the dashboard to make another
        // one rather than to the variable they set. The core package refuses it; this asserts the
        // refusal still names the account it came from.
        $this->configure('pasted', ['token' => "abc\ndef"]);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Cloudflare account "pasted" has a token that cannot be used');

        $this->manager()->client('pasted');
    }

    #[Test]
    public function the_api_settings_reach_the_cores_config(): void
    {
        $config = $this->container()->make(Config::class);
        $config->set('cloudflare.base_uri', 'https://cloudflare.example.test/');
        $config->set('cloudflare.page_size', '25');

        $api = $this->manager()->client()->config();

        // Trailing slash trimmed by the core package, and /client/v4 is not part of the base.
        $this->assertSame('https://cloudflare.example.test', $api->baseUri);
        $this->assertSame(25, $api->pageSize);
        $this->assertSame('https://cloudflare.example.test/client/v4/zones', $api->resolve('zones'));
    }

    #[Test]
    public function a_page_size_read_from_the_environment_as_a_string_is_still_a_number(): void
    {
        // env() answers with a string, so a config value that looks like an int is not one. A
        // page size that arrived as "50" and was passed through as a string would be a type error
        // at the core package's boundary rather than a setting that works.
        $this->container()->make(Config::class)->set('cloudflare.page_size', '50');

        $this->assertSame(50, $this->manager()->client()->config()->pageSize);
    }

    #[Test]
    public function one_config_is_shared_by_every_account(): void
    {
        // There is exactly one Cloudflare, so the base URI and page size describe the API rather
        // than a credential. Asserted because the alternative - a Config per client - is what a
        // reader would assume from the constructor.
        $manager = $this->manager();

        $this->assertSame($manager->client('main')->config(), $manager->client('customers')->config());
    }

    #[Test]
    public function an_unusable_page_size_names_the_configuration_rather_than_an_argument(): void
    {
        $this->container()->make(Config::class)->set('cloudflare.page_size', 0);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('cloudflare.page_size');

        $this->manager()->client();
    }

    #[Test]
    public function a_base_uri_without_a_scheme_names_the_configuration(): void
    {
        $this->container()->make(Config::class)->set('cloudflare.base_uri', 'api.cloudflare.test');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('cloudflare.base_uri');

        $this->manager()->client();
    }

    #[Test]
    public function the_original_reason_is_kept_as_the_previous_exception(): void
    {
        $this->container()->make(Config::class)->set('cloudflare.base_uri', 'api.cloudflare.test');

        try {
            $this->manager()->client();
            $this->fail('Expected an InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            $previous = $e->getPrevious();

            $this->assertNotNull($previous);
            $this->assertStringContainsString('must be absolute', $previous->getMessage());
        }
    }

    #[Test]
    public function an_unset_default_falls_back_to_main(): void
    {
        $this->container()->make(Config::class)->set('cloudflare.default', '');

        $this->assertSame('main', $this->manager()->getDefaultAccount());
    }

    #[Test]
    public function unnamed_calls_are_forwarded_to_the_default_account(): void
    {
        $this->assertSame(
            $this->manager()->client()->zones(),
            $this->manager()->zones(),
        );
    }

    #[Test]
    public function a_method_the_client_does_not_have_is_a_bad_method_call(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method');

        // Through __call() explicitly. That is the method under test - the guard that turns a
        // typo into a named error instead of a fatal one hop further in.
        $this->manager()->__call('noSuchEndpoint', []);
    }

    #[Test]
    public function an_empty_inventory_is_inspectable_rather_than_fatal(): void
    {
        // An application whose account list comes from a file rather than from config needs to
        // report WHY it is empty - a missing or unparseable inventory - rather than have every
        // command die. Nothing here throws until a specific account is named, so a diagnostic
        // command can resolve the manager and describe the situation.
        $this->container()->make(Config::class)->set('cloudflare.accounts', []);

        $manager = $this->manager();

        $this->assertSame([], $manager->configuredAccounts());
        $this->assertSame('main', $manager->getDefaultAccount());

        $this->expectException(UnknownAccount::class);
        $this->expectExceptionMessage('No accounts are configured');

        $manager->client('somewhere');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function configure(string $name, array $settings): void
    {
        $config = $this->container()->make(Config::class);

        $accounts = $config->get('cloudflare.accounts');
        $config->set('cloudflare.accounts', array_merge(is_array($accounts) ? $accounts : [], [$name => $settings]));
    }

    private function manager(): CloudflareManager
    {
        return $this->container()->make(CloudflareManager::class);
    }
}
