<?php
/**
 * EFRIS web-service client.
 *
 * Two connection modes:
 *  - enabler: talks plain (base64) JSON to URA's Offline Enabler running on the server.
 *             The enabler handles AES/RSA, signing and offline queueing.
 *  - direct:  talks to URA directly. Business calls are AES-encrypted and RSA-signed
 *             with the taxpayer private key whose public key was uploaded on the EFRIS portal.
 *
 * Every request uses the same envelope: data / globalInfo / returnStateInfo.
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_Client {

	const AES_TRANSIENT = 'nexa_efris_aes_key';

	/** Interfaces that are never encrypted in direct mode. */
	const PLAIN_INTERFACES = array( 'T101', 'T102', 'T103', 'T104' );

	public function endpoint() {
		if ( ! Nexa_EFRIS_Settings::is_direct() ) {
			return Nexa_EFRIS_Settings::get( 'enabler_url' );
		}
		return 'live' === Nexa_EFRIS_Settings::get( 'environment' )
			? Nexa_EFRIS_Settings::get( 'direct_url_live' )
			: Nexa_EFRIS_Settings::get( 'direct_url_test' );
	}

	/**
	 * Call an EFRIS interface.
	 *
	 * @param string     $code    Interface code, e.g. T109.
	 * @param array|null $payload Business data (will be JSON-encoded).
	 * @return array|WP_Error Decoded response content (array) or error.
	 */
	public function call( $code, $payload = null ) {
		$json    = ( null === $payload ) ? '' : wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$encrypt = Nexa_EFRIS_Settings::is_direct() && ! in_array( $code, self::PLAIN_INTERFACES, true );

		$content   = '';
		$signature = '';
		$key       = null;

		if ( '' !== $json ) {
			if ( $encrypt ) {
				$key = $this->get_aes_key();
				if ( is_wp_error( $key ) ) {
					return $key;
				}
				$cipher = $this->aes_encrypt( $json, $key );
				if ( is_wp_error( $cipher ) ) {
					return $cipher;
				}
				$content   = base64_encode( $cipher );
				$signature = $this->sign( $content );
				if ( is_wp_error( $signature ) ) {
					return $signature;
				}
			} else {
				$content = base64_encode( $json );
			}
		}

		$envelope = array(
			'data'            => array(
				'content'         => $content,
				'signature'       => $signature,
				'dataDescription' => array(
					'codeType'    => $encrypt ? '1' : '0',
					'encryptCode' => $encrypt ? '2' : '1',
					'zipCode'     => '0',
				),
			),
			'globalInfo'      => $this->global_info( $code ),
			'returnStateInfo' => array(
				'returnCode'    => '',
				'returnMessage' => '',
			),
		);

		Nexa_EFRIS_Logger::debug( "→ {$code} request: " . $json );

		$response = wp_remote_post(
			$this->endpoint(),
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $envelope ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Nexa_EFRIS_Logger::error( "{$code} transport error: " . $response->get_error_message() );
			return $response;
		}

		$http = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $http || ! is_array( $body ) ) {
			$msg = sprintf( 'EFRIS %s: unexpected HTTP %s response.', $code, $http );
			Nexa_EFRIS_Logger::error( $msg . ' Body: ' . substr( wp_remote_retrieve_body( $response ), 0, 500 ) );
			return new WP_Error( 'nexa_efris_http', $msg );
		}

		$state       = isset( $body['returnStateInfo'] ) ? $body['returnStateInfo'] : array();
		$return_code = isset( $state['returnCode'] ) ? (string) $state['returnCode'] : '';
		$return_msg  = isset( $state['returnMessage'] ) ? (string) $state['returnMessage'] : '';

		if ( '00' !== $return_code ) {
			// An expired/invalid AES key: drop it so the next call fetches a fresh one.
			if ( $encrypt ) {
				delete_transient( self::AES_TRANSIENT );
			}
			Nexa_EFRIS_Logger::error( "{$code} failed [{$return_code}] {$return_msg}" );
			return new WP_Error( 'nexa_efris_' . $return_code, $return_msg ? $return_msg : "EFRIS returned code {$return_code}", array( 'code' => $return_code ) );
		}

		$data    = isset( $body['data'] ) ? $body['data'] : array();
		$decoded = $this->decode_content( $data, $key );

		Nexa_EFRIS_Logger::debug( "← {$code} response: " . wp_json_encode( $decoded ) );

		return $decoded;
	}

	private function global_info( $code ) {
		return array(
			'appId'          => 'AP04',
			'version'        => '1.1.20191201',
			'dataExchangeId' => str_replace( '-', '', wp_generate_uuid4() ),
			'interfaceCode'  => $code,
			'requestCode'    => 'TP',
			'requestTime'    => self::now(),
			'responseCode'   => 'TA',
			'userName'       => 'admin',
			'deviceMAC'      => 'FFFFFFFFFFFF',
			'deviceNo'       => Nexa_EFRIS_Settings::get( 'device_no' ),
			'tin'            => Nexa_EFRIS_Settings::get( 'tin' ),
			'brn'            => Nexa_EFRIS_Settings::get( 'brn' ),
			'taxpayerID'     => '1',
			'longitude'      => '32.5825',
			'latitude'       => '0.3476',
			'agentType'      => '0',
			'extendField'    => array(
				'responseDateFormat' => 'dd/MM/yyyy',
				'responseTimeFormat' => 'dd/MM/yyyy HH:mm:ss',
				'referenceNo'        => '',
				'operatorName'       => Nexa_EFRIS_Settings::get( 'operator' ),
			),
		);
	}

	/** Current Uganda time; URA rejects requests more than ~10 minutes off. */
	public static function now( $format = 'Y-m-d H:i:s' ) {
		$dt = new DateTime( 'now', new DateTimeZone( 'Africa/Kampala' ) );
		return $dt->format( $format );
	}

	/**
	 * Decode response content. Tries the possible combinations of
	 * base64 → (AES decrypt) → (gunzip) so it tolerates either order.
	 */
	private function decode_content( $data, $key ) {
		$content = isset( $data['content'] ) ? $data['content'] : '';
		if ( '' === $content || null === $content ) {
			return array();
		}
		$desc   = isset( $data['dataDescription'] ) ? $data['dataDescription'] : array();
		$is_aes = isset( $desc['encryptCode'] ) && '2' === (string) $desc['encryptCode'];
		$is_zip = isset( $desc['zipCode'] ) && '1' === (string) $desc['zipCode'];

		$raw = base64_decode( $content );
		if ( false === $raw ) {
			return array();
		}

		$candidates = array( $raw );

		if ( $is_zip ) {
			$unz = @gzdecode( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $unz ) {
				$candidates[] = $unz;
			}
		}

		if ( $is_aes ) {
			if ( null === $key ) {
				$key = $this->get_aes_key();
			}
			if ( ! is_wp_error( $key ) ) {
				foreach ( $candidates as $c ) {
					$dec = $this->aes_decrypt( $c, $key );
					if ( false !== $dec && null !== $dec ) {
						$candidates[] = $dec;
						$unz          = @gzdecode( $dec ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
						if ( false !== $unz ) {
							$candidates[] = $unz;
						}
					}
				}
			}
		}

		foreach ( array_reverse( $candidates ) as $c ) {
			$json = json_decode( $c, true );
			if ( null !== $json ) {
				return is_array( $json ) ? $json : array( 'value' => $json );
			}
		}

		return array( 'raw' => $content );
	}

	/* ------------------------------------------------------------------
	 * Direct-mode cryptography
	 * ------------------------------------------------------------------ */

	/** Fetch (T104) or reuse the daily AES key. Returns raw key bytes. */
	public function get_aes_key( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::AES_TRANSIENT );
			if ( $cached ) {
				return base64_decode( $cached );
			}
		}

		$res = $this->call( 'T104' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// URA's field name is misspelled "passowrdDes" in the spec; accept both.
		$enc = '';
		foreach ( array( 'passowrdDes', 'passwordDes' ) as $f ) {
			if ( ! empty( $res[ $f ] ) ) {
				$enc = $res[ $f ];
				break;
			}
		}
		if ( '' === $enc ) {
			return new WP_Error( 'nexa_efris_aes', 'T104 did not return an encrypted AES key.' );
		}

		$pk = $this->private_key();
		if ( is_wp_error( $pk ) ) {
			return $pk;
		}

		$plain = '';
		if ( ! openssl_private_decrypt( base64_decode( $enc ), $plain, $pk, OPENSSL_PKCS1_PADDING ) ) {
			return new WP_Error( 'nexa_efris_aes', 'Could not decrypt the AES key. Is the uploaded public key on the EFRIS portal the pair of your private key?' );
		}

		$key = base64_decode( $plain, true );
		if ( false === $key || ! in_array( strlen( $key ), array( 16, 24, 32 ), true ) ) {
			$key = $plain;
		}

		// Valid for 24h on URA's side; refresh a bit earlier.
		set_transient( self::AES_TRANSIENT, base64_encode( $key ), 20 * HOUR_IN_SECONDS );
		return $key;
	}

	private function aes_cipher( $key ) {
		return 'aes-' . ( strlen( $key ) * 8 ) . '-ecb';
	}

	private function aes_encrypt( $plain, $key ) {
		$out = openssl_encrypt( $plain, $this->aes_cipher( $key ), $key, OPENSSL_RAW_DATA );
		return false === $out ? new WP_Error( 'nexa_efris_aes', 'AES encryption failed.' ) : $out;
	}

	private function aes_decrypt( $cipher, $key ) {
		return openssl_decrypt( $cipher, $this->aes_cipher( $key ), $key, OPENSSL_RAW_DATA );
	}

	private function sign( $content ) {
		$pk = $this->private_key();
		if ( is_wp_error( $pk ) ) {
			return $pk;
		}
		$sig = '';
		if ( ! openssl_sign( $content, $sig, $pk, OPENSSL_ALGO_SHA1 ) ) {
			return new WP_Error( 'nexa_efris_sign', 'Could not sign the request with the private key.' );
		}
		return base64_encode( $sig );
	}

	/** Load the private key from a .pfx/.p12 keystore or a PEM file. */
	private function private_key() {
		static $key = null;
		if ( null !== $key ) {
			return $key;
		}
		$path = Nexa_EFRIS_Settings::get( 'key_path' );
		$pass = Nexa_EFRIS_Settings::get( 'key_password' );

		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'nexa_efris_key', 'Private key file not found or not readable. Check the key path in EFRIS settings.' );
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( preg_match( '/\.(pfx|p12)$/i', $path ) ) {
			$certs = array();
			if ( ! openssl_pkcs12_read( $data, $certs, $pass ) ) {
				return new WP_Error( 'nexa_efris_key', 'Could not open the .pfx keystore. Check the password. (On OpenSSL 3 you may need to convert legacy .pfx files to PEM.)' );
			}
			$key = openssl_pkey_get_private( $certs['pkey'] );
		} else {
			$key = openssl_pkey_get_private( $data, $pass );
		}

		if ( ! $key ) {
			$key = null;
			return new WP_Error( 'nexa_efris_key', 'Invalid private key.' );
		}
		return $key;
	}

	/* ------------------------------------------------------------------
	 * Convenience wrappers
	 * ------------------------------------------------------------------ */

	public function server_time() {
		return $this->call( 'T101' );
	}

	public function taxpayer_info() {
		return $this->call( 'T103' );
	}

	public function dictionary() {
		return $this->call( 'T115' );
	}
}
