<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

use Ravlin\Http\Response;

final class Router {
	private const PARAM_PATTERN = '/^\{([A-Za-z_][A-Za-z0-9_]*)(?::(.+))?\}$/Ds';

	private const TYPES = [ 'int' => '[0-9]+' ];

	private const SAFE_PATH_PATTERN = '/^[^\x00-\x1F\x7F\x{80}-\x{9F}]*$/Du';

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
		$compiled = $this->compile( $path );
		if ( $compiled === null ) {
			trigger_error( 'Invalid route path skipped: ' . $path, E_USER_WARNING );
		} elseif ( $valid === [] ) {
			trigger_error( 'Route with no valid HTTP methods skipped: ' . $path, E_USER_WARNING );
		} else {
			$this->routes[count( $compiled['segments'] )][] = [ 'route' => $route ] + $compiled;
		}
		return $route;
	}

	public function match( string $method, string $path ) : Matched {
		$segments = $this->decode( $path );
		$found = null;
		$fallback = null;
		$allowed = [];
		foreach ( $segments === null ? [] : ( $this->routes[count( $segments )] ?? [] ) as $entry ) {
			$params = $this->match_segments( $entry, $segments );
			if ( $params === false ) {
				$fallback = null;
				$allowed = [];
				break;
			}
			if ( $params === null ) {
				continue;
			}
			$methods = $entry['route']->methods;
			if ( in_array( $method, $methods, true ) ) {
				$found = new Matched( $entry['route'], $params );
				break;
			}
			if ( $fallback === null && $method === 'HEAD' && in_array( 'GET', $methods, true ) ) {
				$fallback = new Matched( $entry['route'], $params );
			}
			array_push( $allowed, ...$methods );
		}
		$matched = $found ?? $fallback ?? new Matched( null, [], $this->with_implicit( $allowed ) );
		return $matched;
	}

	private function compile( string $path ) : ?array {
		$segments = explode( '/', $path );
		$params = [];
		$constraints = [];
		$valid = str_starts_with( $path, '/' ) && preg_match( self::SAFE_PATH_PATTERN, $path ) === 1;
		foreach ( $segments as $i => $segment ) {
			if ( preg_match( self::PARAM_PATTERN, $segment, $m ) ) {
				$valid = $valid && ! in_array( $m[1], $params, true );
				$params[$i] = $m[1];
				if ( isset( $m[2] ) ) {
					$constraints[$m[1]] = $this->constraint( $m[2] );
					$valid = $valid && $constraints[$m[1]] !== null;
				}
			} elseif ( strpbrk( $segment, '{}%' ) !== false ) {
				$valid = false;
			}
		}
		$compiled = $valid ? [ 'segments' => $segments, 'params' => $params, 'constraints' => $constraints ] : null;
		return $compiled;
	}

	private function constraint( string $pattern ) : ?string {
		$regex = '#\A(?:' . ( self::TYPES[$pattern] ?? $pattern ) . ')\z#u';
		set_error_handler( fn () => true );
		$compiles = ! str_contains( $pattern, '#' ) && preg_match( $regex, '' ) !== false;
		restore_error_handler();
		if ( ! $compiles ) {
			$regex = null;
		}
		return $regex;
	}

	private function decode( string $path ) : ?array {
		$segments = explode( '/', $path );
		if ( str_contains( $path, '%' ) ) {
			$segments = array_map( rawurldecode( ... ), $segments );
		}
		if ( preg_match( self::SAFE_PATH_PATTERN, implode( '/', $segments ) ) !== 1 ) {
			$segments = null;
		}
		return $segments;
	}

	private function match_segments( array $entry, array $segments ) : array|false|null {
		$params = null;
		if ( $entry['params'] === [] ) {
			$params = $segments === $entry['segments'] ? [] : null;
		} else {
			$params = [];
			foreach ( $entry['segments'] as $i => $segment ) {
				$name = $entry['params'][$i] ?? null;
				$ok = $name === null ? $segment === $segments[$i] : $segments[$i] !== '';
				if ( ! $ok ) {
					$params = null;
					break;
				}
				if ( $name !== null ) {
					$params[$name] = $segments[$i];
				}
			}
			foreach ( $params === null ? [] : $entry['constraints'] as $name => $regex ) {
				$result = preg_match( $regex, $params[$name], $m );
				if ( $result === false ) {
					trigger_error( 'Route constraint failed, ' . preg_last_error_msg() . ': ' . $entry['route']->path, E_USER_WARNING );
					$params = false;
					break;
				}
				if ( $result !== 1 || $m[0] !== $params[$name] ) {
					$params = null;
					break;
				}
			}
		}
		return $params;
	}

	private function with_implicit( array $methods ) : array {
		if ( $methods !== [] ) {
			if ( in_array( 'GET', $methods, true ) ) {
				$methods[] = 'HEAD';
			}
			$methods[] = 'OPTIONS';
		}
		$methods = array_values( array_unique( $methods ) );
		return $methods;
	}
}
