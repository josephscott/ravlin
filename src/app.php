<?php
declare( strict_types = 1 );

namespace Ravlin;

use Ravlin\Http\Request;
use Ravlin\Http\Response;

final class App {
	public function __construct(
		public private(set) array $config = [],
	) {}

	public function handle( Request $req ) : Response {
		$res = new Response( 'Not Found', 404 );
		return $res;
	}
}
