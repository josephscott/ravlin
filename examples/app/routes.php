<?php
declare( strict_types = 1 );

use Ravlin\Http\Request;
use Ravlin\Routing\Group;

/** @var Ravlin\App $app */
$app->get( '/', fn ( Request $req ) => '<h1>Hello from Ravlin</h1>' );
$app->group( '/api', function( Group $api ) {
	$api->get( '/json', fn ( Request $req ) => [ 'framework' => 'ravlin', 'method' => $req->method ] );
	$api->get( '/hello/{name}', fn ( Request $req ) => [ 'hello' => $req->get_param( 'name' ) ] );
	$api->get( '/posts/{id:int}', fn ( Request $req ) => [ 'post' => $req->get_param( 'id' ) ] );
} );
