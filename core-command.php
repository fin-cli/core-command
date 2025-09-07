<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$fpcli_core_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $fpcli_core_autoloader ) ) {
	require_once $fpcli_core_autoloader;
}

WP_CLI::add_command( 'core', 'Core_Command' );
