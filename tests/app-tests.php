<?php
declare( strict_types = 1 );

use Ravlin\App;
use Ravlin\Http\Request;
use Ravlin\Http\Response;

final class AppTestsInvokable {
	public function __construct( private App $app ) {}

	public function __invoke( Request $req ) {
		return $this->app->config['name'] . ':' . $req->method;
	}
}

final class AppTestsMethods {
	public static bool $constructed = false;

	private int $calls = 0;

	public function __construct() {
		self::$constructed = true;
	}

	public function get( Request $req ) {
		return 'get';
	}

	public function post( Request $req ) {
		return 'post';
	}

	public function show( Request $req ) {
		$this->calls++;
		return 'show ' . $this->calls;
	}

	private function hidden() {
		return 'hidden';
	}
}

final class AppTestsStatic {
	public function __construct( string $dsn ) {}

	public static function show( Request $req ) {
		return 'static';
	}
}

class AppTestsBase {
	public function delete( Request $req ) {
		return 'base-delete';
	}
}

final class AppTestsChild extends AppTestsBase {
	public function get( Request $req ) {
		return 'child-get';
	}

	public function options( Request $req ) {
		return new Response( 'custom options' );
	}
}

final class AppTestsPrivateGet {
	private function get() {
		return 'private';
	}
}

final class AppTestsMagic {
	public function __call( string $name, array $args ) {
		return 'magic';
	}
}

final class AppTestsHead {
	public function head( Request $req ) {
		return ( new Response() )->set_header( 'X-From', 'head' );
	}

	public function get( Request $req ) {
		return ( new Response() )->set_header( 'X-From', 'get' );
	}
}

function app_tests_function( Request $req ) {
	return 'function';
}

final class App_Tests_Function {
	public function get( Request $req ) {
		return 'class';
	}
}

test( 'config is readable', fn () => expect( ( new App( [ 'debug' => true ] ) )->config )->toBe( [ 'debug' => true ] ) );

test( 'handle returns a 404 when nothing matches', function() {
	$r = ( new App() )->handle( new Request( 'GET', '/nope' ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 404, 'Not Found' ] );
} );

test( 'route shortcuts register the matching HTTP method', function( string $fn, string $method ) {
	$route = ( new App() )->$fn( '/x', 'h' );
	expect( [ $route->methods, $route->path, $route->handler ] )->toBe( [ [ $method ], '/x', 'h' ] );
} )->with( [ [ 'get', 'GET' ], [ 'post', 'POST' ], [ 'put', 'PUT' ], [ 'patch', 'PATCH' ], [ 'delete', 'DELETE' ] ] );

test( 'handle runs the matched handler and converts its result', function( mixed $result, int $status, ?string $type, string $body ) {
	$app = new App();
	$app->get( '/x', fn () => $result );
	$r = $app->handle( new Request( 'GET', '/x' ) );
	expect( [ $r->status, $r->get_header( 'content-type' ), $r->body ] )->toBe( [ $status, $type, $body ] );
} )->with( [
	'string' => [ 'hi', 200, 'text/html; charset=utf-8', 'hi' ],
	'array' => [ [ 'ok' => true ], 200, 'application/json', '{"ok":true}' ],
	'response' => [ new Response( 'made', 201 ), 201, null, 'made' ],
] );

test( 'handle passes the request to the handler', function() {
	$app = new App();
	$app->post( '/echo', fn ( Request $req ) => $req->get_form( 'name' ) );
	$r = $app->handle( new Request( 'POST', '/echo', form: [ 'name' => 'ravlin' ] ) );
	expect( $r->body )->toBe( 'ravlin' );
} );

test( 'handle returns the plain 500 with a warning for unsupported handler results', function( mixed $result ) {
	$app = new App();
	$app->get( '/x', fn () => $result );
	[ $r, $warnings ] = capture_warnings( fn () => $app->handle( new Request( 'GET', '/x' ) ) );
	expect( [ $r->status, $r->body, $r->headers ] )->toBe( [ 500, 'Internal Server Error', [ 'content-type' => [ 'text/plain; charset=utf-8' ], 'cache-control' => [ 'no-store' ] ] ] )->and( $warnings )->toHaveCount( 1 );
} )->with( [ 'null' => null, 'int' => 42, 'object' => new stdClass() ] );

