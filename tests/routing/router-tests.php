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
