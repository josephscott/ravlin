<?php
declare( strict_types = 1 );

$file_vars = array_keys( get_defined_vars() );
$has_this = isset( $this );
$sees_private = isset( $app->router );
$app->get( '/from-file', fn () => [ 'this' => $has_this, 'private' => $sees_private, 'vars' => $file_vars ] );
