<?php
declare( strict_types = 1 );

namespace Ravlin;

use Ravlin\Handling\Csrf;
use Ravlin\Handling\ErrorHandler;
use Ravlin\Handling\ErrorLog;
use Ravlin\Handling\SecurityHeaders;
use Ravlin\Http\Request;
use Ravlin\Http\Response;
use Ravlin\Routing\Group;
use Ravlin\Routing\Route;
use Ravlin\Routing\Router;
use Ravlin\Routing\Shortcuts;

final class App {
	use Shortcuts;

	private Router $router;

	private ErrorLog $log;

	private ErrorHandler $errors;

	private Csrf $csrf;

	private SecurityHeaders $security_headers;

	private bool $debug;

	public function __construct(
		public private(set) array $config = [],
	) {
		$this->router = new Router();
		$this->log = new ErrorLog();
		$this->debug = ( $config['debug'] ?? false ) === true;
		$this->errors = new ErrorHandler( $this->debug, $this->log );
		$csrf = $config['csrf'] ?? true;
		$valid = is_bool( $csrf ) || ( is_array( $csrf ) && array_diff( array_keys( $csrf ), [ 'trusted_origins' ] ) === []
			&& ( ! array_key_exists( 'trusted_origins', $csrf ) || is_array( $csrf['trusted_origins'] ) ) );
		if ( ! $valid ) {
			trigger_error( 'Invalid csrf config ignored, CSRF protection stays on', E_USER_WARNING );
			$csrf = true;
		}
		$this->csrf = new Csrf( $csrf !== false, is_array( $csrf ) ? ( $csrf['trusted_origins'] ?? [] ) : [] );
		$this->security_headers = new SecurityHeaders( $config['security_headers'] ?? true );
	}

	public function route( array $methods, string $path, mixed $handler ) : Route {
		$route = $this->router->add( $methods, $path, $handler );
		return $route;
	}

	public function group( string $prefix, callable $fn ) : self {
		( new Group( $this, '' ) )->group( $prefix, $fn );
		return $this;
	}

	public function load_routes( string $file ) : self {
		$path = $file;
		if ( ! $this->is_absolute( $file ) ) {
			$base = $this->config['base_path'] ?? null;
			$path = is_string( $base ) && $this->is_absolute( $base ) ? rtrim( $base, DIRECTORY_SEPARATOR === '\\' ? '/\\' : '/' ) . '/' . $file : null;
		}
		if ( $path === null || ! is_file( $path ) || ! is_readable( $path ) ) {
			trigger_error( 'Routes file not found or unreadable (relative paths need an absolute base_path): ' . $file, E_USER_WARNING );
		} else {
			$load = \Closure::bind( static function( App $app, string $routes_file ) : void {
				require $routes_file;
			}, null, null );
			$load( $this, $path );
		}
		return $this;
	}

