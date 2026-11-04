<?php
declare( strict_types = 1 );

namespace Ravlin\Handling;

use Ravlin\Http\Response;

/** @internal Used by App::handle(): turns an uncaught Throwable into a logged 500. */
final class ErrorHandler {
	private const MAX_CHAIN = 10;

	private const MAX_FRAMES = 50;

	private const MAX_MESSAGE = 8192;

	public function __construct(
		private bool $debug,
		private ErrorLog $log,
	) {}

	public function response( \Throwable $e ) : Response {
		$chain = $this->chain( $e );
		$lines = [];
		foreach ( $chain as $t ) {
			$lines[] = $this->describe( $t ) . ' ' . implode( ' ', $this->frames( $t ) );
		}
		$this->log->write( 'uncaught ' . implode( ' | previous: ', $lines ) );
		if ( $this->debug ) {
			$res = Response::html( $this->page( $chain ), 500 )->set_header( 'Cache-Control', 'no-store' );
		} else {
			$res = Response::server_error();
		}
		return $res;
	}

	private function chain( \Throwable $e ) : array {
		$chain = [];
		for ( $t = $e; $t !== null && count( $chain ) < self::MAX_CHAIN; $t = $t->getPrevious() ) {
			$chain[] = $t;
		}
		return $chain;
	}

	private function describe( \Throwable $t ) : string {
		$message = $t->getMessage();
		if ( strlen( $message ) > self::MAX_MESSAGE ) {
			$message = substr( $message, 0, self::MAX_MESSAGE ) . '...[truncated]';
		}
		$text = $t::class . ': ' . $message . ' in ' . $t->getFile() . ':' . $t->getLine();
		return $text;
	}

	private function frames( \Throwable $t ) : array {
		$trace = $t->getTrace();
		$frames = [];
		foreach ( array_slice( $trace, 0, self::MAX_FRAMES ) as $i => $f ) {
			$frames[] = '#' . $i . ' ' . ( $f['file'] ?? '[internal]' ) . ':' . ( $f['line'] ?? 0 ) . ' ' . ( $f['class'] ?? '' ) . ( $f['type'] ?? '' ) . $f['function'] . '()';
		}
		if ( count( $trace ) > self::MAX_FRAMES ) {
			$frames[] = '... ' . ( count( $trace ) - self::MAX_FRAMES ) . ' more frames';
		}
		return $frames;
	}

	private function page( array $chain ) : string {
		$html = '<!doctype html><meta charset="utf-8"><title>500 Internal Server Error</title><h1>500 Internal Server Error</h1>';
		foreach ( $chain as $t ) {
			$html .= '<h2>' . $this->e( $t::class ) . '</h2><pre>' . $this->e( $this->describe( $t ) ) . '</pre><ol start="0">';
			foreach ( $this->frames( $t ) as $frame ) {
				$html .= '<li>' . $this->e( $frame ) . '</li>';
			}
			$html .= '</ol>';
		}
		return $html;
	}

	private function e( string $text ) : string {
		$escaped = htmlspecialchars( addcslashes( $text, "\0..\10\13..\37\177" ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		return $escaped;
	}
}
