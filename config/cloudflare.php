<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Account
    |--------------------------------------------------------------------------
    |
    | Which entry under "accounts" a call without a name uses: Cloudflare::zones()
    | is Cloudflare::client(<this>)->zones(). An application using one token never
    | needs to name it.
    |
    */

    'default' => env('CLOUDFLARE_ACCOUNT', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    |
    | One entry per API token, named however you like -- the name is what
    | Cloudflare::client('...') takes. Each needs a token and nothing else: there is
    | one Cloudflare, so unlike a self-hosted API there is no URL to configure per
    | account.
    |
    | MORE THAN ONE ENTRY IS THE NORMAL CASE HERE, not an exotic one, because a
    | Cloudflare token is scoped. It is restricted to named permissions and to a list
    | of zones or accounts, so "read every zone" and "edit records in this one zone"
    | are two different credentials, and the narrower one is the one to reach for
    | first. Create them at https://dash.cloudflare.com/profile/api-tokens.
    |
    | What a token needs for this package: Zone:Read to see zones, DNS:Read to read
    | records, DNS:Edit to change them.
    |
    | An account with no token is refused when its client is built rather than
    | allowed to fail on first use. There is no anonymous request on this API to fall
    | back to, and an empty credential comes back as a 400 about malformed request
    | headers, which reads as a bug in the client rather than as an unset variable.
    |
    | The Global API Key is deliberately not supported. It cannot be scoped to a
    | zone, cannot be verified, and grants everything the account can do to anything
    | that gets hold of it.
    |
    | CLOUDFLARE_API_TOKEN is the variable to set. CLOUDFLARE_TOKEN, the earlier name,
    | is still read when CLOUDFLARE_API_TOKEN is unset OR EMPTY -- `?:` rather than
    | env()'s default, because a blank CLOUDFLARE_API_TOKEN= line is not null, so the
    | default would never apply and an account with a working CLOUDFLARE_TOKEN would be
    | refused.
    |
    */

    'accounts' => [

        'main' => [
            'token' => env('CLOUDFLARE_API_TOKEN') ?: env('CLOUDFLARE_TOKEN'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | How many items a list request asks for when the caller does not say. Null uses
    | each endpoint's own default, which is what almost every application wants.
    |
    | THE LIMITS DIFFER PER ENDPOINT ON THIS API, so a single number here is harder
    | to set well than it looks:
    |
    |   zones, accounts     5 to 50, default 20
    |   DNS records         1 to 5000000, default 100
    |
    | So 100 -- the obvious value, and the one DNS records already uses -- is REFUSED
    | by the zones endpoint, and the refusal arrives the first time something lists
    | zones rather than when the application boots. Leave this null and pass a size
    | to the individual call instead, unless every list in the application is of the
    | same kind.
    |
    | The refusal is raised before the request is sent, so a wrong value here costs a
    | failed call and never a wasted one.
    |
    */

    'page_size' => env('CLOUDFLARE_PAGE_SIZE'),

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | The API root WITHOUT the /client/v4 segment. Null uses Cloudflare's own host,
    | which is what all but two situations want: a recorded fixture served locally,
    | and an outbound proxy that terminates the connection.
    |
    | There is no version setting, and that is deliberate rather than an omission.
    | /client/v4 has been the only version of this API since 2014; the core package
    | holds it as a constant so that nothing invites an application to make it
    | configurable, because the only values it could take are wrong.
    |
    */

    'base_uri' => env('CLOUDFLARE_API_URL'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Applied to Laravel's HTTP client on every request, so a consumer's own
    | Http::globalOptions() and Http::globalRequestMiddleware() apply alongside them.
    |
    | There is no redirect setting: Guzzle's PSR-18 entry point does not follow
    | redirects, and no endpoint this package wraps answers one.
    |
    */

    'timeout' => env('CLOUDFLARE_TIMEOUT', 10),

    'connect_timeout' => env('CLOUDFLARE_CONNECT_TIMEOUT', 5),

];
