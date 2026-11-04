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
