<?php
/**
 * Self-contained QR Code generator (byte mode, ECC level M, versions 1–40).
 * Based on the public algorithm described in ISO/IEC 18004 (structure follows
 * Project Nayuki's reference implementation). Outputs SVG or PNG (GD).
 */

defined( 'ABSPATH' ) || exit;

class Nexa_EFRIS_QR {

	// Index 1 = ECC level M.
	private static $ecc_per_block = array(
		array( -1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
		array( -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28 ),
		array( -1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
		array( -1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
	);
	private static $num_blocks = array(
		array( -1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25 ),
		array( -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49 ),
		array( -1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68 ),
		array( -1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81 ),
	);

	const ECL_INDEX  = 1; // M.
	const ECL_FORMAT = 0; // M format bits.

	private $size;
	private $version;
	private $modules  = array();
	private $function = array();

	/** @return bool[][] matrix [y][x] true = dark */
	public static function matrix( $text ) {
		$q = new self();
		return $q->encode( (string) $text );
	}

	private function encode( $text ) {
		$data = array_values( unpack( 'C*', $text ) ? unpack( 'C*', $text ) : array() );
		$len  = count( $data );

		for ( $ver = 1; $ver <= 40; $ver++ ) {
			$cap_bits = self::data_codewords( $ver ) * 8;
			$cc_bits  = $ver <= 9 ? 8 : 16;
			if ( 4 + $cc_bits + $len * 8 <= $cap_bits ) {
				break;
			}
		}
		if ( $ver > 40 ) {
			throw new RuntimeException( 'QR data too long' );
		}
		$this->version = $ver;
		$this->size    = $ver * 4 + 17;

		// Bit stream.
		$bits = array();
		self::append( $bits, 4, 4 );
		self::append( $bits, $len, $ver <= 9 ? 8 : 16 );
		foreach ( $data as $b ) {
			self::append( $bits, $b, 8 );
		}
		$cap = self::data_codewords( $ver ) * 8;
		self::append( $bits, 0, min( 4, $cap - count( $bits ) ) );
		self::append( $bits, 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $cap; $pad ^= 0xEC ^ 0x11 ) {
			self::append( $bits, $pad, 8 );
		}
		$codewords = array_fill( 0, count( $bits ) >> 3, 0 );
		foreach ( $bits as $i => $bit ) {
			$codewords[ $i >> 3 ] |= $bit << ( 7 - ( $i & 7 ) );
		}

		$all = $this->add_ecc( $codewords );

		$this->modules  = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->function = $this->modules;
		$this->draw_function_patterns();
		$this->draw_codewords( $all );

		// Choose the mask with the lowest penalty.
		$best = 0;
		$min  = PHP_INT_MAX;
		for ( $m = 0; $m < 8; $m++ ) {
			$this->apply_mask( $m );
			$this->draw_format( $m );
			$p = $this->penalty();
			if ( $p < $min ) {
				$min  = $p;
				$best = $m;
			}
			$this->apply_mask( $m ); // Undo (XOR).
		}
		$this->apply_mask( $best );
		$this->draw_format( $best );

		return $this->modules;
	}

	private static function append( &$bits, $val, $len ) {
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$bits[] = ( $val >> $i ) & 1;
		}
	}

	private static function raw_modules( $ver ) {
		$r = ( 16 * $ver + 128 ) * $ver + 64;
		if ( $ver >= 2 ) {
			$na = intdiv( $ver, 7 ) + 2;
			$r -= ( 25 * $na - 10 ) * $na - 55;
			if ( $ver >= 7 ) {
				$r -= 36;
			}
		}
		return $r;
	}

	private static function data_codewords( $ver ) {
		return intdiv( self::raw_modules( $ver ), 8 ) - self::$ecc_per_block[ self::ECL_INDEX ][ $ver ] * self::$num_blocks[ self::ECL_INDEX ][ $ver ];
	}

	private function add_ecc( $data ) {
		$ver         = $this->version;
		$num_blocks  = self::$num_blocks[ self::ECL_INDEX ][ $ver ];
		$ecc_len     = self::$ecc_per_block[ self::ECL_INDEX ][ $ver ];
		$raw         = intdiv( self::raw_modules( $ver ), 8 );
		$num_short   = $num_blocks - $raw % $num_blocks;
		$short_len   = intdiv( $raw, $num_blocks );
		$divisor     = self::rs_divisor( $ecc_len );
		$blocks      = array();
		$k           = 0;
		for ( $i = 0; $i < $num_blocks; $i++ ) {
			$dlen = $short_len - $ecc_len + ( $i < $num_short ? 0 : 1 );
			$dat  = array_slice( $data, $k, $dlen );
			$k   += $dlen;
			$ecc  = self::rs_remainder( $dat, $divisor );
			if ( $i < $num_short ) {
				$dat[] = 0;
			}
			$blocks[] = array_merge( $dat, $ecc );
		}
		$out = array();
		$bl  = count( $blocks[0] );
		for ( $i = 0; $i < $bl; $i++ ) {
			foreach ( $blocks as $j => $b ) {
				if ( $i !== $short_len - $ecc_len || $j >= $num_short ) {
					$out[] = $b[ $i ];
				}
			}
		}
		return $out;
	}

	private static function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z & 0xFF;
	}

	private static function rs_divisor( $degree ) {
		$r      = array_fill( 0, $degree, 0 );
		$r[ $degree - 1 ] = 1;
		$root   = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$r[ $j ] = self::gf_mul( $r[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$r[ $j ] ^= $r[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $r;
	}

	private static function rs_remainder( $data, $divisor ) {
		$r = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor = $b ^ array_shift( $r );
			$r[]    = 0;
			foreach ( $divisor as $i => $d ) {
				$r[ $i ] ^= self::gf_mul( $d, $factor );
			}
		}
		return $r;
	}

	private function set_fn( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]  = (bool) $dark;
		$this->function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns() {
		$s = $this->size;
		for ( $i = 0; $i < $s; $i++ ) {
			$this->set_fn( 6, $i, 0 === $i % 2 );
			$this->set_fn( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $s - 4, 3 ), array( 3, $s - 4 ) ) as $c ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$x = $c[0] + $dx;
					$y = $c[1] + $dy;
					if ( $x >= 0 && $x < $s && $y >= 0 && $y < $s ) {
						$d = max( abs( $dx ), abs( $dy ) );
						$this->set_fn( $x, $y, 2 !== $d && 4 !== $d );
					}
				}
			}
		}
		$pos = $this->alignment_positions();
		$n   = count( $pos );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = 0; $j < $n; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $n - 1 === $j ) || ( $n - 1 === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_fn( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format( 0 ); // Reserve.
		if ( $this->version >= 7 ) {
			$rem = $this->version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = ( $this->version << 12 ) | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$bit = ( $bits >> $i ) & 1;
				$a   = $s - 11 + $i % 3;
				$b   = intdiv( $i, 3 );
				$this->set_fn( $a, $b, $bit );
				$this->set_fn( $b, $a, $bit );
			}
		}
	}

