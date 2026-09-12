# hampel/cloudflare-api-laravel

Laravel integration for [`hampel/cloudflare-api`](https://github.com/hampel/cloudflare-api): a
service provider, a manager for named API tokens, a facade — and one adapter that is the reason
the package exists. Install it in a Laravel or Laravel Zero application that manages DNS, and
every request the client makes becomes visible to `Http::fake()`.

If you only want the API client, install the core package directly. This one is for the case
where the application is a Laravel application and its test suite already speaks `Http::`.

## Installation

```bash
composer require hampel/cloudflare-api-laravel
```

The provider and the `Cloudflare` facade are registered by package discovery. Publish the config
if you want to edit it:

```bash
php artisan vendor:publish --tag=cloudflare-config
```

Set a token in the environment and nothing else is required:

```ini
CLOUDFLARE_TOKEN=your-api-token
```

## More than one token is the normal case, not an exotic one

A Cloudflare API token is scoped — to named permissions, and to a list of zones or accounts. So
"read every zone" and "edit records in this one zone" are two different credentials, and the
narrower one is the one to reach for first. The configuration holds as many as you like:

```php
'accounts' => [
    'main' => ['token' => env('CLOUDFLARE_TOKEN')],
    'customers' => ['token' => env('CLOUDFLARE_CUSTOMERS_TOKEN')],
],
```

```php
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;

Cloudflare::zones()->all();                        // the default account
Cloudflare::client('customers')->zones()->all();   // a named one
```

Calls that do not name an account go to the default, so a single-token application never writes
`client()` at all. Create tokens at `https://dash.cloudflare.com/profile/api-tokens`; this package
needs `Zone:Read` to see zones, `DNS:Read` to read records and `DNS:Edit` to change them.

The Global API Key is deliberately not supported. It cannot be scoped to a zone, cannot be
verified, and grants everything the account can do to anything that gets hold of it.

## Usage

The facade forwards to the core package's client, so its documentation is this package's
documentation from the second call onwards.

```php
use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Laravel\Facades\Cloudflare;

$zone = Cloudflare::zones()->getByName('example.com');

$records = Cloudflare::zones()->records($zone->id)->all();

Cloudflare::zones()->records($zone->id)->create(
    DnsRecord::a('www', '203.0.113.10')->withTtl(300)
);
```

Inject the manager instead of using the facade wherever that reads better — it is bound as a
singleton:

```php
use Hampel\Cloudflare\Api\Laravel\CloudflareManager;

public function __construct(private readonly CloudflareManager $cloudflare)
{
}

$this->cloudflare->client('customers')->zones()->all();
```

Check the credential at startup, before anything that matters:

```php
$token = Cloudflare::verify();     // raises NotAuthenticatedException if it is not usable

$token->isActive();
$token->expiresWithinDays(30);
```

**`verify()` cannot tell you what the token may do.** Cloudflare publishes a token's permissions
nowhere — no endpoint, no response header — so a verification that passes says the credential is
real and live, and says nothing about whether it can read a zone. The only way to find out whether
a token can do something is to try it.

## Http::fake() works, and that is the whole point

The core package takes a PSR-18 client, which is not Laravel's HTTP client — so by default nothing
it sends is visible to `Http::fake()`, and an application testing against it has to fake at the
transport library instead, in a vocabulary the rest of its suite does not use. This package binds
an adapter that sends through Laravel's handler stack, so the fakes, `Http::assertSent()` and
`Http::preventStrayRequests()` all reach it:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    'api.cloudflare.com/*' => Http::response([
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => [['id' => '023e105f4ecef8ad9ca31a8372d0c353', 'name' => 'example.com']],
        'result_info' => ['page' => 1, 'per_page' => 20, 'count' => 1, 'total_count' => 1],
    ]),
]);

$zone = Cloudflare::zones()->findByName('example.com');

Http::assertSent(fn ($request) => $request->hasHeader('Authorization'));
```

Everything in that example goes through the core package's real request building, status mapping
and exception hierarchy. Only the socket is replaced — which is what makes the fake worth
trusting: a 404 still arrives as `NotFoundException` rather than as an unsuccessful `Response`.

**Give the fake a body.** `Http::fake()` with no arguments answers every request with an empty
200, which on this API cannot be a valid answer — every endpoint replies in the envelope above.
The core package raises `MalformedResponseException` rather than reading it as "this zone has no
records", so the mistake is loud here. Do not rely on that; rely on the body.

`Http::fake()` can be registered before or after the client is resolved. That ordering is the one
thing an adapter like this usually gets wrong, and the tests pin it.

## Traps

**A global `page_size` cannot be set well, so the shipped config leaves it null.** The limits are
per endpoint on this API: DNS records take 1 to 5000000 and default to 100, while zones and
accounts take 5 to 50 and default to 20. So 100 — the obvious number — is refused by the zones
endpoint, and the refusal arrives the first time something lists zones rather than when the
application boots. Pass a size to the individual call instead. The refusal happens before the
request is sent, so a wrong value costs a failed call and never a wasted one.

**A zone that does not exist answers 403, not 404.** Cloudflare will not confirm to a credential
which zone ids exist, so "no such zone" and "not your zone" are the same reply. `zones()->find()`
absorbs it and returns null, but only for error code 9109 — a 403 for any other reason still
raises, because silently returning null for a misconfigured credential would send whoever reads it
to the wrong dashboard.

**A bad token arrives two ways and only one of them is a 401.** A value of the right shape but the
wrong content answers 401; a value Cloudflare cannot parse at all — a placeholder left in a config
file, a truncated copy — answers 400 before authentication runs. Both raise
`NotAuthenticatedException`, so one catch around a startup check covers the commonest
configuration mistake as well as the rarer one.

**Telescope will not show this traffic.** Laravel raises its `RequestSending` and
`ResponseReceived` events from `PendingRequest::send()`, a layer above the handler stack this
adapter drives, so those events do not fire. The core package logs every request through PSR-3
instead, which under Laravel reaches the application log.

**Cloudflare's rate limit is counted over five minutes**, and going over it blocks every call for
the next five rather than only the one that went over. The response metadata carries what is left
where Cloudflare reports it — but the rate limit headers are sent per endpoint, so a figure read
from one says nothing about another, and their absence is not evidence of exhaustion.

## What this package does not add

It adds no endpoints, no entities and no behaviour of its own. Everything reachable through the
facade comes from the core package, which wraps zones (read-only), DNS records (full CRUD plus
BIND export) and token verification.

For any of Cloudflare's other endpoints, write an `Endpoint` subclass and hand it to the client —
there is nothing to register:

```php
$cloudflare = Cloudflare::client();

$settings = $cloudflare->endpoint(ZoneSettings::class)->all($zoneId);

// or, for a single call
$ssl = $cloudflare->connection()->get('zones/' . $zoneId . '/settings/ssl')->object();
```

Both go over this package's transport, so a test of either is faked like everything else.

## Requirements

PHP 8.3 or later; Laravel 12 or 13. Tested against PHP 8.3 and 8.5 at both ends of the Laravel
range, including a `--prefer-lowest` resolution of Laravel 12.

Laravel Zero is supported and has its own test, because its container does not bind the HTTP
client factory that a full Laravel application does — without the binding this package adds, a
fake would silently fail to intercept and the request would reach the real API.

## Licence

MIT. See `LICENSE.md`.
