<?php
/**
 * Admin tools: test connection, fetch dictionary, reset key, manual submit, bulk product sync.
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Admin {

	public static function init() {
		add_action( 'admin_post_nexa_efris_test', array( __CLASS__, 'action_test' ) );
		add_action( 'admin_post_nexa_efris_dictionary', array( __CLASS__, 'action_dictionary' ) );
		add_action( 'admin_post_nexa_efris_reset_key', array( __CLASS__, 'action_reset_key' ) );
		add_action( 'admin_post_nexa_efris_submit', array( __CLASS__, 'action_submit' ) );
		add_action( 'admin_post_nexa_efris_branches', array( __CLASS__, 'action_branches' ) );
		add_action( 'admin_post_nexa_efris_cn_apply', array( __CLASS__, 'action_cn_apply' ) );
		add_action( 'admin_post_nexa_efris_cn_check', array( __CLASS__, 'action_cn_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
	}

	private static function tool_url( $action ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), 'nexa_efris_tools' );
	}

	public static function render_tools() {
		$dict  = get_option( 'nexa_efris_dictionary' );
		$units = Nexa_EFRIS_Settings::units();
		echo '<h2>' . esc_html__( 'Tools', 'nexa-efris' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Save your settings first, then use these.', 'nexa-efris' ) . '</p><p>';
		echo '<a class="button" href="' . esc_url( self::tool_url( 'nexa_efris_test' ) ) . '">' . esc_html__( 'Test connection (T101 / T103)', 'nexa-efris' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::tool_url( 'nexa_efris_dictionary' ) ) . '">' . esc_html__( 'Fetch EFRIS dictionary (T115)', 'nexa-efris' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::tool_url( 'nexa_efris_branches' ) ) . '">' . esc_html__( 'Fetch branches (T138)', 'nexa-efris' ) . '</a> ';
		if ( Nexa_EFRIS_Settings::is_direct() ) {
			echo '<a class="button" href="' . esc_url( self::tool_url( 'nexa_efris_reset_key' ) ) . '">' . esc_html__( 'Refresh AES key (T104)', 'nexa-efris' ) . '</a>';
		}
		echo '</p>';

		if ( $dict ) {
			/* translators: %d: number of units */
			echo '<p>' . esc_html( sprintf( __( 'Dictionary cached. %d units of measure available.', 'nexa-efris' ), count( $units ) ) ) . '</p>';
			if ( $units ) {
				echo '<details><summary>' . esc_html__( 'Show unit codes', 'nexa-efris' ) . '</summary><table class="widefat striped" style="max-width:480px">';
				foreach ( $units as $code => $label ) {
					echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $label ) . '</td></tr>';
				}
				echo '</table></details>';
			}
		}
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nexa-efris' ) );
		}
		check_admin_referer( 'nexa_efris_tools' );
	}

	private static function back( $type, $msg, $url = null ) {
		set_transient( 'nexa_efris_notice_' . get_current_user_id(), array( $type, $msg ), 60 );
		wp_safe_redirect( $url ? $url : admin_url( 'admin.php?page=wc-settings&tab=nexa_efris' ) );
		exit;
	}

	public static function action_test() {
		self::guard();
		$c    = new Nexa_EFRIS_Client();
		$time = $c->server_time();
		if ( is_wp_error( $time ) ) {
			self::back( 'error', 'T101 failed: ' . $time->get_error_message() );
		}
		$server_time = isset( $time['currentTime'] ) ? $time['currentTime'] : wp_json_encode( $time );

		$info = $c->taxpayer_info();
		if ( is_wp_error( $info ) ) {
			self::back( 'warning', 'Connected (server time ' . $server_time . ') but T103 failed: ' . $info->get_error_message() );
		}

		// Fill empty seller fields from the taxpayer record.
		$tp  = isset( $info['taxpayer'] ) ? $info['taxpayer'] : $info;
		$map = array(
			'legal_name'    => 'legalName',
			'business_name' => 'businessName',
			'address'       => 'address',
			'phone'         => 'contactMobile',
			'email'         => 'contactEmail',
			'brn'           => 'ninBrn',
		);
		$filled = array();
		foreach ( $map as $opt => $field ) {
			if ( '' === Nexa_EFRIS_Settings::get( $opt ) && ! empty( $tp[ $field ] ) ) {
				update_option( 'nexa_efris_' . $opt, sanitize_text_field( $tp[ $field ] ) );
				$filled[] = $opt;
			}
		}

		$msg = 'EFRIS connection OK. Server time: ' . $server_time . '.';
		if ( ! empty( $tp['legalName'] ) ) {
			$msg .= ' Taxpayer: ' . $tp['legalName'] . '.';
		}
		if ( $filled ) {
			$msg .= ' Filled: ' . implode( ', ', $filled ) . '.';
		}

		if ( Nexa_EFRIS_Settings::is_direct() ) {
			$key = $c->get_aes_key( true );
			$msg .= is_wp_error( $key ) ? ' AES key: FAILED – ' . $key->get_error_message() : ' AES key OK.';
		}
		self::back( 'success', $msg );
	}

	public static function action_dictionary() {
		self::guard();
		$d = ( new Nexa_EFRIS_Client() )->dictionary();
		if ( is_wp_error( $d ) ) {
			self::back( 'error', 'T115 failed: ' . $d->get_error_message() );
		}
		update_option( 'nexa_efris_dictionary', $d, false );
		self::back( 'success', 'EFRIS dictionary saved (' . count( $d ) . ' sections).' );
	}

	public static function action_branches() {
		self::guard();
		$b = ( new Nexa_EFRIS_Client() )->call( 'T138' );
		if ( is_wp_error( $b ) ) {
			self::back( 'error', 'T138 failed: ' . $b->get_error_message() );
		}
		update_option( 'nexa_efris_branches', $b, false );
		self::back( 'success', sprintf( 'Saved %d branch(es). Pick one in “Branch” above.', count( Nexa_EFRIS_Settings::branches() ) ) );
	}

	private static function refund_guard() {
		$refund_id = isset( $_GET['refund_id'] ) ? absint( $_GET['refund_id'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nexa-efris' ) );
		}
		check_admin_referer( 'nexa_efris_cn_' . $refund_id );
		$refund = wc_get_order( $refund_id );
		$order  = $refund ? wc_get_order( $refund->get_parent_id() ) : null;
		return array( $refund_id, $order ? $order->get_edit_order_url() : admin_url() );
	}

	public static function action_cn_apply() {
		list( $id, $back ) = self::refund_guard();
		$r = Nexa_EFRIS_Credit_Note::apply( $id );
		is_wp_error( $r )
			? self::back( 'error', 'Credit note: ' . $r->get_error_message(), $back )
			: self::back( 'success', 'Credit note applied. URA must approve it; status is checked hourly.', $back );
	}

	public static function action_cn_check() {
		list( $id, $back ) = self::refund_guard();
		$s = Nexa_EFRIS_Credit_Note::check( $id, false );
		is_wp_error( $s ) ? self::back( 'error', $s->get_error_message(), $back ) : self::back( 'info', 'Credit note status: ' . $s, $back );
	}

	public static function action_reset_key() {
		self::guard();
		$key = ( new Nexa_EFRIS_Client() )->get_aes_key( true );
		is_wp_error( $key ) ? self::back( 'error', $key->get_error_message() ) : self::back( 'success', 'New AES key obtained.' );
	}

	public static function action_submit() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nexa-efris' ) );
		}
		check_admin_referer( 'nexa_efris_submit_' . $order_id );
		$order = wc_get_order( $order_id );
		$back  = $order ? $order->get_edit_order_url() : admin_url();
		if ( ! $order ) {
			self::back( 'error', 'Order not found.', $back );
		}
		$res = Nexa_EFRIS_Invoice::submit( $order );
		is_wp_error( $res )
			? self::back( 'error', 'EFRIS: ' . $res->get_error_message(), $back )
			: self::back( 'success', 'EFRIS: order fiscalised. FDN ' . $order->get_meta( '_nexa_efris_fdn' ), $back );
	}

	public static function bulk_actions( $actions ) {
		$actions['nexa_efris_sync'] = __( 'Register in EFRIS', 'nexa-efris' );
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'nexa_efris_sync' !== $action ) {
			return $redirect;
		}
		$ok   = 0;
		$fail = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$r = Nexa_EFRIS_Product::sync( $p );
			if ( is_wp_error( $r ) ) {
				$fail[] = $p->get_name() . ': ' . $r->get_error_message();
			} else {
				++$ok;
			}
		}
		$msg = sprintf( 'EFRIS: %d product(s) registered.', $ok );
		if ( $fail ) {
			$msg .= ' Failed – ' . implode( ' | ', array_slice( $fail, 0, 5 ) );
		}
		set_transient( 'nexa_efris_notice_' . get_current_user_id(), array( $fail ? 'warning' : 'success', $msg ), 60 );
		return $redirect;
	}

	public static function notices() {
		$key = 'nexa_efris_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( $n ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}

		if ( Nexa_EFRIS_Settings::is_on( 'enabled' ) && ( '' === Nexa_EFRIS_Settings::get( 'tin' ) || '' === Nexa_EFRIS_Settings::get( 'device_no' ) ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Nexa EFRIS is enabled but TIN or device number is missing.', 'nexa-efris' ) . '</p></div>';
		}
	}
}
