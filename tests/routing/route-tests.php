<?php
declare( strict_types = 1 );

use Ravlin\Routing\Route;

test( 'set_option stores known options and get_option reads them', function() {
	$route = ( new Route( [ 'POST' ], '/hook', 'h' ) )->set_option( 'csrf', false )->set_option( 'csp', "default-src 'self'" );
	expect( [ $route->get_option( 'csrf' ), $route->get_option( 'csp' ), $route->get_option( 'nope' ), $route->options ] )
		->toBe( [ false, "default-src 'self'", null, [ 'csrf' => false, 'csp' => "default-src 'self'" ] ] );
} );

test( 'set_option skips unknown names, wrong types and control bytes, with a warning', function( string $name, mixed $value ) {
	$route = new Route( [ 'GET' ], '/x', 'h' );
	[ $result, $warnings ] = capture_warnings( fn () => $route->set_option( $name, $value ) );
	expect( $result )->toBe( $route )->and( $route->options )->toBe( [] )
		->and( $warnings )->toBe( [ 'Invalid route option skipped: ' . $name ] );
} )->with( [
	'unknown name' => [ 'crsf', false ],
	'csrf as string' => [ 'csrf', 'false' ],
	'csrf as int' => [ 'csrf', 0 ],
	'csp as bool' => [ 'csp', true ],
	'csp with CRLF' => [ 'csp', "default-src 'self'\r\nX-Evil: 1" ],
	'csp with NUL' => [ 'csp', "a\0b" ],
	'csp empty' => [ 'csp', '' ],
	'csp non-ASCII' => [ 'csp', "default-src \xC3\xA9" ],
	'csp with tab' => [ 'csp', "default-src\t'self'" ],
	'csp only a space' => [ 'csp', ' ' ],
	'csp with a leading space' => [ 'csp', " default-src 'self'" ],
	'csp with a trailing space' => [ 'csp', "default-src 'self' " ],
	'csp with a trailing new line' => [ 'csp', "default-src 'self'\n" ],
] );

test( 'options set on the returned route are seen on the route the router matches', function() {
	$router = new Ravlin\Routing\Router();
	$router->add( [ 'POST' ], '/hook', 'h' )->set_option( 'csrf', false );
	expect( $router->match( 'POST', '/hook' )->route?->get_option( 'csrf' ) )->toBeFalse();
} );

test( 'a single-character csp option is kept', function() {
	[ $route, $warnings ] = capture_warnings( fn () => ( new Route( [ 'GET' ], '/x', 'h' ) )->set_option( 'csp', 'a' ) );
	expect( [ $route->get_option( 'csp' ), $warnings ] )->toBe( [ 'a', [] ] );
} );
