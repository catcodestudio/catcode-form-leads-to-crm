<?php
/**
 * At-rest obfuscation for the stored CRM keys and tokens.
 *
 * AES-256-CBC with an HMAC, keyed from the site's own WordPress salts, so a
 * copied settings row is useless without the wp-config secrets. Without
 * OpenSSL a salt-derived XOR is used — defence in depth, not real crypto.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Core;

defined( 'ABSPATH' ) || exit;

class Crypto {

	const PREFIX = 'ccflc$';

	private static function secret(): string {
		return hash( 'sha256', 'CatCodeFormLeads|' . wp_salt( 'auth' ), true );
	}

	public static function is_encrypted( string $value ): bool {
		return 0 === strncmp( $value, self::PREFIX, strlen( self::PREFIX ) );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$secret = self::secret();
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return self::PREFIX . base64_encode( $plain ^ str_pad( '', strlen( $plain ), $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv  = openssl_random_pseudo_bytes( 16 );
		$ct  = openssl_encrypt( $plain, 'aes-256-cbc', hash_hmac( 'sha256', 'enc', $secret, true ), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $ct, hash_hmac( 'sha256', 'mac', $secret, true ), true );
		return self::PREFIX . base64_encode( "\xCC" . 'cc2' . $iv . $mac . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		if ( ! self::is_encrypted( $stored ) ) {
			return $stored; // Plain value saved before encryption was applied.
		}
		$bytes = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes ) {
			return '';
		}
		$secret = self::secret();
		if ( 0 === strncmp( $bytes, "\xCC" . 'cc2', 4 ) && strlen( $bytes ) > 52 && function_exists( 'openssl_decrypt' ) ) {
			$iv  = substr( $bytes, 4, 16 );
			$mac = substr( $bytes, 20, 32 );
			$ct  = substr( $bytes, 52 );
			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $ct, hash_hmac( 'sha256', 'mac', $secret, true ), true ), $mac ) ) {
				return '';
			}
			$out = openssl_decrypt( $ct, 'aes-256-cbc', hash_hmac( 'sha256', 'enc', $secret, true ), OPENSSL_RAW_DATA, $iv );
			return false === $out ? '' : $out;
		}
		return $bytes ^ str_pad( '', strlen( $bytes ), $secret ); // Written on a host without OpenSSL.
	}
}
