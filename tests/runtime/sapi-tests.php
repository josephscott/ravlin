<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Runtime\Sapi;

test( 'run discards stray output, including buffers a handler leaves open', function() {
	$server = $_SERVER;
	$log_file = (string) tempnam( sys_get_temp_dir(), 'ravlin-log' );
	$old_log = ini_set( 'error_log', $log_file );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI'] = '/x';
	$app = new App();
	$app->get( '/x', function() { echo 'stray'; ob_start(); echo 'inner'; return 'ok'; } );
	$level = ob_get_level();
	ob_start();
	Sapi::run( $app );
	$out = ob_get_clean();
	$log = (string) file_get_contents( $log_file );
	ini_set( 'error_log', (string) $old_log );
	unlink( $log_file );
	$_SERVER = $server;
	expect( $out )->toBe( 'ok' )->and( ob_get_level() )->toBe( $level )
		->and( $log )->toContain( 'ravlin: discarded 10 bytes of output' );
} );
