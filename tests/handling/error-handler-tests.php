<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Http\Request;

final class ErrorTestsNeedsDsn {
	public function __construct( string $dsn ) {}

	public function get() {
		return 'never';
	}
}

function error_tests_throw( string $secret ) : never {
	throw new RuntimeException( 'boom' );
}

function error_tests_deep( int $n ) : never {
	if ( $n === 0 ) {
		$e = null;
		for ( $i = 0; $i < 15; $i++ ) {
			$e = new RuntimeException( 'level ' . $i, 0, $e );
		}
		throw $e;
	}
	error_tests_deep( $n - 1 );
}

function error_tests_handle( App $app, string $method = 'GET' ) : array {
	$result = capture_log( fn () => $app->handle( new Request( $method, '/x' ) ) );
	return $result;
}

test( 'an uncaught throwable becomes a plain, uncached 500 and is logged', function() {
	$app = new App();
	$app->get( '/x', fn () => throw new RuntimeException( 'db down' ) );
	[ $r, $log ] = error_tests_handle( $app );
	expect( [ $r->status, $r->body, $r->headers ] )
		->toBe( [ 500, 'Internal Server Error', [ 'content-type' => [ 'text/plain; charset=utf-8' ], 'cache-control' => [ 'no-store' ] ] ] )
		->and( $log )->toContain( 'ravlin: uncaught RuntimeException: db down in ' . __FILE__ . ':' );
} );

test( 'PHP errors from handlers and class constructors are caught too', function( string $kind, string $class ) {
	$handler = match ( $kind ) {
		'type' => fn ( int $id ) => 'x',
		'constructor' => ErrorTestsNeedsDsn::class,
		'modulo' => fn () => 1 % 0,
	};
	$app = new App();
	$app->get( '/x', $handler );
	[ $r, $log ] = error_tests_handle( $app );
	expect( $r->status )->toBe( 500 )->and( $log )->toContain( 'ravlin: uncaught ' . $class . ':' );
} )->with( [
	'handler type error' => [ 'type', TypeError::class ],
	'constructor rejects the App' => [ 'constructor', TypeError::class ],
	'modulo by zero' => [ 'modulo', DivisionByZeroError::class ],
] );

test( 'only debug === true shows details', function( mixed $debug ) {
	$app = new App( [ 'debug' => $debug ] );
	$app->get( '/x', fn () => throw new RuntimeException( 'secret detail' ) );
	[ $r ] = error_tests_handle( $app );
	expect( [ $r->body, $r->get_header( 'content-type' ) ] )->toBe( [ 'Internal Server Error', 'text/plain; charset=utf-8' ] );
} )->with( [ 'off' => false, 'string true' => 'true', 'int 1' => 1 ] );

test( 'debug shows an escaped detail page with frames and the previous chain', function() {
	$app = new App( [ 'debug' => true ] );
	$app->get( '/x', fn () => throw new LogicException( 'outer <b>', 0, new RuntimeException( 'inner "q"' ) ) );
	[ $r ] = error_tests_handle( $app );
	expect( [ $r->status, $r->get_header( 'content-type' ), $r->get_header( 'cache-control' ) ] )->toBe( [ 500, 'text/html; charset=utf-8', 'no-store' ] )
		->and( $r->body )->toContain( '<h2>LogicException</h2><pre>LogicException: outer &lt;b&gt; in ' . htmlspecialchars( __FILE__ ) . ':' )
		->and( $r->body )->toContain( '<h2>RuntimeException</h2><pre>RuntimeException: inner &quot;q&quot; in ' )
		->and( $r->body )->toContain( '<li>#0 ' )
		->and( $r->body )->toContain( 'Ravlin\App-&gt;dispatch()' )
		->and( $r->body )->not->toContain( '<b>' );
} );

