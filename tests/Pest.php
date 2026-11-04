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
