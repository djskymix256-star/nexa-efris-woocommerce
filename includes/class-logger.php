<?php
/**
 * Logs to WooCommerce > Status > Logs (source: nexa-efris).
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Logger {

	public static function log( $message, $level = 'info' ) {
		if ( 'debug' === $level && ! Nexa_EFRIS_Settings::is_on( 'debug' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, is_string( $message ) ? $message : wp_json_encode( $message ), array( 'source' => 'nexa-efris' ) );
		}
	}

	public static function error( $message ) {
		self::log( $message, 'error' );
	}

	public static function debug( $message ) {
		self::log( $message, 'debug' );
	}
}
