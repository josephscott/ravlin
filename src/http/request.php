<?php
declare( strict_types = 1 );

namespace Ravlin\Http;

final class Request {
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
		return $this->headers[strtolower( $name )][0] ?? null;
	}

	public function get_query( string $name ) : string|array|null {
		return $this->query[$name] ?? null;
	}

	public function get_form( string $name ) : string|array|null {
		return $this->form[$name] ?? null;
	}

	public function get_cookie( string $name ) : string|array|null {
		return $this->cookies[$name] ?? null;
	}
}
