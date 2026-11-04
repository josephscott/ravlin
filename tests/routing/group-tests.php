<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Http\Request;
use Ravlin\Routing\Group;

test( 'group registers every shortcut and route() under the prefix', function() {
	$app = new App();
	$routes = [];
	$app->group( '/admin', function( Group $g ) use ( &$routes ) {
		foreach ( [ 'get', 'post', 'put', 'patch', 'delete' ] as $fn ) {
			$routes[] = $g->$fn( '/' . $fn, 'h' );
		}
		$routes[] = $g->route( [ 'GET', 'POST' ], '/both', 'h' );
	} );
	expect( array_map( fn ( $r ) => [ $r->methods, $r->path ], $routes ) )->toBe( [
		[ [ 'GET' ], '/admin/get' ], [ [ 'POST' ], '/admin/post' ], [ [ 'PUT' ], '/admin/put' ],
		[ [ 'PATCH' ], '/admin/patch' ], [ [ 'DELETE' ], '/admin/delete' ], [ [ 'GET', 'POST' ], '/admin/both' ],
	] );
} );

test( 'group routes are dispatched, and an empty path is the prefix itself', function( string $path, string $body ) {
	$app = new App();
	$app->group( '/admin', function( Group $g ) {
		$g->get( '', fn () => 'index' );
		$g->get( '/', fn () => 'slash' );
		$g->get( '/users', fn () => 'users' );
	} );
	expect( $app->handle( new Request( 'GET', $path ) )->body )->toBe( $body );
} )->with( [ [ '/admin', 'index' ], [ '/admin/', 'slash' ], [ '/admin/users', 'users' ] ] );

test( 'groups nest and prefixes can hold params', function() {
	$app = new App();
	$app->group( '/users/{user:int}', function( Group $g ) {
		$g->group( '/posts', function( Group $posts ) {
			$posts->get( '/{post}', fn ( Request $req ) => $req->get_param( 'user' ) . '/' . $req->get_param( 'post' ) );
		} );
	} );
	expect( $app->handle( new Request( 'GET', '/users/7/posts/hi' ) )->body )->toBe( '7/hi' )
		->and( $app->handle( new Request( 'GET', '/users/x/posts/hi' ) )->status )->toBe( 404 );
} );

test( 'an invalid prefix skips the group with a warning and never calls it', function( string $prefix ) {
	$called = false;
	[ , $warnings ] = capture_warnings( fn () => ( new App() )->group( $prefix, function() use ( &$called ) {
		$called = true;
	} ) );
	expect( $called )->toBeFalse()->and( $warnings )->toBe( [ 'Invalid group prefix skipped: ' . $prefix ] );
} )->with( [
	'empty' => '', 'root' => '/', 'no slash' => 'admin', 'trailing slash' => '/admin/', 'empty segment' => '/a//b',
	'newline' => "/a\n", 'tab' => "/a\tb", 'DEL' => "/a\x7F", 'C1 NEL' => "/a\u{85}", 'invalid UTF-8' => "/a\xFF", 'percent' => '/a%20',
] );

test( 'an invalid nested prefix is skipped without appending to the outer prefix', function() {
	$app = new App();
	[ , $warnings ] = capture_warnings( fn () => $app->group( '/admin', function( Group $g ) {
		$g->group( 'users', fn ( Group $inner ) => $inner->get( '/x', fn () => 'bad' ) );
	} ) );
	expect( $warnings )->toBe( [ 'Invalid group prefix skipped: users' ] )
		->and( $app->handle( new Request( 'GET', '/adminusers/x' ) )->status )->toBe( 404 );
} );

test( 'group returns its owner for chaining', function() {
	$app = new App();
	$inner = null;
	$result = $app->group( '/a', function( Group $g ) use ( &$inner ) {
		$inner = $g->group( '/b', fn () => null ) === $g;
	} );
	expect( $result )->toBe( $app )->and( $inner )->toBeTrue();
} );

test( 'a group path without a leading slash is not joined to the prefix and is rejected', function( string $path ) {
	$app = new App();
	[ , $warnings ] = capture_warnings( fn () => $app->group( '/admin', fn ( Group $g ) => $g->get( $path, 'h' ) ) );
	expect( $warnings )->toBe( [ 'Invalid route path skipped: ' . $path ] )
		->and( $app->handle( new Request( 'GET', '/admin' . $path ) )->status )->toBe( 404 );
} )->with( [ 'suffix' => 'istrator', 'param' => '{x}', 'closing brace' => '}/x' ] );

test( 'a param cannot be split across a prefix and a path', function() {
	$app = new App();
	[ , $warnings ] = capture_warnings( fn () => $app->group( '/{id', fn ( Group $g ) => $g->get( '}/x', 'h' ) ) );
	expect( $warnings )->toBe( [ 'Invalid route path skipped: }/x' ] )
		->and( $app->handle( new Request( 'GET', '/5/x' ) )->status )->toBe( 404 );
} );
