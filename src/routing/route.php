<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

final class Route {
	private const OPTIONS = [ 'csrf' => 'bool', 'csp' => 'string' ];

	public private(set) array $options = [];

	public function __construct(
		public private(set) array $methods,
		public private(set) string $path,
		public private(set) mixed $handler,
	) {}

	public function set_option( string $name, mixed $value ) : self {
		$valid = ( self::OPTIONS[$name] ?? null ) === get_debug_type( $value ) && ( ! is_string( $value ) || preg_match( '/^[\x20-\x7E]+$/D', $value ) === 1 );
		if ( $valid ) {
			$this->options[$name] = $value;
		} else {
			trigger_error( 'Invalid route option skipped: ' . $name, E_USER_WARNING );
		}
		return $this;
	}

	public function get_option( string $name ) : mixed {
		$value = $this->options[$name] ?? null;
		return $value;
	}

	public function served_methods() : array {
		$methods = $this->methods;
		if ( $this->is_class() ) {
			$methods = [];
			foreach ( $this->methods as $method ) {
				if ( $this->find_method( $method ) !== null ) {
					$methods[] = $method;
				}
			}
		}
		return $methods;
	}

	public function class_method( string $method ) : ?string {
		$name = $this->is_class() ? $this->find_method( $method ) : null;
		return $name;
	}

	private function find_method( string $method ) : ?string {
		$name = null;
		$wanted = $method === 'HEAD' ? [ '__invoke', 'head', 'get' ] : [ '__invoke', strtolower( $method ) ];
		foreach ( $wanted as $candidate ) {
			if ( ( $candidate === '__invoke' || ! str_starts_with( $candidate, '__' ) ) && method_exists( $this->handler, $candidate ) ) {
				$name = $candidate;
				break;
			}
		}
		return $name;
	}

	private function is_class() : bool {
		$is_class = is_string( $this->handler ) && ! is_callable( $this->handler ) && class_exists( $this->handler );
		return $is_class;
	}
}
