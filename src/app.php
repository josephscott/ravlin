<?php
declare( strict_types = 1 );

namespace Ravlin;

use Ravlin\Http\Request;
use Ravlin\Http\Response;
use Ravlin\Routing\Group;
use Ravlin\Routing\Route;
use Ravlin\Routing\Router;
use Ravlin\Routing\Shortcuts;

final class App {
	use Shortcuts;

	private Router $router;

	public function __construct(
		public private(set) array $config = [],
	) {
		$this->router = new Router();
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
		$matched = $this->router->match( $req->method, $req->path );
		if ( $matched->route === null ) {
			$res = $this->no_route( $req, $matched->allowed );
		} else {
			$res = $this->dispatch( $matched->route, $req->with_params( $matched->params ) );
		}
		if ( $req->method === 'HEAD' ) {
			$res->set_body( '' );
		}
		return $res;
	}

	private function no_route( Request $req, array $allowed ) : Response {
		if ( $allowed === [] ) {
			$res = new Response( 'Not Found', 404 );
		} elseif ( $req->method === 'OPTIONS' ) {
			$res = ( new Response( '', 204 ) )->set_header( 'Allow', implode( ', ', $allowed ) );
		} else {
			$res = ( new Response( 'Method Not Allowed', 405 ) )->set_header( 'Allow', implode( ', ', $allowed ) );
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
			$res = new Response( '', 500 );
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
			$res = new Response( '', 500 );
		}
		return $res;
	}
}