test( 'debug escapes file paths in the location and in frames', function() {
	$file = sys_get_temp_dir() . '/ravlin-<i>&-' . getmypid() . '.php';
	file_put_contents( $file, '<?php function error_tests_tmp_throw() { throw new RuntimeException( "x" ); }' );
	require_once $file;
	$app = new App( [ 'debug' => true ] );
	$app->get( '/x', fn () => error_tests_tmp_throw() );
	[ $r ] = error_tests_handle( $app );
	unlink( $file );
	$escaped = htmlspecialchars( basename( $file ) );
	expect( $r->body )->toContain( $escaped . ':1</pre>' )
		->and( $r->body )->toContain( 'error_tests_tmp_throw()</li>' )
		->and( $r->body )->toContain( '<li>#0 ' )
		->and( $r->body )->not->toContain( '<i>' );
} );

test( 'debug output never contains raw control bytes, such as the NUL in anonymous class names', function() {
	$app = new App( [ 'debug' => true ] );
	$app->get( '/x', fn () => throw new class( "tab\there\0nul" ) extends RuntimeException {} );
	[ $r ] = error_tests_handle( $app );
	expect( $r->body )->not->toContain( "\0" )->and( $r->body )->toContain( 'tab	here\000nul' );
} );

test( 'the log is one escaped line, includes the previous chain, and never includes argument values', function() {
	$old = ini_set( 'zend.exception_ignore_args', '0' );
	$app = new App( [ 'debug' => true ] );
	$app->get( '/x', function() {
		try {
			error_tests_throw( 'hunter2-password' );
		} catch ( RuntimeException $e ) {
			throw new LogicException( "line one\nline two, fake \\n newline", 0, $e );
		}
	} );
	[ $r, $log ] = error_tests_handle( $app );
	ini_set( 'zend.exception_ignore_args', (string) $old );
	expect( substr_count( trim( $log ), "\n" ) )->toBe( 0 )
		->and( $log )->toContain( 'LogicException: line one\nline two, fake \\\\n newline' )
		->and( $log )->toContain( '| previous: RuntimeException: boom' )
		->and( $log )->toContain( 'error_tests_throw()' )
		->and( $log )->not->toContain( 'hunter2' )
		->and( $r->body )->not->toContain( 'hunter2' );
} );

test( 'traces are capped at 50 frames and chains at 10 exceptions', function() {
	$app = new App( [ 'debug' => true ] );
	$app->get( '/x', fn () => error_tests_deep( 80 ) );
	[ $r ] = error_tests_handle( $app );
	$first = explode( '</ol>', $r->body )[0];
	expect( substr_count( $r->body, '<h2>' ) )->toBe( 10 )
		->and( substr_count( $first, '<li>' ) )->toBe( 51 )
		->and( $first )->toContain( '<li>#49 ' )->not->toContain( '<li>#50 ' )
		->and( $first )->toMatch( '/<li>\.\.\. \d+ more frames<\/li>$/' );
} );

// Frames hold full file paths, so a long enough checkout path pushes the line past ErrorLog::MAX_LINE.
test( 'the log line has the same capped frames, wherever the line limit cuts it', function() {
	$app = new App();
	$app->get( '/x', fn () => error_tests_deep( 80 ) );
	[ , $log ] = error_tests_handle( $app );
	$segments = explode( ' | previous: ', $log );
	$first = count( $segments ) > 1 ? $segments[0] : '';
	expect( $log )->toContain( ' #0 ' )->not->toContain( '#50 ' )
		->and( $first === '' ? $log : $first )->toMatch( $first === '' ? '/\.\.\.\[truncated\]\n$/' : '/ #49 [^#]+ \.\.\. \d+ more frames$/' );
} );

test( 'huge messages are truncated in the log and the page', function() {
	$app = new App( [ 'debug' => true ] );
	$app->get( '/x', fn () => throw new RuntimeException( str_repeat( "\x01", 1_000_000 ) ) );
	[ $r, $log ] = error_tests_handle( $app );
	expect( strlen( $log ) )->toBeLessThan( 70_000 )->and( $log )->toContain( '...[truncated]' )
		->and( strlen( $r->body ) )->toBeLessThan( 70_000 );
} );

