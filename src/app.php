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

	public function handle( Request $req ) : Response {
		$res = new Response( 'Not Found', 404 );
		return $res;
	}
}
