<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

use Ravlin\Http\Response;

final class Router {
	private array $routes = [];

	public function add( array $methods, string $path, mixed $handler ) : Route {
		$valid = [];
		foreach ( $methods as $method ) {
			if ( is_string( $method ) && preg_match( Response::TOKEN_PATTERN, $method ) ) {
				$valid[] = strtoupper( $method );
			} else {
				trigger_error( 'Invalid HTTP method skipped for route: ' . $path, E_USER_WARNING );
			}
		}
		$route = new Route( $valid, $path, $handler );
		$this->routes[] = $route;
		return $route;
	}

	public function match( string $method, string $path ) : ?Route {
		$found = null;
		foreach ( $this->routes as $route ) {
			if ( $this->path_matches( $route, $path ) && in_array( $method, $route->methods, true ) ) {
				$found = $route;
				break;
			}
		}
		return $found;
	}

	public function allowed_methods( string $path ) : array {
		$methods = [];
		foreach ( $this->routes as $route ) {
			if ( $this->path_matches( $route, $path ) ) {
				array_push( $methods, ...$route->methods );
			}
		}
		if ( $methods !== [] ) {
			if ( in_array( 'GET', $methods, true ) ) {
				$methods[] = 'HEAD';
			}
			$methods[] = 'OPTIONS';
		}
		$methods = array_values( array_unique( $methods ) );
		return $methods;
	}

	private function path_matches( Route $route, string $path ) : bool {
		$matches = $route->path === $path;
		return $matches;
	}
}
