<?php
declare( strict_types = 1 );

namespace Ravlin\Handling;

/** @internal One-line, escaped, size-capped writes to error_log, and the PHP error handler runtimes install. */
final class ErrorLog {
	private const MAX_LINE = 16384;

	public function write( string $text ) : void {
		$line = addcslashes( substr( $text, 0, self::MAX_LINE ), "\0..\37\177\\" );
		if ( strlen( $text ) > self::MAX_LINE || strlen( $line ) > self::MAX_LINE ) {
			$line = substr( $line, 0, self::MAX_LINE ) . '...[truncated]';
		}
		error_log( 'ravlin: ' . $line );
	}

	public function install() : ?\Closure {
		$handler = $this->handle_error( ... );
		if ( set_error_handler( $handler ) !== null ) {
			restore_error_handler();
			$handler = null;
		}
		return $handler;
	}

	public function uninstall( ?\Closure $handler ) : void {
		if ( $handler !== null ) {
			$current = set_error_handler( null );
			restore_error_handler();
			if ( $current === $handler ) {
				restore_error_handler();
			}
		}
	}

	private function handle_error( int $no, string $message, string $file = '', int $line = 0 ) : bool {
		$handled = ( error_reporting() & $no ) !== 0 && $no !== E_USER_ERROR && $no !== E_RECOVERABLE_ERROR;
		if ( $handled ) {
			$level = match ( $no ) {
				E_WARNING, E_USER_WARNING => 'Warning',
				E_NOTICE, E_USER_NOTICE => 'Notice',
				E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
				default => 'Error',
			};
			$this->write( 'PHP ' . $level . ': ' . $message . ' in ' . $file . ':' . $line );
		}
		return $handled;
	}
}
