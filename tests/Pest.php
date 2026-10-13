<?php
declare( strict_types = 1 );

function capture_warnings( callable $fn ) : array {
	$warnings = [];
	set_error_handler( function( int $no, string $msg ) use ( &$warnings ) { $warnings[] = $msg; return true; } );
	$result = $fn();
	restore_error_handler();
	return [ $result, $warnings ];
}
