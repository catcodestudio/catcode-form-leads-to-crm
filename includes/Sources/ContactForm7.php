<?php
/**
 * Contact Form 7.
 *
 * Roles of the fields come from the tag types, so auto-generated names (text-32,
 * tel-635) work. Human captions exist only in the form template, so they are read
 * from there: otherwise a manager sees "menu-12: Kyiv".
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Sources;

use CatCode\FormLeads\Lead\Capture;

defined( 'ABSPATH' ) || exit;

class ContactForm7 extends Source {

	public function id(): string {
		return 'cf7';
	}

	public function label(): string {
		return 'Contact Form 7';
	}

	public function active(): bool {
		return defined( 'WPCF7_VERSION' );
	}

	public function register(): void {
		// Late on purpose: anti-spam plugins set $abort on this same hook.
		add_action( 'wpcf7_before_send_mail', array( $this, 'on_submit' ), 99, 3 );
	}

	/**
	 * @param \WPCF7_ContactForm $form       Form.
	 * @param bool               $abort      True when something stopped the submission.
	 * @param \WPCF7_Submission  $submission Submission.
	 */
	public function on_submit( $form, $abort, $submission ): void {
		if ( $abort || ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
			return;
		}

		$posted = (array) $submission->get_posted_data();
		$fields = array();

		foreach ( (array) $form->scan_form_tags() as $tag ) {
			$name = (string) $tag->name;
			if ( '' === $name || ! isset( $posted[ $name ] ) ) {
				continue;
			}
			$fields[] = array(
				'name'  => $name,
				'label' => self::label_of( $tag, $name, $form ),
				'type'  => (string) $tag->basetype,
				'value' => $posted[ $name ],
			);
		}

		Capture::submit(
			array(
				'source'     => $this->id(),
				'form_id'    => (string) $form->id(),
				'form_title' => (string) $form->title(),
				'page_url'   => (string) $submission->get_meta( 'url' ),
				'fields'     => $fields,
			)
		);
	}

	public function forms(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'        => 'wpcf7_contact_form',
				'numberposts'      => 200,
				'post_status'      => 'publish',
				'suppress_filters' => false,
			)
		) as $post ) {
			$out[ (string) $post->ID ] = (string) $post->post_title;
		}
		return $out;
	}

	/**
	 * The caption an editor wrote next to the field, then its placeholder.
	 *
	 * @param \WPCF7_FormTag     $tag  Tag.
	 * @param string             $name Field name.
	 * @param \WPCF7_ContactForm $form Form.
	 */
	private static function label_of( $tag, string $name, $form ): string {
		$from_markup = self::label_from_markup( $form, $name );
		if ( '' !== $from_markup ) {
			return $from_markup;
		}
		// The values of a select or a radio are its options, not a caption.
		if ( is_object( $tag ) && method_exists( $tag, 'has_option' ) && $tag->has_option( 'placeholder' ) ) {
			$values = isset( $tag->values ) ? (array) $tag->values : array();
			return isset( $values[0] ) ? trim( (string) $values[0] ) : '';
		}
		return '';
	}

	/**
	 * @param \WPCF7_ContactForm $form Form.
	 * @param string             $name Field name.
	 */
	private static function label_from_markup( $form, string $name ): string {
		$template = is_object( $form ) && method_exists( $form, 'prop' ) ? (string) $form->prop( 'form' ) : '';
		if ( '' === $template || ! preg_match( '/\[[^\]\[]*\b' . preg_quote( $name, '/' ) . '\b[^\]\[]*\]/u', $template, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$before = substr( $template, 0, (int) $m[0][1] );

		// <label>Caption [text* name]</label>: the label is still open before the tag.
		$open  = strrpos( $before, '<label' );
		$close = strrpos( $before, '</label' );
		if ( false !== $open && ( false === $close || $open > $close ) ) {
			$last = (string) preg_replace( '/^<label[^>]*>/u', '', substr( $before, $open ) );
		} elseif ( preg_match( '/<(label|span)\b[^>]*>((?:(?!<(?:label|span)\b).)*)<\/\1>(?:\s|<br\s*\/?>)*$/su', $before, $caption ) ) {
			// A closed caption only if it stands right before the tag, not from another field.
			$last = $caption[2];
		} else {
			return '';
		}
		$last = (string) preg_replace( '/\[[^\]]*\]/u', '', $last );
		$last = wp_strip_all_tags( $last );
		$last = trim( html_entity_decode( $last, ENT_QUOTES, 'UTF-8' ) );
		$last = trim( (string) preg_replace( '/\s+/u', ' ', $last ) );

		return trim( $last, " \t\n\r\0\x0B:*" );
	}
}
