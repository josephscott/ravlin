# Ravlin API sketch

## Response — mutable, all writes go through methods (validation choke point)
- `new Response( string $body = '', int $status = 200 )`
- `set_status( int $status ) : self`, `set_body( string $body ) : self`
- `set_header( string $name, string $value ) : self` — replace; rejects bad names and CR/LF/NUL
- `add_header( string $name, string $value ) : self` — append (Set-Cookie); `remove_header( string $name ) : self`
- `get_header( string $name ) : ?string` — case-insensitive, first value; `$headers` readable, `private(set)`
- `Response::html( string $body, int $status = 200 )`, `Response::json( mixed $data, int $status = 200 )`
- `Response::redirect( string $url, int $status = 303 )` — 303 so POST → GET is always correct
- `Response::server_error()` — the one plain 500: `Internal Server Error`, `text/plain; charset=utf-8`, `Cache-Control: no-store`; also what `json()` returns when encoding fails; it doesn't log

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
- `load_routes( string $file ) : self` — relative paths need an absolute `base_path` (`/…`, `phar://…`; `C:\…` only on Windows, UNC paths unsupported), never the CWD; absolute paths as-is; missing/unreadable → warning
- The file is `require`d unbound: only `$app` (and `$routes_file`) in scope, no `$this`, no App private access; it must not declare functions/classes (two Apps in one process → fatal); loading twice registers routes twice
- Errors thrown while loading routes happen at boot, outside `handle()`'s outermost catch — they stop the process
- `get|post|put|patch|delete( string $path, $handler ) : Route`; `route( array $methods, $path, $handler ) : Route`
- Handler: `fn ( Request $req ) : Response|string|array` — string → `Response::html()`, array → `Response::json()`
- Handler forms (`callable|array|string`): closure, `[ Class::class, 'method' ]`, `Class::class`, `[ $object, 'method' ]`
- `Class::class` alone: calls `__invoke()` if present, else the method named after the HTTP method (`get()`, `post()`; HEAD → `head()`, then `get()`)
- A class route only serves the methods its class implements (names starting with `__` never count) — others fall through to later routes, then 405/OPTIONS `Allow` lists only what's served
- Only the route's listed methods are checked: a `get()` route on a class with just `head()` serves nothing (404); a private `get()` still matches → 500 + warning
- Inherited public methods count: don't put public `get()`/`delete()` helpers on a base class you route to
- Anything already callable is used as-is: a function name beats a class of the same name; `[ Class::class, 'static' ]` is called without constructing
- `::class` forms: `new Class( $app )` per request, only for the matched route — no state carried between requests
- `[ $object, 'method' ]`: one object reused across requests in worker runtimes — keep it stateless
- In worker runtimes, static properties on handler classes persist across requests; handlers must not register routes on `$app`
- Paths: `/posts/{id}`, `/posts/{id:int}`, `/tags/{slug:[a-z-]+}` — read with `$req->get_param( 'id' )`, always a string
- Constraints: `int` = `[0-9]+`; anything else is a regex for the whole decoded segment (no `/` or `#`); bad regex → route skipped with a warning
- A failed constraint means the route doesn't match: later routes are tried, then 404 (not 405)
- Constraint regexes use `/u`, so `\d` and `\w` match Unicode — use `[0-9]`, `[A-Za-z0-9_]`; `int` has no length limit, `(int)` saturates; type names are case-sensitive
- Avoid nested quantifiers; a PCRE error (backtrack/JIT limit) fails closed → 404 with a warning naming the route
- Each request path segment is percent-decoded once on its own (`rawurldecode`, `+` stays `+`); literals compare decoded, `%2F` stays inside a param
- Params are never empty and may contain `/` or `..` — never use one as a file path without a constraint
- A decoded path with control bytes (`\x00`–`\x1F`, `\x7F`, U+0080–U+009F) or invalid UTF-8 matches nothing → 404
- Route paths are written decoded (`/café`, not `/caf%C3%A9`); a `%`, control byte, bad `{...}` or no valid methods → skipped with a warning
- `$req->path` stays raw (`/%61dmin` matches the `/admin` route) — authorize by route or group, never by string checks on `$req->path`
- `group( string $prefix, callable $fn ) : self` — `$fn( Group $g )` registers `$g->get( ... )` etc. under the prefix; groups nest, prefixes may hold params
- Prefix: `/a` or `/a/{id}` — no trailing `/`, empty segments, `%`, control bytes or invalid UTF-8, else skipped with a warning
- Group paths must be `''` (the prefix itself) or start with `/`; anything else is not joined and is rejected, so a route can't escape its group
- Params reach the handler via `Request::with_params( array $params ) : Request` — `@internal`, returns a copy
- `handle( Request $req ) : Response` — no output, no globals; runtimes call it and send the result
- HEAD falls back to the GET route (the handler sees `$req->method === 'HEAD'`); App always empties HEAD bodies
- Runtimes must not compute `Content-Length` for HEAD from the emptied body — send only what the handler set
- No route for the path → 404; path known but not the method → 405 + `Allow`; OPTIONS → 204 + `Allow`

