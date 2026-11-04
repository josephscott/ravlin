<?php
declare( strict_types = 1 );

function sapi_server_start( string $log ) : array {
	$server = null;
	for ( $try = 0; $try < 5 && $server === null; $try++ ) {
		$port = random_int( 20000, 60000 );
		$cmd = [ PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'error_log=' . $log, '-S', '127.0.0.1:' . $port, __DIR__ . '/../fixtures/sapi-app.php' ];
		$proc = proc_open( $cmd, [ [ 'pipe', 'r' ], [ 'file', '/dev/null', 'w' ], [ 'file', '/dev/null', 'w' ] ], $pipes );
		for ( $wait = 0; $wait < 50; $wait++ ) {
			[ $socket ] = capture_warnings( fn () => fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 ) );
			if ( $socket !== false ) {
				fclose( $socket );
				$server = proc_get_status( $proc )['running'] ? [ $proc, $port ] : null;
				break;
			}
			usleep( 20_000 );
		}
		if ( $server === null ) {
			proc_terminate( $proc );
		}
	}
	return $server ?? [ null, 0 ];
}

function sapi_server_get( int $port, string $path ) : array {
	$body = (string) @file_get_contents( 'http://127.0.0.1:' . $port . $path, false, stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 5 ] ] ) );
	$status = (int) explode( ' ', http_get_last_response_headers()[0] ?? 'HTTP/1.1 0' )[1];
	return [ $status, $body ];
}

test( 'under php -S, exit, fatals, warnings and throwables never leak handler output', function() {
	if ( ! function_exists( 'proc_open' ) ) {
		$this->markTestSkipped( 'proc_open is disabled' );
	}
	$log = (string) tempnam( sys_get_temp_dir(), 'ravlin-server-log' );
	[ $proc, $port ] = sapi_server_start( $log );
	expect( $proc )->not->toBeNull();
	$results = [];
	foreach ( [ '/ok', '/exit', '/fatal', '/fatal-display', '/warn', '/throw' ] as $path ) {
		$results[$path] = sapi_server_get( $port, $path );
	}
	proc_terminate( $proc );
	proc_close( $proc );
	$logged = (string) file_get_contents( $log );
	unlink( $log );
	$display = $results['/fatal-display'];
	unset( $results['/fatal-display'] );
	expect( $results )->toBe( [
		'/ok' => [ 200, 'ok' ],
		'/exit' => [ 500, '' ],
		'/fatal' => [ 500, '' ],
		'/warn' => [ 200, 'ok' ],
		'/throw' => [ 500, 'Internal Server Error' ],
	] )->and( $display[1] )->toContain( 'Allowed memory size' )->not->toContain( 'SECRET' )
		->and( $logged )->toContain( 'ravlin: PHP Warning: SECRET warning' )
		->and( $logged )->toContain( 'ravlin: uncaught RuntimeException: SECRET' );
} );
