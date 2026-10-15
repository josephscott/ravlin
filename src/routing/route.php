<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

final class Route {
	public private(set) array $options = [];

	public function __construct(
		public private(set) array $methods,
		public private(set) string $path,
		public private(set) mixed $handler,
	) {}
}
