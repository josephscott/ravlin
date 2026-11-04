<?php
declare( strict_types = 1 );

require __DIR__ . '/../../../vendor/autoload.php';

$app = new Ravlin\App( [ 'base_path' => dirname( __DIR__ ) ] );
$app->load_routes( 'routes.php' );
Ravlin\Runtime\Sapi::run( $app );
