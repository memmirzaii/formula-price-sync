<?php
/**
 * Formula Price Sync – Composer-compatible PSR-4 autoloader (production).
 */
spl_autoload_register( static function ( $class ) {
	$prefix = 'FormulaPriceSync\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require $file;
	}
} );
