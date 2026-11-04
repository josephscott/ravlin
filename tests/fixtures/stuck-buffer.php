<?php
declare( strict_types = 1 );

require __DIR__ . '/../../vendor/autoload.php';

$app = new Ravlin\App();
$app->get( '/x', function() {
	ob_start( null, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE );
	echo 'stuck';
	return 'ok';
} );
set_error_handler( fn () => true );
$r = $app->handle( new Ravlin\Http\Request( 'GET', '/x' ) );
echo 'status=' . $r->status . ';after';
