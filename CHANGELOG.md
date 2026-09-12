# CHANGELOG

## Unreleased

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
  as a Laravel Zero application does not
- `Psr\Http\Client\ClientInterface` is bound separately: rebind it to route the package's requests
  through an application's own HTTP client
- `page_size` and `base_uri` are top-level settings, shared by every account's client; the core
  package validates both when the client is built. There is deliberately no `version` setting —
  `/client/v4` has been the only version of this API since 2014
- `UnknownAccount` and `InvalidConfiguration` extend the core package's `CloudflareException`. An
  account with no token, a token containing whitespace, and a base URI or page size the API would
  refuse are all reported when the client is built rather than on the first request
- requires `hampel/cloudflare-api` `^0.1.1`. The patch floor is deliberate: 0.1.0 maps every 400
  to `ValidationException`, so a token Cloudflare cannot parse escapes the documented
  `catch (NotAuthenticatedException)`
