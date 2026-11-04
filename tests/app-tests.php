<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Http\Request;
use Ravlin\Http\Response;

test( 'config is readable', fn () => expect( ( new App( [ 'debug' => true ] ) )->config )->toBe( [ 'debug' => true ] ) );

test( 'handle returns a 404 when nothing matches', function() {
	$r = ( new App() )->handle( new Request( 'GET', '/nope' ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 404, 'Not Found' ] );
} );

test( 'route shortcuts register the matching HTTP method', function( string $fn, string $method ) {
	$route = ( new App() )->$fn( '/x', 'h' );
	expect( [ $route->methods, $route->path, $route->handler ] )->toBe( [ [ $method ], '/x', 'h' ] );
} )->with( [ [ 'get', 'GET' ], [ 'post', 'POST' ], [ 'put', 'PUT' ], [ 'patch', 'PATCH' ], [ 'delete', 'DELETE' ] ] );

test( 'handle runs the matched handler and converts its result', function( mixed $result, int $status, ?string $type, string $body ) {
	$app = new App();
	$app->get( '/x', fn () => $result );
	$r = $app->handle( new Request( 'GET', '/x' ) );
	expect( [ $r->status, $r->get_header( 'content-type' ), $r->body ] )->toBe( [ $status, $type, $body ] );
} )->with( [
	'string' => [ 'hi', 200, 'text/html; charset=utf-8', 'hi' ],
	'array' => [ [ 'ok' => true ], 200, 'application/json', '{"ok":true}' ],
	'response' => [ new Response( 'made', 201 ), 201, null, 'made' ],
] );

test( 'handle passes the request to the handler', function() {
	$app = new App();
	$app->post( '/echo', fn ( Request $req ) => $req->get_form( 'name' ) );
	$r = $app->handle( new Request( 'POST', '/echo', form: [ 'name' => 'ravlin' ] ) );
	expect( $r->body )->toBe( 'ravlin' );
} );

test( 'handle returns an empty 500 with a warning for unsupported handler results', function( mixed $result ) {
	$app = new App();
	$app->get( '/x', fn () => $result );
	[ $r, $warnings ] = capture_warnings( fn () => $app->handle( new Request( 'GET', '/x' ) ) );
	expect( [ $r->status, $r->body, $r->headers ] )->toBe( [ 500, '', [] ] )->and( $warnings )->toHaveCount( 1 );
} )->with( [ 'null' => null, 'int' => 42, 'object' => new stdClass() ] );

test( 'handle returns an empty 500 with a warning when the handler is not callable', function( mixed $handler ) {
	$app = new App();
	$app->get( '/x', $handler );
	[ $r, $warnings ] = capture_warnings( fn () => $app->handle( new Request( 'GET', '/x' ) ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 500, '' ] )->and( $warnings )->toBe( [ 'Route handler is not callable: /x' ] );
} )->with( [ 'missing function' => 'no_such_function', 'class name' => stdClass::class, 'non-static method' => [ Response::class, 'set_body' ] ] );

test( 'handle returns a copy of a Response the handler returns', function() {
	$shared = new Response( 'cached' );
	$app = new App();
	$app->get( '/x', fn () => $shared );
	$first = $app->handle( new Request( 'GET', '/x' ) );
	$first->add_header( 'X-Per-Request', 'one' );
	$second = $app->handle( new Request( 'GET', '/x' ) );
	expect( $second )->not->toBe( $shared )->not->toBe( $first )->and( $second->headers )->toBe( [] )->and( $second->body )->toBe( 'cached' );
} );

test( 'handle returns an empty 500 when an array result cannot be JSON encoded', function() {
	$app = new App();
	$app->get( '/x', fn () => [ 'bad' => "\xB1\x31" ] );
	[ $r, $warnings ] = capture_warnings( fn () => $app->handle( new Request( 'GET', '/x' ) ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 500, '' ] )->and( $warnings )->toHaveCount( 1 )
		->and( $warnings[0] )->toStartWith( 'JSON encode failed' );
} );

test( 'handle dispatches callables other than closures', function( mixed $handler ) {
	$app = new App();
	$app->get( '/x', $handler );
	expect( $app->handle( new Request( 'GET', '/x' ) )->body )->toBe( 'called' );
} )->with( [
	'object and method' => fn () => [ new class {
		public function show() {
			return 'called';
		}
	}, 'show' ],
	'invokable object' => fn () => new class {
		public function __invoke() {
			return 'called';
		}
	},
] );

