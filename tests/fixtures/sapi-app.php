<?php
declare( strict_types = 1 );

require __DIR__ . '/../../vendor/autoload.php';

$app = new Ravlin\App();
$app->get( '/ok', fn () => 'ok' );
$app->get( '/exit', function() {
	echo 'SECRET';
	exit;
} );
$fatal = function() {
	echo 'SECRET';
	ini_set( 'memory_limit', '8M' );
	$a = str_repeat( 'x', 64 * 1024 * 1024 );
	return $a;
};
$app->get( '/fatal', function() use ( $fatal ) {
	ini_set( 'display_errors', '0' );
	return $fatal();
} );
$app->get( '/fatal-display', $fatal );
$app->get( '/warn', function() {
	trigger_error( 'SECRET warning', E_USER_WARNING );
	return 'ok';
} );
$app->post( '/post', fn () => 'posted' );
$app->route( [ 'GET', 'POST' ], '/echo', fn ( Ravlin\Http\Request $req ) => [
	'q' => $req->get_query( 'q' ),
	'f' => $req->get_form( 'f' ),
	'body' => $req->body,
	'content-type' => $req->get_header( 'content-type' ),
	'content-length' => $req->get_header( 'content-length' ),
	'host' => $req->get_header( 'host' ),
] );
$app->get( '/p/{v}', fn ( Ravlin\Http\Request $req ) => (string) $req->get_param( 'v' ) );
$app->get( '/throw', fn () => throw new RuntimeException( 'SECRET' ) );
Ravlin\Runtime\Sapi::run( $app );
