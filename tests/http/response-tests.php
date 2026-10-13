<?php
declare( strict_types = 1 );

use Ravlin\Http\Response;

test( 'defaults to empty 200', function() {
	$r = new Response();
	expect( $r->body )->toBe( '' )->and( $r->status )->toBe( 200 )->and( $r->headers )->toBe( [] );
} );

test( 'properties are read-only from outside', function() {
	$r = new Response();
	$r->status = 500;
} )->throws( Error::class, 'Cannot modify private(set) property' );

test( 'set_header stores a lowercased name and returns the same response', function() {
	$r = new Response();
	expect( $r->set_header( 'Content-Type', 'text/html' ) )->toBe( $r )
		->and( $r->headers )->toBe( [ 'content-type' => [ 'text/html' ] ] );
} );

test( 'set_header replaces an existing value regardless of case', function() {
	expect( ( new Response() )->set_header( 'X-A', '1' )->set_header( 'x-a', '2' )->headers )->toBe( [ 'x-a' => [ '2' ] ] );
} );
