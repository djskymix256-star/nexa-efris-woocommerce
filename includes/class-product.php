<?php
/**
 * Product-level EFRIS fields, registration (T130) and stock-in (T131).
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Product {

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'maybe_queue_sync' ) );
		add_action( 'nexa_efris_sync_product', array( __CLASS__, 'sync_by_id' ) );
	}

	public static function tax_categories() {
		return array(
			'standard' => array( 'rate' => '0.18', 'code' => '01', 'name' => '18%' ),
			'zero'     => array( 'rate' => '0',    'code' => '02', 'name' => '0%' ),
			'exempt'   => array( 'rate' => '-',    'code' => '03', 'name' => 'Exempt' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Admin fields
	 * ------------------------------------------------------------------ */

	public static function fields() {
		global $product_object;
		echo '<div class="options_group nexa-efris-fields">';
		echo '<p class="form-field"><strong>' . esc_html__( 'URA EFRIS', 'nexa-efris' ) . '</strong></p>';

		woocommerce_wp_text_input(
			array(
				'id'          => '_nexa_efris_item_code',
				'label'       => __( 'EFRIS item code', 'nexa-efris' ),
				'placeholder' => __( 'Defaults to SKU', 'nexa-efris' ),
				'desc_tip'    => true,
				'description' => __( 'Unique goods code registered in EFRIS. Leave empty to use the SKU.', 'nexa-efris' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_nexa_efris_commodity',
				'label'       => __( 'Commodity category code', 'nexa-efris' ),
				'placeholder' => Nexa_EFRIS_Settings::get( 'default_commodity' ),
			)
		);
		self::unit_field( '_nexa_efris_unit', $product_object ? $product_object->get_meta( '_nexa_efris_unit' ) : '' );
		woocommerce_wp_select(
			array(
				'id'      => '_nexa_efris_tax',
				'label'   => __( 'EFRIS tax category', 'nexa-efris' ),
				'options' => array( '' => __( 'Use default', 'nexa-efris' ) ) + wp_list_pluck( self::tax_categories(), 'name' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_nexa_efris_excise_code',
				'label'       => __( 'Excise duty code', 'nexa-efris' ),
				'placeholder' => __( 'Leave empty if no excise', 'nexa-efris' ),
				'desc_tip'    => true,
				'description' => __( 'EFRIS excise duty code (e.g. for alcohol, soft drinks, cigarettes). Product prices are treated as including excise and VAT.', 'nexa-efris' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_nexa_efris_excise_rule',
				'label'   => __( 'Excise rule', 'nexa-efris' ),
				'options' => array(
					'1' => __( 'Percentage of value', 'nexa-efris' ),
					'2' => __( 'Fixed amount per unit', 'nexa-efris' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_nexa_efris_excise_rate',
				'label'       => __( 'Excise rate', 'nexa-efris' ),
				'placeholder' => __( 'e.g. 12 (for 12%) or 150 (UGX per unit)', 'nexa-efris' ),
				'type'        => 'number',
				'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_nexa_efris_excise_unit',
				'label'       => __( 'Excise unit code', 'nexa-efris' ),
				'placeholder' => __( 'Only for per-unit excise', 'nexa-efris' ),
			)
		);

		if ( $product_object && $product_object->get_id() ) {
			echo '<p class="form-field">' . wp_kses_post( self::status_html( $product_object ) ) . '</p>';
		}
		echo '</div>';
	}

	private static function unit_field( $name, $value, $label_id = null ) {
		$units = Nexa_EFRIS_Settings::units();
		$list  = 'nexa-efris-units';
		woocommerce_wp_text_input(
			array(
				'id'                => $label_id ? $label_id : $name,
				'name'              => $name,
				'value'             => $value,
				'label'             => __( 'Unit of measure code', 'nexa-efris' ),
				'placeholder'       => Nexa_EFRIS_Settings::get( 'default_unit' ),
				'custom_attributes' => $units ? array( 'list' => $list ) : array(),
			)
		);
		static $printed = false;
		if ( $units && ! $printed ) {
			$printed = true;
			echo '<datalist id="' . esc_attr( $list ) . '">';
			foreach ( $units as $code => $label ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</datalist>';
		}
	}

	public static function status_html( $product ) {
		$err = $product->get_meta( '_nexa_efris_sync_error' );
		$reg = $product->get_meta( '_nexa_efris_registered' );
		if ( $err ) {
			return '<span style="color:#b32d2e">' . esc_html__( 'EFRIS sync error:', 'nexa-efris' ) . ' ' . esc_html( $err ) . '</span>';
		}
		if ( $reg ) {
			/* translators: %s: date */
			return '<span style="color:#008a20">' . esc_html( sprintf( __( 'Registered in EFRIS (%s)', 'nexa-efris' ), wp_date( 'd M Y H:i', (int) $reg ) ) ) . '</span>';
		}
		return esc_html__( 'Not yet registered in EFRIS.', 'nexa-efris' );
	}

	public static function save( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verifies the product nonce.
		foreach ( array( 'item_code', 'commodity', 'unit', 'tax', 'excise_code', 'excise_rule', 'excise_rate', 'excise_unit' ) as $f ) {
			$k = '_nexa_efris_' . $f;
			if ( isset( $_POST[ $k ] ) ) {
				$product->update_meta_data( $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) );
			}
		}
		// phpcs:enable
	}

	public static function variation_fields( $loop, $variation_data, $variation ) {
		$v = wc_get_product( $variation->ID );
		echo '<div class="nexa-efris-variation" style="clear:both">';
		woocommerce_wp_text_input(
			array(
				'id'            => "_nexa_efris_item_code_{$loop}",
				'name'          => "_nexa_efris_item_code[{$loop}]",
				'value'         => $v ? $v->get_meta( '_nexa_efris_item_code' ) : '',
				'label'         => __( 'EFRIS item code', 'nexa-efris' ),
				'placeholder'   => __( 'Defaults to variation SKU', 'nexa-efris' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "_nexa_efris_unit_{$loop}",
				'name'          => "_nexa_efris_unit[{$loop}]",
				'value'         => $v ? $v->get_meta( '_nexa_efris_unit' ) : '',
				'label'         => __( 'EFRIS unit code', 'nexa-efris' ),
				'placeholder'   => __( 'Inherit from parent', 'nexa-efris' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		if ( $v ) {
			echo '<p class="form-row form-row-full">' . wp_kses_post( self::status_html( $v ) ) . '</p>';
		}
		echo '</div>';
	}

	public static function save_variation( $variation, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification
		foreach ( array( 'item_code', 'unit' ) as $f ) {
			$k = '_nexa_efris_' . $f;
			if ( isset( $_POST[ $k ][ $i ] ) ) {
				$variation->update_meta_data( $k, sanitize_text_field( wp_unslash( $_POST[ $k ][ $i ] ) ) );
			}
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------------------
	 * Resolution helpers
	 * ------------------------------------------------------------------ */

	/** Read an EFRIS field from product → parent → plugin default. */
	public static function resolve( $product, $field ) {
		$v = (string) $product->get_meta( '_nexa_efris_' . $field );
		if ( '' === $v && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$v = (string) $parent->get_meta( '_nexa_efris_' . $field );
			}
		}
		if ( '' === $v ) {
			$map = array(
				'commodity' => 'default_commodity',
				'unit'      => 'default_unit',
				'tax'       => 'default_tax',
			);
			if ( isset( $map[ $field ] ) ) {
				$v = Nexa_EFRIS_Settings::get( $map[ $field ] );
			}
		}
		return $v;
	}

	public static function item_code( $product ) {
		$code = (string) $product->get_meta( '_nexa_efris_item_code' );
		if ( '' === $code ) {
			$code = (string) $product->get_sku( 'edit' ); // 'edit' so variations don't inherit the parent SKU.
		}
		if ( '' === $code ) {
			$code = 'WC-' . $product->get_id();
		}
		return apply_filters( 'nexa_efris_item_code', $code, $product );
	}

	/**
	 * Excise settings for a product, or null.
	 *
	 * @return array|null { code, rule ('1' % | '2' per unit), rate (float), unit }
	 */
	public static function excise_for( $product ) {
		$code = self::resolve( $product, 'excise_code' );
		if ( '' === $code ) {
			return null;
		}
		$rule = self::resolve( $product, 'excise_rule' );
		return array(
			'code' => $code,
			'rule' => '2' === $rule ? '2' : '1',
			'rate' => (float) self::resolve( $product, 'excise_rate' ),
			'unit' => self::resolve( $product, 'excise_unit' ),
		);
	}

	public static function tax_for( $product ) {
		$cats = self::tax_categories();
		$key  = self::resolve( $product, 'tax' );
		return isset( $cats[ $key ] ) ? $cats[ $key ] : $cats['standard'];
	}

	/* ------------------------------------------------------------------
	 * Sync
	 * ------------------------------------------------------------------ */

	public static function maybe_queue_sync( $product ) {
		if ( ! Nexa_EFRIS_Settings::is_on( 'enabled' ) || ! Nexa_EFRIS_Settings::is_on( 'auto_sync' ) || ! is_admin() ) {
			return;
		}
		if ( 'publish' !== $product->get_status() || $product->is_type( 'variation' ) ) {
			return;
		}
		$args = array( 'product_id' => $product->get_id() );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'nexa_efris_sync_product', $args, 'nexa-efris' ) ) {
			return;
		}
		as_enqueue_async_action( 'nexa_efris_sync_product', $args, 'nexa-efris' );
	}

	public static function sync_by_id( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			self::sync( $product );
		}
	}

	/**
	 * Register or update a product (and its variations) in EFRIS.
	 *
	 * @return true|WP_Error
	 */
	public static function sync( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$errors = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$r = self::sync( $child );
					if ( is_wp_error( $r ) ) {
						$errors[] = $child->get_name() . ': ' . $r->get_error_message();
					}
				}
			}
			return $errors ? new WP_Error( 'nexa_efris_sync', implode( '; ', $errors ) ) : true;
		}

		$result = self::send_goods( $product, $product->get_meta( '_nexa_efris_registered' ) ? '102' : '101' );

		// Already exists in EFRIS → retry as an update.
		if ( is_wp_error( $result ) && false !== stripos( $result->get_error_message(), 'exist' ) ) {
			$result = self::send_goods( $product, '102' );
		}

		if ( is_wp_error( $result ) ) {
			$product->update_meta_data( '_nexa_efris_sync_error', $result->get_error_message() );
		} else {
			$product->update_meta_data( '_nexa_efris_registered', time() );
			$product->delete_meta_data( '_nexa_efris_sync_error' );
		}

		// Save meta without re-triggering our own save hook.
		remove_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'maybe_queue_sync' ) );
		$product->save_meta_data();
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'maybe_queue_sync' ) );

		return $result;
	}

	private static function send_goods( $product, $operation ) {
		$commodity = self::resolve( $product, 'commodity' );
		$unit      = self::resolve( $product, 'unit' );
		if ( '' === $commodity || '' === $unit ) {
			return new WP_Error( 'nexa_efris_config', __( 'Missing commodity category code or unit of measure (set on the product or in EFRIS settings).', 'nexa-efris' ) );
		}

		$price  = (float) wc_get_price_including_tax( $product );
		$excise = self::excise_for( $product );

		$goods = array(
			'operationType'       => $operation, // 101 add, 102 modify.
			'goodsName'           => mb_substr( wp_strip_all_tags( $product->get_name() ), 0, 200 ),
			'goodsCode'           => self::item_code( $product ),
			'measureUnit'         => $unit,
			'unitPrice'           => self::money( $price > 0 ? $price : 1 ),
			'currency'            => '101', // UGX.
			'commodityCategoryId' => $commodity,
			'haveExciseTax'       => $excise ? '101' : '102', // 101 yes, 102 no.
			'description'         => '',
			'stockPrewarning'     => '0',
			'pieceMeasureUnit'    => '',
			'havePieceUnit'       => '102',
			'pieceUnitPrice'      => '',
			'packageScaledValue'  => '',
			'pieceScaledValue'    => '',
			'exciseDutyCode'      => $excise ? $excise['code'] : '',
			'haveOtherUnit'       => '102',
			'goodsTypeCode'       => '101',
		);

		$goods = apply_filters( 'nexa_efris_goods_payload', $goods, $product );

		$res = ( new Nexa_EFRIS_Client() )->call( 'T130', array( $goods ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::first_item_error( $res );
	}

	/** T130/T131 return a list of failed items; empty list = success. */
	private static function first_item_error( $res ) {
		if ( is_array( $res ) ) {
			foreach ( $res as $item ) {
				if ( is_array( $item ) && isset( $item['returnCode'] ) && '00' !== (string) $item['returnCode'] ) {
					return new WP_Error( 'nexa_efris_' . $item['returnCode'], isset( $item['returnMessage'] ) ? $item['returnMessage'] : 'EFRIS item error' );
				}
			}
		}
		return true;
	}

	/**
	 * Increase EFRIS stock for a product (T131).
	 *
	 * @return true|WP_Error
	 */
	public static function stock_in( $product, $qty, $unit_price ) {
		$supplier = Nexa_EFRIS_Settings::get( 'business_name' ) ? Nexa_EFRIS_Settings::get( 'business_name' ) : Nexa_EFRIS_Settings::get( 'legal_name' );
		$payload  = array(
			'goodsStockIn'     => array(
				'operationType'     => '101', // Increase.
				'supplierTin'       => '',
				'supplierName'      => $supplier,
				'adjustType'        => '',
				'remarks'           => 'WooCommerce auto stock-in',
				'stockInDate'       => Nexa_EFRIS_Client::now( 'Y-m-d' ),
				'stockInType'       => Nexa_EFRIS_Settings::get( 'stock_in_type' ),
				'productionBatchNo' => '',
				'productionDate'    => '',
				'branchId'          => Nexa_EFRIS_Settings::get( 'branch_id' ),
				'invoiceNo'         => '',
				'isCheckBatchNo'    => '0',
				'rollBackIfError'   => '0',
				'goodsTypeCode'     => '101',
			),
			'goodsStockInItem' => array(
				array(
					'commodityGoodsId' => '',
					'goodsCode'        => self::item_code( $product ),
					'measureUnit'      => self::resolve( $product, 'unit' ),
					'quantity'         => (string) $qty,
					'unitPrice'        => self::money( $unit_price ),
					'remarks'          => '',
					'fuelTankId'       => '',
					'lossQuantity'     => '',
					'originalQuantity' => '',
				),
			),
		);

		$payload = apply_filters( 'nexa_efris_stock_in_payload', $payload, $product, $qty );
		$res     = ( new Nexa_EFRIS_Client() )->call( 'T131', $payload );
		return is_wp_error( $res ) ? $res : self::first_item_error( $res );
	}

	public static function money( $n ) {
		return number_format( (float) $n, 2, '.', '' );
	}
}
