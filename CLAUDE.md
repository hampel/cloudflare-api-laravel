# hampel/cloudflare-api-laravel

Laravel integration for `hampel/cloudflare-api`. A service provider, a manager for named API
tokens, and a facade — and one adapter that is the reason the package exists.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

## Layout

| path | what it is |
|---|---|
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/CloudflareManager.php` | one client per configured token, memoised |
| `src/CloudflareServiceProvider.php` | the bindings, the merged config, the publish tag |
| `src/Facades/Cloudflare.php` | the facade, and the `@method` block that types it |
| `src/Exception/` | configuration failures, in the core package's hierarchy |
| `config/cloudflare.php` | the published config |

## What the package is for

The core package takes its own PSR-18 client, so nothing it sends is visible to `Http::fake()`.
`PendingRequestClient` supplies a client that sends through Laravel's handler stack, so the fakes,
`Http::assertSent()` and `Http::preventStrayRequests()` all reach it — while the core package's
request building, status mapping and exception hierarchy stay untouched.

The core package's `Connection` docblock says the PSR-18 seam "lets a Laravel integration route
this traffic through `Http::fake()`". **That is true only because this class exists** — there is
nothing automatic about it, and a PSR-18 client is not Laravel's HTTP client.

`tests/HttpFakeTest.php` is that claim, asserted. If it does not pass, the package has no reason
to exist.

## The adapter, and why it looks the way it does

Three decisions in `PendingRequestClient` are load-bearing and each has a way of looking like
clutter to be tidied away:

- **The pending request is rebuilt on every send.** `Factory::fake()` *replaces* the factory's
  stub collection, and `createPendingRequest()` copies whatever is there when it is called — so a
  client built once and kept holds a snapshot, and a fake registered after it was built never
  applies. On a DNS client that is not a failing test, it is a live change to somebody's zone.
  `HttpFakeTest::faking_after_the_client_was_resolved_still_intercepts` is the test.
- **One Guzzle handler is shared across those rebuilds.** The handler owns curl's connection pool,
  so keep-alive survives even though the stack around it is new each time. Records page at 100 by
  default, and Cloudflare's rate limit is counted over five minutes, so a walk is exactly the
  thing that wants its connection back.
- **`send()` rather than `sendRequest()`, with four options set by hand.** `laravel_data` and
  `on_stats` are `PendingRequest::sendRequest()`'s contract with the handler stack it builds;
  anything driving that stack without going through that method has to supply them. Laravel 13
  reads both defensively, **Laravel 12 does not.** The other three reproduce Guzzle's own
  `sendRequest()`, and `http_errors` must stay off.

  **All three failure modes were probed on Laravel 12 on 2026-09-13**, at `--prefer-lowest`, by
  removing each and running the suite:

  | removed | what happened |
  |---|---|
  | `laravel_data` | `ErrorException: Undefined array key "laravel_data"` on every request |
  | `on_stats` | `ErrorException: Undefined array key "on_stats"` on every request |
  | `http_errors => false` | a faked 404 arrived as `RequestException`, `Could not reach the Cloudflare API` |

  The third is the one that would survive review, because it fails as a plausible-sounding
  transport error rather than as a PHP notice — and on this API it destroys a distinction the
  core package works hard for, since a 403 with code 9109 means *no such zone* while a 403 with
  any other code means the credential is wrong. **Only the Laravel 12 CI job catches any of
  them** — on 13 the suite passes with the first two gone.

## The core package constraint is `^1.0`, and one major only

**Not `^0.1.1|^1.0`.** This package re-exports the core package's types through a facade whose
`@method` annotations are the only types a call through it has, so spanning two majors would mean
a consumer's `DnsRecord::$type` is nullable or not depending on which base version resolved. A
wrapper cannot promise a stabler surface than the package it re-exports.

1.0.0 made two breaking changes and neither reaches `src/`: `DnsRecord::$type` became
`?RecordType`, and `Zones::identifier()` moved to `Support\Identifier::for()`. Nothing here
touches either — the facade re-exports `DnsRecords`, the *endpoint*, not the entity. Verified
on 2026-09-13 across both supported Laravel majors before the constraint moved.

**Watch for a new accessor on `Client` rather than for a major version.** The core package wraps a
dozen of Cloudflare's two thousand endpoints, so it is expected to grow accessors, and its
`RecordType` mirrors a list Cloudflare controls — a type Cloudflare adds arrives in a **minor**
release, because promising otherwise would promise a major on Cloudflare's schedule. So write a
`default` arm in any `match` over `RecordType`, and note that `FacadeConformanceTest` is what turns
a new accessor into a failing test rather than a call that silently loses its type.

### A floor is a claim, and only `--prefer-lowest` tests it

Kept because the discipline outlives the constraint it was learned on. The original floor was
written `^0.1`, which permits 0.1.0 — and 0.1.0 maps every 400 to `ValidationException`, so a token
Cloudflare cannot parse escaped the `catch (NotAuthenticatedException)` this package's README
documents.

Found on 2026-09-13 by running the `--prefer-lowest` corner locally: the suite passed on 0.1.1 and
`ExceptionPassthroughTest` errored on 0.1.0 with `Undefined constant
ApiException::CODE_INVALID_REQUEST_HEADERS`. Nothing else caught it — the suite, PHPStan level 10
and a real-application install all passed, because all three resolved the newest release.

**The wrong floor was not a guess, it was the upstream package's stated advice**, which is the last
place anyone thinks to check; its README recommended `^0.1` thirty lines above the section
documenting the fix that `^0.1` excludes. So the lesson is not "read the constraint more
carefully": raise the floor whenever a test depends on a fix, and do not widen it back without
running that corner.

## Facts worth not rediscovering

- **A forgotten fake body is LOUD here.** `Http::fake()` with no arguments answers an empty 200,
  and on this API every endpoint replies in an envelope — so an empty body cannot be a valid answer
  and `MalformedResponseException` is raised rather than the response reading as *this zone has no
  records*. That is not the usual behaviour for a client of this kind: an API that answers a bare
  collection, or that uses 204, has to treat an empty body as an empty result and cannot be strict
  here. Do not rely on it; it is an absence, and the README says give the fake a body.
- **A global `page_size` cannot be set well, because the limits are per endpoint.** DNS records
  take 1 to 5000000 and default to 100; zones and accounts take 5 to 50 and default to 20. So 100
  — the obvious value — is refused by the zones endpoint. The shipped config leaves it null, and
  `TransportTest::a_global_page_size_the_zones_endpoint_refuses_fails_before_a_request_is_spent`
  pins both halves. The refusal happens before the request is sent, so a wrong value never spends
  a request against the five-minute budget.
- **There is no `version` setting and its absence is asserted.** `/client/v4` has been the only
  version since 2014 and the core package holds it as a constant; a settable one here would invite
  an application to configure it, and the only values it could take are wrong. This is the one
  place the config shape deliberately departs from the usual shape for a wrapper of this kind,
  which carries a version setting because its API has more than one version to point at.
- **A zone that does not exist answers 403 with code 9109, not 404**, and the whole discrimination
  rides on the error code in the response BODY — so a transport that reshaped the body would
  collapse *no such zone* into *bad credential*. `ExceptionPassthroughTest` asserts both halves
  through a fake rather than trusting the core package's own suite.
- **`ResponseReceived` and `ConnectionFailed` do not fire, but `RequestSending` does** — and the
  symmetry invites the opposite guess, which is how the wrong version of this note reached three
  packages. Laravel dispatches the first two from `PendingRequest::send()`, a layer above the
  handler stack, which the adapter never calls; it registers `RequestSending` as a before-sending
  callback that `buildBeforeSendingHandler()` puts *inside* the stack the adapter does drive.
  Telescope listens for `ResponseReceived`, so the practical conclusion is unchanged — it shows
  nothing — but a listener pairing `RequestSending` with a response counts requests that never get
  one. `tests/EventTest.php` measures all three rather than restating this; verified on Laravel
  13.31.0 and 12.61.1.
- **`failOnDeprecation` is inert in a Testbench package without help.** Laravel's `HandleExceptions`
  replaces PHPUnit's error handler when the application boots. `withoutDeprecationHandling()` in
  `setUp()` fixes it for test-executed paths — but the provider's own `register()` has already run
  inside `parent::setUp()`, so
  `ConfigurationTest::registering_the_provider_binds_the_manager_and_merges_the_config` registers
  it again against an application built in the test body.
- **Laravel Zero does not bind the HTTP client factory, and Testbench cannot see that.** Laravel
  binds `Illuminate\Http\Client\Factory` as a singleton in `FoundationServiceProvider`; Laravel
  Zero's provider set does not, and `app:install http` only runs `composer require
  illuminate/http`. Unbound, every `make()` builds a fresh factory, so the package holds a
  different one from the facade and `Http::fake()` does not intercept — the request reaches the
  real API. `singletonIf` in the provider closes it. `tests/LaravelZeroTest.php` builds the
  container by hand because Testbench always boots a full application and can never reach this.
- **`composer-require-checker` carries the undeclared-dependency check here, not the dev-free
  PHPStan job.** `laravel/framework` `replace`s every `illuminate/*` component, so the framework
  supplies every `Illuminate` symbol whether its component was declared or not. The five
  whitelisted symbols are that same `replace`. A *new* `Illuminate` symbol appearing there is a
  prompt to check `require`, not to extend the list.

  **It is not in `composer check`, so it does not run with the rest.** Run it by hand after adding
  or changing any `use` in `src/` — CI gets the binary from `setup-php`'s `tools:` input, which has
  no equivalent on a workstation:

  ```bash
  mkdir -p /tmp/crc && composer -d /tmp/crc require maglnet/composer-require-checker
  /tmp/crc/vendor/bin/composer-require-checker check \
      --config-file=.github/composer-require-checker.json composer.json
  ```

## The facade's annotations are the only types it has

`Cloudflare::zones()` goes through the manager's `__call()` and returns `mixed`; the
`@method static` block is what makes `Cloudflare::zones()->all()` analysable. So an accessor added
to the core package's `Client` in a later release is a call that works at runtime and silently
loses its type — and the core package wraps a dozen of Cloudflare's two thousand endpoints, so it
is expected to grow accessors in minor releases. `tests/FacadeConformanceTest.php` compares the two
lists in both directions and checks every annotated return type resolves.

The manager needs the same coverage and gets it from one `@mixin Client` line, which cannot drift.
The facade cannot use `@mixin` because `__callStatic()` needs `@method static`.

## The named accessor is `client()` because the manager must not shadow the client

The manager forwards unknown calls to the default client, so any method declared on it shadows one
of the same name on `Client` and makes the client's unreachable through the facade.
`Client::accounts()` is Cloudflare's accounts endpoint, which is why the inventory method here is
`configuredAccounts()` and the named-credential accessor is `client()`. The config key is still
`accounts`, because that is what they are.
`FacadeConformanceTest::the_manager_does_not_shadow_a_client_method` keeps this from being
reintroduced for any name.

## No harness

Everything worth exercising here is container and configuration wiring, which Testbench sees. The
core package's harness drives the real API calls.

It becomes worth reconsidering if this package grows something it owns end to end — an artisan
command, a queue-worker interaction, a retry policy over the rate-limit headers. Until then a
harness would need a container and a config repository, and Laravel would end up inside it, which
is the opposite of what a harness is for.

## Do not add endpoints at this layer

The core package deliberately omits zone create, delete and settings, the batch, import and scan
endpoints, and the Global API Key. If any of those are wanted **they belong in the core package**,
not here — this layer adds no endpoints, no entities and no behaviour of its own, and an endpoint
added here would be one the core package's own consumers cannot reach.

`Client::endpoint()` and `Connection` are the escape hatches meanwhile, and both go over this
package's transport, so a test of either is faked like everything else.
