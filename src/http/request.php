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
}
