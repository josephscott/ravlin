<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Runtime\Sapi;

function sapi_tests_run( App $app ) : array {
	$server = $_SERVER;
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI'] = '/x';
	ob_start();
	[ , $log ] = capture_log( fn () => Sapi::run( $app ) );
	$out = ob_get_clean();
	$_SERVER = $server;
	return [ $out, $log ];
}

test( 'run sends the response body and nothing else', function() {
	$app = new App();
	$app->get( '/x', function() {
		echo 'stray';
		ob_start();
		echo 'inner';
		return 'ok';
	} );
	$level = ob_get_level();
	[ $out, $log ] = sapi_tests_run( $app );
	expect( [ $out, ob_get_level() ] )->toBe( [ 'ok', $level ] )->and( $log )->toContain( 'ravlin: discarded 10 bytes of output' );
} );

test( 'run sends warnings to the log, not the output, and removes its handler afterwards', function() {
	$app = new App();
	$app->get( '/x', function() {
		trigger_error( 'heads up', E_USER_WARNING );
		return 'ok';
	} );
	$old = error_reporting( E_ALL );
	[ [ $out, $log ], $after ] = without_error_handler( function() use ( $app ) {
		$result = sapi_tests_run( $app );
		$after = set_error_handler( null );
		restore_error_handler();
		return [ $result, $after ];
	} );
	error_reporting( $old );
	expect( [ $out, $after ] )->toBe( [ 'ok', null ] )->and( $log )->toContain( 'ravlin: PHP Warning: heads up in ' . __FILE__ );
} );

test( 'run leaves an app error handler in place', function() {
	$app = new App();
	$app->get( '/x', function() {
		trigger_error( 'to the app', E_USER_WARNING );
		return 'ok';
	} );
	[ [ $out, $log ], $warnings ] = capture_warnings( fn () => sapi_tests_run( $app ) );
	expect( [ $out, $warnings ] )->toBe( [ 'ok', [ 'to the app' ] ] )->and( $log )->not->toContain( 'to the app' );
} );

test( 'request marks HTTPS the way web servers report it', function( ?string $value, bool $https ) {
	$server = $_SERVER;
	unset( $_SERVER['HTTPS'] );
	if ( $value !== null ) {
		$_SERVER['HTTPS'] = $value;
	}
	$req = Sapi::request();
	$_SERVER = $server;
	expect( $req->https )->toBe( $https );
} )->with( [
	'unset' => [ null, false ],
	'empty' => [ '', false ],
	'off (IIS)' => [ 'off', false ],
	'OFF' => [ 'OFF', false ],
	'on' => [ 'on', true ],
	'1' => [ '1', true ],
] );

test( 'request takes the path and Host from an absolute-form request target, as RFC 9112 requires', function( string $uri, string $path, string $host ) {
	$server = $_SERVER;
	$_SERVER['REQUEST_URI'] = $uri;
	$_SERVER['HTTP_HOST'] = 'sent.test';
	$req = Sapi::request();
	$_SERVER = $server;
	expect( [ $req->path, $req->get_header( 'host' ) ] )->toBe( [ $path, $host ] );
} )->with( [
	'origin form' => [ '/a/b?x=1', '/a/b', 'sent.test' ],
	'absolute form' => [ 'http://example.test/a/b?x=1', '/a/b', 'example.test' ],
	'absolute form, https, port, any case' => [ 'HTTPS://Example.test:8443/a', '/a', 'Example.test:8443' ],
	'absolute form without a path' => [ 'http://example.test', '/', 'example.test' ],
	'absolute form with only a query' => [ 'http://example.test?x=1', '/', 'example.test' ],
	'userinfo is not part of Host' => [ 'http://user:pw@example.test/a', '/a', 'example.test' ],
	'empty authority keeps Host' => [ 'http:///a', '/a', 'sent.test' ],
	'scheme-like path stays' => [ '/http://example.test/a', '/http://example.test/a', 'sent.test' ],
	'other scheme stays' => [ 'ftp://example.test/a', 'ftp://example.test/a', 'sent.test' ],
	'double slash stays' => [ '//example.test/a', '//example.test/a', 'sent.test' ],
] );

test( 'request skips empty content headers, which CGI servers send when the client sent none', function( string $value, ?string $expected ) {
	$server = $_SERVER;
	$_SERVER['CONTENT_TYPE'] = $value;
	$_SERVER['CONTENT_LENGTH'] = $value;
	$req = Sapi::request();
	$_SERVER = $server;
	expect( [ $req->get_header( 'content-type' ), $req->get_header( 'content-length' ) ] )->toBe( [ $expected, $expected ] );
} )->with( [ 'empty' => [ '', null ], 'set' => [ '7', '7' ] ] );
