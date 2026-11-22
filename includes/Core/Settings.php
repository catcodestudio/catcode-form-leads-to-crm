<?php
/**
 * Settings: one option row. Keys and tokens inside it are encrypted.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Core;

use CatCode\FormLeads\Targets\Registry;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'catcode_flc_settings';

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/** @return array<string,mixed> */
	private static function defaults(): array {
		return array(
			'crm'          => '',
			'extra_crms'   => array(),
			'telegram_on'  => '0',
			'targets'      => array(),
			'forms'        => array(),
			'utm'          => '1',
			'phone_prefix' => '+380',
			'retention'    => '30',
			'delete_data'  => '1',
		);
	}

	/** @return array<string,mixed> */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * @param string $key      Setting.
	 * @param mixed  $fallback Value when it is not set.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = '' ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/** @param array<string,mixed> $values Fields to merge. */
	public static function update( array $values ): void {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, true );
		self::$cache = $all;
	}

	/**
	 * What one CRM or the bot was given, with secrets decrypted.
	 *
	 * @param string $id Target id.
	 * @return array<string,string|array<string,string>>
	 */
	public static function target( string $id ): array {
		$targets = (array) self::get( 'targets', array() );
		$saved   = isset( $targets[ $id ] ) && is_array( $targets[ $id ] ) ? $targets[ $id ] : array();
		$target  = Registry::get( $id );
		if ( null === $target ) {
			return $saved;
		}
		foreach ( $target->fields() as $field ) {
			$key = $field['key'];
			if ( ! isset( $saved[ $key ] ) ) {
				$saved[ $key ] = '';
			}
			if ( 'secret' === $field['type'] ) {
				$saved[ $key ] = Crypto::decrypt( (string) $saved[ $key ] );
			}
		}
		return $saved;
	}

	/**
	 * Store what the plugin itself learned about a target (folder id, field map).
	 *
	 * @param string              $id     Target id.
	 * @param array<string,mixed> $values Fields, none of them secret.
	 */
	public static function update_target( string $id, array $values ): void {
		$targets = (array) self::get( 'targets', array() );
		$current = isset( $targets[ $id ] ) && is_array( $targets[ $id ] ) ? $targets[ $id ] : array();

		$targets[ $id ] = array_merge( $current, $values );
		self::update( array( 'targets' => $targets ) );
	}

	/** Forms are sent unless the owner switched them off. */
	public static function form_enabled( string $form_key ): bool {
		$forms = (array) self::get( 'forms', array() );
		return ! isset( $forms[ $form_key ]['enabled'] ) || '0' !== (string) $forms[ $form_key ]['enabled'];
	}

	/** Forget the cached row (tests, other processes). */
	public static function flush(): void {
		self::$cache = null;
	}
}
