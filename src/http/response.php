<?php
declare( strict_types = 1 );

namespace Ravlin\Http;

final class Response {
	public private(set) array $headers = [];

	public function __construct(
		public private(set) string $body = '',
		public private(set) int $status = 200,
	) {}

	public function set_header( string $name, string $value ) : self {
		$this->headers[strtolower( $name )] = [ $value ];
		return $this;
	}
}
