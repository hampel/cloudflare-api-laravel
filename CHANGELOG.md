# CHANGELOG

## 1.2.1 (2026-09-14)

### Fixed

- an account whose name contains a dot, such as `example.com`, resolves; it previously raised
  `UnknownAccount` while `configuredAccounts()` listed it
- the config comment and `README` list `Account Settings:Read`, the permission `accounts()` needs

## 1.2.0 (2026-09-14)

### Changed

- the transport is bound under `cloudflare.http_client`, exposed as
  `CloudflareServiceProvider::HTTP_CLIENT`, and `CloudflareManager` is built from that key alone.
  An application overrides the transport by binding that key; its binding is kept whichever order
  the providers register in
- `Psr\Http\Client\ClientInterface` is no longer bound by this package, and binding it no longer
  changes the transport this package uses. Another package binding it no longer replaces this
  package's transport either
- a `cloudflare.http_client` binding that is not a PSR-18 client raises `InvalidConfiguration`
  naming the key

## 1.1.1 (2026-09-14)

### Fixed

- `Http::swap()` is followed by a client resolved before the swap, including the swapped factory's
  fakes and `preventStrayRequests()`
- the configured `timeout` and `connect_timeout`, and the transport options from
  `Http::globalOptions()`, reach the request. A global header, query or body option is not applied

## 1.1.0 (2026-09-13)

### Added

- `CLOUDFLARE_API_TOKEN` is the documented token variable. `CLOUDFLARE_TOKEN` is still read when
  `CLOUDFLARE_API_TOKEN` is unset or empty

### Changed

- requires `hampel/cloudflare-api` `^1.0.1`, raised from `^1.0`. A token refused by its IP address
  filter now raises `NotAuthenticatedException`, where `Zones::find()` previously returned `null`

### Fixed

- `README` documents registering the provider under Laravel Zero, which does not run package
  discovery, and publishing the config there once the provider is listed
- `README` documents that `verify()` passes a token refused by its IP address filter, and shows a
  startup check that lists one page of zones after verifying

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
