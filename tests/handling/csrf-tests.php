<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Http\Request;

function csrf_tests_app( array $config = [], ?bool $route_option = null ) : App {
	$app = new App( $config );
	$route = $app->route( [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'PURGE', 'OPTIONS' ], '/x', fn () => 'ran' );
	if ( $route_option !== null ) {
		$route->set_option( 'csrf', $route_option );
	}
	return $app;
}

function csrf_tests_status( App $app, string $method, array $headers ) : int {
	$status = $app->handle( new Request( $method, '/x', headers: $headers ) )->status;
	return $status;
}

test( 'safe methods are never checked', function( string $method ) {
	expect( csrf_tests_status( csrf_tests_app(), $method, [ 'Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.test' ] ) )->toBe( 200 );
} )->with( [ 'GET', 'HEAD', 'OPTIONS' ] );

test( 'unsafe methods follow Sec-Fetch-Site when it is present, whatever Origin says', function( mixed $site, string $origin, int $status ) {
	expect( csrf_tests_status( csrf_tests_app(), 'POST', [ 'Sec-Fetch-Site' => $site, 'Host' => 'app.test', 'Origin' => $origin ] ) )->toBe( $status );
} )->with( [
	'same-origin' => [ 'same-origin', 'https://app.test', 200 ],
	'same-origin, foreign Origin' => [ 'same-origin', 'https://evil.test', 200 ],
	'none (user initiated)' => [ 'none', 'https://evil.test', 200 ],
	'upper case' => [ 'Same-Origin', 'https://app.test', 200 ],
	'same-site' => [ 'same-site', 'https://app.test', 403 ],
	'cross-site, even with an Origin matching Host' => [ 'cross-site', 'https://app.test', 403 ],
	'unknown value' => [ 'bogus', 'https://app.test', 403 ],
	'empty' => [ '', 'https://app.test', 403 ],
	'two headers' => [ [ 'same-origin', 'same-origin' ], 'https://app.test', 403 ],
] );

test( 'every unsafe method is checked', function( string $method ) {
	expect( csrf_tests_status( csrf_tests_app(), $method, [ 'Sec-Fetch-Site' => 'cross-site' ] ) )->toBe( 403 );
} )->with( [ 'POST', 'PUT', 'PATCH', 'DELETE', 'PURGE' ] );

test( 'without Sec-Fetch-Site, Origin must match Host', function( array $headers, int $status ) {
	expect( csrf_tests_status( csrf_tests_app(), 'POST', $headers ) )->toBe( $status );
} )->with( [
	'no Origin either (not a browser)' => [ [ 'Host' => 'app.test' ], 200 ],
	'same host' => [ [ 'Origin' => 'https://app.test', 'Host' => 'app.test' ], 200 ],
	'same host and port, any case' => [ [ 'Origin' => 'https://App.Test:8443', 'Host' => 'app.test:8443' ], 200 ],
	'ipv6' => [ [ 'Origin' => 'http://[::1]:8000', 'Host' => '[::1]:8000' ], 200 ],
	'default https port in Host' => [ [ 'Origin' => 'https://app.test', 'Host' => 'app.test:443' ], 200 ],
	'default http port in Host' => [ [ 'Origin' => 'http://app.test', 'Host' => 'app.test:80' ], 200 ],
	'https port in Host, http Origin' => [ [ 'Origin' => 'http://app.test', 'Host' => 'app.test:443' ], 403 ],
	'other scheme' => [ [ 'Origin' => 'chrome-extension://app.test', 'Host' => 'app.test' ], 403 ],
	'scheme with a prefix' => [ [ 'Origin' => 'xhttps://app.test', 'Host' => 'app.test' ], 403 ],
	'other host' => [ [ 'Origin' => 'https://evil.test', 'Host' => 'app.test' ], 403 ],
	'other port' => [ [ 'Origin' => 'https://app.test:8443', 'Host' => 'app.test' ], 403 ],
	'subdomain' => [ [ 'Origin' => 'https://evil.app.test', 'Host' => 'app.test' ], 403 ],
	'suffix trick' => [ [ 'Origin' => 'https://app.test.evil.test', 'Host' => 'app.test' ], 403 ],
	'null origin' => [ [ 'Origin' => 'null', 'Host' => 'app.test' ], 403 ],
	'origin with a path' => [ [ 'Origin' => 'https://app.test/x', 'Host' => 'app.test' ], 403 ],
	'origin with userinfo' => [ [ 'Origin' => 'https://evil@app.test', 'Host' => 'app.test' ], 403 ],
	'origin with a query' => [ [ 'Origin' => 'https://app.test?x', 'Host' => 'app.test' ], 403 ],
	'origin with a fragment' => [ [ 'Origin' => 'https://app.test#x', 'Host' => 'app.test' ], 403 ],
	'origin with a space' => [ [ 'Origin' => 'https://app.test x', 'Host' => 'app.test x' ], 403 ],
	'Host with a path' => [ [ 'Origin' => 'https://app.test', 'Host' => 'app.test/x' ], 403 ],
	'no Host' => [ [ 'Origin' => 'https://app.test' ], 403 ],
	'empty Host' => [ [ 'Origin' => 'https://app.test', 'Host' => '' ], 403 ],
	'two Origin headers' => [ [ 'Origin' => [ 'https://app.test', 'https://evil.test' ], 'Host' => 'app.test' ], 403 ],
] );

