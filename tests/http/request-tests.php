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
