<?php
declare( strict_types = 1 );

use Ravlin\Handling\ErrorLog;

function error_log_tests_handler() : Closure {
	$log = new ErrorLog();
	$handler = without_error_handler( function() use ( $log ) {
		$handler = $log->install();
		$log->uninstall( $handler );
		return $handler;
	} );
	return $handler;
}

test( 'write prefixes, escapes control bytes and backslashes, and keeps one line', function() {
	[ , $log ] = capture_log( fn () => ( new ErrorLog() )->write( "a\nb\\nc\0d\x7F" ) );
	expect( $log )->toContain( 'ravlin: a\nb\\\\nc\000d\177' )->and( substr_count( trim( $log ), "\n" ) )->toBe( 0 );
} );

test( 'write caps the line length after escaping', function( string $text ) {
	[ , $log ] = capture_log( fn () => ( new ErrorLog() )->write( $text ) );
	expect( strlen( $log ) )->toBeLessThan( 16_500 )->and( $log )->toContain( '...[truncated]' );
} )->with( [
	'long plain' => str_repeat( 'x', 100_000 ),
	'long, escapes to 4x' => str_repeat( "\x01", 100_000 ),
	'under the cap until escaped' => str_repeat( "\x01", 10_000 ),
] );

test( 'install returns the handler when none is set, and uninstall removes it', function() {
	$log = new ErrorLog();
	[ $handler, $top, $after ] = without_error_handler( function() use ( $log ) {
		$handler = $log->install();
		$top = set_error_handler( null );
		restore_error_handler();
		$log->uninstall( $handler );
		$after = set_error_handler( null );
		restore_error_handler();
		return [ $handler, $top, $after ];
	} );
	expect( $handler )->toBeInstanceOf( Closure::class )->and( $top )->toBe( $handler )->and( $after )->toBeNull();
} );

test( 'install leaves an existing handler alone and returns null', function() {
	$mine = fn () => true;
	set_error_handler( $mine );
	$handler = ( new ErrorLog() )->install();
	$top = set_error_handler( null );
	restore_error_handler();
	restore_error_handler();
	expect( $handler )->toBeNull()->and( $top )->toBe( $mine );
} );

test( 'uninstall does not pop a handler someone installed on top of it', function() {
	$log = new ErrorLog();
	$other = fn () => true;
	$top = without_error_handler( function() use ( $log, $other ) {
		$handler = $log->install();
		set_error_handler( $other );
		$log->uninstall( $handler );
		$top = set_error_handler( null );
		restore_error_handler();
		restore_error_handler();
		restore_error_handler();
		return $top;
	} );
	expect( $top )->toBe( $other );
} );

test( 'the handler logs reported errors with their level and returns true', function( int $no, string $level ) {
	$handler = error_log_tests_handler();
	$old = error_reporting( E_ALL );
	[ $handled, $log ] = capture_log( fn () => $handler( $no, 'msg', '/f.php', 7 ) );
	error_reporting( $old );
	expect( $handled )->toBeTrue()->and( $log )->toContain( 'ravlin: PHP ' . $level . ': msg in /f.php:7' );
} )->with( [
	[ E_WARNING, 'Warning' ], [ E_USER_WARNING, 'Warning' ], [ E_NOTICE, 'Notice' ], [ E_USER_NOTICE, 'Notice' ],
	[ E_DEPRECATED, 'Deprecated' ], [ E_USER_DEPRECATED, 'Deprecated' ],
] );

test( 'the handler returns false without logging for unreported, suppressed, and fatal-class errors', function( int $no, int $reporting ) {
	$handler = error_log_tests_handler();
	$old = error_reporting( $reporting );
	[ $handled, $log ] = capture_log( fn () => $handler( $no, 'msg', '/f.php', 7 ) );
	error_reporting( $old );
	expect( [ $handled, $log ] )->toBe( [ false, '' ] );
} )->with( [
	'outside error_reporting' => [ E_USER_NOTICE, E_ALL & ~E_USER_NOTICE ],
	'@-suppressed' => [ E_WARNING, 4437 ],
	'E_USER_ERROR' => [ E_USER_ERROR, E_ALL ],
	'E_RECOVERABLE_ERROR' => [ E_RECOVERABLE_ERROR, E_ALL ],
] );

test( 'error_get_last still works with @ while the handler is installed', function() {
	$log = new ErrorLog();
	$message = without_error_handler( function() use ( $log ) {
		$handler = $log->install();
		error_clear_last();
		@file_get_contents( '/ravlin-no-such-file' );
		$last = error_get_last()['message'] ?? '';
		$log->uninstall( $handler );
		return $last;
	} );
	expect( $message )->toContain( 'ravlin-no-such-file' );
} );
