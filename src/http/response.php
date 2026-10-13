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
		if ( $this->valid_header( $name, $value ) ) {
			$this->headers[strtolower( $name )] = [ $value ];
		}
		return $this;
	}

	public function add_header( string $name, string $value ) : self {
		if ( $this->valid_header( $name, $value ) ) {
			$this->headers[strtolower( $name )][] = $value;
		}
		return $this;
	}

	public function remove_header( string $name ) : self {
		unset( $this->headers[strtolower( $name )] );
		return $this;
	}

	public function get_header( string $name ) : ?string {
		return $this->headers[strtolower( $name )][0] ?? null;
	}

	private function valid_header( string $name, string $value ) : bool {
		if ( ! preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name ) ) {
			trigger_error( 'Invalid header name', E_USER_WARNING );
			return false;
		}
		if ( strpbrk( $value, "\r\n\0" ) !== false ) {
			trigger_error( 'Invalid header value for ' . $name, E_USER_WARNING );
			return false;
		}
		return true;
	}
}
