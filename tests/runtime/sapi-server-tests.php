<?php
declare( strict_types = 1 );

function sapi_server_missing( string $runtime ) : ?string {
	$missing = function_exists( 'proc_open' ) ? null : 'proc_open is disabled';
	if ( $missing === null && $runtime === 'php-fpm' ) {
		$missing = is_executable( sapi_server_fpm() ) && is_executable( sapi_server_nginx() ) ? null : 'php-fpm or nginx not found (set RAVLIN_PHP_FPM / RAVLIN_NGINX)';
	}
	return $missing;
}

// Homebrew and source builds keep php-fpm beside the CLI; Debian and Ubuntu name it php-fpm8.x.
function sapi_server_fpm() : string {
	$candidates = [ dirname( PHP_BINARY, 2 ) . '/sbin/php-fpm', '/usr/sbin/php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, trim( (string) shell_exec( 'command -v php-fpm' ) ) ];
	$fpm = getenv( 'RAVLIN_PHP_FPM' ) ?: ( array_values( array_filter( $candidates, fn ( $c ) => $c !== '' && is_executable( $c ) ) )[0] ?? '' );
	return $fpm;
}

function sapi_server_nginx() : string {
	$nginx = getenv( 'RAVLIN_NGINX' ) ?: trim( (string) shell_exec( 'command -v nginx' ) );
	return $nginx;
}

function sapi_server_raw( int $port, string $request ) : array {
	[ $socket ] = capture_warnings( fn () => fsockopen( '127.0.0.1', $port, $errno, $errstr, 5 ) );
	$response = '';
	if ( $socket !== false ) {
		fwrite( $socket, $request );
		$response = (string) stream_get_contents( $socket );
		fclose( $socket );
	}
	[ $head, $body ] = explode( "\r\n\r\n", $response, 2 ) + [ '', '' ];
	$result = [ (int) ( explode( ' ', $head )[1] ?? 0 ), $body ];
	return $result;
}

function sapi_server_open( array $cmd ) : mixed {
	$proc = proc_open( $cmd, [ [ 'pipe', 'r' ], [ 'file', '/dev/null', 'w' ], [ 'file', '/dev/null', 'w' ] ], $pipes );
	return $proc;
}

// Starts the fixture app under php -S, or under nginx + php-fpm; returns [ procs, port, run dir ], or [ [], 0, '' ] if it never came up.
function sapi_server_start( string $runtime, string $log ) : array {
	$ini = [ '-d', 'expose_php=1', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'error_log=' . $log ];
	$fixture = (string) realpath( __DIR__ . '/../fixtures/sapi-app.php' );
	$root = function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ? [ '-R' ] : [];
	$server = null;
	for ( $try = 0; $try < 5 && $server === null; $try++ ) {
		$port = random_int( 20000, 60000 );
		$run = '';
		if ( $runtime === 'php-fpm' ) {
			$run = sys_get_temp_dir() . '/ravlin-fpm-' . bin2hex( random_bytes( 4 ) );
			mkdir( $run );
			$fill = [ '{port}' => (string) $port, '{fpm_port}' => (string) ( $port + 1 ), '{run}' => $run, '{root}' => dirname( $fixture ), '{script}' => $fixture ];
			foreach ( [ 'php-fpm.conf', 'nginx.conf' ] as $conf ) {
				file_put_contents( $run . '/' . $conf, strtr( (string) file_get_contents( __DIR__ . '/../../examples/app/fpm/' . $conf ), $fill ) );
			}
			$procs = [
				sapi_server_open( [ sapi_server_fpm(), ...$ini, ...$root, '-F', '-y', $run . '/php-fpm.conf' ] ),
				sapi_server_open( [ sapi_server_nginx(), '-p', $run, '-e', $run . '/nginx-error.log', '-c', $run . '/nginx.conf' ] ),
			];
		} else {
			$procs = [ sapi_server_open( [ PHP_BINARY, ...$ini, '-S', '127.0.0.1:' . $port, $fixture ] ) ];
		}
		for ( $wait = 0; $wait < 100 && $server === null; $wait++ ) {
			[ $status ] = capture_warnings( fn () => sapi_server_get( $port, '/ok' ) );
			$running = array_filter( $procs, fn ( $p ) => is_resource( $p ) && proc_get_status( $p )['running'] );
			if ( $status === [ 200, 'ok' ] && count( $running ) === count( $procs ) ) {
				$server = [ $procs, $port, $run ];
			} elseif ( count( $running ) !== count( $procs ) ) {
				break;
			}
			usleep( 20_000 );
		}
		if ( $server === null ) {
			sapi_server_stop( [ $procs, $port, $run ] );
		}
	}
	return $server ?? [ [], 0, '' ];
}

function sapi_server_stop( array $server ) : void {
	[ $procs, , $run ] = $server;
	foreach ( array_filter( $procs, is_resource( ... ) ) as $proc ) {
		proc_terminate( $proc );
		proc_close( $proc );
	}
	if ( $run !== '' ) {
		foreach ( [ 'php-fpm.conf', 'nginx.conf', 'php-fpm.log', 'php-fpm.pid', 'nginx.pid', 'nginx-error.log' ] as $file ) {
			if ( is_file( $run . '/' . $file ) ) {
				unlink( $run . '/' . $file );
			}
		}
		foreach ( [ 'client-body', 'fastcgi', 'proxy', 'uwsgi', 'scgi' ] as $dir ) {
			if ( is_dir( $run . '/' . $dir ) ) {
				rmdir( $run . '/' . $dir );
			}
		}
		rmdir( $run );
	}
}

function sapi_server_get( int $port, string $path, string $method = 'GET', array $headers = [], string $content = '' ) : array {
	$http = [ 'method' => $method, 'header' => $headers, 'content' => $content, 'ignore_errors' => true, 'timeout' => 5 ];
	$body = (string) @file_get_contents( 'http://127.0.0.1:' . $port . $path, false, stream_context_create( [ 'http' => $http ] ) );
	$status = (int) explode( ' ', http_get_last_response_headers()[0] ?? 'HTTP/1.1 0' )[1];
	return [ $status, $body ];
}

test( 'exit, fatals, warnings and throwables never leak handler output', function( string $runtime ) {
	$missing = sapi_server_missing( $runtime );
	if ( $missing !== null ) {
		$this->markTestSkipped( $missing );
	}
	$log = (string) tempnam( sys_get_temp_dir(), 'ravlin-server-log' );
	$server = sapi_server_start( $runtime, $log );
	$port = $server[1];
	if ( $port === 0 ) {
		unlink( $log );
	}
	expect( $port )->not->toBe( 0 );
	$results = [];
	foreach ( [ '/ok', '/ok?x=1', '/%6Fk', '/exit', '/fatal', '/fatal-display', '/warn', '/throw' ] as $path ) {
		$results[$path] = sapi_server_get( $port, $path );
	}
	$results['HEAD /ok'] = sapi_server_get( $port, '/ok', 'HEAD' );
	$results['absolute form'] = sapi_server_raw( $port, 'GET http://127.0.0.1:' . $port . "/ok HTTP/1.0\r\nHost: 127.0.0.1:" . $port . "\r\nConnection: close\r\n\r\n" )[0];
	$server_header = strtolower( implode( "\n", http_get_last_response_headers() ?? [] ) );
	sapi_server_stop( $server );
	$logged = (string) file_get_contents( $log );
	unlink( $log );
	$display = $results['/fatal-display'];
	unset( $results['/fatal-display'] );
	expect( $results )->toBe( [
		'/ok' => [ 200, 'ok' ],
		'/ok?x=1' => [ 200, 'ok' ],
		'/%6Fk' => [ 200, 'ok' ],
		'/exit' => [ 500, '' ],
		'/fatal' => [ 500, '' ],
		'/warn' => [ 200, 'ok' ],
		'/throw' => [ 500, 'Internal Server Error' ],
		'HEAD /ok' => [ 200, '' ],
		'absolute form' => 200,
	] )->and( str_contains( $server_header, 'server: nginx' ) )->toBe( $runtime === 'php-fpm' )
		->and( $server_header )->not->toContain( 'server: nginx/' )
		->and( $display[1] )->toContain( 'Allowed memory size' )->not->toContain( 'SECRET' )
		->and( $logged )->toContain( 'ravlin: PHP Warning: SECRET warning' )
		->and( $logged )->toContain( 'ravlin: uncaught RuntimeException: SECRET' );
} )->with( [ 'php -S', 'php-fpm' ] );

test( 'CSRF protection sees the browser headers', function( string $runtime ) {
	$missing = sapi_server_missing( $runtime );
	if ( $missing !== null ) {
		$this->markTestSkipped( $missing );
	}
	$log = (string) tempnam( sys_get_temp_dir(), 'ravlin-server-log' );
	$server = sapi_server_start( $runtime, $log );
	$port = $server[1];
	if ( $port === 0 ) {
		unlink( $log );
	}
	expect( $port )->not->toBe( 0 );
	$own = 'http://127.0.0.1:' . $port;
	$results = [
		'no browser headers' => sapi_server_get( $port, '/post', 'POST' ),
		'same-origin' => sapi_server_get( $port, '/post', 'POST', [ 'Sec-Fetch-Site: same-origin', 'Origin: https://evil.test' ] ),
		'cross-site' => sapi_server_get( $port, '/post', 'POST', [ 'Sec-Fetch-Site: cross-site', 'Origin: ' . $own ] ),
		'own Origin' => sapi_server_get( $port, '/post', 'POST', [ 'Origin: ' . $own ] ),
		'foreign Origin' => sapi_server_get( $port, '/post', 'POST', [ 'Origin: https://evil.test' ] ),
	];
	sapi_server_stop( $server );
	unlink( $log );
	expect( $results )->toBe( [
		'no browser headers' => [ 200, 'posted' ],
		'same-origin' => [ 200, 'posted' ],
		'cross-site' => [ 403, 'Forbidden' ],
		'own Origin' => [ 200, 'posted' ],
		'foreign Origin' => [ 403, 'Forbidden' ],
	] );
} )->with( [ 'php -S', 'php-fpm' ] );

test( 'responses carry the security headers and no X-Powered-By', function( string $runtime ) {
	$missing = sapi_server_missing( $runtime );
	if ( $missing !== null ) {
		$this->markTestSkipped( $missing );
	}
	$log = (string) tempnam( sys_get_temp_dir(), 'ravlin-server-log' );
	$server = sapi_server_start( $runtime, $log );
	$port = $server[1];
	if ( $port === 0 ) {
		unlink( $log );
	}
	expect( $port )->not->toBe( 0 );
	$headers = [];
	foreach ( [ 'plain' => [ '123: x' ], 'forwarded https' => [ 'X-Forwarded-Proto: https' ] ] as $name => $sent ) {
		sapi_server_get( $port, '/ok', 'GET', $sent );
		$headers[$name] = strtolower( implode( "\n", http_get_last_response_headers() ?? [] ) );
	}
	sapi_server_get( $port, '/throw' );
	$headers['500'] = strtolower( implode( "\n", http_get_last_response_headers() ?? [] ) );
	sapi_server_get( $port, '/exit' );
	$headers['exit'] = strtolower( implode( "\n", http_get_last_response_headers() ?? [] ) );
	sapi_server_stop( $server );
	$logged = (string) file_get_contents( $log );
	unlink( $log );
	expect( $logged )->not->toContain( 'Request header' )
		->and( $headers['plain'] )->toContain( "content-security-policy: default-src 'self'; script-src 'self' 'nonce-" )
		->toContain( 'x-content-type-options: nosniff' )->not->toContain( 'x-powered-by' )->not->toContain( 'strict-transport-security' )
		->and( $headers['forwarded https'] )->toContain( 'strict-transport-security: max-age=31536000' )->not->toContain( 'x-powered-by' )
		->and( $headers['500'] )->toContain( 'x-content-type-options: nosniff' )->not->toContain( 'x-powered-by' )
		->and( $headers['exit'] )->toContain( ' 500 ' )->not->toContain( 'x-powered-by' );
} )->with( [ 'php -S', 'php-fpm' ] );

test( 'query strings, form bodies, content headers, encoded slashes and Host reach the app the same way', function( string $runtime ) {
	$missing = sapi_server_missing( $runtime );
	if ( $missing !== null ) {
		$this->markTestSkipped( $missing );
	}
	$log = (string) tempnam( sys_get_temp_dir(), 'ravlin-server-log' );
	$server = sapi_server_start( $runtime, $log );
	$port = $server[1];
	if ( $port === 0 ) {
		unlink( $log );
	}
	$form = sapi_server_get( $port, '/echo?q=a%20b', 'POST', [ 'Content-Type: application/x-www-form-urlencoded' ], 'f=c%26d' );
	$get = sapi_server_get( $port, '/echo' );
	$absolute = sapi_server_raw( $port, "GET http://example.test/echo HTTP/1.0\r\nHost: 127.0.0.1:" . $port . "\r\nConnection: close\r\n\r\n" );
	$slash = sapi_server_get( $port, '/p/a%2Fb' );
	$underscore = sapi_server_raw( $port, "POST /post HTTP/1.0\r\nHost: 127.0.0.1:" . $port . "\r\nSec-Fetch-Site: cross-site\r\nSec_Fetch_Site: same-origin\r\nContent-Length: 0\r\nConnection: close\r\n\r\n" );
	sapi_server_stop( $server );
	unlink( $log );
	expect( $port )->not->toBe( 0 )
		->and( json_decode( $form[1], true ) )->toBe( [ 'q' => 'a b', 'f' => 'c&d', 'body' => 'f=c%26d', 'content-type' => 'application/x-www-form-urlencoded', 'content-length' => '7', 'host' => '127.0.0.1:' . $port ] )
		->and( json_decode( $get[1], true ) )->toBe( [ 'q' => null, 'f' => null, 'body' => '', 'content-type' => null, 'content-length' => null, 'host' => '127.0.0.1:' . $port ] )
		->and( json_decode( $absolute[1], true )['host'] ?? null )->toBe( 'example.test' )
		->and( $slash )->toBe( [ 200, 'a/b' ] )
		->and( $underscore[0] )->toBe( $runtime === 'php-fpm' ? 403 : 200 );
} )->with( [ 'php -S', 'php-fpm' ] );