test( 'trusted origins are allowed cross-site, compared exactly after default-port normalization', function( string $origin, int $status ) {
	$app = csrf_tests_app( [ 'csrf' => [ 'trusted_origins' => [ 'https://Admin.app.test:443', 'http://localhost:5173', 'http://[::1]:8000', 'https://my_host.test:65535', 'http://b.test:1' ] ] ] );
	expect( csrf_tests_status( $app, 'POST', [ 'Sec-Fetch-Site' => 'cross-site', 'Origin' => $origin ] ) )->toBe( $status )
		->and( csrf_tests_status( $app, 'POST', [ 'Origin' => $origin, 'Host' => 'app.test' ] ) )->toBe( $status );
} )->with( [
	'trusted' => [ 'https://admin.app.test', 200 ],
	'trusted with port' => [ 'http://localhost:5173', 200 ],
	'trusted ipv6' => [ 'http://[::1]:8000', 200 ],
	'trusted with an underscore and the highest port' => [ 'https://my_host.test:65535', 200 ],
	'trusted with the lowest port' => [ 'http://b.test:1', 200 ],
	'other ipv6 port' => [ 'http://[::1]:8001', 403 ],
	'other scheme' => [ 'http://admin.app.test', 403 ],
	'other port' => [ 'http://localhost:5174', 403 ],
	'not listed' => [ 'https://evil.test', 403 ],
] );

test( 'invalid trusted origins are skipped with a warning', function( mixed $origin ) {
	[ $app, $warnings ] = capture_warnings( fn () => csrf_tests_app( [ 'csrf' => [ 'trusted_origins' => [ $origin ] ] ] ) );
	expect( $warnings )->toHaveCount( 1 )->and( $warnings[0] )->toStartWith( 'Invalid trusted origin skipped (use scheme://host[:port], punycode for IDN): ' )
		->and( csrf_tests_status( $app, 'POST', [ 'Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://a.test' ] ) )->toBe( 403 );
} )->with( [
	'path' => 'https://a.test/',
	'no scheme' => 'a.test',
	'ftp' => 'ftp://a.test',
	'wildcard' => 'https://*.a.test',
	'port too high' => 'https://a.test:99999',
	'port zero' => 'https://a.test:0',
	'only a dot' => 'https://.',
	'only a dash' => 'https://-',
	'trailing dot' => 'https://a.test.',
	'unicode' => 'https://bücher.test',
	'unclosed ipv6' => 'http://[::1',
	'not ipv6' => 'http://[:]',
	'ipv4 in brackets' => 'http://[1.2.3.4]',
	'scheme with a prefix' => 'xhttps://a.test',
	'not a string' => 42,
] );

