<?php
declare( strict_types = 1 );

namespace Ravlin\Http;

final class Request {
	public private(set) array $params = [];

	public function __construct(
		public private(set) string $method = 'GET',
		public private(set) string $path = '/',
		public private(set) array $query = [],
		public private(set) array $form = [],
		public private(set) array $cookies = [],
		public private(set) array $headers = [],
		public private(set) string $body = '',
	) {
		$this->method = strtoupper( $method );
		$this->headers = array_map( fn ( $v ) => (array) $v, array_change_key_case( $headers, CASE_LOWER ) );
	}

	public function get_header( string $name ) : ?string {
		$value = $this->headers[strtolower( $name )][0] ?? null;
		return $value;
	}

	public function get_query( string $name ) : string|array|null {
		$value = $this->query[$name] ?? null;
		return $value;
	}

	public function get_form( string $name ) : string|array|null {
		$value = $this->form[$name] ?? null;
		return $value;
	}

	public function get_param( string $name ) : ?string {
		$value = $this->params[$name] ?? null;
		return $value;
	}

	/** @internal Used by App to pass route params to the handler. */
	public function with_params( array $params ) : self {
		$req = clone $this;
		$req->params = [];
		foreach ( $params as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$req->params[$name] = $value;
			} else {
				trigger_error( 'Route param skipped, names and values must be strings', E_USER_WARNING );
			}
		}
		return $req;
	}

	public function get_cookie( string $name ) : string|array|null {
		$value = $this->cookies[$name] ?? null;
		return $value;
	}
}
