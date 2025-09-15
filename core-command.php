<?php

if ( ! class_exists( 'FIN_CLI' ) ) {
	return;
}

$fincli_core_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $fincli_core_autoloader ) ) {
	require_once $fincli_core_autoloader;
}

FIN_CLI::add_command( 'core', 'Core_Command' );
