<?php
/**
 * Ligase_Crypto
 *
 * At-rest encryption for secrets stored in the options table (currently the NER
 * API key). AES-256-CBC keyed on wp_salt('auth') — the same scheme Ligase_GSC
 * uses for its service-account credentials.
 *
 * Ciphertext carries a version prefix so values can be told apart from secrets
 * that were stored as plaintext before encryption was introduced: maybe_decrypt()
 * transparently passes those legacy values through, so upgrading never wipes a
 * working key.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ligase_Crypto {

	private const PREFIX = 'ligase:enc:v1:';

	/**
	 * Encrypt a plaintext secret. Returns '' for empty input or on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $plaintext, 'AES-256-CBC', wp_salt( 'auth' ), OPENSSL_RAW_DATA, $iv );
		if ( $enc === false ) {
			return '';
		}
		return self::PREFIX . base64_encode( $iv . $enc );
	}

	/**
	 * Whether a stored value is Ligase ciphertext (vs a legacy plaintext secret).
	 */
	public static function is_encrypted( string $value ): bool {
		return strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) === 0;
	}

	/**
	 * Decrypt Ligase ciphertext. Returns '' when the value isn't our ciphertext
	 * or cannot be decrypted (e.g. wp_salt changed after a site move).
	 */
	public static function decrypt( string $stored ): string {
		if ( ! self::is_encrypted( $stored ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( $raw === false || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$dec    = openssl_decrypt( $cipher, 'AES-256-CBC', wp_salt( 'auth' ), OPENSSL_RAW_DATA, $iv );

		return $dec !== false ? $dec : '';
	}

	/**
	 * Decrypt our ciphertext, or return the value unchanged when it was stored as
	 * plaintext before encryption existed. Use this on read paths.
	 */
	public static function maybe_decrypt( string $stored ): string {
		return self::is_encrypted( $stored ) ? self::decrypt( $stored ) : $stored;
	}
}
