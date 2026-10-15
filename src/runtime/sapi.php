<?php
declare( strict_types = 1 );

namespace Ravlin\Runtime;

use Ravlin\App;
use Ravlin\Http\Request;
use Ravlin\Http\Response;

final class Sapi {
	public static function run( App $app ) : void {
		$req = self::request();
		$res = $app->handle( $req );
		self::send( $res );
	}

	public static function request() : Request {
		$headers = [];
		foreach ( $_SERVER as $key => $value ) {
			if ( str_starts_with( $key, 'HTTP_' ) ) {
				$headers[str_replace( '_', '-', substr( $key, 5 ) )] = $value;
			}
		}
		foreach ( [ 'CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length' ] as $key => $name ) {
			if ( isset( $_SERVER[$key] ) ) {
				$headers[$name] = $_SERVER[$key];
			}
		}
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$path = explode( '?', $_SERVER['REQUEST_URI'] ?? '/', 2 )[0];
		$body = (string) file_get_contents( 'php://input' );
		$req = new Request( $method, $path, $_GET, $_POST, $_COOKIE, $headers, $body );
		return $req;
	}

	public static function send( Response $res ) : void {
		http_response_code( $res->status );
		foreach ( $res->headers as $name => $values ) {
			foreach ( $values as $value ) {
				header( $name . ': ' . $value, false );
			}
		}
		echo $res->body;
	}
}