test( 'HEAD requests get the 500 headers without a body', function( bool $debug ) {
	$app = new App( [ 'debug' => $debug ] );
	$app->get( '/x', fn () => throw new RuntimeException( 'x' ) );
	[ $r ] = error_tests_handle( $app, 'HEAD' );
	expect( [ $r->status, $r->body, $r->get_header( 'cache-control' ) ] )->toBe( [ 500, '', 'no-store' ] );
} )->with( [ 'prod' => false, 'debug' => true ] );

test( 'stray output is discarded and logged, and buffers a handler leaves open are closed', function() {
	$app = new App();
	$app->get( '/x', function() {
		echo 'stray';
		ob_start();
		echo 'inner';
		return 'ok';
	} );
	$level = ob_get_level();
	ob_start();
	[ $r, $log ] = error_tests_handle( $app );
	$out = ob_get_clean();
	expect( [ $r->body, $out, ob_get_level() ] )->toBe( [ 'ok', '', $level ] )
		->and( $log )->toContain( 'ravlin: discarded 10 bytes of output' );
} );

test( 'stray output is discarded when the handler throws', function() {
	$app = new App();
	$app->get( '/x', function() {
		echo 'partial';
		ob_start();
		throw new RuntimeException( 'x' );
	} );
	$level = ob_get_level();
	ob_start();
	[ $r ] = error_tests_handle( $app );
	$out = ob_get_clean();
	expect( [ $r->status, $out, ob_get_level() ] )->toBe( [ 500, '', $level ] );
} );

test( 'an output callback that throws while being discarded is caught', function() {
	$app = new App();
	$app->get( '/x', function() {
		ob_start( fn ( string $buffer ) => throw new RuntimeException( 'from callback' ) );
		echo 'x';
		return 'ok';
	} );
	$level = ob_get_level();
	[ $r, $log ] = error_tests_handle( $app );
	expect( [ $r->status, ob_get_level() ] )->toBe( [ 500, $level ] )->and( $log )->toContain( 'uncaught RuntimeException: from callback' );
} );

test( 'a handler that closes buffers it did not open gets a 500 and is logged', function() {
	$app = new App();
	$app->get( '/x', function() {
		ob_end_clean();
		ob_end_clean();
		return 'ok';
	} );
	$level = ob_get_level();
	ob_start();
	[ $r, $log ] = error_tests_handle( $app );
	expect( [ $r->status, ob_get_level() ] )->toBe( [ 500, $level ] )->and( $log )->toContain( 'a handler closed output buffers it did not open' );
} );

test( 'a handler that flushes the capture buffer away gets a 500 and is logged', function() {
	$app = new App();
	$app->get( '/x', function() {
		ob_end_flush();
		echo 'LEAKED';
		return 'ok';
	} );
	ob_start();
	[ $r, $log ] = error_tests_handle( $app );
	$out = ob_get_clean();
	expect( [ $r->status, $out ] )->toBe( [ 500, 'LEAKED' ] )->and( $log )->toContain( 'a handler closed output buffers it did not open' );
} );

test( 'a buffer that cannot be removed is emptied, gets a 500, and later output passes through instead of vanishing', function() {
	$script = __DIR__ . '/../fixtures/stuck-buffer.php';
	exec( escapeshellarg( PHP_BINARY ) . ' -d error_log=/dev/null ' . escapeshellarg( $script ), $output, $code );
	expect( [ $code, implode( "\n", $output ) ] )->toBe( [ 0, 'status=500;after' ] );
} );

test( 'a returned Response::json failure gets the plain 500', function() {
	$app = new App();
	$app->get( '/x', fn () => Ravlin\Http\Response::json( [ 'bad' => "\xB1" ] ) );
	[ [ $r ], $warnings ] = capture_warnings( fn () => error_tests_handle( $app ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 500, 'Internal Server Error' ] )->and( $warnings )->toHaveCount( 1 );
} );
