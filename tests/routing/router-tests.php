<?php
declare( strict_types = 1 );

use Ravlin\Routing\Router;

test( 'add uppercases methods and returns the route', function() {
	$route = ( new Router() )->add( [ 'get', 'Post' ], '/posts', 'h' );
	expect( [ $route->methods, $route->path, $route->handler ] )->toBe( [ [ 'GET', 'POST' ], '/posts', 'h' ] );
} );

test( 'match finds the first route with the same method and exact path', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	$router->add( [ 'POST' ], '/posts', 'store' );
	$router->add( [ 'GET' ], '/posts', 'dupe' );
	expect( $router->match( 'GET', '/posts' )?->handler )->toBe( 'list' )
		->and( $router->match( 'POST', '/posts' )?->handler )->toBe( 'store' );
} );

test( 'match returns null for other methods, a trailing slash, or different case', function( string $method, string $path ) {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	expect( $router->match( $method, $path ) )->toBeNull();
} )->with( [ [ 'DELETE', '/posts' ], [ 'GET', '/posts/' ], [ 'GET', '/Posts' ] ] );

test( 'allowed_methods lists the methods for a path, adding HEAD for GET and OPTIONS', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	$router->add( [ 'POST', 'GET' ], '/posts', 'store' );
	$router->add( [ 'DELETE' ], '/other', 'x' );
	expect( $router->allowed_methods( '/posts' ) )->toBe( [ 'GET', 'POST', 'HEAD', 'OPTIONS' ] );
} );

test( 'allowed_methods only adds HEAD when GET is present', function() {
	$router = new Router();
	$router->add( [ 'POST' ], '/posts', 'store' );
	expect( $router->allowed_methods( '/posts' ) )->toBe( [ 'POST', 'OPTIONS' ] );
} );

test( 'allowed_methods is empty for an unknown path', function() {
	$router = new Router();
	$router->add( [ 'GET' ], '/posts', 'list' );
	expect( $router->allowed_methods( '/nope' ) )->toBe( [] );
} );

test( 'allowed_methods does not repeat explicitly registered HEAD or OPTIONS', function() {
	$router = new Router();
	$router->add( [ 'HEAD', 'GET' ], '/x', 'a' );
	$router->add( [ 'OPTIONS' ], '/x', 'b' );
	expect( $router->allowed_methods( '/x' ) )->toBe( [ 'HEAD', 'GET', 'OPTIONS' ] );
} );

test( 'add skips methods that are not valid HTTP tokens, with a warning', function() {
	[ $route, $warnings ] = capture_warnings( fn () => ( new Router() )->add( [ 'get', "PUT\nX-Evil: 1", 'GET POST', '', "GET\n", 42, null ], '/x', 'h' ) );
	expect( $route->methods )->toBe( [ 'GET' ] )->and( $warnings )->toBe( array_fill( 0, 6, 'Invalid HTTP method skipped for route: /x' ) );
} );
