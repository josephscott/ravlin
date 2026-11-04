<?php
declare( strict_types = 1 );

function capture_warnings( callable $fn ) : array {
	$warnings = [];
	$level = error_reporting( E_ALL );
	set_error_handler( function( int $no, string $msg ) use ( &$warnings ) {
		if ( error_reporting() & $no ) {
			$warnings[] = $msg;
		}
		return true;
	} );
	$result = $fn();
	restore_error_handler();
	error_reporting( $level );
	return [ $result, $warnings ];
}

function capture_log( callable $fn ) : array {
	$file = (string) tempnam( sys_get_temp_dir(), 'ravlin-log' );
	$old = ini_set( 'error_log', $file );
	$result = $fn();
	ini_set( 'error_log', (string) $old );
	$log = (string) file_get_contents( $file );
	unlink( $file );
	return [ $result, $log ];
}

function without_error_handler( callable $fn ) : mixed {
	set_error_handler( null );
	$result = $fn();
	restore_error_handler();
	return $result;
}