	private function alignment_positions() {
		$ver = $this->version;
		if ( 1 === $ver ) {
			return array();
		}
		$na   = intdiv( $ver, 7 ) + 2;
		$step = ( 32 === $ver ) ? 26 : (int) ceil( ( $ver * 4 + 4 ) / ( $na * 2 - 2 ) ) * 2;
		$res  = array( 6 );
		for ( $p = $this->size - 7; count( $res ) < $na; $p -= $step ) {
			array_splice( $res, 1, 0, array( $p ) );
		}
		return $res;
	}

	private function draw_format( $mask ) {
		$data = ( self::ECL_FORMAT << 3 ) | $mask;
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$s    = $this->size;
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_fn( 8, $i, ( $bits >> $i ) & 1 );
		}
		$this->set_fn( 8, 7, ( $bits >> 6 ) & 1 );
		$this->set_fn( 8, 8, ( $bits >> 7 ) & 1 );
		$this->set_fn( 7, 8, ( $bits >> 8 ) & 1 );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_fn( 14 - $i, 8, ( $bits >> $i ) & 1 );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_fn( $s - 1 - $i, 8, ( $bits >> $i ) & 1 );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_fn( 8, $s - 15 + $i, ( $bits >> $i ) & 1 );
		}
		$this->set_fn( 8, $s - 8, true );
	}

	private function draw_codewords( $data ) {
		$s     = $this->size;
		$i     = 0;
		$total = count( $data ) * 8;
		for ( $right = $s - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $s; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x  = $right - $j;
					$up = 0 === ( ( $right + 1 ) & 2 );
					$y  = $up ? $s - 1 - $vert : $vert;
					if ( ! $this->function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = (bool) ( ( $data[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						$i++;
					}
				}
			}
		}
	}

	private function apply_mask( $m ) {
		$s = $this->size;
		for ( $y = 0; $y < $s; $y++ ) {
			for ( $x = 0; $x < $s; $x++ ) {
				switch ( $m ) {
					case 0: $inv = 0 === ( $x + $y ) % 2; break;
					case 1: $inv = 0 === $y % 2; break;
					case 2: $inv = 0 === $x % 3; break;
					case 3: $inv = 0 === ( $x + $y ) % 3; break;
					case 4: $inv = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2; break;
					case 5: $inv = 0 === $x * $y % 2 + $x * $y % 3; break;
					case 6: $inv = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2; break;
					default: $inv = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
				}
				if ( $inv && ! $this->function[ $y ][ $x ] ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/** Simplified penalty: runs of 5+ same colour and 2x2 blocks. */
	private function penalty() {
		$s = $this->size;
		$p = 0;
		$m = $this->modules;
		for ( $y = 0; $y < $s; $y++ ) {
			$run_r = 1;
			$run_c = 1;
			for ( $x = 1; $x < $s; $x++ ) {
				if ( $m[ $y ][ $x ] === $m[ $y ][ $x - 1 ] ) {
					$run_r++;
					if ( 5 === $run_r ) {
						$p += 3;
					} elseif ( $run_r > 5 ) {
						$p++;
					}
				} else {
					$run_r = 1;
				}
				if ( $m[ $x ][ $y ] === $m[ $x - 1 ][ $y ] ) {
					$run_c++;
					if ( 5 === $run_c ) {
						$p += 3;
					} elseif ( $run_c > 5 ) {
						$p++;
					}
				} else {
					$run_c = 1;
				}
				if ( $y > 0 && $m[ $y ][ $x ] === $m[ $y ][ $x - 1 ] && $m[ $y ][ $x ] === $m[ $y - 1 ][ $x ] && $m[ $y ][ $x ] === $m[ $y - 1 ][ $x - 1 ] ) {
					$p += 3;
				}
			}
		}
		return $p;
	}

	/* ------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------ */

	public static function svg( $text, $px = 160 ) {
		$m   = self::matrix( $text );
		$n   = count( $m );
		$q   = 4;
		$dim = $n + 2 * $q;
		$d   = '';
		foreach ( $m as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					$d .= 'M' . ( $x + $q ) . ',' . ( $y + $q ) . 'h1v1h-1z';
				}
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $px . '" height="' . (int) $px . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
	}

	/** Write a PNG to $file (needs GD). */
	public static function png( $text, $file, $scale = 6 ) {
		if ( ! function_exists( 'imagecreate' ) ) {
			return false;
		}
		$m     = self::matrix( $text );
		$n     = count( $m );
		$q     = 4;
		$scale = max( $scale, (int) ceil( 480 / ( $n + 2 * $q ) ) ); // Keep dense codes scannable.
		$dim   = ( $n + 2 * $q ) * $scale;
		$img = imagecreate( $dim, $dim );
		$wh  = imagecolorallocate( $img, 255, 255, 255 );
		$bk  = imagecolorallocate( $img, 0, 0, 0 );
		imagefill( $img, 0, 0, $wh );
		foreach ( $m as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					imagefilledrectangle( $img, ( $x + $q ) * $scale, ( $y + $q ) * $scale, ( $x + $q + 1 ) * $scale - 1, ( $y + $q + 1 ) * $scale - 1, $bk );
				}
			}
		}
		$ok = imagepng( $img, $file );
		imagedestroy( $img );
		return $ok;
	}

	/** Cached PNG in uploads/nexa-efris-qr/, returns public URL (works in emails). */
	public static function png_url( $text ) {
		$up   = wp_upload_dir();
		$dir  = trailingslashit( $up['basedir'] ) . 'nexa-efris-qr';
		$name = md5( $text ) . '.png';
		$file = $dir . '/' . $name;
		if ( ! file_exists( $file ) ) {
			wp_mkdir_p( $dir );
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore
			}
			try {
				if ( ! self::png( $text, $file ) ) {
					return '';
				}
			} catch ( Exception $e ) {
				return '';
			}
		}
		return trailingslashit( $up['baseurl'] ) . 'nexa-efris-qr/' . $name;
	}
}