test( 'handle returns the plain 500 with a warning when the handler is not callable', function( mixed $handler ) {
	$app = new App();
	$app->get( '/x', $handler );
	[ $r, $warnings ] = capture_warnings( fn () => $app->handle( new Request( 'GET', '/x' ) ) );
	expect( [ $r->status, $r->body, $r->headers ] )->toBe( [ 500, 'Internal Server Error', [ 'content-type' => [ 'text/plain; charset=utf-8' ], 'cache-control' => [ 'no-store' ] ] ] )->and( $warnings )->toBe( [ 'Route handler is not callable: /x' ] );
} )->with( [
	'missing function' => 'no_such_function',
	'missing class method' => [ [ AppTestsMethods::class, 'nope' ] ],
	'private class method' => [ [ AppTestsMethods::class, 'hidden' ] ],
	'array with extra items' => [ [ AppTestsMethods::class, 'show', 'x' ] ],
	'static-style array of an unknown class' => [ [ 'NoSuchClass', 'show' ] ],
] );

test( 'handle returns a copy of a Response the handler returns', function() {
	$shared = new Response( 'cached' );
	$app = new App();
	$app->get( '/x', fn () => $shared );
	$first = $app->handle( new Request( 'GET', '/x' ) );
	$first->add_header( 'X-Per-Request', 'one' );
	$second = $app->handle( new Request( 'GET', '/x' ) );
	expect( $second )->not->toBe( $shared )->not->toBe( $first )->and( $second->headers )->toBe( [] )->and( $second->body )->toBe( 'cached' );
} );

test( 'handle returns the plain 500 when an array result cannot be JSON encoded', function() {
	$app = new App();
	$app->get( '/x', fn () => [ 'bad' => "\xB1\x31" ] );
	[ $r, $warnings ] = capture_warnings( fn () => $app->handle( new Request( 'GET', '/x' ) ) );
	expect( [ $r->status, $r->body, $r->headers ] )->toBe( [ 500, 'Internal Server Error', [ 'content-type' => [ 'text/plain; charset=utf-8' ], 'cache-control' => [ 'no-store' ] ] ] )->and( $warnings )->toHaveCount( 1 )
		->and( $warnings[0] )->toStartWith( 'JSON encode failed' );
} );

test( 'handle dispatches callables other than closures', function( mixed $handler ) {
	$app = new App();
	$app->get( '/x', $handler );
	expect( $app->handle( new Request( 'GET', '/x' ) )->body )->toBe( 'called' );
} )->with( [
	'object and method' => fn () => [ new class {
		public function show() {
			return 'called';
		}
	}, 'show' ],
	'invokable object' => fn () => new class {
		public function __invoke() {
			return 'called';
		}
	},
] );

test( 'HEAD falls back to the GET route and sends no body', function() {
	$app = new App();
	$app->get( '/x', fn () => [ 'ok' => true ] );
	$r = $app->handle( new Request( 'HEAD', '/x' ) );
	expect( [ $r->status, $r->get_header( 'content-type' ), $r->body ] )->toBe( [ 200, 'application/json', '' ] );
} );

test( 'an explicit HEAD route wins over the GET fallback', function() {
	$app = new App();
	$app->get( '/x', fn () => 'get' );
	$app->route( [ 'HEAD' ], '/x', fn () => new Response( 'head', 204 ) );
	$r = $app->handle( new Request( 'HEAD', '/x' ) );
	expect( [ $r->status, $r->body ] )->toBe( [ 204, '' ] );
} );

