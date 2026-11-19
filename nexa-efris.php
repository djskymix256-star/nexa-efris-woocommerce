<?php
/**
 * Plugin Name:       Nexa EFRIS for WooCommerce
 * Plugin URI:        https://github.com/djskymix256-star/nexa-efris-woocommerce
 * Description:       Fiscalise WooCommerce orders with URA EFRIS (Uganda): register products, issue e-invoices/e-receipts, and show the FDN, verification code and QR code on orders and emails.
 * Version:           0.2.0
 * Author:            Nexa Blaze
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Text Domain:       nexa-efris
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXA_EFRIS_VERSION', '0.2.0' );
define( 'NEXA_EFRIS_FILE', __FILE__ );
define( 'NEXA_EFRIS_DIR', plugin_dir_path( __FILE__ ) );

// Declare HPOS + checkout blocks compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Nexa EFRIS needs WooCommerce to be active.', 'nexa-efris' ) . '</p></div>';
				}
			);
			return;
		}

		require_once NEXA_EFRIS_DIR . 'includes/class-settings.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-logger.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-client.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-product.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-invoice.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-credit-note.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-qr.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-display.php';
		require_once NEXA_EFRIS_DIR . 'includes/class-admin.php';

		Nexa_EFRIS_Product::init();
		Nexa_EFRIS_Invoice::init();
		Nexa_EFRIS_Credit_Note::init();
		Nexa_EFRIS_Display::init();
		Nexa_EFRIS_Admin::init();
	}
);

add_filter(
	'woocommerce_get_settings_pages',
	function ( $pages ) {
		$pages[] = include NEXA_EFRIS_DIR . 'includes/class-settings-page.php';
		return $pages;
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=nexa_efris' ) ) . '">' . esc_html__( 'Settings', 'nexa-efris' ) . '</a>' );
		return $links;
	}
);
