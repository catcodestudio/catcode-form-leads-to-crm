<?php
/**
 * Turns the fields of any form into one lead.
 *
 * Forms name their fields any way they like (text-32, tel-635, your-name), so a
 * field's role comes from its type first, then from its caption and name. What
 * has no role stays in `extra` under its caption and ends up in the CRM comment:
 * no field a visitor filled in is dropped.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Lead;

use CatCode\FormLeads\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Lead {

	/** Field types that carry nothing a manager needs. */
	const SKIP_TYPES = array( 'submit', 'button', 'acceptance', 'consent', 'gdpr', 'quiz', 'file', 'upload', 'password', 'captcha', 'captchac', 'captchar', 'recaptcha', 'hcaptcha', 'turnstile', 'honeypot', 'html', 'section', 'divider', 'pagebreak', 'page-break', 'step', 'response', 'count', 'signature' );

	const NOT_A_NAME = '/compan|компан|product|товар|domain|домен|site|сайт|organi[sz]|організ|организ|form|форм/iu';

	/**
	 * @param array<string,mixed> $raw source, form_id, form_title, page_url, fields[]{name,label,type,value}.
	 * @return array<string,mixed> The shape of catcode_lead_captured, plus source and key.
	 */
	public static function from_fields( array $raw ): array {
		$lead = array(
			'source'        => sanitize_key( (string) ( isset( $raw['source'] ) ? $raw['source'] : 'custom' ) ),
			'type'          => '',
			'name'          => '',
			'phone'         => '',
			'email'         => '',
			'comment'       => '',
			'product'       => '',
			'product_sku'   => '',
			'product_price' => '',
			'page_url'      => esc_url_raw( (string) ( isset( $raw['page_url'] ) ? $raw['page_url'] : '' ) ),
			'form_id'       => sanitize_text_field( (string) ( isset( $raw['form_id'] ) ? $raw['form_id'] : '' ) ),
			'form_title'    => sanitize_text_field( (string) ( isset( $raw['form_title'] ) ? $raw['form_title'] : '' ) ),
			'extra'         => array(),
			'utm'           => array(),
		);

		$utm     = array();
		$unnamed = array();

		foreach ( (array) ( isset( $raw['fields'] ) ? $raw['fields'] : array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name  = (string) ( isset( $field['name'] ) ? $field['name'] : '' );
			$label = trim( (string) ( isset( $field['label'] ) ? $field['label'] : '' ) );
			$type  = strtolower( (string) ( isset( $field['type'] ) ? $field['type'] : 'text' ) );
			$value = isset( $field['value'] ) ? $field['value'] : '';
			$value = is_array( $value ) ? implode( 'name' === $type ? ' ' : ', ', array_filter( array_map( 'strval', self::flat( $value ) ), 'strlen' ) ) : (string) $value;
			$value = trim( 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) );

			if ( '' === $value || in_array( $type, self::SKIP_TYPES, true ) ) {
				continue;
			}
			$value = mb_substr( $value, 0, 5000 );

			if ( preg_match( '/^utm[-_](source|medium|campaign|content|term)$/i', $name, $m ) ) {
				$utm[ strtolower( $m[1] ) ] = $value;
				continue;
			}
			if ( in_array( strtolower( $name ), array( 'gclid', 'fbclid' ), true ) ) {
				$utm[ strtolower( $name ) ] = $value;
				continue;
			}

			$role = self::role( $name, $label, $type );
			switch ( $role ) {
				case 'email':
				case 'phone':
					if ( '' === $lead[ $role ] ) {
						$lead[ $role ] = $value;
					} else {
						$lead['extra'][ self::caption( $label, $name ) ] = $value;
					}
					break;
				case 'name':
					$lead['name'] = trim( $lead['name'] . ' ' . $value );
					break;
				case 'comment':
					$lead['comment'] = '' === $lead['comment'] ? $value : $lead['comment'] . "\n" . $value;
					break;
				case 'product':
				case 'product_sku':
				case 'product_price':
					$lead[ $role ] = $value;
					break;
				default:
					if ( 'text' === $type && ! self::informative( $label, $name ) ) {
						$unnamed[] = array( self::caption( $label, $name ), $value );
						break;
					}
					$lead['extra'][ self::caption( $label, $name ) ] = $value;
			}
		}

		// text-32 with no caption: the first such field of a form is its name field.
		foreach ( $unnamed as $index => $item ) {
			if ( 0 === $index && '' === $lead['name'] ) {
				$lead['name'] = $item[1];
				continue;
			}
			$lead['extra'][ $item[0] ] = $item[1];
		}

		if ( count( $lead['extra'] ) > 50 ) {
			$lead['extra'] = array_slice( $lead['extra'], 0, 50, true );
		}

		// A mistyped address is no address, but the manager should still see what was typed.
		$typed         = $lead['email'];
		$lead['email'] = (string) sanitize_email( $typed );
		if ( '' === $lead['email'] && '' !== $typed ) {
			$lead['extra']['E-mail'] = $typed;
		}
		$lead['phone'] = self::phone( $lead['phone'] );
		$lead['type']  = $lead['form_title'];
		$lead['utm']   = array() !== $utm ? Utm::clean( $utm + Utm::current() ) : Utm::current();

		$lead['key'] = self::key( $lead );

		return $lead;
	}

	/**
	 * @param array<int|string,mixed> $value Possibly nested value.
	 * @return array<int,mixed>
	 */
	private static function flat( array $value ): array {
		$out = array();
		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$out ) {
				if ( is_scalar( $item ) ) {
					$out[] = $item;
				}
			}
		);
		return $out;
	}

	/** A role by type, then by caption, then by field name. */
	private static function role( string $name, string $label, string $type ): string {
		if ( 'email' === $type ) {
			return 'email';
		}
		if ( in_array( $type, array( 'tel', 'phone' ), true ) ) {
			return 'phone';
		}
		if ( 'name' === $type ) {
			return 'name';
		}
		$low = strtolower( $name );
		if ( in_array( $low, array( 'product', 'product-name', 'product_name' ), true ) ) {
			return 'product';
		}
		if ( in_array( $low, array( 'product-sku', 'product_sku', 'sku' ), true ) ) {
			return 'product_sku';
		}
		if ( in_array( $low, array( 'product-price', 'product_price' ), true ) ) {
			return 'product_price';
		}
		if ( 'textarea' === $type ) {
			return 'comment';
		}
		if ( ! in_array( $type, array( 'text', 'hidden', '' ), true ) ) {
			return '';
		}
		foreach ( array( $label, str_replace( array( '-', '_' ), ' ', $name ) ) as $text ) {
			if ( '' === $text ) {
				continue;
			}
			if ( preg_match( '/(^|\s)e?\s?-?mail(\s|\d|$)|пошта|почта|е-мейл|імейл/iu', $text ) ) {
				return 'email';
			}
			if ( preg_match( '/(^|\s)(tel|phone|mobile|mob)(\s|\d|$)|телефон|мобільн|мобильн/iu', $text ) ) {
				return 'phone';
			}
			if ( preg_match( '/(^|\s)(comment|message|msg|note|question)(\s|\d|s|$)|коментар|комментар|повідомлен|сообщен|питання|вопрос|примітк|примечан/iu', $text ) ) {
				return 'comment';
			}
			if ( ! preg_match( self::NOT_A_NAME, $text ) && preg_match( '/(^|\s)(name|fio|pib|first name|last name|surname)(\s|\d|$)|ім.?я|имя|прізвищ|фамил|піб|фио/iu', $text ) ) {
				return 'name';
			}
			// A real caption that says something else: do not look at the machine name.
			if ( $text === $label ) {
				return '';
			}
		}
		return '';
	}

	/** Does the caption say more than a generated name like text-32? */
	private static function informative( string $label, string $name ): bool {
		$text = '' !== $label ? $label : $name;
		return ! preg_match( '/^(text|field|input|name)[\s_-]*\d*$/i', $text );
	}

	private static function caption( string $label, string $name ): string {
		$text = '' !== $label ? $label : ucfirst( trim( str_replace( array( '-', '_' ), ' ', $name ) ) );
		return mb_substr( sanitize_text_field( $text ), 0, 120 );
	}

	/** Local numbers get the country code from the settings; anything else stays as typed. */
	public static function phone( string $phone ): string {
		$phone  = trim( $phone );
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return $phone;
		}
		if ( 0 === strpos( $phone, '+' ) ) {
			return '+' . $digits;
		}
		$prefix = (string) preg_replace( '/\D+/', '', (string) Settings::get( 'phone_prefix', '+380' ) );
		if ( '' === $prefix ) {
			return $phone;
		}
		if ( 0 === strpos( $digits, $prefix ) && strlen( $digits ) > strlen( $prefix ) + 6 ) {
			return '+' . $digits;
		}
		// In Ukraine the leading 0 of 0XX XXX XX XX is part of the code: 050... is +38 050...
		if ( '380' === $prefix ) {
			if ( '0' === $digits[0] && 10 === strlen( $digits ) ) {
				return '+38' . $digits;
			}
			if ( 0 === strpos( $digits, '80' ) && 11 === strlen( $digits ) ) {
				return '+3' . $digits;
			}
			return $phone;
		}
		if ( '0' === $digits[0] && strlen( $digits ) >= 9 ) {
			return '+' . $prefix . substr( $digits, 1 );
		}
		return $phone;
	}

	/** Same visitor, same form, same words: the same lead. UTM and time do not count. */
	public static function key( array $lead ): string {
		return md5(
			(string) wp_json_encode(
				array(
					$lead['source'],
					$lead['form_id'],
					$lead['name'],
					$lead['phone'],
					$lead['email'],
					$lead['comment'],
					$lead['product'],
					$lead['extra'],
				)
			)
		);
	}

	/** Is there anything to send at all? */
	public static function has_contact( array $lead ): bool {
		return '' !== $lead['name'] || '' !== $lead['phone'] || '' !== $lead['email'] || '' !== $lead['comment'] || array() !== $lead['extra'];
	}

	/** A name for the CRM record: never empty, some CRMs refuse a blank one. */
	public static function display_name( array $lead ): string {
		foreach ( array( 'name', 'phone', 'email' ) as $key ) {
			if ( '' !== (string) $lead[ $key ] ) {
				return (string) $lead[ $key ];
			}
		}
		return __( 'Lead from the site', 'catcode-form-leads-to-crm' );
	}

	/** A title for a CRM deal or card. */
	public static function title( array $lead ): string {
		$type = '' !== (string) $lead['type'] ? (string) $lead['type'] : __( 'Lead from the site', 'catcode-form-leads-to-crm' );
		return '' !== (string) $lead['name'] ? $type . ' — ' . $lead['name'] : $type;
	}

	/**
	 * What the manager reads in the CRM: the visitor's words, then every other
	 * field, the form, the page and the ad source.
	 *
	 * @param array<string,mixed> $lead Lead.
	 * @param bool                $tags Include UTM lines (off where the CRM has its own fields for them).
	 */
	public static function comment_text( array $lead, bool $tags = true ): string {
		$lines = array();
		if ( '' !== (string) $lead['comment'] ) {
			$lines[] = (string) $lead['comment'];
			$lines[] = '';
		}
		foreach ( (array) $lead['extra'] as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		if ( '' !== (string) $lead['product'] ) {
			$lines[] = __( 'Product', 'catcode-form-leads-to-crm' ) . ': ' . $lead['product'] . ( '' !== (string) $lead['product_sku'] ? ' (' . $lead['product_sku'] . ')' : '' );
		}
		if ( '' !== (string) $lead['form_title'] ) {
			$lines[] = __( 'Form', 'catcode-form-leads-to-crm' ) . ': ' . $lead['form_title'];
		}
		if ( '' !== (string) $lead['page_url'] ) {
			$lines[] = __( 'Page', 'catcode-form-leads-to-crm' ) . ': ' . $lead['page_url'];
		}
		if ( $tags ) {
			foreach ( (array) $lead['utm'] as $key => $value ) {
				$lines[] = ( in_array( $key, array( 'gclid', 'fbclid', 'referrer', 'landing' ), true ) ? $key : 'utm_' . $key ) . ': ' . $value;
			}
		} else {
			foreach ( array( 'gclid', 'fbclid', 'referrer', 'landing' ) as $key ) {
				if ( ! empty( $lead['utm'][ $key ] ) ) {
					$lines[] = $key . ': ' . $lead['utm'][ $key ];
				}
			}
		}
		return trim( implode( "\n", $lines ) );
	}

	/** What the "send a test lead" button sends. */
	public static function sample(): array {
		$lead = array(
			'source'        => 'test',
			'type'          => __( 'Test lead', 'catcode-form-leads-to-crm' ),
			'name'          => __( 'Test lead, please delete', 'catcode-form-leads-to-crm' ),
			'phone'         => self::phone( '050 000 00 00' ),
			'email'         => 'test@example.com',
			'comment'       => __( 'Sent from the plugin settings to check the connection.', 'catcode-form-leads-to-crm' ),
			'product'       => '',
			'product_sku'   => '',
			'product_price' => '',
			'page_url'      => home_url( '/' ),
			'form_id'       => '0',
			'form_title'    => __( 'Test lead', 'catcode-form-leads-to-crm' ),
			'extra'         => array(),
			'utm'           => array(),
		);

		$lead['key'] = md5( 'test' . microtime() );

		return $lead;
	}
}
