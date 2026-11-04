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
