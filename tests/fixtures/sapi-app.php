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
$app->get( '/throw', fn () => throw new RuntimeException( 'SECRET' ) );
Ravlin\Runtime\Sapi::run( $app );
