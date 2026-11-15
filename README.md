# Ravlin

A small, secure-by-default PHP framework.

- Routes are plain PHP: closures, classes or methods
- CSRF protection and strict security headers are on by default
- Never throws exceptions; bad input gets a warning and is skipped
- Runs under php-fpm and `php -S`
- Requires PHP 8.4+, no other dependencies

Ravlin is early and the API may still change.

## Quick Start

```bash
mkdir my-app
cd my-app
composer require josephscott/ravlin
```

A minimal app is two files:

```
my-app/
├── routes.php
└── public/
    └── index.php
```

`public/index.php`:

```php
<?php
declare( strict_types = 1 );

require __DIR__ . '/../vendor/autoload.php';

$app = new Ravlin\App( [ 'base_path' => dirname( __DIR__ ) ] );
$app->load_routes( 'routes.php' );
Ravlin\Runtime\Sapi::run( $app );
```

`routes.php`:

```php
<?php
declare( strict_types = 1 );

use Ravlin\Http\Request;

/** @var Ravlin\App $app */
$app->get( '/', fn ( Request $req ) => '<h1>Hello</h1>' );
$app->get( '/hello/{name}', fn ( Request $req ) => [ 'hello' => $req->get_param( 'name' ) ] );
```

Run it:

```bash
php -S 127.0.0.1:8000 public/index.php
```

Open http://127.0.0.1:8000. Done.

---

## Config

`new Ravlin\App( $config )` takes a plain PHP array:

| Key | Default | |
|---|---|---|
| `base_path` | none | Absolute path. Relative `load_routes()` files are found here. |
| `debug` | `false` | `true` shows a detail page for errors instead of a plain 500. |
| `csrf` | `true` | `false` turns it off. See [CSRF](#csrf). |
| `security_headers` | `true` | `false` turns them off. See [Security Headers](#security-headers). |

An invalid config value gets a warning and the safe default.

## Routes

```php
$app->get( '/posts', $handler );
$app->post( '/posts', $handler );
$app->put( '/posts/{id}', $handler );
$app->patch( '/posts/{id}', $handler );
$app->delete( '/posts/{id}', $handler );
$app->route( [ 'GET', 'POST' ], '/search', $handler );
```

Routes can go in a separate file with `$app->load_routes( 'routes.php' )`. Only `$app` is in scope there.

### Path Params

```php
$app->get( '/posts/{id}', $handler );          // any segment
$app->get( '/posts/{id:int}', $handler );      // digits only
$app->get( '/tags/{slug:[a-z-]+}', $handler ); // regex
```

Read them with `$req->get_param( 'id' )`. They are always strings.

A param that fails its constraint doesn't match. Later routes are tried, then 404.

Params may contain `/` or `..`. Never use one as a file path without a constraint.

### Groups

```php
use Ravlin\Routing\Group;

$app->group( '/api', function( Group $api ) {
	$api->get( '/posts', $handler );
	$api->group( '/users/{id:int}', function( Group $user ) {
		$user->get( '/posts', $handler );
	} );
} );
```

### Handlers

A handler takes a `Request` and returns:

- a `string`, sent as HTML
- an `array`, sent as JSON
- a `Response`, sent as is

Handler forms:

```php
$app->get( '/', fn ( Request $req ) => 'Hello' );
$app->get( '/a', [ PostController::class, 'show' ] );
$app->get( '/b', [ $object, 'show' ] );
$app->route( [ 'GET', 'POST' ], '/c', PostPage::class );
```

`Class::class` alone calls `__invoke()`, or else the method named after the HTTP method (`get()`, `post()`). Methods the class doesn't have aren't served.

`::class` handlers get `new Class( $app )` for each request.

### Automatic Responses

- No route for the path: 404
- Path known, method not: 405 with `Allow`
- `OPTIONS`: 204 with `Allow`
- `HEAD`: uses the `GET` route, body removed

## Request

`Ravlin\Http\Request` is read only.

| | |
|---|---|
| `$req->method` | Uppercase |
| `$req->path` | Raw path, no query string |
| `$req->body` | Raw body |
| `$req->https` | `true` on a TLS connection |
| `$req->csp_nonce` | This request's CSP nonce |
| `get_header( $name )` | Case-insensitive, first value |
| `get_query( $name )` | `$_GET` value |
| `get_form( $name )` | `$_POST` value |
| `get_cookie( $name )` | Cookie value |
| `get_param( $name )` | Route param |

Missing values return `null`. The full arrays are on `$req->headers`, `$req->query`, `$req->form`, `$req->cookies` and `$req->params`.

`$req->path` is raw, so `/%61dmin` matches the `/admin` route. Authorize by route or group, not by checking `$req->path`.

## Response

```php
use Ravlin\Http\Response;

Response::html( '<h1>Hi</h1>' );
Response::json( [ 'ok' => true ], 201 );
Response::redirect( '/posts' ); // 303

( new Response( 'Teapot', 418 ) )
	->set_header( 'Content-Type', 'text/plain' )
	->add_header( 'Set-Cookie', 'a=1' )
	->remove_header( 'X-Thing' );
```

Headers with bad names or CR/LF/NUL in the value are rejected with a warning.

Use `Response::redirect()` instead of `header( 'Location: ...' ); exit;`. Calling `exit` ends in a 500.

## CSRF

Unsafe methods (anything but GET, HEAD and OPTIONS) are checked with `Sec-Fetch-Site` and `Origin`, modeled on Go's `net/http` `CrossOriginProtection`. No tokens needed. Cross-origin browser requests get a 403 and the handler never runs.

Allow other origins:

```php
$app = new Ravlin\App( [
	'csrf' => [ 'trusted_origins' => [ 'https://admin.example.com' ] ],
] );
```

Turn it off for one route, like a webhook:

```php
$app->post( '/webhook', $handler )->set_option( 'csrf', false );
```

With `debug` on, the reason for each 403 is logged.

## Security Headers

Every response gets:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-…'; style-src 'self' 'nonce-…'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Strict-Transport-Security: max-age=31536000
```

`Strict-Transport-Security` is only sent over HTTPS (or `X-Forwarded-Proto: https`).

Inline scripts and styles need the nonce:

```php
<script nonce="<?= $req->csp_nonce ?>">...</script>
```

Change or drop headers in config. `{nonce}` is replaced with the request's nonce:

```php
$app = new Ravlin\App( [
	'security_headers' => [
		'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; script-src 'nonce-{nonce}'",
		'Strict-Transport-Security' => false,
	],
] );
```

Replace the CSP for one route:

```php
$app->get( '/embed', $handler )->set_option( 'csp', "default-src 'self'; frame-ancestors https://example.com" );
```

A header the handler sets always wins.

## Errors

Uncaught errors become a plain `500 Internal Server Error` and are logged with `error_log`. With `debug` on, you get a page with the message, file, line and trace. Don't turn on `debug` in production.

PHP warnings are logged too. Handler output that isn't returned (a stray `echo`) is thrown away and logged.

## Running It

Ravlin runs under php-fpm and the PHP built-in web server (`php -S`). Other environments are not supported.

`Ravlin\Runtime\Sapi::run( $app )` handles the request in both. Ravlin doesn't serve static files.

## Development

```bash
git clone https://github.com/josephscott/ravlin.git
cd ravlin
composer install
```

Run the example app in `examples/app/`:

```bash
make serve
```

That serves http://127.0.0.1:8000. To run it behind nginx + php-fpm instead:

```bash
make serve-fpm
```

That serves http://127.0.0.1:8001. The configs in `examples/app/fpm/` are for development only.

Check everything:

```bash
make all
```

Runs autoload, style, lint, analyze (PHPStan) and tests (Pest). `make help` lists every target.

## License

MIT