## Built-in request handling (fixed order inside `App::handle()`, no public middleware API yet)
1. Error handler — the one outermost `catch ( \Throwable )`: plain 500 in prod, detail page when `debug` is true
- Prod 500: `Internal Server Error`, `text/plain; charset=utf-8`; debug (`=== true` only): escaped HTML with class, message, file:line, trace, previous chain; both `Cache-Control: no-store`
- Every uncaught Throwable is logged as one `error_log` line (control bytes and `\` escaped, trace without argument values, ≤ 10 previous, ≤ 50 frames, messages ≤ 8 KB, line ≤ 16 KB)
- All framework 500s (throwable, non-callable handler, unsupported result, JSON failure) share the plain shape above
- `handle()` captures handler output with a discarding buffer, unwinds buffers inside the catch, and logs the stray byte count
- A handler that closes ravlin's buffer (e.g. `ob_end_flush()`) → 500 + log, its output may already have escaped; one that opens a buffer that can't be removed → buffer emptied, 500 + log, later output passes through instead of vanishing
- Warnings → log is a runtime policy, not `handle()`'s: runtimes install `ErrorLog` (only if no handler is set; an app's Sentry/test handler is left alone), and remove it only if it's still on top
- The handler returns false for unreported/`@` errors (so `error_get_last()` works) and for `E_USER_ERROR`/`E_RECOVERABLE_ERROR` (PHP still halts); reported warnings never set `error_get_last()`
- `Sapi`: `exit`/`die` or a fatal before the response is sent → 500, empty body (so `header( 'Location: …' ); exit;` becomes a 500 — return `Response::redirect()`); with `display_errors=1` PHP prints fatals itself (never in production)
- `Sapi::run()` is once per process (each call registers a shutdown function); callers of `handle()` outside a runtime get PHP's native warning logging
- Not catchable: fatal errors (memory, timeouts), `exit`/`die`, errors while loading routes at boot; `handle()` is not reentrant across Fibers (output buffers are process-global)
2. CSRF — unsafe methods rejected with 403 when cross-site (Sec-Fetch-Site, then Origin) or the signed token is bad
3. Route match → `with_params()` → handler; string/array return converted to a Response
4. Security headers — CSP with per-request nonce, nosniff, frame-ancestors, Referrer-Policy, COOP, CORP, HSTS on HTTPS
- Security headers are added to every response, including the 500 from step 1 and the 403 from step 2
- Config switches: `csrf`, `security_headers` (`true`/`false` or an array of overrides)
- Per route: `->set_option( 'csrf', false )` (e.g. webhooks), `->set_option( 'csp', '...' )`; `get_option( $name )` → null when unset
- Only known options with the right type (`csrf` bool, `csp` non-empty printable ASCII) are kept; anything else → warning

## Runtimes — each takes the App; the App never knows which runtime runs it
- `Sapi::run( App $app ) : void` — php -S, php-fpm, FrankenPHP classic; Request from superglobals + `php://input`, sent with `header()`/`echo`
- `FrankenPhpWorker::run( App $app ) : void` — loops `frankenphp_handle_request()`; a fresh Request per iteration, nothing kept between
- `Workerman::run( App $app, string $listen = 'http://0.0.0.0:8080', int $workers = 4 ) : void`
- Static files: out of scope for now — ravlin doesn't serve them (FrankenPHP's Caddy still does on its own)
- All runtimes only translate: Request in → `$app->handle()` → Response out
- Runtimes install `ErrorLog` (once per process for workers); Workerman installs its own handler (stdout → /dev/null in daemon mode), so its adapter must replace it and require an `error_log` file
