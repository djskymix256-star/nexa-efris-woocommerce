<?php
/**
 * Credit notes for refunds of fiscalised orders.
 *
 * EFRIS credit notes are *applications* (T110) that URA approves. After applying,
 * the plugin polls approval status hourly (T111) for up to 3 days and stores the
 * credit note FDN once approved.
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Credit_Note {

	const MAX_CHECKS = 72;

	const STATUS = array(
		'101' => 'approved',
		'102' => 'pending',
		'103' => 'rejected',
		'104' => 'voided',
	);

	public static function init() {
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 10, 2 );
		add_action( 'nexa_efris_credit_note', array( __CLASS__, 'process' ) );
		add_action( 'nexa_efris_credit_note_check', array( __CLASS__, 'check' ) );
	}

	public static function on_refund( $order_id, $refund_id ) {
		if ( ! Nexa_EFRIS_Settings::is_on( 'enabled' ) || ! Nexa_EFRIS_Settings::is_on( 'auto_credit_note' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! Nexa_EFRIS_Invoice::is_fiscalised( $order ) ) {
			return;
		}
		as_enqueue_async_action( 'nexa_efris_credit_note', array( 'refund_id' => (int) $refund_id ), 'nexa-efris' );
	}

	public static function process( $refund_id ) {
		$r = self::apply( $refund_id );
		if ( is_wp_error( $r ) ) {
			Nexa_EFRIS_Logger::error( 'Credit note for refund #' . $refund_id . ': ' . $r->get_error_message() );
		}
	}

	/**
	 * Apply for a credit note for one refund.
	 *
	 * @return array|WP_Error
	 */
	public static function apply( $refund_id ) {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund || ! is_a( $refund, 'WC_Order_Refund' ) ) {
			return new WP_Error( 'nexa_efris_cn', 'Refund not found.' );
		}
		$order = wc_get_order( $refund->get_parent_id() );
		if ( ! $order || ! Nexa_EFRIS_Invoice::is_fiscalised( $order ) ) {
			return new WP_Error( 'nexa_efris_cn', 'The original order is not fiscalised.' );
		}
		if ( $refund->get_meta( '_nexa_efris_cn_ref' ) ) {
			return new WP_Error( 'nexa_efris_cn', 'A credit note was already applied for this refund.' );
		}

		$snapshot = (array) $order->get_meta( '_nexa_efris_lines' );
		if ( ! $snapshot ) {
			return self::fail( $order, $refund, __( 'No invoice line snapshot on the order (fiscalised by an older version). Create this credit note on the EFRIS portal.', 'nexa-efris' ) );
		}

		$credited = (array) $order->get_meta( '_nexa_efris_credited' );
		$want     = self::map_refund( $refund, $order, $snapshot );

		if ( ! $want ) {
			return self::fail( $order, $refund, __( 'This refund is an amount-only partial refund that cannot be matched to invoice lines. Refund by item quantity, or create the credit note on the EFRIS portal.', 'nexa-efris' ) );
		}

		$m      = array( 'Nexa_EFRIS_Product', 'money' );
		$goods  = array();
		$groups = array();
		$gross  = 0.0;
		$taxes  = 0.0;

		foreach ( $want as $key => $qty ) {
			$l         = $snapshot[ $key ];
			$remaining = $l['qty'] - ( isset( $credited[ $key ] ) ? (float) $credited[ $key ] : 0 );
			$qty       = min( $qty, $remaining );
			if ( $qty <= 0 ) {
				continue;
			}
			$a       = Nexa_EFRIS_Invoice::amounts( $l['unit_price'], -$qty, $l['tax'], $l['excise'] );
			$goods[] = Nexa_EFRIS_Invoice::goods_row( $l, -$qty, $a );
			Nexa_EFRIS_Invoice::add_tax( $groups, $l, $a );
			$gross          += $a['total'];
			$taxes          += $a['vat'] + $a['excise'];
			$want[ $key ]    = $qty;
		}

		if ( ! $goods ) {
			return self::fail( $order, $refund, __( 'All refunded lines have already been credited in EFRIS.', 'nexa-efris' ) );
		}

		$reason_code = Nexa_EFRIS_Settings::get( 'cn_reason_code' );
		$reason      = $refund->get_reason() ? $refund->get_reason() : __( 'Refund', 'nexa-efris' );
		$reference   = 'WCR' . $refund->get_id();

		$payload = array(
			'oriInvoiceId'             => $order->get_meta( '_nexa_efris_invoice_id' ),
			'oriInvoiceNo'             => $order->get_meta( '_nexa_efris_fdn' ),
			'reasonCode'               => $reason_code,
			'reason'                   => '105' === $reason_code ? $reason : '',
			'applicationTime'          => Nexa_EFRIS_Client::now(),
			'invoiceApplyCategoryCode' => '101', // Credit note.
			'currency'                 => $order->get_currency(),
			'contactName'              => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'contactMobileNum'         => $order->get_billing_phone(),
			'contactEmail'             => $order->get_billing_email(),
			'source'                   => '103',
			'remarks'                  => 'Refund #' . $refund->get_id() . ' of order #' . $order->get_order_number() . ': ' . $reason,
			'sellersReferenceNo'       => $reference,
			'goodsDetails'             => $goods,
			'taxDetails'               => Nexa_EFRIS_Invoice::tax_details( $groups ),
			'summary'                  => array(
				'netAmount'   => call_user_func( $m, $gross - $taxes ),
				'taxAmount'   => call_user_func( $m, $taxes ),
				'grossAmount' => call_user_func( $m, $gross ),
				'itemCount'   => (string) count( $goods ),
				'modeCode'    => '0',
				'qrCode'      => '',
			),
			'payWay'                   => array(
				array(
					'paymentMode'   => $order->get_meta( '_nexa_efris_payment_mode' ) ? $order->get_meta( '_nexa_efris_payment_mode' ) : Nexa_EFRIS_Settings::get( 'default_payment_mode' ),
					'paymentAmount' => call_user_func( $m, abs( $gross ) ),
					'orderNumber'   => 'a',
				),
			),
			'buyerDetails'             => $order->get_meta( '_nexa_efris_buyer' ) ? $order->get_meta( '_nexa_efris_buyer' ) : Nexa_EFRIS_Invoice::buyer( $order ),
			'importServicesSeller'     => new stdClass(),
			'basicInformation'         => array(
				'operator'            => Nexa_EFRIS_Settings::get( 'operator' ),
				'invoiceKind'         => '1',
				'invoiceIndustryCode' => '101',
				'branchId'            => Nexa_EFRIS_Settings::get( 'branch_id' ),
			),
		);

		$payload = apply_filters( 'nexa_efris_credit_note_payload', $payload, $refund, $order );
		$res     = ( new Nexa_EFRIS_Client() )->call( 'T110', $payload );
		if ( is_wp_error( $res ) ) {
			return self::fail( $order, $refund, $res->get_error_message() );
		}

		foreach ( $want as $key => $qty ) {
			$credited[ $key ] = ( isset( $credited[ $key ] ) ? (float) $credited[ $key ] : 0 ) + $qty;
		}
		$order->update_meta_data( '_nexa_efris_credited', $credited );

		$refund->update_meta_data( '_nexa_efris_cn_ref', ! empty( $res['referenceNo'] ) ? $res['referenceNo'] : $reference );
		$refund->update_meta_data( '_nexa_efris_cn_status', 'pending' );
		$refund->update_meta_data( '_nexa_efris_cn_amount', call_user_func( $m, abs( $gross ) ) );
		$refund->update_meta_data( '_nexa_efris_cn_checks', 0 );
		$refund->delete_meta_data( '_nexa_efris_cn_error' );
		$refund->save();

		/* translators: 1: refund id, 2: reference */
		$order->add_order_note( sprintf( __( 'EFRIS: credit note applied for refund #%1$d (ref %2$s). Awaiting URA approval.', 'nexa-efris' ), $refund->get_id(), $refund->get_meta( '_nexa_efris_cn_ref' ) ) );
		$order->save();

		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'nexa_efris_credit_note_check', array( 'refund_id' => (int) $refund->get_id() ), 'nexa-efris' );
		do_action( 'nexa_efris_credit_note_applied', $refund, $order, $res );
		return $res;
	}

	/**
	 * Map refund items to snapshot keys → quantity.
	 */
	private static function map_refund( $refund, $order, $snapshot ) {
		$want = array();

		foreach ( $refund->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $ritem ) {
			$orig = (int) $ritem->get_meta( '_refunded_item_id' );
			$type = $ritem->get_type();
			if ( 'line_item' === $type ) {
				$key = 'item_' . $orig;
				$qty = abs( (float) $ritem->get_quantity() );
			} elseif ( 'fee' === $type ) {
				$key = 'fee_' . $orig;
				$qty = ( abs( (float) $ritem->get_total() ) > 0 ) ? 1 : 0;
			} else {
				$key = 'shipping';
				$qty = ( abs( (float) $ritem->get_total() ) > 0 ) ? 1 : 0;
			}
			if ( $qty > 0 && isset( $snapshot[ $key ] ) ) {
				$want[ $key ] = ( isset( $want[ $key ] ) ? $want[ $key ] : 0 ) + $qty;
			}
		}

		// Full refund with no item breakdown → credit everything.
		if ( ! $want && abs( (float) $refund->get_amount() ) >= (float) $order->get_total() - 0.01 ) {
			foreach ( $snapshot as $key => $l ) {
				$want[ $key ] = $l['qty'];
			}
		}
		return $want;
	}

	private static function fail( $order, $refund, $message ) {
		$refund->update_meta_data( '_nexa_efris_cn_error', $message );
		$refund->save();
		/* translators: 1: refund id, 2: message */
		$order->add_order_note( sprintf( __( 'EFRIS credit note for refund #%1$d not applied: %2$s', 'nexa-efris' ), $refund->get_id(), $message ) );
		$order->save();
		return new WP_Error( 'nexa_efris_cn', $message );
	}

	/** Poll URA for approval (T111). */
	public static function check( $refund_id, $reschedule = true ) {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund || ! $refund->get_meta( '_nexa_efris_cn_ref' ) ) {
			return new WP_Error( 'nexa_efris_cn', 'No credit note application on this refund.' );
		}
		$res = ( new Nexa_EFRIS_Client() )->call(
			'T111',
			array(
				'referenceNo'              => $refund->get_meta( '_nexa_efris_cn_ref' ),
				'oriInvoiceNo'             => '',
				'invoiceNo'                => '',
				'combineKeywords'          => '',
				'approveStatus'            => '',
				'queryType'                => '1',
				'invoiceApplyCategoryCode' => '101',
				'startDate'                => '',
				'endDate'                  => '',
				'pageNo'                   => '1',
				'pageSize'                 => '10',
			)
		);

		$status = 'pending';
		if ( ! is_wp_error( $res ) ) {
			$records = isset( $res['records'] ) ? $res['records'] : ( isset( $res[0] ) ? $res : array() );
			foreach ( (array) $records as $rec ) {
				if ( ! is_array( $rec ) ) {
					continue;
				}
				$code   = isset( $rec['approveStatus'] ) ? (string) $rec['approveStatus'] : '';
				$status = isset( self::STATUS[ $code ] ) ? self::STATUS[ $code ] : 'pending';
				if ( ! empty( $rec['invoiceNo'] ) ) {
					$refund->update_meta_data( '_nexa_efris_cn_fdn', $rec['invoiceNo'] );
				}
				if ( ! empty( $rec['id'] ) ) {
					$refund->update_meta_data( '_nexa_efris_cn_id', $rec['id'] );
				}
				break;
			}
		}

		$checks = (int) $refund->get_meta( '_nexa_efris_cn_checks' ) + 1;
		$refund->update_meta_data( '_nexa_efris_cn_checks', $checks );
		$prev = $refund->get_meta( '_nexa_efris_cn_status' );
		$refund->update_meta_data( '_nexa_efris_cn_status', $status );
		$refund->save();

		if ( $status !== $prev && 'pending' !== $status ) {
			$order = wc_get_order( $refund->get_parent_id() );
			if ( $order ) {
				$fdn = $refund->get_meta( '_nexa_efris_cn_fdn' );
				/* translators: 1: refund id, 2: status, 3: FDN */
				$order->add_order_note( sprintf( __( 'EFRIS: credit note for refund #%1$d is %2$s%3$s.', 'nexa-efris' ), $refund->get_id(), $status, $fdn ? ' (FDN ' . $fdn . ')' : '' ) );
			}
		}

		if ( $reschedule && 'pending' === $status && $checks < self::MAX_CHECKS ) {
			as_schedule_single_action( time() + HOUR_IN_SECONDS, 'nexa_efris_credit_note_check', array( 'refund_id' => (int) $refund_id ), 'nexa-efris' );
		}
		return $status;
	}
}
