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

test( 'set_header and add_header skip invalid headers with a warning', function( string $method, string $name, string $value ) {
	$warnings = [];
	set_error_handler( function( int $no, string $msg ) use ( &$warnings ) { $warnings[] = $msg; return true; } );
	$r = ( new Response() )->$method( $name, $value );
	restore_error_handler();
	expect( $r->headers )->toBe( [] )->and( $warnings )->toHaveCount( 1 );
} )->with( [ 'set_header', 'add_header' ] )->with( [ [ "X-A\n", 'v' ], [ 'Bad Name', 'v' ], [ '', 'v' ], [ 'X-A', "a\r\nb" ], [ 'X-A', "a\nb" ], [ 'X-A', "a\0b" ] ] );

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

test( 'set_status and set_body update the response and chain', function() {
	$r = new Response();
	expect( $r->set_status( 404 )->set_body( 'nope' ) )->toBe( $r )
		->and( $r->status )->toBe( 404 )->and( $r->body )->toBe( 'nope' );
} );

test( 'invalid status codes are skipped with a warning', function( int $status ) {
	$warnings = [];
	set_error_handler( function( int $no, string $msg ) use ( &$warnings ) { $warnings[] = $msg; return true; } );
	$a = new Response( '', $status );
	$b = ( new Response() )->set_status( $status );
	restore_error_handler();
	expect( [ $a->status, $b->status ] )->toBe( [ 200, 200 ] )->and( $warnings )->toHaveCount( 2 );
} )->with( [ -1, 0, 99, 600 ] );

test( 'html sets the body, status and an HTML content type', function() {
	$r = Response::html( '<p>hi</p>', 201 );
	expect( [ $r->body, $r->status, $r->get_header( 'content-type' ) ] )->toBe( [ '<p>hi</p>', 201, 'text/html; charset=utf-8' ] );
} );

test( 'redirect defaults to 303 with a Location header', function() {
	$r = Response::redirect( '/login' );
	expect( [ $r->status, $r->get_header( 'location' ), $r->body ] )->toBe( [ 303, '/login', '' ] );
} );

test( 'json encodes data with an application/json content type', function() {
	$r = Response::json( [ 'url' => 'https://x.test/a', 'n' => 1 ], 201 );
	expect( [ $r->status, $r->body, $r->get_header( 'content-type' ) ] )->toBe( [ 201, '{"url":"https://x.test/a","n":1}', 'application/json' ] );
} );

test( 'json returns an empty 500 with a warning when encoding fails', function( mixed $data ) {
	$warnings = [];
	set_error_handler( function( int $no, string $msg ) use ( &$warnings ) { $warnings[] = $msg; return true; } );
	$r = Response::json( $data );
	restore_error_handler();
	expect( [ $r->status, $r->body, $r->headers ] )->toBe( [ 500, '', [] ] )->and( $warnings )->toHaveCount( 1 );
} )->with( [ 'invalid utf-8' => "\xB1\x31", 'NAN' => NAN ] );
