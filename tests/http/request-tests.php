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