	private function is_absolute( string $path ) : bool {
		$windows = DIRECTORY_SEPARATOR === '\\' && preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) === 1;
		$absolute = str_starts_with( $path, '/' ) || str_starts_with( $path, 'phar://' ) || $windows;
		return $absolute;
	}

	public function handle( Request $req ) : Response {
		$level = ob_get_level();
		$capture = new \ArrayObject( [ 'active' => true, 'ended' => false ] );
		ob_start( static function( string $buffer, int $phase ) use ( $capture ) : string {
			if ( $capture['active'] && ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
				$capture['ended'] = true;
			}
			$out = $capture['active'] ? '' : $buffer;
			return $out;
		} );
		try {
			$req = $req->with_csp_nonce( base64_encode( random_bytes( 16 ) ) );
			$res = $this->route_request( $req );
			$clean = $this->discard_output( $level, $capture );
		} catch ( \Throwable $e ) {
			$res = $this->errors->response( $e );
			$clean = $this->discard_output( $level, $capture );
		}
		if ( ! $clean ) {
			$res = Response::server_error();
		}
		$this->security_headers->apply( $res, $req );
		if ( $req->method === 'HEAD' ) {
			$res->set_body( '' );
		}
		return $res;
	}

	private function discard_output( int $level, \ArrayObject $capture ) : bool {
		$closed = $capture['ended'] === true;
		$capture['active'] = false;
		$stray = 0;
		$stuck = false;
		while ( ! $stuck && ob_get_level() > $level ) {
			$before = ob_get_level();
			$stray += strlen( (string) ob_get_clean() );
			$stuck = ob_get_level() === $before;
			if ( $stuck ) {
				$stray += strlen( (string) ob_get_contents() );
				ob_clean();
			}
		}
		$closed = $closed || ob_get_level() < $level;
		if ( $stuck ) {
			$this->log->write( 'could not remove an output buffer a handler opened; later output is no longer discarded' );
		}
		if ( $closed ) {
			$this->log->write( 'a handler closed output buffers it did not open; its later output may have been sent' );
		}
		if ( $stray > 0 ) {
			$this->log->write( 'discarded ' . $stray . ' bytes of output produced while handling a request' );
		}
		$clean = ! $stuck && ! $closed;
		return $clean;
	}

	private function route_request( Request $req ) : Response {
		$matched = $this->router->match( $req->method, $req->path );
		$reason = $matched->route === null ? null : $this->csrf->rejection( $req, $matched->route->get_option( 'csrf' ) );
		if ( $matched->route === null ) {
			$res = $this->no_route( $req, $matched->allowed );
		} elseif ( $reason !== null ) {
			if ( $this->debug ) {
				$this->log->write( 'CSRF rejected ' . $req->method . ' ' . $req->path . ': ' . $reason );
			}
			$res = ( new Response( 'Forbidden', 403 ) )->set_header( 'Content-Type', 'text/plain; charset=utf-8' );
		} else {
			$res = $this->dispatch( $matched->route, $req->with_params( $matched->params ) );
			$csp = $matched->route->get_option( 'csp' );
			if ( is_string( $csp ) && $res->get_header( 'content-security-policy' ) === null ) {
				$res->set_header( 'Content-Security-Policy', SecurityHeaders::with_nonce( $csp, $req ) );
			}
		}
		return $res;
	}

	private function no_route( Request $req, array $allowed ) : Response {
		if ( $allowed === [] ) {
			$res = ( new Response( 'Not Found', 404 ) )->set_header( 'Content-Type', 'text/plain; charset=utf-8' );
		} elseif ( $req->method === 'OPTIONS' ) {
			$res = ( new Response( '', 204 ) )->set_header( 'Allow', implode( ', ', $allowed ) );
		} else {
			$res = ( new Response( 'Method Not Allowed', 405 ) )->set_header( 'Allow', implode( ', ', $allowed ) )->set_header( 'Content-Type', 'text/plain; charset=utf-8' );
		}
		return $res;
	}

	private function dispatch( Route $route, Request $req ) : Response {
		$handler = $route->handler;
		$name = $route->class_method( $req->method );
		if ( $name !== null ) {
			$handler = [ new $handler( $this ), $name ];
		} elseif ( is_array( $handler ) && ! is_callable( $handler ) && array_is_list( $handler ) && count( $handler ) === 2 && is_string( $handler[0] ) && class_exists( $handler[0] ) ) {
			$handler = [ new $handler[0]( $this ), $handler[1] ];
		}
		if ( is_callable( $handler ) ) {
			$res = $this->to_response( $handler( $req ) );
		} else {
			trigger_error( 'Route handler is not callable: ' . $route->path, E_USER_WARNING );
			$res = Response::server_error();
		}
		return $res;
	}

	private function to_response( mixed $result ) : Response {
		if ( $result instanceof Response ) {
			$res = clone $result;
		} elseif ( is_string( $result ) ) {
			$res = Response::html( $result );
		} elseif ( is_array( $result ) ) {
			$res = Response::json( $result );
		} else {
			trigger_error( 'Handler returned an unsupported type: ' . get_debug_type( $result ), E_USER_WARNING );
			$res = Response::server_error();
		}
		return $res;
	}
}
