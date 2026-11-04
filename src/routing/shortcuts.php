<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

trait Shortcuts {
	abstract public function route( array $methods, string $path, mixed $handler ) : Route;

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
}
