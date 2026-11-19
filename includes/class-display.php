<?php
/**
 * Order meta box, order list column, customer-facing output and the buyer TIN checkout field.
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Display {

	const BLOCK_TIN_FIELD = 'nexa-efris/buyer-tin';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

		// Order list column (HPOS + legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		// Customer output.
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'customer_block' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_block' ), 10, 4 );

		// Buyer TIN at checkout (classic + blocks).
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'classic_tin_field' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_tin_field' ) );
	}

	/* ------------------------------------------------------------------
	 * Buyer TIN
	 * ------------------------------------------------------------------ */

	public static function classic_tin_field( $fields ) {
		$fields['billing_tin'] = array(
			'label'    => __( 'TIN (for business invoices)', 'nexa-efris' ),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 35,
		);
		return $fields;
	}

	public static function block_tin_field() {
		if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOCK_TIN_FIELD,
					'label'    => __( 'TIN (for business invoices)', 'nexa-efris' ),
					'location' => 'order',
					'type'     => 'text',
					'required' => false,
				)
			);
		}
	}

	public static function buyer_tin( $order ) {
		$tin = (string) $order->get_meta( '_billing_tin' );
		if ( '' === $tin ) {
			$tin = (string) $order->get_meta( '_wc_other/' . self::BLOCK_TIN_FIELD );
		}
		return preg_replace( '/\D/', '', $tin );
	}

	/* ------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	public static function add_meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'nexa-efris', __( 'URA EFRIS', 'nexa-efris' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	public static function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$fdn = $order->get_meta( '_nexa_efris_fdn' );
		$err = $order->get_meta( '_nexa_efris_error' );

		if ( $fdn ) {
			echo '<p><strong>' . esc_html__( 'FDN:', 'nexa-efris' ) . '</strong> ' . esc_html( $fdn ) . '<br>';
			echo '<strong>' . esc_html__( 'Verification code:', 'nexa-efris' ) . '</strong> ' . esc_html( $order->get_meta( '_nexa_efris_verification' ) ) . '<br>';
			echo '<strong>' . esc_html__( 'Issued:', 'nexa-efris' ) . '</strong> ' . esc_html( $order->get_meta( '_nexa_efris_issued' ) );
			if ( $order->get_meta( '_nexa_efris_rate' ) ) {
				echo '<br><strong>' . esc_html__( 'URA rate:', 'nexa-efris' ) . '</strong> 1 ' . esc_html( $order->get_currency() ) . ' = ' . esc_html( $order->get_meta( '_nexa_efris_rate' ) ) . ' UGX';
			}
			echo '</p>';
			$qr = $order->get_meta( '_nexa_efris_qr' );
			if ( $qr ) {
				echo '<p>' . self::qr_img( $qr ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts / generated SVG.
			}
			echo self::credit_notes_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		if ( $err ) {
			echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'Last error:', 'nexa-efris' ) . '</strong> ' . esc_html( $err ) . '</p>';
			/* translators: %d: attempts */
			echo '<p class="description">' . esc_html( sprintf( __( 'Attempts: %d', 'nexa-efris' ), (int) $order->get_meta( '_nexa_efris_attempts' ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Not fiscalised yet.', 'nexa-efris' ) . '</p>';
		}

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=nexa_efris_submit&order_id=' . $order->get_id() ), 'nexa_efris_submit_' . $order->get_id() );
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Send to EFRIS now', 'nexa-efris' ) . '</a></p>';
	}

	public static function add_column( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$new['nexa_efris'] = __( 'EFRIS', 'nexa-efris' );
			}
		}
		if ( ! isset( $new['nexa_efris'] ) ) {
			$new['nexa_efris'] = __( 'EFRIS', 'nexa-efris' );
		}
		return $new;
	}

	public static function render_column( $column, $order ) {
		if ( 'nexa_efris' !== $column ) {
			return;
		}
		$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_nexa_efris_fdn' ) ) {
			echo '<span style="color:#008a20" title="' . esc_attr( $order->get_meta( '_nexa_efris_fdn' ) ) . '">✔ ' . esc_html__( 'Fiscalised', 'nexa-efris' ) . '</span>';
		} elseif ( $order->get_meta( '_nexa_efris_error' ) ) {
			echo '<span style="color:#b32d2e" title="' . esc_attr( $order->get_meta( '_nexa_efris_error' ) ) . '">✖ ' . esc_html__( 'Failed', 'nexa-efris' ) . '</span>';
		} else {
			echo '–';
		}
	}

	/* ------------------------------------------------------------------
	 * Customer
	 * ------------------------------------------------------------------ */

	public static function customer_block( $order ) {
		if ( Nexa_EFRIS_Settings::is_on( 'show_customer' ) ) {
			echo self::block_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
	}

	public static function email_block( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( ! Nexa_EFRIS_Settings::is_on( 'show_customer' ) || ! $order->get_meta( '_nexa_efris_fdn' ) ) {
			return;
		}
		if ( $plain_text ) {
			echo "\nURA EFRIS\n";
			echo 'FDN: ' . esc_html( $order->get_meta( '_nexa_efris_fdn' ) ) . "\n";
			echo 'Verification code: ' . esc_html( $order->get_meta( '_nexa_efris_verification' ) ) . "\n\n";
			return;
		}
		echo self::block_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function block_html( $order ) {
		$fdn = $order->get_meta( '_nexa_efris_fdn' );
		if ( ! $fdn ) {
			return '';
		}
		$qr   = $order->get_meta( '_nexa_efris_qr' );
		$html = '<section class="nexa-efris" style="margin:1.5em 0;padding:1em;border:1px solid #ddd">';
		$html .= '<h2 style="margin-top:0">' . esc_html__( 'URA EFRIS fiscal details', 'nexa-efris' ) . '</h2>';
		$html .= '<p><strong>' . esc_html__( 'Fiscal Document Number (FDN):', 'nexa-efris' ) . '</strong> ' . esc_html( $fdn ) . '<br>';
		$html .= '<strong>' . esc_html__( 'Verification code:', 'nexa-efris' ) . '</strong> ' . esc_html( $order->get_meta( '_nexa_efris_verification' ) ) . '<br>';
		$html .= '<strong>' . esc_html__( 'Seller TIN:', 'nexa-efris' ) . '</strong> ' . esc_html( Nexa_EFRIS_Settings::get( 'tin' ) ) . '</p>';
		if ( $qr ) {
			$html .= '<p>' . self::qr_img( $qr ) . '</p>';
		}
		foreach ( $order->get_refunds() as $refund ) {
			if ( 'approved' === $refund->get_meta( '_nexa_efris_cn_status' ) && $refund->get_meta( '_nexa_efris_cn_fdn' ) ) {
				$html .= '<p><strong>' . esc_html__( 'Credit note FDN:', 'nexa-efris' ) . '</strong> ' . esc_html( $refund->get_meta( '_nexa_efris_cn_fdn' ) ) . '</p>';
			}
		}
		$html .= '</section>';
		return $html;
	}

	/**
	 * QR image URL. Default uses a public QR service; swap it for a local library
	 * (e.g. chillerlan/php-qrcode) with the nexa_efris_qr_image_url filter.
	 */
	public static function qr_url( $text ) {
		return apply_filters( 'nexa_efris_qr_image_url', Nexa_EFRIS_QR::png_url( $text ), $text );
	}

	/** <img> tag for the QR; falls back to inline SVG if PNG can't be written. */
	public static function qr_img( $text, $px = 140 ) {
		$url = self::qr_url( $text );
		if ( $url ) {
			return '<img src="' . esc_url( $url ) . '" width="' . (int) $px . '" height="' . (int) $px . '" alt="EFRIS QR code">';
		}
		try {
			return Nexa_EFRIS_QR::svg( $text, $px );
		} catch ( Exception $e ) {
			return '';
		}
	}

	private static function credit_notes_html( $order ) {
		$html = '';
		foreach ( $order->get_refunds() as $refund ) {
			$ref    = $refund->get_meta( '_nexa_efris_cn_ref' );
			$status = $refund->get_meta( '_nexa_efris_cn_status' );
			$err    = $refund->get_meta( '_nexa_efris_cn_error' );
			$colors = array(
				'approved' => '#008a20',
				'pending'  => '#996800',
				'rejected' => '#b32d2e',
				'voided'   => '#646970',
			);
			$html .= '<div style="border-top:1px solid #dcdcde;padding-top:6px;margin-top:6px">';
			/* translators: 1: refund id, 2: amount */
			$html .= '<strong>' . esc_html( sprintf( __( 'Refund #%1$d – %2$s', 'nexa-efris' ), $refund->get_id(), wp_strip_all_tags( wc_price( $refund->get_amount(), array( 'currency' => $order->get_currency() ) ) ) ) ) . '</strong><br>';
			if ( $ref ) {
				$html .= esc_html__( 'Credit note:', 'nexa-efris' ) . ' <span style="color:' . esc_attr( isset( $colors[ $status ] ) ? $colors[ $status ] : '#000' ) . ';font-weight:600">' . esc_html( ucfirst( $status ) ) . '</span><br>';
				$html .= '<small>' . esc_html__( 'Ref:', 'nexa-efris' ) . ' ' . esc_html( $ref ) . '</small>';
				if ( $refund->get_meta( '_nexa_efris_cn_fdn' ) ) {
					$html .= '<br><small>' . esc_html__( 'FDN:', 'nexa-efris' ) . ' ' . esc_html( $refund->get_meta( '_nexa_efris_cn_fdn' ) ) . '</small>';
				}
				if ( 'pending' === $status ) {
					$url   = wp_nonce_url( admin_url( 'admin-post.php?action=nexa_efris_cn_check&refund_id=' . $refund->get_id() ), 'nexa_efris_cn_' . $refund->get_id() );
					$html .= '<br><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Check approval', 'nexa-efris' ) . '</a>';
				}
			} else {
				if ( $err ) {
					$html .= '<span style="color:#b32d2e">' . esc_html( $err ) . '</span><br>';
				}
				$url   = wp_nonce_url( admin_url( 'admin-post.php?action=nexa_efris_cn_apply&refund_id=' . $refund->get_id() ), 'nexa_efris_cn_' . $refund->get_id() );
				$html .= '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Apply credit note', 'nexa-efris' ) . '</a>';
			}
			$html .= '</div>';
		}
		return $html;
	}
}
