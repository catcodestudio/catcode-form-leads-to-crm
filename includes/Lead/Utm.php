<?php
/**
 * Where the visitor came from: a first-party cookie written by assets/js/utm.js,
 * so a form sent a few pages after the ad is still attributed to it.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Lead;

use CatCode\FormLeads\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Utm {

	const COOKIE = 'catcode_flc_utm';

	/** Keys kept, the same as in the catcode_lead_captured lead. */
	const KEYS = array( 'source', 'medium', 'campaign', 'content', 'term', 'gclid', 'fbclid', 'referrer', 'landing' );

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( '1' === (string) Settings::get( 'utm', '1' ) ) {
			wp_enqueue_script( 'catcode-flc-utm', CCFLC_URL . 'assets/js/utm.js', array(), CCFLC_VERSION, true );
		}
	}

	/** @return array<string,string> */
	public static function current(): array {
		if ( '1' !== (string) Settings::get( 'utm', '1' ) || empty( $_COOKIE[ self::COOKIE ] ) ) {
			return array();
		}
		// wp_strip_all_tags() keeps the percent-encoded parts of the landing and referrer URLs,
		// which sanitize_text_field() would drop; every value is sanitised by its type in clean().
		$raw  = rawurldecode( wp_strip_all_tags( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		$data = json_decode( $raw, true );
		return is_array( $data ) ? self::clean( $data ) : array();
	}

	/**
	 * @param array<string,mixed> $data Raw values.
	 * @return array<string,string>
	 */
	public static function clean( array $data ): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
				continue;
			}
			$value = in_array( $key, array( 'referrer', 'landing' ), true )
				? esc_url_raw( (string) $data[ $key ] )
				: sanitize_text_field( (string) $data[ $key ] );
			$value = mb_substr( trim( $value ), 0, 300 );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		// A click id with no utm_source still tells where the visitor came from.
		if ( empty( $out['source'] ) ) {
			if ( ! empty( $out['gclid'] ) ) {
				$out['source'] = 'google';
				$out['medium'] = isset( $out['medium'] ) ? $out['medium'] : 'cpc';
			} elseif ( ! empty( $out['fbclid'] ) ) {
				$out['source'] = 'facebook';
			}
		}

		return $out;
	}
}