test( 'HEAD falls back to the GET route and sends no body', function() {
	$app = new App();
	$app->get( '/x', fn () => [ 'ok' => true ] );
	$r = $app->handle( new Request( 'HEAD', '/x' ) );
	expect( [ $r->status, $r->get_header( 'content-type' ), $r->body ] )->toBe( [ 200, 'application/json', '' ] );
} );

test( 'an explicit HEAD route wins over the GET fallback', function() {
	$app = new App();
	$app->get( '/x', fn () => 'get' );
	$app->route( [ 'HEAD' ], '/x', fn () => new Response( 'head', 204 ) );
	$r = $app->handle( new Request( 'HEAD', '/x' ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 204, '' ] );
} );

test( 'a known path with the wrong method gets a 405 with Allow', function() {
	$app = new App();
	$app->get( '/x', fn () => 'get' );
	$app->post( '/x', fn () => 'post' );
	$r = $app->handle( new Request( 'DELETE', '/x' ) );
	expect( [ $r->status, $r->get_header( 'allow' ), $r->body ] )->toBe( [ 405, 'GET, POST, HEAD, OPTIONS', 'Method Not Allowed' ] );
} );

test( 'OPTIONS on a known path gets a 204 with Allow', function() {
	$app = new App();
	$app->post( '/x', fn () => 'post' );
	$r = $app->handle( new Request( 'OPTIONS', '/x' ) );
	expect( [ $r->status, $r->get_header( 'allow' ), $r->body ] )->toBe( [ 204, 'POST, OPTIONS', '' ] );
} );

test( 'an explicit OPTIONS route wins over the automatic response', function() {
	$app = new App();
	$app->route( [ 'OPTIONS' ], '/x', fn () => new Response( 'custom', 200 ) );
	expect( $app->handle( new Request( 'OPTIONS', '/x' ) )->body )->toBe( 'custom' );
} );

test( 'HEAD responses never carry a body, including 404 and 405', function( string $path, int $status, ?string $allow ) {
	$app = new App();
	$app->post( '/post-only', fn () => 'post' );
	$r = $app->handle( new Request( 'HEAD', $path ) );
	expect( [ $r->status, $r->body, $r->get_header( 'allow' ) ] )->toBe( [ $status, '', $allow ] );
} )->with( [ 'not found' => [ '/nope', 404, null ], 'wrong method' => [ '/post-only', 405, 'POST, OPTIONS' ] ] );

test( 'OPTIONS and wrong methods on an unknown path still get a 404', function( string $method ) {
	$r = ( new App() )->handle( new Request( $method, '/nope' ) );
	expect( $r->status )->toBe( 404 );
} )->with( [ 'OPTIONS', 'DELETE' ] );

test( 'the HEAD fallback handler sees HEAD and its headers are kept', function() {
	$app = new App();
	$app->get( '/x', fn ( Request $req ) => ( new Response( 'body' ) )->set_header( 'X-Method', $req->method )->set_header( 'Content-Length', '4' ) );
	$r = $app->handle( new Request( 'HEAD', '/x' ) );
	expect( [ $r->get_header( 'x-method' ), $r->get_header( 'content-length' ), $r->body ] )->toBe( [ 'HEAD', '4', '' ] );
} );

test( 'HEAD does not empty a Response the handler keeps and reuses', function() {
	$shared = new Response( 'cached-body' );
	$app = new App();
	$app->get( '/x', fn () => $shared );
	$app->handle( new Request( 'HEAD', '/x' ) );
	expect( $app->handle( new Request( 'GET', '/x' ) )->body )->toBe( 'cached-body' )->and( $shared->body )->toBe( 'cached-body' );
} );

test( 'an unknown method on a known path gets a 405', function() {
	$app = new App();
	$app->get( '/x', fn () => 'get' );
	$r = $app->handle( new Request( 'FOO', '/x' ) );
	expect( [ $r->status, $r->get_header( 'allow' ) ] )->toBe( [ 405, 'GET, HEAD, OPTIONS' ] );
} );
