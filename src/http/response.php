<?php
declare( strict_types = 1 );

namespace Ravlin\Http;

final class Response {
	public function __construct(
		public private(set) string $body = '',
		public private(set) int $status = 200,
		public private(set) array $headers = [],
	) {}
}
