<?php
declare( strict_types = 1 );

namespace Ravlin\Handling;

use Ravlin\Http\Request;
use Ravlin\Http\Response;

/** @internal Used by App::handle(): adds strict security headers to every response, without replacing headers the handler set. */
final class SecurityHeaders {
	private const DEFAULTS = [
		'content-security-policy' => "default-src 'self'; script-src 'self' 'nonce-{nonce}'; style-src 'self' 'nonce-{nonce}'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
		'x-content-type-options' => 'nosniff',
		'referrer-policy' => 'strict-origin-when-cross-origin',
		'cross-origin-opener-policy' => 'same-origin',
		'cross-origin-resource-policy' => 'same-origin',
		'strict-transport-security' => 'max-age=31536000',
	];

	private const VALUE_PATTERN = '/^[\x21-\x7E](?:[\x20-\x7E]*[\x21-\x7E])?$/D';

	private array $headers;

	public function __construct( mixed $config ) {
		$headers = $config === false ? [] : self::DEFAULTS;
		if ( is_array( $config ) ) {
			foreach ( $config as $name => $value ) {
				$key = strtolower( (string) $name );
				if ( isset( self::DEFAULTS[$key] ) && ( $value === false || ( is_string( $value ) && preg_match( self::VALUE_PATTERN, $value ) === 1 ) ) ) {
					$headers[$key] = $value;
				} else {
					trigger_error( 'Invalid security_headers entry skipped: ' . $name, E_USER_WARNING );
				}
			}
		} elseif ( ! is_bool( $config ) ) {
			trigger_error( 'Invalid security_headers config ignored, the default headers stay on', E_USER_WARNING );
		}
		$this->headers = array_filter( $headers, is_string( ... ) );
	}

	public static function with_nonce( string $value, Request $req ) : string {
		$value = str_replace( '{nonce}', $req->csp_nonce, $value );
		return $value;
	}

	public function apply( Response $res, Request $req ) : void {
		foreach ( $this->headers as $name => $value ) {
			$skip = $res->get_header( $name ) !== null || ( $name === 'strict-transport-security' && ! $this->is_https( $req ) );
			if ( ! $skip ) {
				$res->set_header( $name, self::with_nonce( $value, $req ) );
			}
		}
	}

	private function is_https( Request $req ) : bool {
		$forwarded = strtolower( trim( explode( ',', $req->get_header( 'x-forwarded-proto' ) ?? '' )[0] ) );
		$https = $req->https || $forwarded === 'https';
		return $https;
	}
}