test( 'a known path with the wrong method gets a 405 with Allow', function() {
	$app = new App();
	$app->get( '/x', fn () => 'get' );
	$app->post( '/x', fn () => 'post' );
	$r = $app->handle( new Request( 'DELETE', '/x' ) );
	expect( [ $r->status, $r->get_header( 'allow' ), $r->body ] )->toBe( [ 405, 'GET, POST, HEAD, OPTIONS', 'Method Not Allowed' ] );
} );

test( 'OPTIONS on a known path gets a 204 with Allow', function() {
	$app = new App();
	$app->post( '/x', fn () => 'post' );
	$r = $app->handle( new Request( 'OPTIONS', '/x' ) );
	expect( [ $r->status, $r->get_header( 'allow' ), $r->body ] )->toBe( [ 204, 'POST, OPTIONS', '' ] );
} );

test( 'an explicit OPTIONS route wins over the automatic response', function() {
	$app = new App();
	$app->route( [ 'OPTIONS' ], '/x', fn () => new Response( 'custom', 200 ) );
	expect( $app->handle( new Request( 'OPTIONS', '/x' ) )->body )->toBe( 'custom' );
} );

test( 'HEAD responses never carry a body, including 404 and 405', function( string $path, int $status, ?string $allow ) {
	$app = new App();
	$app->post( '/post-only', fn () => 'post' );
	$r = $app->handle( new Request( 'HEAD', $path ) );
	expect( [ $r->status, $r->body, $r->get_header( 'allow' ) ] )->toBe( [ $status, '', $allow ] );
} )->with( [ 'not found' => [ '/nope', 404, null ], 'wrong method' => [ '/post-only', 405, 'POST, OPTIONS' ] ] );

test( 'OPTIONS and wrong methods on an unknown path still get a 404', function( string $method ) {
	$r = ( new App() )->handle( new Request( $method, '/nope' ) );
	expect( $r->status )->toBe( 404 );
} )->with( [ 'OPTIONS', 'DELETE' ] );

test( 'the HEAD fallback handler sees HEAD and its headers are kept', function() {
	$app = new App();
	$app->get( '/x', fn ( Request $req ) => ( new Response( 'body' ) )->set_header( 'X-Method', $req->method )->set_header( 'Content-Length', '4' ) );
	$r = $app->handle( new Request( 'HEAD', '/x' ) );
	expect( [ $r->get_header( 'x-method' ), $r->get_header( 'content-length' ), $r->body ] )->toBe( [ 'HEAD', '4', '' ] );
} );

test( 'HEAD does not empty a Response the handler keeps and reuses', function() {
	$shared = new Response( 'cached-body' );
	$app = new App();
	$app->get( '/x', fn () => $shared );
	$app->handle( new Request( 'HEAD', '/x' ) );
	expect( $app->handle( new Request( 'GET', '/x' ) )->body )->toBe( 'cached-body' )->and( $shared->body )->toBe( 'cached-body' );
} );

test( 'an unknown method on a known path gets a 405', function() {
	$app = new App();
	$app->get( '/x', fn () => 'get' );
	$r = $app->handle( new Request( 'FOO', '/x' ) );
	expect( [ $r->status, $r->get_header( 'allow' ) ] )->toBe( [ 405, 'GET, HEAD, OPTIONS' ] );
} );

test( 'handlers read route params from the request', function() {
	$app = new App();
	$app->get( '/users/{name}', fn ( Request $req ) => [ 'name' => $req->get_param( 'name' ), 'missing' => $req->get_param( 'nope' ) ] );
	expect( $app->handle( new Request( 'GET', '/users/ana%20b' ) )->body )->toBe( '{"name":"ana b","missing":null}' );
} );

test( 'the HEAD fallback and 405 work for param routes', function() {
	$app = new App();
	$app->get( '/users/{name}', fn ( Request $req ) => $req->get_param( 'name' ) );
	$head = $app->handle( new Request( 'HEAD', '/users/x' ) );
	$wrong = $app->handle( new Request( 'POST', '/users/x' ) );
	expect( [ $head->status, $head->body, $wrong->status, $wrong->get_header( 'allow' ) ] )->toBe( [ 200, '', 405, 'GET, HEAD, OPTIONS' ] );
} );

