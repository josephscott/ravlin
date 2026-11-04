<?php
declare( strict_types = 1 );

require __DIR__ . '/../../../vendor/autoload.php';

use Ravlin\Http\Request;

$app = new Ravlin\App();
$app->get( '/', fn ( Request $req ) => '<h1>Hello from Ravlin</h1>' );
$app->get( '/json', fn ( Request $req ) => [ 'framework' => 'ravlin', 'method' => $req->method ] );
$app->get( '/hello/{name}', fn ( Request $req ) => [ 'hello' => $req->get_param( 'name' ) ] );
Ravlin\Runtime\Sapi::run( $app );