test( 'csrf can be turned off per route or globally, and back on per route', function( array $config, ?bool $option, int $status ) {
	expect( csrf_tests_status( csrf_tests_app( $config, $option ), 'POST', [ 'Sec-Fetch-Site' => 'cross-site' ] ) )->toBe( $status );
} )->with( [
	'route off' => [ [], false, 200 ],
	'global off' => [ [ 'csrf' => false ], null, 200 ],
	'global off, route on' => [ [ 'csrf' => false ], true, 403 ],
	'global array keeps it on' => [ [ 'csrf' => [] ], null, 403 ],
] );

test( 'an invalid csrf config warns and keeps protection on', function( mixed $csrf ) {
	[ $app, $warnings ] = capture_warnings( fn () => csrf_tests_app( [ 'csrf' => $csrf ] ) );
	expect( $warnings )->toBe( [ 'Invalid csrf config ignored, CSRF protection stays on' ] )
		->and( csrf_tests_status( $app, 'POST', [ 'Sec-Fetch-Site' => 'cross-site' ] ) )->toBe( 403 );
} )->with( [
	'string' => 'off',
	'int' => 0,
	'trusted_origins not an array' => [ [ 'trusted_origins' => 'https://a.test' ] ],
	'trusted_origins null' => [ [ 'trusted_origins' => null ] ],
	'unknown key' => [ [ 'enabled' => false ] ],
	'misspelled key' => [ [ 'trusted_origin' => [ 'https://a.test' ] ] ],
	'a bare list' => [ [ 'https://a.test' ] ],
] );

test( 'valid csrf configs give no warnings', function( mixed $csrf ) {
	[ , $warnings ] = capture_warnings( fn () => csrf_tests_app( [ 'csrf' => $csrf ] ) );
	expect( $warnings )->toBe( [] );
} )->with( [
	'on' => true,
	'off' => false,
	'null means the default' => null,
	'empty array' => [ [] ],
	'trusted origins' => [ [ 'trusted_origins' => [ 'https://a.test', 'http://[::1]:8000' ] ] ],
] );

test( 'a rejected request gets a plain 403 and the handler never runs', function() {
	$ran = false;
	$app = new App();
	$app->post( '/x', function() use ( &$ran ) {
		$ran = true;
		return 'ran';
	} );
	$r = $app->handle( new Request( 'POST', '/x', headers: [ 'Sec-Fetch-Site' => 'cross-site' ] ) );
	expect( [ $r->status, $r->body, $r->get_header( 'content-type' ), $ran ] )->toBe( [ 403, 'Forbidden', 'text/plain; charset=utf-8', false ] );
} );

test( 'unknown paths, wrong methods and automatic OPTIONS keep their 404, 405 and 204 for cross-site requests', function() {
	$app = new App();
	$app->post( '/x', fn () => 'ran' );
	$cross = [ 'Sec-Fetch-Site' => 'cross-site' ];
	expect( $app->handle( new Request( 'POST', '/nope', headers: $cross ) )->status )->toBe( 404 )
		->and( $app->handle( new Request( 'PUT', '/x', headers: $cross ) )->status )->toBe( 405 )
		->and( $app->handle( new Request( 'OPTIONS', '/x', headers: $cross ) )->status )->toBe( 204 );
} );

test( 'with debug on, a rejection logs its reason; with debug off it logs nothing', function( bool $debug, string $expected ) {
	$app = csrf_tests_app( [ 'debug' => $debug ] );
	[ , $log ] = capture_log( fn () => without_error_handler( fn () => csrf_tests_status( $app, 'POST', [ 'Origin' => 'https://evil.test', 'Host' => 'app.test' ] ) ) );
	expect( $log === '' ? '' : substr( $log, strpos( $log, 'ravlin: ' ) ) )->toBe( $expected );
} )->with( [
	'debug' => [ true, "ravlin: CSRF rejected POST /x: Origin https://evil.test does not match Host app.test and is not trusted\n" ],
	'no debug' => [ false, '' ],
] );
