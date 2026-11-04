<?php
declare( strict_types = 1 );

namespace Ravlin\Handling;

use Ravlin\Http\Request;

/** @internal Cross-origin protection for unsafe methods, modeled on Go's net/http CrossOriginProtection (stricter where noted in notes/api.md). */
final class Csrf {
	private const SAFE_METHODS = [ 'GET', 'HEAD', 'OPTIONS' ];

	private const ORIGIN_PATTERN = '#^(https?)://([a-z0-9_](?:[a-z0-9_.-]*[a-z0-9_])?|\[[0-9a-f:.]+\])(?::([0-9]{1,5}))?$#D';

	private array $trusted = [];

	public function __construct(
		private bool $enabled,
		array $trusted_origins = [],
	) {
		foreach ( $trusted_origins as $origin ) {
			$normalized = is_string( $origin ) ? $this->normalize( $origin ) : null;
			if ( $normalized === null ) {
				trigger_error( 'Invalid trusted origin skipped (use scheme://host[:port], punycode for IDN): ' . ( is_string( $origin ) ? $origin : get_debug_type( $origin ) ), E_USER_WARNING );
			} else {
				$this->trusted[$normalized] = true;
			}
		}
	}

	public function rejection( Request $req, mixed $route_option ) : ?string {
		$reason = null;
		$enabled = is_bool( $route_option ) ? $route_option : $this->enabled;
		if ( $enabled && ! in_array( $req->method, self::SAFE_METHODS, true ) ) {
			$reason = $this->check( $req );
		}
		return $reason;
	}

	private function check( Request $req ) : ?string {
		$sites = $req->headers['sec-fetch-site'] ?? [];
		$origins = $req->headers['origin'] ?? [];
		$origin = $origins[0] ?? null;
		if ( count( $sites ) > 1 || count( $origins ) > 1 ) {
			$reason = 'more than one Sec-Fetch-Site or Origin header';
		} elseif ( $sites !== [] ) {
			$allowed = in_array( strtolower( $sites[0] ), [ 'same-origin', 'none' ], true ) || $this->is_trusted( $origin );
			$reason = $allowed ? null : 'Sec-Fetch-Site ' . $sites[0] . ' is not same-origin or none, and Origin ' . ( $origin ?? '(none)' ) . ' is not trusted';
		} elseif ( $origin === null ) {
			$reason = null;
		} else {
			$host = $req->get_header( 'host' );
			$allowed = $this->same_host( $origin, $host ) || $this->is_trusted( $origin );
			$reason = $allowed ? null : 'Origin ' . $origin . ' does not match Host ' . ( $host ?? '(none)' ) . ' and is not trusted';
		}
		return $reason;
	}

	private function same_host( string $origin, ?string $host ) : bool {
		$normalized = $this->normalize( $origin );
		$same = $normalized !== null && in_array( $normalized, [ $this->normalize( 'http://' . $host ), $this->normalize( 'https://' . $host ) ], true );
		return $same;
	}

	private function is_trusted( ?string $origin ) : bool {
		$normalized = $origin === null ? null : $this->normalize( $origin );
		$trusted = $normalized !== null && isset( $this->trusted[$normalized] );
		return $trusted;
	}

	private function normalize( string $origin ) : ?string {
		$normalized = null;
		$valid = preg_match( self::ORIGIN_PATTERN, strtolower( $origin ), $m ) === 1
			&& ( $m[2][0] !== '[' || filter_var( substr( $m[2], 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false );
		if ( $valid ) {
			$port = isset( $m[3] ) ? (int) $m[3] : null;
			if ( $port === null || $port === ( $m[1] === 'https' ? 443 : 80 ) ) {
				$normalized = $m[1] . '://' . $m[2];
			} elseif ( $port >= 1 && $port <= 65535 ) {
				$normalized = $m[1] . '://' . $m[2] . ':' . $port;
			}
		}
		return $normalized;
	}
}
