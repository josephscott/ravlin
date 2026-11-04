<?php
declare( strict_types = 1 );

namespace Ravlin\Http;

final class Request {
	public private(set) array $params = [];

	public private(set) string $csp_nonce = '';

	public function __construct(
		public private(set) string $method = 'GET',
		public private(set) string $path = '/',
		public private(set) array $query = [],
		public private(set) array $form = [],
		public private(set) array $cookies = [],
		public private(set) array $headers = [],
		public private(set) string $body = '',
		public private(set) bool $https = false,
	) {
		$this->method = strtoupper( $method );
		$this->headers = [];
		foreach ( $headers as $name => $value ) {
			$values = is_array( $value ) && $value !== [] ? array_values( $value ) : [ $value ];
			$strings = array_map( fn ( $v ) => is_string( $v ) ? $v : '', $values );
			if ( $strings !== $values ) {
				trigger_error( 'Request header values must be strings, replaced with an empty string: ' . $name, E_USER_WARNING );
			}
			$this->headers[strtolower( (string) $name )] = $strings;
		}
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

	/** @internal Used by App to give each request its CSP nonce. */
	public function with_csp_nonce( string $nonce ) : self {
		$req = clone $this;
		$req->csp_nonce = $nonce;
		return $req;
	}

	public function get_cookie( string $name ) : string|array|null {
		$value = $this->cookies[$name] ?? null;
		return $value;
	}
}
