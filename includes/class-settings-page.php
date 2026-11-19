<?php
/**
 * WooCommerce > Settings > EFRIS tab.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Nexa_EFRIS_Settings_Page' ) ) :

	class Nexa_EFRIS_Settings_Page extends WC_Settings_Page {

		public function __construct() {
			$this->id    = 'nexa_efris';
			$this->label = __( 'EFRIS', 'nexa-efris' );
			parent::__construct();
		}

		protected function get_settings_for_default_section() {
			$p = 'nexa_efris_';
			$d = Nexa_EFRIS_Settings::defaults();

			return array(
				array(
					'title' => __( 'URA EFRIS connection', 'nexa-efris' ),
					'type'  => 'title',
					'desc'  => __( 'Register on the EFRIS test portal first, then fill in your TIN and the device number URA assigned you. Use the Tools below to test the connection.', 'nexa-efris' ),
					'id'    => $p . 'conn',
				),
				array(
					'title'   => __( 'Enable fiscalisation', 'nexa-efris' ),
					'id'      => $p . 'enabled',
					'type'    => 'checkbox',
					'default' => $d['enabled'],
					'desc'    => __( 'Send orders to EFRIS automatically', 'nexa-efris' ),
				),
				array(
					'title'   => __( 'Environment', 'nexa-efris' ),
					'id'      => $p . 'environment',
					'type'    => 'select',
					'default' => $d['environment'],
					'options' => array(
						'test' => __( 'Test (sandbox)', 'nexa-efris' ),
						'live' => __( 'Production', 'nexa-efris' ),
					),
				),
				array(
					'title'   => __( 'Connection mode', 'nexa-efris' ),
					'id'      => $p . 'connection_mode',
					'type'    => 'select',
					'default' => $d['connection_mode'],
					'options' => array(
						'enabler' => __( 'Offline Enabler (recommended – handles encryption & offline)', 'nexa-efris' ),
						'direct'  => __( 'Direct API (plugin encrypts & signs)', 'nexa-efris' ),
					),
				),
				array(
					'title'   => __( 'Offline Enabler URL', 'nexa-efris' ),
					'id'      => $p . 'enabler_url',
					'type'    => 'text',
					'default' => $d['enabler_url'],
					'desc'    => __( 'Where the URA Offline Enabler listens on your server.', 'nexa-efris' ),
				),
				array(
					'title'   => __( 'Direct API URL (test)', 'nexa-efris' ),
					'id'      => $p . 'direct_url_test',
					'type'    => 'text',
					'default' => $d['direct_url_test'],
				),
				array(
					'title'   => __( 'Direct API URL (production)', 'nexa-efris' ),
					'id'      => $p . 'direct_url_live',
					'type'    => 'text',
					'default' => $d['direct_url_live'],
				),
				array(
					'title'    => __( 'Private key file (direct mode)', 'nexa-efris' ),
					'id'       => $p . 'key_path',
					'type'     => 'text',
					'desc'     => __( 'Absolute server path to your .pfx/.p12 or PEM private key. Keep it OUTSIDE the web root. Its public key must be uploaded on the EFRIS portal.', 'nexa-efris' ),
					'desc_tip' => false,
				),
				array(
					'title' => __( 'Private key password', 'nexa-efris' ),
					'id'    => $p . 'key_password',
					'type'  => 'password',
				),
				array(
					'type' => 'sectionend',
					'id'   => $p . 'conn',
				),

				array(
					'title' => __( 'Seller details', 'nexa-efris' ),
					'type'  => 'title',
					'desc'  => __( '“Test connection” fills empty fields from your EFRIS taxpayer record.', 'nexa-efris' ),
					'id'    => $p . 'seller',
				),
				array( 'title' => __( 'TIN', 'nexa-efris' ), 'id' => $p . 'tin', 'type' => 'text' ),
				array( 'title' => __( 'BRN / NIN', 'nexa-efris' ), 'id' => $p . 'brn', 'type' => 'text' ),
				array( 'title' => __( 'Device number', 'nexa-efris' ), 'id' => $p . 'device_no', 'type' => 'text', 'desc' => __( 'Virtual device number assigned by URA.', 'nexa-efris' ) ),
				array( 'title' => __( 'Legal name', 'nexa-efris' ), 'id' => $p . 'legal_name', 'type' => 'text' ),
				array( 'title' => __( 'Business name', 'nexa-efris' ), 'id' => $p . 'business_name', 'type' => 'text' ),
				array( 'title' => __( 'Address', 'nexa-efris' ), 'id' => $p . 'address', 'type' => 'text' ),
				array( 'title' => __( 'Phone', 'nexa-efris' ), 'id' => $p . 'phone', 'type' => 'text' ),
				array( 'title' => __( 'Email', 'nexa-efris' ), 'id' => $p . 'email', 'type' => 'email' ),
				array( 'title' => __( 'Place of business', 'nexa-efris' ), 'id' => $p . 'place_of_business', 'type' => 'text' ),
				array( 'title' => __( 'Operator name', 'nexa-efris' ), 'id' => $p . 'operator', 'type' => 'text', 'default' => $d['operator'] ),
				self::branch_field( $p ),
				array(
					'type' => 'sectionend',
					'id'   => $p . 'seller',
				),

				array(
					'title' => __( 'Invoicing', 'nexa-efris' ),
					'type'  => 'title',
					'id'    => $p . 'inv',
				),
				array(
					'title'   => __( 'Fiscalise when order is', 'nexa-efris' ),
					'id'      => $p . 'trigger_status',
					'type'    => 'select',
					'default' => $d['trigger_status'],
					'options' => array(
						'processing' => __( 'Processing (paid)', 'nexa-efris' ),
						'completed'  => __( 'Completed', 'nexa-efris' ),
					),
				),
				array(
					'title'   => __( 'Document type', 'nexa-efris' ),
					'id'      => $p . 'invoice_kind',
					'type'    => 'select',
					'default' => $d['invoice_kind'],
					'options' => array(
						'1' => __( 'Invoice', 'nexa-efris' ),
						'2' => __( 'Receipt', 'nexa-efris' ),
					),
				),
				array(
					'title'   => __( 'Default tax category', 'nexa-efris' ),
					'id'      => $p . 'default_tax',
					'type'    => 'select',
					'default' => $d['default_tax'],
					'options' => self::tax_options(),
				),
				array(
					'title' => __( 'Default commodity category code', 'nexa-efris' ),
					'id'    => $p . 'default_commodity',
					'type'  => 'text',
					'desc'  => __( 'EFRIS goods/service category code used when a product has none.', 'nexa-efris' ),
				),
				array(
					'title' => __( 'Default unit of measure code', 'nexa-efris' ),
					'id'    => $p . 'default_unit',
					'type'  => 'text',
					'desc'  => __( 'EFRIS unit code (fetch the dictionary under Tools to see valid codes).', 'nexa-efris' ),
				),
				array(
					'title'   => __( 'Default payment mode', 'nexa-efris' ),
					'id'      => $p . 'default_payment_mode',
					'type'    => 'select',
					'default' => $d['default_payment_mode'],
					'options' => self::payment_modes(),
					'desc'    => __( 'Used for gateways not mapped automatically (bank transfer → EFT, COD → cash, cheque → cheque; gateways with “momo/mobile/mtn/airtel” → mobile money).', 'nexa-efris' ),
				),
				array(
					'title' => __( 'Shipping commodity code', 'nexa-efris' ),
					'id'    => $p . 'shipping_commodity',
					'type'  => 'text',
					'desc'  => __( 'Service category code for delivery charges. Leave empty to leave shipping off the EFRIS invoice.', 'nexa-efris' ),
				),
				array(
					'title' => __( 'Shipping unit code', 'nexa-efris' ),
					'id'    => $p . 'shipping_unit',
					'type'  => 'text',
				),
				array(
					'title' => __( 'Fees commodity code', 'nexa-efris' ),
					'id'    => $p . 'fee_commodity',
					'type'  => 'text',
					'desc'  => __( 'Service category code for positive cart fees (e.g. packaging, service charge). Negative fees (discounts) are spread across the items automatically.', 'nexa-efris' ),
				),
				array(
					'title' => __( 'Fees unit code', 'nexa-efris' ),
					'id'    => $p . 'fee_unit',
					'type'  => 'text',
				),
				array(
					'type' => 'sectionend',
					'id'   => $p . 'inv',
				),

				array(
					'title' => __( 'Products & stock', 'nexa-efris' ),
					'type'  => 'title',
					'id'    => $p . 'prod',
				),
				array(
					'title'   => __( 'Auto-register products', 'nexa-efris' ),
					'id'      => $p . 'auto_sync',
					'type'    => 'checkbox',
					'default' => $d['auto_sync'],
					'desc'    => __( 'Register/update products in EFRIS (T130) on save and before invoicing', 'nexa-efris' ),
				),
				array(
					'title'   => __( 'Auto stock-in before sale', 'nexa-efris' ),
					'id'      => $p . 'auto_stock',
					'type'    => 'checkbox',
					'default' => $d['auto_stock'],
					'desc'    => __( 'Increase EFRIS stock (T131) by the sold quantity before issuing the invoice. Use only if you do not record purchases in EFRIS separately.', 'nexa-efris' ),
				),
				array(
					'title'   => __( 'Stock-in type', 'nexa-efris' ),
					'id'      => $p . 'stock_in_type',
					'type'    => 'select',
					'default' => $d['stock_in_type'],
					'options' => array(
						'101' => __( 'Import', 'nexa-efris' ),
						'102' => __( 'Local purchase', 'nexa-efris' ),
						'103' => __( 'Manufacture / assembly', 'nexa-efris' ),
						'104' => __( 'Opening stock', 'nexa-efris' ),
					),
				),
				array(
					'type' => 'sectionend',
					'id'   => $p . 'prod',
				),

				array(
					'title' => __( 'Refunds → credit notes', 'nexa-efris' ),
					'type'  => 'title',
					'desc'  => __( 'Credit notes are applications that URA approves. The plugin submits them on refund and checks approval hourly.', 'nexa-efris' ),
					'id'    => $p . 'cn',
				),
				array(
					'title'   => __( 'Auto credit note on refund', 'nexa-efris' ),
					'id'      => $p . 'auto_credit_note',
					'type'    => 'checkbox',
					'default' => $d['auto_credit_note'],
					'desc'    => __( 'Apply for an EFRIS credit note (T110) when a fiscalised order is refunded', 'nexa-efris' ),
				),
				array(
					'title'   => __( 'Default reason', 'nexa-efris' ),
					'id'      => $p . 'cn_reason_code',
					'type'    => 'select',
					'default' => $d['cn_reason_code'],
					'options' => array(
						'101' => __( 'Return of products (expiry or damage)', 'nexa-efris' ),
						'102' => __( 'Cancellation of the purchase', 'nexa-efris' ),
						'103' => __( 'Invoice amount wrongly stated (miscalculation)', 'nexa-efris' ),
						'104' => __( 'Partial or complete waive-off', 'nexa-efris' ),
						'105' => __( 'Others (uses the refund reason text)', 'nexa-efris' ),
					),
				),
				array(
					'type' => 'sectionend',
					'id'   => $p . 'cn',
				),

				array(
					'title' => __( 'Display & debugging', 'nexa-efris' ),
					'type'  => 'title',
					'id'    => $p . 'disp',
				),
				array(
					'title'   => __( 'Show to customers', 'nexa-efris' ),
					'id'      => $p . 'show_customer',
					'type'    => 'checkbox',
					'default' => $d['show_customer'],
					'desc'    => __( 'Show FDN, verification code and QR on order emails and My Account', 'nexa-efris' ),
				),
				array(
					'title'   => __( 'Debug log', 'nexa-efris' ),
					'id'      => $p . 'debug',
					'type'    => 'checkbox',
					'default' => $d['debug'],
					'desc'    => __( 'Log full requests/responses to WooCommerce > Status > Logs (nexa-efris)', 'nexa-efris' ),
				),
				array(
					'type' => 'sectionend',
					'id'   => $p . 'disp',
				),
			);
		}

		public function output() {
			parent::output();
			Nexa_EFRIS_Admin::render_tools();
		}

		public function save() {
			parent::save();
			delete_transient( Nexa_EFRIS_Client::AES_TRANSIENT );
		}

		private static function branch_field( $p ) {
			$branches = Nexa_EFRIS_Settings::branches();
			if ( $branches ) {
				return array(
					'title'   => __( 'Branch', 'nexa-efris' ),
					'id'      => $p . 'branch_id',
					'type'    => 'select',
					'options' => array( '' => __( 'Head office / none', 'nexa-efris' ) ) + $branches,
				);
			}
			return array(
				'title' => __( 'Branch ID', 'nexa-efris' ),
				'id'    => $p . 'branch_id',
				'type'  => 'text',
				'desc'  => __( 'Optional. Use “Fetch branches” under Tools to pick from a list.', 'nexa-efris' ),
			);
		}

		public static function tax_options() {
			return array(
				'standard' => __( 'Standard rated (18%)', 'nexa-efris' ),
				'zero'     => __( 'Zero rated (0%)', 'nexa-efris' ),
				'exempt'   => __( 'Exempt', 'nexa-efris' ),
			);
		}

		public static function payment_modes() {
			return array(
				'101' => __( 'Credit', 'nexa-efris' ),
				'102' => __( 'Cash', 'nexa-efris' ),
				'103' => __( 'Cheque', 'nexa-efris' ),
				'104' => __( 'Demand draft', 'nexa-efris' ),
				'105' => __( 'Mobile money', 'nexa-efris' ),
				'106' => __( 'Visa / MasterCard', 'nexa-efris' ),
				'107' => __( 'EFT', 'nexa-efris' ),
				'108' => __( 'POS', 'nexa-efris' ),
				'109' => __( 'RTGS', 'nexa-efris' ),
				'110' => __( 'Swift transfer', 'nexa-efris' ),
			);
		}
	}

endif;

return new Nexa_EFRIS_Settings_Page();
