<?php
declare( strict_types = 1 );

namespace Ravlin\Routing;

final class Matched {
	public function __construct(
		public private(set) ?Route $route = null,
		public private(set) array $params = [],
		public private(set) array $allowed = [],
	) {}
}
