<?php
/**
 * Builds and submits EFRIS invoices/receipts (T109) for WooCommerce orders.
 * Submission runs in the background via Action Scheduler, with retries.
 *
 * Supports: VAT categories, excise duty, shipping & fees as service lines,
 * negative fees (spread over items), non-UGX currencies and branches.
 * A snapshot of the invoiced lines is stored on the order for credit notes.
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Invoice {

	const MAX_ATTEMPTS = 5;

	public static function init() {
		$status = Nexa_EFRIS_Settings::get( 'trigger_status' );
		add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'queue' ) );
		add_action( 'nexa_efris_submit_order', array( __CLASS__, 'process' ) );
	}

	public static function is_fiscalised( $order ) {
		return '' !== (string) $order->get_meta( '_nexa_efris_fdn' );
	}

	public static function queue( $order_id ) {
		if ( ! Nexa_EFRIS_Settings::is_on( 'enabled' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || self::is_fiscalised( $order ) ) {
			return;
		}
		$args = array( 'order_id' => (int) $order_id );
		if ( ! as_has_scheduled_action( 'nexa_efris_submit_order', $args, 'nexa-efris' ) ) {
			as_enqueue_async_action( 'nexa_efris_submit_order', $args, 'nexa-efris' );
		}
	}

	/** Action Scheduler callback. */
	public static function process( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$result = self::submit( $order );

		if ( is_wp_error( $result ) && ! $result->get_error_data( 'no_retry' ) ) {
			$attempts = (int) $order->get_meta( '_nexa_efris_attempts' );
			if ( $attempts < self::MAX_ATTEMPTS ) {
				$delay = 5 * MINUTE_IN_SECONDS * pow( 2, max( 0, $attempts - 1 ) ); // 5, 10, 20, 40 min.
				as_schedule_single_action( time() + $delay, 'nexa_efris_submit_order', array( 'order_id' => (int) $order_id ), 'nexa-efris' );
			}
		}
	}

	/**
	 * Fiscalise one order now.
	 *
	 * @return array|WP_Error
	 */
	public static function submit( $order ) {
		if ( self::is_fiscalised( $order ) ) {
			return new WP_Error( 'nexa_efris_done', __( 'Order is already fiscalised.', 'nexa-efris' ), array( 'no_retry' => true ) );
		}

		$order->update_meta_data( '_nexa_efris_attempts', (int) $order->get_meta( '_nexa_efris_attempts' ) + 1 );
		$order->save();

		$prep = self::prepare( $order );
		if ( is_wp_error( $prep ) ) {
			return self::fail( $order, $prep );
		}

		$built = self::build_payload( $order );
		if ( is_wp_error( $built ) ) {
			return self::fail( $order, $built );
		}

		$res = ( new Nexa_EFRIS_Client() )->call( 'T109', $built['payload'] );
		if ( is_wp_error( $res ) ) {
			return self::fail( $order, $res );
		}

		$basic   = isset( $res['basicInformation'] ) ? $res['basicInformation'] : array();
		$summary = isset( $res['summary'] ) ? $res['summary'] : array();
		$fdn     = isset( $basic['invoiceNo'] ) ? $basic['invoiceNo'] : '';

		if ( '' === $fdn ) {
			return self::fail( $order, new WP_Error( 'nexa_efris_nofdn', __( 'EFRIS accepted the request but returned no FDN.', 'nexa-efris' ) ) );
		}

		$order->update_meta_data( '_nexa_efris_fdn', $fdn );
		$order->update_meta_data( '_nexa_efris_verification', isset( $basic['antifakeCode'] ) ? $basic['antifakeCode'] : '' );
		$order->update_meta_data( '_nexa_efris_invoice_id', isset( $basic['invoiceId'] ) ? $basic['invoiceId'] : '' );
		$order->update_meta_data( '_nexa_efris_issued', isset( $basic['issuedDate'] ) ? $basic['issuedDate'] : Nexa_EFRIS_Client::now() );
		$order->update_meta_data( '_nexa_efris_qr', isset( $summary['qrCode'] ) ? $summary['qrCode'] : '' );
		$order->update_meta_data( '_nexa_efris_lines', $built['lines'] );
		$order->update_meta_data( '_nexa_efris_buyer', $built['payload']['buyerDetails'] );
		$order->update_meta_data( '_nexa_efris_payment_mode', $built['payload']['payWay'][0]['paymentMode'] );
		$order->delete_meta_data( '_nexa_efris_error' );
		/* translators: %s: fiscal document number */
		$order->add_order_note( sprintf( __( 'EFRIS: fiscalised. FDN %s', 'nexa-efris' ), $fdn ) );
		$order->save();

		do_action( 'nexa_efris_order_fiscalised', $order, $res );
		return $res;
	}

	private static function fail( $order, WP_Error $error ) {
		$order->update_meta_data( '_nexa_efris_error', $error->get_error_message() );
		/* translators: %s: error message */
		$order->add_order_note( sprintf( __( 'EFRIS submission failed: %s', 'nexa-efris' ), $error->get_error_message() ) );
		$order->save();
		Nexa_EFRIS_Logger::error( 'Order #' . $order->get_id() . ': ' . $error->get_error_message() );
		return $error;
	}

	/* ------------------------------------------------------------------
	 * Preparation: register goods/services, stock-in, exchange rate
	 * ------------------------------------------------------------------ */

	private static function prepare( $order ) {
		$auto_sync  = Nexa_EFRIS_Settings::is_on( 'auto_sync' );
		$auto_stock = Nexa_EFRIS_Settings::is_on( 'auto_stock' );

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				return new WP_Error( 'nexa_efris_product', sprintf( 'Line "%s" has no linked product.', $item->get_name() ), array( 'no_retry' => true ) );
			}
			if ( $auto_sync && ! $product->get_meta( '_nexa_efris_registered' ) ) {
				$r = Nexa_EFRIS_Product::sync( $product );
				if ( is_wp_error( $r ) ) {
					return new WP_Error( $r->get_error_code(), $product->get_name() . ': ' . $r->get_error_message() );
				}
			}
			if ( $auto_stock && ! $product->is_virtual() && ! $item->get_meta( '_nexa_efris_stocked' ) ) {
				$qty   = max( 1, (float) $item->get_quantity() );
				$price = ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $qty;
				$r     = Nexa_EFRIS_Product::stock_in( $product, $qty, $price );
				if ( is_wp_error( $r ) ) {
					return new WP_Error( $r->get_error_code(), 'Stock-in ' . $product->get_name() . ': ' . $r->get_error_message() );
				}
				$item->update_meta_data( '_nexa_efris_stocked', 1 );
				$item->save();
			}
		}

		if ( $auto_sync ) {
			if ( '' !== Nexa_EFRIS_Settings::get( 'shipping_commodity' ) && $order->get_shipping_total() > 0 ) {
				$r = self::register_service( 'WC-SHIPPING', 'Delivery charges', Nexa_EFRIS_Settings::get( 'shipping_commodity' ), self::service_unit( 'shipping_unit' ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
			$has_fee = false;
			foreach ( $order->get_fees() as $fee ) {
				if ( (float) $fee->get_total() > 0 ) {
					$has_fee = true;
				}
			}
			if ( $has_fee && '' !== self::fee_commodity() ) {
				$r = self::register_service( 'WC-FEE', 'Service fees', self::fee_commodity(), self::service_unit( 'fee_unit' ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}

		// Non-UGX: record URA's exchange rate for reference (T121).
		if ( 'UGX' !== $order->get_currency() && '' === (string) $order->get_meta( '_nexa_efris_rate' ) ) {
			$rate = ( new Nexa_EFRIS_Client() )->call(
				'T121',
				array(
					'currency'  => $order->get_currency(),
					'issueDate' => Nexa_EFRIS_Client::now(),
				)
			);
			if ( ! is_wp_error( $rate ) && ! empty( $rate['rate'] ) ) {
				$order->update_meta_data( '_nexa_efris_rate', $rate['rate'] );
				$order->save();
			}
		}
		return true;
	}

	private static function fee_commodity() {
		$c = Nexa_EFRIS_Settings::get( 'fee_commodity' );
		return '' !== $c ? $c : Nexa_EFRIS_Settings::get( 'shipping_commodity' );
	}

	private static function service_unit( $key ) {
		$u = Nexa_EFRIS_Settings::get( $key );
		return '' !== $u ? $u : Nexa_EFRIS_Settings::get( 'default_unit' );
	}

	/** Register a service good once (cached in an option). */
	private static function register_service( $code, $name, $commodity, $unit ) {
		$opt = 'nexa_efris_service_' . sanitize_key( $code );
		if ( get_option( $opt ) ) {
			return true;
		}
		$good = array(
			'operationType'       => '101',
			'goodsName'           => $name,
			'goodsCode'           => $code,
			'measureUnit'         => $unit,
			'unitPrice'           => '1.00',
			'currency'            => '101',
			'commodityCategoryId' => $commodity,
			'haveExciseTax'       => '102',
			'description'         => '',
			'stockPrewarning'     => '0',
			'pieceMeasureUnit'    => '',
			'havePieceUnit'       => '102',
			'pieceUnitPrice'      => '',
			'packageScaledValue'  => '',
			'pieceScaledValue'    => '',
			'exciseDutyCode'      => '',
			'haveOtherUnit'       => '102',
			'goodsTypeCode'       => '101',
		);
		$res = ( new Nexa_EFRIS_Client() )->call( 'T130', array( $good ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( (array) $res as $i ) {
			if ( is_array( $i ) && isset( $i['returnCode'] ) && '00' !== (string) $i['returnCode'] && false === stripos( (string) ( $i['returnMessage'] ?? '' ), 'exist' ) ) {
				return new WP_Error( 'nexa_efris_service', $name . ': ' . ( $i['returnMessage'] ?? 'error' ) );
			}
		}
		update_option( $opt, time(), false );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Line maths (shared with credit notes)
	 * ------------------------------------------------------------------ */

	/**
	 * Compute amounts for one line. Prices are inclusive of VAT and excise.
	 *
	 * @param float      $unit_price Gross unit price.
	 * @param float      $qty        Quantity (negative for credit notes).
	 * @param array      $tax        Tax category (rate, code, name).
	 * @param array|null $excise     Excise settings.
	 * @return array { total, vat, excise }
	 */
	public static function amounts( $unit_price, $qty, $tax, $excise ) {
		$total = round( $unit_price * $qty, 2 );
		$rate  = $tax['rate'];
		$vat   = ( '-' === $rate || 0.0 === (float) $rate ) ? 0.0 : round( $total * (float) $rate / ( 1 + (float) $rate ), 2 );
		$exc   = 0.0;
		if ( $excise ) {
			if ( '2' === $excise['rule'] ) {
				$exc = round( $qty * $excise['rate'], 2 );
			} else {
				$e   = $excise['rate'] / 100;
				$exc = round( ( $total - $vat ) * $e / ( 1 + $e ), 2 );
			}
		}
		return array(
			'total'  => $total,
			'vat'    => $vat,
			'excise' => $exc,
		);
	}

	/** Build one goodsDetails row from a line snapshot and its amounts. */
	public static function goods_row( $l, $qty, $a ) {
		$m  = array( 'Nexa_EFRIS_Product', 'money' );
		$ex = $l['excise'];
		return array(
			'item'              => $l['name'],
			'itemCode'          => $l['code'],
			'qty'               => self::qty( $qty ),
			'unitOfMeasure'     => $l['unit'],
			'unitPrice'         => call_user_func( $m, $l['unit_price'] ),
			'total'             => call_user_func( $m, $a['total'] ),
			'taxRate'           => $l['tax']['rate'],
			'tax'               => call_user_func( $m, $a['vat'] ),
			'discountTotal'     => '',
			'discountTaxRate'   => '',
			'orderNumber'       => (string) $l['order_no'],
			'discountFlag'      => '2',
			'deemedFlag'        => '2',
			'exciseFlag'        => $ex ? '1' : '2',
			'categoryId'        => $ex ? $ex['code'] : '',
			'categoryName'      => '',
			'goodsCategoryId'   => $l['commodity'],
			'goodsCategoryName' => '',
			'exciseRate'        => $ex ? ( '2' === $ex['rule'] ? (string) $ex['rate'] : (string) ( $ex['rate'] / 100 ) ) : '',
			'exciseRule'        => $ex ? $ex['rule'] : '',
			'exciseTax'         => $ex ? call_user_func( $m, $a['excise'] ) : '',
			'pack'              => '',
			'stick'             => '',
			'exciseUnit'        => ( $ex && '2' === $ex['rule'] ) ? $ex['unit'] : '',
			'exciseCurrency'    => ( $ex && '2' === $ex['rule'] ) ? 'UGX' : '',
			'exciseRateName'    => $ex ? ( '2' === $ex['rule'] ? 'UGX' . $ex['rate'] . ' per unit' : $ex['rate'] . '%' ) : '',
			'vatApplicableFlag' => '1',
		);
	}

	/** Accumulate taxDetails rows (VAT per category + excise per code). */
	public static function add_tax( &$groups, $l, $a ) {
		$key = 'vat_' . $l['tax']['code'];
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'taxCategoryCode' => $l['tax']['code'],
				'rate'            => $l['tax']['rate'],
				'name'            => $l['tax']['name'],
				'net'             => 0.0,
				'tax'             => 0.0,
				'gross'           => 0.0,
				'unit'            => '',
				'currency'        => '',
			);
		}
		$groups[ $key ]['net']   += $a['total'] - $a['vat'];
		$groups[ $key ]['tax']   += $a['vat'];
		$groups[ $key ]['gross'] += $a['total'];

		if ( $l['excise'] ) {
			$ex  = $l['excise'];
			$key = 'exc_' . $ex['code'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'taxCategoryCode' => '05',
					'rate'            => '2' === $ex['rule'] ? (string) $ex['rate'] : (string) ( $ex['rate'] / 100 ),
					'name'            => '2' === $ex['rule'] ? 'UGX' . $ex['rate'] . ' per unit' : $ex['rate'] . '%',
					'net'             => 0.0,
					'tax'             => 0.0,
					'gross'           => 0.0,
					'unit'            => '2' === $ex['rule'] ? $ex['unit'] : '',
					'currency'        => '2' === $ex['rule'] ? 'UGX' : '',
				);
			}
			$groups[ $key ]['net']   += $a['total'] - $a['vat'] - $a['excise'];
			$groups[ $key ]['tax']   += $a['excise'];
			$groups[ $key ]['gross'] += $a['total'] - $a['vat'];
		}
	}

	public static function tax_details( $groups ) {
		$m   = array( 'Nexa_EFRIS_Product', 'money' );
		$out = array();
		foreach ( $groups as $g ) {
			$out[] = array(
				'taxCategoryCode' => $g['taxCategoryCode'],
				'netAmount'       => call_user_func( $m, $g['net'] ),
				'taxRate'         => $g['rate'],
				'taxAmount'       => call_user_func( $m, $g['tax'] ),
				'grossAmount'     => call_user_func( $m, $g['gross'] ),
				'exciseUnit'      => $g['unit'],
				'exciseCurrency'  => $g['currency'],
				'taxRateName'     => $g['name'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Payload
	 * ------------------------------------------------------------------ */

	/** Collect order lines as snapshots keyed by source. */
	private static function collect_lines( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$lines[ 'item_' . $item_id ] = array(
				'name'      => mb_substr( wp_strip_all_tags( $item->get_name() ), 0, 200 ),
				'code'      => Nexa_EFRIS_Product::item_code( $product ),
				'qty'       => (float) $item->get_quantity(),
				'gross'     => (float) $item->get_total() + (float) $item->get_total_tax(),
				'unit'      => Nexa_EFRIS_Product::resolve( $product, 'unit' ),
				'tax'       => Nexa_EFRIS_Product::tax_for( $product ),
				'excise'    => Nexa_EFRIS_Product::excise_for( $product ),
				'commodity' => Nexa_EFRIS_Product::resolve( $product, 'commodity' ),
			);
		}

		// Negative fees (discounts) are spread proportionally over product lines.
		$negative = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			$g = (float) $fee->get_total() + (float) $fee->get_total_tax();
			if ( $g < 0 ) {
				$negative += -$g;
			}
		}
		if ( $negative > 0 ) {
			$base = array_sum( wp_list_pluck( $lines, 'gross' ) );
			if ( $base > 0 ) {
				foreach ( $lines as $k => $l ) {
					$lines[ $k ]['gross'] = max( 0, $l['gross'] - $negative * ( $l['gross'] / $base ) );
				}
			}
		}

		$cats        = Nexa_EFRIS_Product::tax_categories();
		$default_tax = isset( $cats[ Nexa_EFRIS_Settings::get( 'default_tax' ) ] ) ? $cats[ Nexa_EFRIS_Settings::get( 'default_tax' ) ] : $cats['standard'];

		$ship = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		if ( $ship > 0 && '' !== Nexa_EFRIS_Settings::get( 'shipping_commodity' ) ) {
			$lines['shipping'] = array(
				'name'      => 'Delivery charges',
				'code'      => 'WC-SHIPPING',
				'qty'       => 1,
				'gross'     => $ship,
				'unit'      => self::service_unit( 'shipping_unit' ),
				'tax'       => $default_tax,
				'excise'    => null,
				'commodity' => Nexa_EFRIS_Settings::get( 'shipping_commodity' ),
			);
		}

		if ( '' !== self::fee_commodity() ) {
			foreach ( $order->get_fees() as $fee_id => $fee ) {
				$g = (float) $fee->get_total() + (float) $fee->get_total_tax();
				if ( $g > 0 ) {
					$lines[ 'fee_' . $fee_id ] = array(
						'name'      => mb_substr( wp_strip_all_tags( $fee->get_name() ), 0, 200 ),
						'code'      => 'WC-FEE',
						'qty'       => 1,
						'gross'     => $g,
						'unit'      => self::service_unit( 'fee_unit' ),
						'tax'       => $default_tax,
						'excise'    => null,
						'commodity' => self::fee_commodity(),
					);
				}
			}
		}
		return $lines;
	}

	/**
	 * @return array|WP_Error { payload, lines }
	 */
	public static function build_payload( $order ) {
		$m          = array( 'Nexa_EFRIS_Product', 'money' );
		$goods      = array();
		$groups     = array();
		$gross_sum  = 0.0;
		$tax_sum    = 0.0;
		$n          = 0;
		$snapshot   = array();

		foreach ( self::collect_lines( $order ) as $key => $l ) {
			if ( $l['qty'] <= 0 || $l['gross'] <= 0 ) {
				continue; // EFRIS rejects zero-value lines.
			}
			$l['unit_price'] = round( $l['gross'] / $l['qty'], 2 );
			$l['order_no']   = $n;
			$a               = self::amounts( $l['unit_price'], $l['qty'], $l['tax'], $l['excise'] );

			$goods[] = self::goods_row( $l, $l['qty'], $a );
			self::add_tax( $groups, $l, $a );

			$gross_sum += $a['total'];
			$tax_sum   += $a['vat'] + $a['excise'];
			unset( $l['gross'] );
			$snapshot[ $key ] = $l;
			++$n;
		}

		if ( ! $goods ) {
			return new WP_Error( 'nexa_efris_empty', __( 'Nothing to invoice (all lines are zero-value).', 'nexa-efris' ), array( 'no_retry' => true ) );
		}

		$payload = array(
			'sellerDetails'        => self::seller( 'WC' . $order->get_order_number() ),
			'basicInformation'     => array(
				'invoiceNo'           => '',
				'antifakeCode'        => '',
				'deviceNo'            => Nexa_EFRIS_Settings::get( 'device_no' ),
				'issuedDate'          => Nexa_EFRIS_Client::now(),
				'operator'            => Nexa_EFRIS_Settings::get( 'operator' ),
				'currency'            => $order->get_currency(),
				'oriInvoiceId'        => '',
				'invoiceType'         => '1',
				'invoiceKind'         => Nexa_EFRIS_Settings::get( 'invoice_kind' ),
				'dataSource'          => '103',
				'invoiceIndustryCode' => '101',
				'isBatch'             => '0',
			),
			'buyerDetails'         => self::buyer( $order ),
			'buyerExtend'          => new stdClass(),
			'goodsDetails'         => $goods,
			'taxDetails'           => self::tax_details( $groups ),
			'summary'              => array(
				'netAmount'   => call_user_func( $m, $gross_sum - $tax_sum ),
				'taxAmount'   => call_user_func( $m, $tax_sum ),
				'grossAmount' => call_user_func( $m, $gross_sum ),
				'itemCount'   => (string) count( $goods ),
				'modeCode'    => '1',
				'remarks'     => 'WooCommerce order #' . $order->get_order_number(),
				'qrCode'      => '',
			),
			'payWay'               => array(
				array(
					'paymentMode'   => self::payment_mode( $order ),
					'paymentAmount' => call_user_func( $m, $gross_sum ),
					'orderNumber'   => 'a',
				),
			),
			'extend'               => new stdClass(),
			'importServicesSeller' => new stdClass(),
			'airlineGoodsDetails'  => array(),
			'edcDetails'           => new stdClass(),
		);

		return array(
			'payload' => apply_filters( 'nexa_efris_invoice_payload', $payload, $order ),
			'lines'   => $snapshot,
		);
	}

	public static function seller( $reference ) {
		return array(
			'tin'                => Nexa_EFRIS_Settings::get( 'tin' ),
			'ninBrn'             => Nexa_EFRIS_Settings::get( 'brn' ),
			'legalName'          => Nexa_EFRIS_Settings::get( 'legal_name' ),
			'businessName'       => Nexa_EFRIS_Settings::get( 'business_name' ),
			'address'            => Nexa_EFRIS_Settings::get( 'address' ),
			'mobilePhone'        => Nexa_EFRIS_Settings::get( 'phone' ),
			'linePhone'          => '',
			'emailAddress'       => Nexa_EFRIS_Settings::get( 'email' ),
			'placeOfBusiness'    => Nexa_EFRIS_Settings::get( 'place_of_business' ),
			'referenceNo'        => $reference,
			'branchId'           => Nexa_EFRIS_Settings::get( 'branch_id' ),
			'isCheckReferenceNo' => '1', // Duplicate reference is rejected → no double invoices.
		);
	}

	public static function buyer( $order ) {
		$tin     = Nexa_EFRIS_Display::buyer_tin( $order );
		$company = $order->get_billing_company();
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$address = trim( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_city() ) ) ) );
		$foreign = $order->get_billing_country() && 'UG' !== $order->get_billing_country() && ! $tin;

		return array(
			'buyerTin'          => $tin,
			'buyerNinBrn'       => '',
			'buyerPassportNum'  => '',
			'buyerLegalName'    => $tin && $company ? $company : $name,
			'buyerBusinessName' => $company,
			'buyerAddress'      => $address,
			'buyerEmail'        => $order->get_billing_email(),
			'buyerMobilePhone'  => $order->get_billing_phone(),
			'buyerLinePhone'    => '',
			'buyerPlaceOfBusi'  => '',
			'buyerType'         => $tin ? '0' : ( $foreign ? '2' : '1' ), // 0 B2B, 1 B2C, 2 foreigner.
			'buyerCitizenship'  => '',
			'buyerSector'       => '',
			'buyerReferenceNo'  => '',
		);
	}

	private static function payment_mode( $order ) {
		$gw   = strtolower( (string) $order->get_payment_method() );
		$mode = Nexa_EFRIS_Settings::get( 'default_payment_mode' );
		$map  = array(
			'bacs'   => '107',
			'cod'    => '102',
			'cheque' => '103',
		);
		if ( isset( $map[ $gw ] ) ) {
			$mode = $map[ $gw ];
		} elseif ( preg_match( '/momo|mobile|mtn|airtel|marz|yo_?pay/', $gw ) ) {
			$mode = '105';
		} elseif ( preg_match( '/stripe|card|visa|pesapal|dpo/', $gw ) ) {
			$mode = '106';
		}
		return apply_filters( 'nexa_efris_payment_mode', $mode, $order );
	}

	public static function qty( $q ) {
		return ( floor( $q ) == $q ) ? (string) (int) $q : rtrim( rtrim( number_format( $q, 4, '.', '' ), '0' ), '.' ); // phpcs:ignore Universal.Operators.StrictComparisons
	}
}