test( 'route params do not leak into the original request', function() {
	$app = new App();
	$app->get( '/users/{name}', fn () => 'ok' );
	$req = new Request( 'GET', '/users/x' );
	$app->handle( $req );
	expect( $req->params )->toBe( [] );
} );

test( 'a param with invalid UTF-8 is a 404, not a 500', function() {
	$app = new App();
	$app->get( '/hello/{name}', fn ( Request $req ) => [ 'name' => $req->get_param( 'name' ) ] );
	expect( $app->handle( new Request( 'GET', '/hello/%FF' ) )->status )->toBe( 404 );
} );

test( 'a class name handler calls __invoke on a new instance that gets the App', function() {
	$app = new App( [ 'name' => 'demo' ] );
	$app->route( [ 'GET', 'POST' ], '/x', AppTestsInvokable::class );
	expect( $app->handle( new Request( 'POST', '/x' ) )->body )->toBe( 'demo:POST' );
} );

test( 'a class name handler without __invoke calls the method named after the HTTP method', function( string $method, string $body ) {
	$app = new App();
	$app->route( [ 'GET', 'POST' ], '/x', AppTestsMethods::class );
	expect( $app->handle( new Request( $method, '/x' ) )->body )->toBe( $body );
} )->with( [ [ 'GET', 'get' ], [ 'POST', 'post' ], [ 'HEAD', '' ] ] );

test( 'HEAD prefers head() over get() on a class handler', function( string $method, string $from ) {
	$app = new App();
	$app->route( [ 'GET', 'HEAD' ], '/x', AppTestsHead::class );
	expect( $app->handle( new Request( $method, '/x' ) )->get_header( 'x-from' ) )->toBe( $from );
} )->with( [ [ 'HEAD', 'head' ], [ 'GET', 'get' ] ] );

test( 'a class without a method for the request gets a 405 without being constructed', function() {
	AppTestsMethods::$constructed = false;
	$app = new App();
	$app->route( [ 'GET', 'DELETE', 'MAGIC' ], '/x', AppTestsMethods::class );
	$r = $app->handle( new Request( 'DELETE', '/x' ) );
	$options = $app->handle( new Request( 'OPTIONS', '/x' ) );
	expect( [ $r->status, $r->get_header( 'allow' ), AppTestsMethods::$constructed ] )->toBe( [ 405, 'GET, HEAD, OPTIONS', false ] )
		->and( [ $options->status, $options->get_header( 'allow' ) ] )->toBe( [ 204, 'GET, HEAD, OPTIONS' ] );
} );

test( 'a method a class does not implement falls through to a later route on the same path', function() {
	$app = new App();
	$app->route( [ 'GET', 'POST' ], '/x', AppTestsChild::class );
	$app->post( '/x', fn () => 'closure-post' );
	$app->put( '/x', fn () => 'closure-put' );
	$r = $app->handle( new Request( 'PATCH', '/x' ) );
	expect( $app->handle( new Request( 'POST', '/x' ) )->body )->toBe( 'closure-post' )
		->and( [ $r->status, $r->get_header( 'allow' ) ] )->toBe( [ 405, 'GET, POST, PUT, HEAD, OPTIONS' ] );
} );

test( 'an OPTIONS route method uses options() when present, else the automatic 204', function() {
	$app = new App();
	$app->route( [ 'GET', 'OPTIONS' ], '/child', AppTestsChild::class );
	$app->route( [ 'GET', 'OPTIONS' ], '/methods', AppTestsMethods::class );
	$methods = $app->handle( new Request( 'OPTIONS', '/methods' ) );
	expect( $app->handle( new Request( 'OPTIONS', '/child' ) )->body )->toBe( 'custom options' )
		->and( [ $methods->status, $methods->get_header( 'allow' ) ] )->toBe( [ 204, 'GET, HEAD, OPTIONS' ] );
} );

