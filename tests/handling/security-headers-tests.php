<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Http\Request;
use Ravlin\Http\Response;

const SECURITY_HEADERS_TESTS_CSP = "default-src 'self'; script-src 'self' 'nonce-%s'; style-src 'self' 'nonce-%s'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

function security_headers_tests_defaults( string $nonce ) : array {
	$headers = [
		'content-security-policy' => sprintf( SECURITY_HEADERS_TESTS_CSP, $nonce, $nonce ),
		'x-content-type-options' => 'nosniff',
		'referrer-policy' => 'strict-origin-when-cross-origin',
		'cross-origin-opener-policy' => 'same-origin',
		'cross-origin-resource-policy' => 'same-origin',
	];
	return $headers;
}

function security_headers_tests_only( Response $res ) : array {
	$names = [ 'content-security-policy', 'x-content-type-options', 'referrer-policy', 'cross-origin-opener-policy', 'cross-origin-resource-policy', 'strict-transport-security' ];
	$headers = [];
	foreach ( $names as $name ) {
		if ( $res->get_header( $name ) !== null ) {
			$headers[$name] = $res->get_header( $name );
		}
	}
	return $headers;
}

function security_headers_tests_nonce( Response $res ) : string {
	preg_match( "/'nonce-([^']*)'/", (string) $res->get_header( 'content-security-policy' ), $m );
	$nonce = $m[1] ?? '';
	return $nonce;
}

test( 'every response gets the default headers, with a fresh nonce per request', function( string $method, string $path, int $status, array $config ) {
	$app = new App( $config );
	$app->get( '/ok', fn () => 'ok' );
	$app->get( '/json', fn () => [ 'a' => 1 ] );
	$app->post( '/post', fn () => 'posted' );
	$app->get( '/throw', fn () => throw new RuntimeException( 'x' ) );
	$app->get( '/bad', fn () => 42 );
	$app->get( '/leak', function() {
		ob_end_clean();
		return 'ok';
	} );
	[ [ $first, $second ] ] = capture_warnings( fn () => capture_log( fn () => [
		$app->handle( new Request( $method, $path, headers: [ 'Sec-Fetch-Site' => 'cross-site' ] ) ),
		$app->handle( new Request( $method, $path, headers: [ 'Sec-Fetch-Site' => 'cross-site' ] ) ),
	] )[0] );
	$nonce = security_headers_tests_nonce( $first );
	expect( $first->status )->toBe( $status )
		->and( security_headers_tests_only( $first ) )->toBe( security_headers_tests_defaults( $nonce ) )
		->and( $nonce )->toMatch( '#^[A-Za-z0-9+/]{22}==$#' )
		->and( security_headers_tests_nonce( $second ) )->not->toBe( $nonce );
} )->with( [
	'200 html' => [ 'GET', '/ok', 200, [] ],
	'200 json' => [ 'GET', '/json', 200, [] ],
	'HEAD' => [ 'HEAD', '/ok', 200, [] ],
	'404' => [ 'GET', '/nope', 404, [] ],
	'405' => [ 'DELETE', '/ok', 405, [] ],
	'automatic OPTIONS' => [ 'OPTIONS', '/ok', 204, [] ],
	'CSRF 403' => [ 'POST', '/post', 403, [] ],
	'uncaught throwable' => [ 'GET', '/throw', 500, [] ],
	'uncaught throwable, debug page' => [ 'GET', '/throw', 500, [ 'debug' => true ] ],
	'unsupported result' => [ 'GET', '/bad', 500, [] ],
	'handler closed the output buffer' => [ 'GET', '/leak', 500, [] ],
] );

test( 'the handler sees the same nonce the CSP header carries', function() {
	$app = new App();
	$app->get( '/x', fn ( Request $req ) => $req->csp_nonce );
	$r = $app->handle( new Request( 'GET', '/x' ) );
	expect( $r->body )->toBe( security_headers_tests_nonce( $r ) )->not->toBe( '' );
} );

test( 'not-found and method-not-allowed bodies are plain text', function( string $method, int $status ) {
	$app = new App();
	$app->get( '/x', fn () => 'ok' );
	$r = $app->handle( new Request( $method, $method === 'GET' ? '/nope' : '/x' ) );
	expect( [ $r->status, $r->get_header( 'content-type' ) ] )->toBe( [ $status, 'text/plain; charset=utf-8' ] );
} )->with( [ '404' => [ 'GET', 404 ], '405' => [ 'PUT', 405 ] ] );

test( 'HSTS is only sent over HTTPS, or when a proxy says the request was HTTPS', function( bool $https, array $headers, bool $sent ) {
	$app = new App();
	$app->get( '/x', fn () => 'ok' );
	$r = $app->handle( new Request( 'GET', '/x', headers: $headers, https: $https ) );
	expect( $r->get_header( 'strict-transport-security' ) )->toBe( $sent ? 'max-age=31536000' : null );
} )->with( [
	'plain http' => [ false, [], false ],
	'https' => [ true, [], true ],
	'forwarded https' => [ false, [ 'X-Forwarded-Proto' => 'HTTPS' ], true ],
	'forwarded https first in a list' => [ false, [ 'X-Forwarded-Proto' => ' https , http' ], true ],
	'forwarded http' => [ false, [ 'X-Forwarded-Proto' => 'http' ], false ],
	'forwarded https last in a list' => [ false, [ 'X-Forwarded-Proto' => 'http, https' ], false ],
] );

