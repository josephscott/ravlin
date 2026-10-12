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
