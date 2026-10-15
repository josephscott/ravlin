<?php
declare( strict_types = 1 );

require __DIR__ . '/../../../vendor/autoload.php';

$app = new Ravlin\App();
Ravlin\Runtime\Sapi::run( $app );