test( 'headers the handler sets win over the route option, which wins over config and the defaults', function( array $config, ?string $route_csp, ?string $handler_csp, ?string $expected ) {
	$app = new App( $config );
	$route = $app->get( '/x', function( Request $req ) use ( $handler_csp ) {
		$res = Response::html( $req->csp_nonce )->set_header( 'Referrer-Policy', 'no-referrer' );
		if ( $handler_csp !== null ) {
			$res->set_header( 'Content-Security-Policy', $handler_csp );
		}
		return $res;
	} );
	if ( $route_csp !== null ) {
		$route->set_option( 'csp', $route_csp );
	}
	$r = $app->handle( new Request( 'GET', '/x' ) );
	expect( $r->body )->toMatch( '#^[A-Za-z0-9+/]{22}==$#' )
		->and( $r->get_header( 'content-security-policy' ) )->toBe( $expected === null ? null : str_replace( '{n}', $r->body, $expected ) )
		->and( $r->get_header( 'referrer-policy' ) )->toBe( 'no-referrer' )
		->and( count( $r->headers['content-security-policy'] ?? [] ) )->toBe( $expected === null ? 0 : 1 );
} )->with( [
	'defaults' => [ [], null, null, sprintf( SECURITY_HEADERS_TESTS_CSP, '{n}', '{n}' ) ],
	'config' => [ [ 'security_headers' => [ 'Content-Security-Policy' => "default-src 'self' 'nonce-{nonce}'" ] ], null, null, "default-src 'self' 'nonce-{n}'" ],
	'route over config' => [ [ 'security_headers' => [ 'content-security-policy' => "default-src 'none'" ] ], "script-src 'nonce-{nonce}'", null, "script-src 'nonce-{n}'" ],
	'route with headers off' => [ [ 'security_headers' => false ], "default-src 'self'", null, "default-src 'self'" ],
	'handler over route' => [ [], "default-src 'none'", 'img-src *', 'img-src *' ],
	'config drops it' => [ [ 'security_headers' => [ 'content-security-policy' => false ] ], null, null, null ],
] );

test( 'config can replace or drop single headers, or turn them all off', function( mixed $config, array $expected ) {
	$app = new App( [ 'security_headers' => $config ] );
	$app->get( '/x', fn () => 'ok' );
	$r = $app->handle( new Request( 'GET', '/x', https: true ) );
	$all = security_headers_tests_defaults( security_headers_tests_nonce( $r ) ) + [ 'strict-transport-security' => 'max-age=31536000' ];
	expect( security_headers_tests_only( $r ) )->toBe( array_filter( array_merge( $all, $expected ), is_string( ... ) ) );
} )->with( [
	'on' => [ true, [] ],
	'null means on' => [ null, [] ],
	'off' => [ false, array_fill_keys( [ 'content-security-policy', 'x-content-type-options', 'referrer-policy', 'cross-origin-opener-policy', 'cross-origin-resource-policy', 'strict-transport-security' ], false ) ],
	'replace, any case' => [ [ 'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains' ], [ 'strict-transport-security' => 'max-age=63072000; includeSubDomains' ] ],
	'drop' => [ [ 'cross-origin-resource-policy' => false ], [ 'cross-origin-resource-policy' => false ] ],
	'single character' => [ [ 'cross-origin-resource-policy' => 'x' ], [ 'cross-origin-resource-policy' => 'x' ] ],
] );

test( 'invalid security_headers entries are skipped with a warning, the rest still apply', function( mixed $name, mixed $value ) {
	[ $app, $warnings ] = capture_warnings( fn () => new App( [ 'security_headers' => [ $name => $value, 'referrer-policy' => 'no-referrer' ] ] ) );
	$app->get( '/x', fn () => 'ok' );
	$r = $app->handle( new Request( 'GET', '/x' ) );
	expect( $warnings )->toBe( [ 'Invalid security_headers entry skipped: ' . $name ] )
		->and( [ $r->get_header( 'referrer-policy' ), $r->get_header( 'x-content-type-options' ) ] )->toBe( [ 'no-referrer', 'nosniff' ] );
} )->with( [
	'unknown header' => [ 'X-Frame-Options', 'DENY' ],
	'misspelled header' => [ 'Content-Security-Polcy', "default-src 'none'" ],
	'integer key' => [ 0, 'nosniff' ],
	'true' => [ 'x-content-type-options', true ],
	'null' => [ 'x-content-type-options', null ],
	'empty' => [ 'x-content-type-options', '' ],
	'new line' => [ 'x-content-type-options', "nosniff\r\nSet-Cookie: a=b" ],
	'non-ascii' => [ 'cross-origin-opener-policy', 'nö' ],
	'trailing new line' => [ 'x-content-type-options', "nosniff\n" ],
	'only a space' => [ 'cross-origin-opener-policy', ' ' ],
	'leading space' => [ 'cross-origin-opener-policy', ' same-origin' ],
	'trailing space' => [ 'cross-origin-opener-policy', 'same-origin ' ],
] );

test( 'an invalid security_headers config warns and keeps the defaults', function( mixed $config ) {
	[ $app, $warnings ] = capture_warnings( fn () => new App( [ 'security_headers' => $config ] ) );
	$app->get( '/x', fn () => 'ok' );
	expect( $warnings )->toBe( [ 'Invalid security_headers config ignored, the default headers stay on' ] )
		->and( $app->handle( new Request( 'GET', '/x' ) )->get_header( 'x-content-type-options' ) )->toBe( 'nosniff' );
} )->with( [ 'string' => 'off', 'int' => 0 ] );

test( 'the headers are added once and never repeated when a handler reuses a Response', function() {
	$shared = Response::html( 'ok' );
	$app = new App();
	$app->get( '/x', fn () => $shared );
	$app->handle( new Request( 'GET', '/x' ) );
	$r = $app->handle( new Request( 'GET', '/x' ) );
	expect( $shared->headers )->toBe( [ 'content-type' => [ 'text/html; charset=utf-8' ] ] )
		->and( array_map( count( ... ), $r->headers ) )->each->toBe( 1 );
} );
