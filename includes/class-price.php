<?php
/**
 * Ligase_Price
 *
 * Locale-tolerant price parsing shared by the field resolver and the legacy
 * (resolver-less) type builders. A naive (float) cast on a Polish-formatted
 * price such as "24,99" or "1 299,90 zł" yields 24.0 / 1.0 because PHP stops at
 * the first non-numeric byte — corrupting the price emitted in JSON-LD.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ligase_Price {

	/**
	 * Normalise a human-entered price string to a float.
	 *
	 * Strips currency symbols/spaces/letters, then treats the LAST comma or dot
	 * as the decimal separator (any earlier ones are thousands separators).
	 *
	 * @param mixed $value Raw price value (string, int or float).
	 * @return float Parsed amount, or 0.0 when nothing usable is present.
	 */
	public static function to_number( $value ): float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( ! is_string( $value ) ) {
			return 0.0;
		}

		$clean = preg_replace( '/[^\d,\.\-]/', '', $value );
		if ( $clean === '' || $clean === '-' ) {
			return 0.0;
		}

		$last_comma = strrpos( $clean, ',' );
		$last_dot   = strrpos( $clean, '.' );
		$dec_pos    = false;
		if ( $last_comma !== false && $last_dot !== false ) {
			$dec_pos = max( $last_comma, $last_dot );
		} elseif ( $last_comma !== false ) {
			$dec_pos = $last_comma;
		} elseif ( $last_dot !== false ) {
			$dec_pos = $last_dot;
		}

		if ( $dec_pos === false ) {
			return (float) $clean;
		}

		$int_part  = preg_replace( '/[^\d\-]/', '', substr( $clean, 0, $dec_pos ) );
		$frac_part = preg_replace( '/[^\d]/', '', substr( $clean, $dec_pos + 1 ) );

		return (float) ( $int_part . '.' . $frac_part );
	}

	/**
	 * Normalise a price to the JSON-LD string form (e.g. "24.99").
	 *
	 * @param mixed $value Raw price value.
	 * @return string Numeric string suitable for a schema.org `price`.
	 */
	public static function to_string( $value ): string {
		return (string) self::to_number( $value );
	}
}
