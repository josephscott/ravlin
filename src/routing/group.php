<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

use Ravlin\App;

final class Group {
	use Shortcuts;

	private const PREFIX_PATTERN = '#^(/[^/%\x00-\x1F\x7F\x{80}-\x{9F}]+)+$#Du';

	/** @internal Use App::group() or Group::group(), which validate the prefix. */
	public function __construct(
		private App $app,
		public private(set) string $prefix,
	) {}

	public function route( array $methods, string $path, mixed $handler ) : Route {
		$full = $path === '' || str_starts_with( $path, '/' ) ? $this->prefix . $path : $path;
		$route = $this->app->route( $methods, $full, $handler );
		return $route;
	}

	public function group( string $prefix, callable $fn ) : self {
		if ( preg_match( self::PREFIX_PATTERN, $prefix ) === 1 ) {
			$fn( new self( $this->app, $this->prefix . $prefix ) );
		} else {
			trigger_error( 'Invalid group prefix skipped: ' . $prefix, E_USER_WARNING );
		}
		return $this;
	}
}