test( 'inherited public methods are served', function() {
	$app = new App();
	$app->route( [ 'DELETE' ], '/x', AppTestsChild::class );
	expect( $app->handle( new Request( 'DELETE', '/x' ) )->body )->toBe( 'base-delete' );
} );

test( 'a private method named after the HTTP method gets a 500 with a warning', function() {
	$app = new App();
	$app->get( '/x', AppTestsPrivateGet::class );
	[ $r, $warnings ] = capture_warnings( fn () => $app->handle( new Request( 'GET', '/x' ) ) );
	expect( $r->status )->toBe( 500 )->and( $warnings )->toBe( [ 'Route handler is not callable: /x' ] );
} );

test( 'a static method array is called without constructing the class', function() {
	$app = new App();
	$app->get( '/x', [ AppTestsStatic::class, 'show' ] );
	$app->get( '/y', AppTestsStatic::class . '::show' );
	expect( $app->handle( new Request( 'GET', '/x' ) )->body )->toBe( 'static' )
		->and( $app->handle( new Request( 'GET', '/y' ) )->body )->toBe( 'static' );
} );

test( 'HTTP methods never reach magic methods on a class handler', function( string $method ) {
	$app = new App();
	$app->route( [ 'GET', '__CALL', '__CONSTRUCT' ], '/x', AppTestsMagic::class );
	$r = $app->handle( new Request( $method, '/x' ) );
	expect( $r->status )->toBe( 404 );
} )->with( [ 'GET', '__CALL', '__CONSTRUCT' ] );

test( 'a class with no usable methods leaves the path unknown, so 404', function() {
	$app = new App();
	$app->get( '/x', stdClass::class );
	expect( $app->handle( new Request( 'GET', '/x' ) )->status )->toBe( 404 );
} );

test( 'a class and method handler uses a new instance per request', function() {
	$app = new App();
	$app->get( '/x', [ AppTestsMethods::class, 'show' ] );
	$app->handle( new Request( 'GET', '/x' ) );
	expect( $app->handle( new Request( 'GET', '/x' ) )->body )->toBe( 'show 1' );
} );

test( 'a function name wins over a class with the same name', function() {
	$app = new App();
	$app->get( '/x', 'app_tests_function' );
	expect( $app->handle( new Request( 'GET', '/x' ) )->body )->toBe( 'function' );
} );

test( 'load_routes requires a file relative to base_path with $app in scope, but no $this or App private access', function() {
	$app = new App( [ 'base_path' => __DIR__ . '/fixtures/' ] );
	$result = $app->load_routes( 'routes-file.php' );
	expect( $result )->toBe( $app )->and( $app->handle( new Request( 'GET', '/from-file' ) )->body )->toBe( '{"this":false,"private":false,"vars":["app","routes_file"]}' );
} );

test( 'load_routes accepts an absolute path without base_path', function() {
	$app = ( new App() )->load_routes( __DIR__ . '/fixtures/routes-file.php' );
	expect( $app->handle( new Request( 'GET', '/from-file' ) )->status )->toBe( 200 );
} );

test( 'load_routes warns instead of failing for a missing file or a relative path without base_path', function( array $config, string $file ) {
	$app = new App( $config );
	[ , $warnings ] = capture_warnings( fn () => $app->load_routes( $file ) );
	expect( is_file( 'tests/fixtures/routes-file.php' ) )->toBeTrue( 'tests must run from the repo root for the CWD cases to mean anything' )
		->and( $warnings )->toBe( [ 'Routes file not found or unreadable (relative paths need an absolute base_path): ' . $file ] )
		->and( $app->handle( new Request( 'GET', '/from-file' ) )->status )->toBe( 404 );
} )->with( [
	'missing file' => [ [ 'base_path' => __DIR__ ], 'nope.php' ],
	'no base_path, file exists relative to the CWD' => [ [], 'tests/fixtures/routes-file.php' ],
	'relative base_path, file exists relative to the CWD' => [ [ 'base_path' => 'tests' ], 'fixtures/routes-file.php' ],
	'dot base_path' => [ [ 'base_path' => '.' ], 'tests/fixtures/routes-file.php' ],
	'non-string base_path' => [ [ 'base_path' => true ], 'tests/fixtures/routes-file.php' ],
	'directory' => [ [ 'base_path' => __DIR__ ], 'fixtures' ],
	'other stream wrapper' => [ [ 'base_path' => __DIR__ ], 'file://' . __DIR__ . '/fixtures/routes-file.php' ],
	'drive-letter file off Windows' => [ [ 'base_path' => __DIR__ ], 'C:/routes-file.php' ],
	'drive-letter base_path off Windows' => [ [ 'base_path' => 'C:/' ], 'tests/fixtures/routes-file.php' ],
	'trailing backslash kept off Windows' => [ [ 'base_path' => __DIR__ . '/fixtures\\' ], 'routes-file.php' ],
] );

