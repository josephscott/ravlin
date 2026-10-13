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

test( 'set_header skips invalid headers with a warning', function( string $name, string $value ) {
	$warnings = [];
	set_error_handler( function( int $no, string $msg ) use ( &$warnings ) { $warnings[] = $msg; return true; } );
	$r = ( new Response() )->set_header( $name, $value );
	restore_error_handler();
	expect( $r->headers )->toBe( [] )->and( $warnings )->toHaveCount( 1 );
} )->with( [ [ "X-A\n", 'v' ], [ 'Bad Name', 'v' ], [ '', 'v' ], [ 'X-A', "a\r\nb" ], [ 'X-A', "a\nb" ], [ 'X-A', "a\0b" ] ] );

test( 'add_header appends values under the lowercased name', function() {
	$r = ( new Response() )->set_header( 'Set-Cookie', 'a=1' )->add_header( 'set-cookie', 'b=2' );
	expect( $r->headers )->toBe( [ 'set-cookie' => [ 'a=1', 'b=2' ] ] );
} );

test( 'get_header returns the first value or null, case-insensitive', function() {
	$r = ( new Response() )->add_header( 'X-A', '1' )->add_header( 'X-A', '2' );
	expect( $r->get_header( 'x-a' ) )->toBe( '1' )->and( $r->get_header( 'X-Missing' ) )->toBeNull();
} );

test( 'remove_header deletes all values, case-insensitive', function() {
	expect( ( new Response() )->add_header( 'X-A', '1' )->add_header( 'X-A', '2' )->remove_header( 'x-a' )->headers )->toBe( [] );
} );
