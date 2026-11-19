<?php
/**
 * Settings access helper. Every option is stored as nexa_efris_{key}.
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Settings {

	public static function defaults() {
		return array(
			'enabled'              => 'no',
			'environment'          => 'test',
			'connection_mode'      => 'enabler',
			'enabler_url'          => 'http://localhost:9880/efristcs/ws/tcsapp/getInformation',
			'direct_url_test'      => 'https://efristest.ura.go.ug/efrisws/ws/taapp/getInformation',
			'direct_url_live'      => 'https://efrisws.ura.go.ug/ws/taapp/getInformation',
			'tin'                  => '',
			'brn'                  => '',
			'device_no'            => '',
			'legal_name'           => '',
			'business_name'        => '',
			'address'              => '',
			'phone'                => '',
			'email'                => '',
			'place_of_business'    => '',
			'operator'             => 'admin',
			'key_path'             => '',
			'key_password'         => '',
			'trigger_status'       => 'processing',
			'invoice_kind'         => '1',
			'default_tax'          => 'standard',
			'default_commodity'    => '',
			'default_unit'         => '',
			'auto_sync'            => 'yes',
			'auto_stock'           => 'no',
			'stock_in_type'        => '102',
			'default_payment_mode' => '102',
			'shipping_commodity'   => '',
			'shipping_unit'        => '',
			'fee_commodity'        => '',
			'fee_unit'             => '',
			'branch_id'            => '',
			'auto_credit_note'     => 'yes',
			'cn_reason_code'       => '102',
			'show_customer'        => 'yes',
			'debug'                => 'no',
		);
	}

	public static function get( $key ) {
		$defaults = self::defaults();
		$value    = get_option( 'nexa_efris_' . $key, isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
		return is_string( $value ) ? trim( $value ) : $value;
	}

	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	public static function is_direct() {
		return 'direct' === self::get( 'connection_mode' );
	}

	/** Branches from a cached T138 call, as id => name. */
	public static function branches() {
		$list = get_option( 'nexa_efris_branches', array() );
		$out  = array();
		foreach ( (array) $list as $b ) {
			if ( is_array( $b ) && isset( $b['branchId'] ) ) {
				$out[ $b['branchId'] ] = isset( $b['branchName'] ) ? $b['branchName'] : $b['branchId'];
			}
		}
		return $out;
	}

	/** Units of measure from a cached T115 dictionary, as code => label. */
	public static function units() {
		$dict  = get_option( 'nexa_efris_dictionary', array() );
		$units = array();
		if ( is_array( $dict ) && ! empty( $dict['rateUnit'] ) && is_array( $dict['rateUnit'] ) ) {
			foreach ( $dict['rateUnit'] as $u ) {
				if ( isset( $u['value'], $u['name'] ) ) {
					$units[ $u['value'] ] = $u['name'];
				}
			}
		}
		return $units;
	}
}
