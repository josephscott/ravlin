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
