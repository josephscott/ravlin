<?php
declare( strict_types = 1 );

namespace Ravlin;

use Ravlin\Http\Request;
use Ravlin\Http\Response;
use Ravlin\Routing\Route;
use Ravlin\Routing\Router;

final class App {
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

	public function get( string $path, mixed $handler ) : Route {
		$route = $this->route( [ 'GET' ], $path, $handler );
		return $route;
	}

	public function post( string $path, mixed $handler ) : Route {
		$route = $this->route( [ 'POST' ], $path, $handler );
		return $route;
	}

	public function put( string $path, mixed $handler ) : Route {
		$route = $this->route( [ 'PUT' ], $path, $handler );
		return $route;
	}

	public function patch( string $path, mixed $handler ) : Route {
		$route = $this->route( [ 'PATCH' ], $path, $handler );
		return $route;
	}

	public function delete( string $path, mixed $handler ) : Route {
		$route = $this->route( [ 'DELETE' ], $path, $handler );
		return $route;
	}

	public function handle( Request $req ) : Response {
		$route = $this->router->match( $req->method, $req->path );
		if ( $route === null ) {
			$res = new Response( 'Not Found', 404 );
			return $res;
		}
		$handler = $route->handler;
		if ( ! is_callable( $handler ) ) {
			trigger_error( 'Route handler is not callable: ' . $route->path, E_USER_WARNING );
			$res = new Response( '', 500 );
			return $res;
		}
		$result = $handler( $req );
		$res = $this->to_response( $result );
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
