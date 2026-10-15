<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

final class Router {
	private array $routes = [];

	public function add( array $methods, string $path, mixed $handler ) : Route {
		$methods = array_map( 'strtoupper', $methods );
		$route = new Route( $methods, $path, $handler );
		$this->routes[] = $route;
		return $route;
	}

	public function match( string $method, string $path ) : ?Route {
		$found = null;
		foreach ( $this->routes as $route ) {
			if ( $route->path === $path && in_array( $method, $route->methods, true ) ) {
				$found = $route;
				break;
			}
		}
		return $found;
	}
}