test( 'a drive-letter path off Windows is relative, so a CWD file named C: is never loaded', function() {
	if ( DIRECTORY_SEPARATOR === '\\' ) {
		$this->markTestSkipped( 'drive letters are absolute on Windows' );
	}
	$cwd = getcwd();
	$dir = sys_get_temp_dir() . '/ravlin-cwd-' . getmypid();
	mkdir( $dir . '/C:', 0o700, true );
	copy( __DIR__ . '/fixtures/routes-file.php', $dir . '/C:/routes-file.php' );
	chdir( $dir );
	$app = new App( [ 'base_path' => __DIR__ ] );
	[ , $warnings ] = capture_warnings( fn () => $app->load_routes( 'C:/routes-file.php' ) );
	$base = new App( [ 'base_path' => 'C:/' ] );
	[ , $base_warnings ] = capture_warnings( fn () => $base->load_routes( 'routes-file.php' ) );
	chdir( $cwd );
	unlink( $dir . '/C:/routes-file.php' );
	rmdir( $dir . '/C:' );
	rmdir( $dir );
	expect( [ count( $warnings ), count( $base_warnings ) ] )->toBe( [ 1, 1 ] )
		->and( $app->handle( new Request( 'GET', '/from-file' ) )->status )->toBe( 404 )
		->and( $base->handle( new Request( 'GET', '/from-file' ) )->status )->toBe( 404 );
} );

test( 'load_routes warns instead of failing for an unreadable file', function() {
	$file = tempnam( sys_get_temp_dir(), 'ravlin' );
	chmod( $file, 0 );
	if ( is_readable( $file ) ) {
		unlink( $file );
		$this->markTestSkipped( 'running with permissions that read mode 000 files' );
	}
	[ , $warnings ] = capture_warnings( fn () => ( new App() )->load_routes( $file ) );
	unlink( $file );
	expect( $warnings )->toHaveCount( 1 );
} );

test( 'load_routes reads routes from inside a phar, by absolute path and by a phar base_path', function() {
	$phar = sys_get_temp_dir() . '/ravlin-routes-' . getmypid() . '.phar';
	$build = escapeshellarg( PHP_BINARY ) . ' -d phar.readonly=0 -r ' . escapeshellarg(
		'$p = new Phar( ' . var_export( $phar, true ) . ' ); $p->addFromString( "routes.php", file_get_contents( ' . var_export( __DIR__ . '/fixtures/routes-file.php', true ) . ' ) );'
	);
	if ( ! function_exists( 'exec' ) ) {
		$this->markTestSkipped( 'exec() is disabled' );
	}
	exec( $build, $output, $code );
	expect( [ $code, is_file( $phar ) ] )->toBe( [ 0, true ] );
	$absolute = ( new App() )->load_routes( 'phar://' . $phar . '/routes.php' );
	$relative = ( new App( [ 'base_path' => 'phar://' . $phar ] ) )->load_routes( 'routes.php' );
	unlink( $phar );
	expect( $absolute->handle( new Request( 'GET', '/from-file' ) )->status )->toBe( 200 )
		->and( $relative->handle( new Request( 'GET', '/from-file' ) )->status )->toBe( 200 );
} );
