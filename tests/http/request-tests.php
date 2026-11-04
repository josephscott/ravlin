<?php
declare( strict_types = 1 );

use Ravlin\Http\Request;

test( 'defaults to an empty GET /', function() {
	$r = new Request();
	expect( [ $r->method, $r->path, $r->query, $r->form, $r->cookies, $r->headers, $r->body ] )->toBe( [ 'GET', '/', [], [], [], [], '' ] );
} );

test( 'uppercases the method and normalizes headers to lowercase lists', function() {
	$r = new Request( 'post', headers: [ 'Accept' => 'text/html', 'X-A' => [ '1', '2' ] ] );
	expect( $r->method )->toBe( 'POST' )->and( $r->headers )->toBe( [ 'accept' => [ 'text/html' ], 'x-a' => [ '1', '2' ] ] );
} );

test( 'get_header is case-insensitive and returns the first value or null', function() {
	$r = new Request( headers: [ 'X-A' => [ '1', '2' ] ] );
	expect( [ $r->get_header( 'x-a' ), $r->get_header( 'X-Missing' ) ] )->toBe( [ '1', null ] );
} );

test( 'input getters return a string, an array, or null when missing', function( string $source, string $get ) {
	$r = new Request( ...[ $source => [ 'a' => '1', 'b' => [ '2' ] ] ] );
	expect( [ $r->$get( 'a' ), $r->$get( 'b' ), $r->$get( 'c' ) ] )->toBe( [ '1', [ '2' ], null ] );
} )->with( [ [ 'query', 'get_query' ], [ 'form', 'get_form' ], [ 'cookies', 'get_cookie' ] ] );

test( 'with_params returns a copy and get_param reads it', function() {
	$req = new Request( 'GET', '/x' );
	$copy = $req->with_params( [ 'id' => '7' ] );
	expect( [ $copy->get_param( 'id' ), $copy->get_param( 'nope' ), $req->params, $copy->path ] )->toBe( [ '7', null, [], '/x' ] );
} );

test( 'with_params skips non-string names and values, with a warning', function() {
	[ $req, $warnings ] = capture_warnings( fn () => ( new Request() )->with_params( [ 'id' => 5, 0 => 'x', 'ok' => 'y', 'arr' => [ 'z' ] ] ) );
	expect( $req->params )->toBe( [ 'ok' => 'y' ] )->and( $warnings )->toHaveCount( 3 );
} );

test( 'non-string header values become empty strings with a warning, keeping the value count', function( mixed $value, array $expected ) {
	[ $r, $warnings ] = capture_warnings( fn () => new Request( headers: [ 'X-A' => $value, 'X-Ok' => 'yes' ] ) );
	expect( $r->headers )->toBe( [ 'x-a' => $expected, 'x-ok' => [ 'yes' ] ] )
		->and( $warnings )->toBe( [ 'Request header values must be strings, replaced with an empty string: X-A' ] );
} )->with( [
	'int' => [ 1, [ '' ] ],
	'null' => [ null, [ '' ] ],
	'empty list' => [ [], [ '' ] ],
	'object' => [ (object) [ 'https' ], [ '' ] ],
	'nested list' => [ [ [ 'https' ] ], [ '' ] ],
	'list with an int' => [ [ 'a', 2 ], [ 'a', '' ] ],
] );

test( 'numeric header names and keyed value lists are kept without a warning', function() {
	[ $r, $warnings ] = capture_warnings( fn () => new Request( headers: [ 123 => 'x', 'X-A' => [ 'k' => 'v', 'w' ] ] ) );
	expect( [ $r->headers, $r->get_header( '123' ), $warnings ] )->toBe( [ [ 123 => [ 'x' ], 'x-a' => [ 'v', 'w' ] ], 'x', [] ] );
} );

test( 'a non-string header value never makes handle() throw, and fails closed for CSRF', function( array $headers ) {
	$app = new Ravlin\App();
	$app->post( '/x', fn () => 'ran' );
	[ $r ] = capture_warnings( fn () => $app->handle( new Request( 'POST', '/x', headers: [ 'Host' => 'a.example', 'X-Forwarded-Proto' => 1 ] + $headers ) ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 403, 'Forbidden' ] );
} )->with( [
	'Origin with an int' => [ [ 'Origin' => [ 'https://evil.example', 7 ] ] ],
	'Origin int' => [ [ 'Origin' => 7 ] ],
	'Sec-Fetch-Site nested' => [ [ 'Sec-Fetch-Site' => [ [ 'same-origin' ] ] ] ],
	'Sec-Fetch-Site null' => [ [ 'Sec-Fetch-Site' => null ] ],
] );
