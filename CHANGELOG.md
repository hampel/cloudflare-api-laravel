# CHANGELOG

## 1.0.0 (2026-09-13)

### Changed

- requires `hampel/cloudflare-api` `^1.0`, raised from `^0.1.1`. That release makes
  `DnsRecord::$type` nullable and moves `Zones::identifier()` to `Support\Identifier::for()`;
  neither is reached through this package's own surface
- `README` documents what the major version covers, and what reaches through the facade from the
  core package instead

### Fixed

- `RequestSending` is documented as firing. `ResponseReceived` and `ConnectionFailed` do not fire;
  the adapter's docblock, the `README` and `CLAUDE.md` previously said all three did not.
  `tests/EventTest.php` asserts the split

## 0.1.0 (2026-09-13)

Initial release.

- `CloudflareServiceProvider` binds `CloudflareManager`, merges `config/cloudflare.php` and
  publishes it under the `cloudflare-config` tag
- `CloudflareManager` builds one `Hampel\Cloudflare\Api\Client` per configured token, memoised by
  name; a call naming no account is forwarded to the default
- `Cloudflare` facade, with a `@method` annotation for each accessor on `Client`
- `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` apply to requests the
  API client makes. Request building, status mapping and the exception hierarchy are the core
  package's throughout, and its exceptions reach the caller unchanged
- `Illuminate\Http\Client\Factory` is bound as a singleton when the application has not bound one,
  for Laravel Zero support
- `Psr\Http\Client\ClientInterface` is bound separately: rebind it to route the package's requests
  through an application's own HTTP client
- `page_size` and `base_uri` are top-level settings, shared by every account's client, and are
  validated when the client is built. There is no `version` setting
- `UnknownAccount` and `InvalidConfiguration` extend the core package's `CloudflareException`. An
  account with no token, a token containing whitespace, and a base URI or page size the API would
  refuse are all reported when the client is built rather than on the first request
- requires `hampel/cloudflare-api` `^0.1.1`
