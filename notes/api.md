# Ravlin API sketch

## Response — mutable, all writes go through methods (validation choke point)
- `new Response( string $body = '', int $status = 200 )`
- `set_status( int $status ) : self`, `set_body( string $body ) : self`
- `set_header( string $name, string $value ) : self` — replace; rejects bad names and CR/LF/NUL
- `add_header( string $name, string $value ) : self` — append (Set-Cookie); `remove_header( string $name ) : self`
- `get_header( string $name ) : ?string` — case-insensitive, first value; `$headers` readable, `private(set)`
- `Response::html( string $body, int $status = 200 )`, `Response::json( mixed $data, int $status = 200 )`
- `Response::redirect( string $url, int $status = 303 )` — 303 so POST → GET is always correct

## Request — read-only for users (`private(set)`), built by the runtime adapter
- `$method` (uppercase), `$path` (raw, no query string; the router decodes each segment), `$body` (raw), `$headers` — same shape as Response
- `get_header( string $name ) : ?string` — identical to Response
- `$query`, `$form`, `$cookies` — readable arrays, like `$headers`; `$params` (route) — `array<string, string>`
- `get_query( string $name ) : string|array|null`, same for `get_form()` and `get_cookie()` — null = missing
- `get_param( string $name ) : ?string` — route params are never arrays; constraints check format, value stays a string
- `json() : mixed` — decoded body; invalid-JSON handling TBD (no exceptions); `is_https() : bool`

## Shared conventions
- Verbs: `get_*` reads, `set_*` replaces, `add_*` appends, `remove_*` deletes — same everywhere
- Header names are case-insensitive: stored lowercase, values as lists, `get_header()` returns the first value
- Collections (`$headers`, `$query`, `$form`, `$cookies`, `$params`) are readable `private(set)` arrays beside a `get_*` method
- Only Response is writable by users; Request never has `set_*` / `add_*` / `remove_*` methods

## App and routing
- `new App( array $config )` — `base_path`, `keys`, `debug`; plain PHP config
- `load_routes( string $file ) : self` — `require`s the file (resolved from `base_path`) with `$app` in scope; call again to split
- `get|post|put|patch|delete( string $path, $handler ) : Route`; `route( array $methods, $path, $handler ) : Route`
- Handler: `fn ( Request $req ) : Response|string|array` — string → `Response::html()`, array → `Response::json()`
- Handler forms (`callable|array|string`): closure, `[ Class::class, 'method' ]`, `Class::class`, `[ $object, 'method' ]`
- `Class::class` alone: calls `__invoke()` if present, else the method named after the HTTP method (`get()`, `post()`; HEAD → `get()`; missing → 405)
- `::class` forms: `new Class( $app )` per request, only for the matched route — no state carried between requests
- `[ $object, 'method' ]`: one object reused across requests in worker runtimes — keep it stateless
- Paths: `/posts/{id}`, `/posts/{id:int}`, `/tags/{slug:[a-z-]+}` — read with `$req->get_param( 'id' )`, always a string
- `group( string $prefix, callable $fn )` — `$fn( Group $g )` registers `$g->get( ... )` etc. under the prefix
- Params reach the handler via `Request::with_params( array $params ) : Request` — `@internal`, returns a copy
- `handle( Request $req ) : Response` — no output, no globals; runtimes call it and send the result
- HEAD falls back to the GET route (the handler sees `$req->method === 'HEAD'`); App always empties HEAD bodies
- Runtimes must not compute `Content-Length` for HEAD from the emptied body — send only what the handler set
- No route for the path → 404; path known but not the method → 405 + `Allow`; OPTIONS → 204 + `Allow`

## Built-in request handling (fixed order inside `App::handle()`, no public middleware API yet)
1. Error handler — the one outermost `catch ( \Throwable )`: plain 500 in prod, detail page when `debug` is true
2. CSRF — unsafe methods rejected with 403 when cross-site (Sec-Fetch-Site, then Origin) or the signed token is bad
3. Route match → `with_params()` → handler; string/array return converted to a Response
4. Security headers — CSP with per-request nonce, nosniff, frame-ancestors, Referrer-Policy, COOP, CORP, HSTS on HTTPS
- Security headers are added to every response, including the 500 from step 1 and the 403 from step 2
- Config switches: `csrf`, `security_headers` (`true`/`false` or an array of overrides)
- Per route: `->set_option( 'csrf', false )` (e.g. webhooks), `->set_option( 'csp', '...' )`

## Runtimes — each takes the App; the App never knows which runtime runs it
- `Sapi::run( App $app ) : void` — php -S, php-fpm, FrankenPHP classic; Request from superglobals + `php://input`, sent with `header()`/`echo`
- `FrankenPhpWorker::run( App $app ) : void` — loops `frankenphp_handle_request()`; a fresh Request per iteration, nothing kept between
- `Workerman::run( App $app, string $listen = 'http://0.0.0.0:8080', int $workers = 4 ) : void`
- Static files: out of scope for now — ravlin doesn't serve them (FrankenPHP's Caddy still does on its own)
- All runtimes only translate: Request in → `$app->handle()` → Response out
