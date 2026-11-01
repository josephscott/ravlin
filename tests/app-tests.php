<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Http\Request;

test( 'config is readable', fn () => expect( ( new App( [ 'debug' => true ] ) )->config )->toBe( [ 'debug' => true ] ) );

test( 'handle returns a 404 when nothing matches', function() {
	$r = ( new App() )->handle( new Request( 'GET', '/nope' ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 404, 'Not Found' ] );
} );

test( 'route shortcuts register the matching HTTP method', function( string $fn, string $method ) {
	$route = ( new App() )->$fn( '/x', 'h' );
	expect( [ $route->methods, $route->path, $route->handler ] )->toBe( [ [ $method ], '/x', 'h' ] );
} )->with( [ [ 'get', 'GET' ], [ 'post', 'POST' ], [ 'put', 'PUT' ], [ 'patch', 'PATCH' ], [ 'delete', 'DELETE' ] ] );
