<?php
/**
 * The way in for every form: normalise, drop the double submit, queue.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Lead;

use CatCode\FormLeads\Core\Compat;
use CatCode\FormLeads\Core\Lock;
use CatCode\FormLeads\Core\Settings;
use CatCode\FormLeads\Targets\Registry;

defined( 'ABSPATH' ) || exit;

class Capture {

	/** A double click or a repeated submit within this window is the same lead. */
	const DUPLICATE_WINDOW = 300;

	/** @param array<string,mixed> $raw source, form_id, form_title, page_url, fields[]. */
	public static function submit( $raw ): void {
		if ( ! is_array( $raw ) ) {
			return;
		}

		$lead = Lead::from_fields( $raw );

		if ( ! Settings::form_enabled( $lead['source'] . ':' . $lead['form_id'] ) || ! Lead::has_contact( $lead ) ) {
			return;
		}

		/**
		 * The last chance to change a lead or to drop it (return false).
		 *
		 * @param array<string,mixed> $lead Lead.
		 */
		$lead = apply_filters( 'catcode_flc_lead', $lead );
		if ( ! is_array( $lead ) ) {
			return;
		}

		if ( ! Lock::once( Lead::key( $lead ), self::DUPLICATE_WINDOW ) ) {
			return;
		}

		$targets = self::targets( $lead );
		if ( array() !== $targets ) {
			Queue::add( $lead, $targets );
		}

		// Telegram Order Notifications hooks Contact Form 7 itself, so announcing a
		// Contact Form 7 lead to it as well would double its message.
		if ( 'cf7' !== $lead['source'] || ! Compat::telegram_plugin_active() ) {
			/**
			 * Fires once per lead, for any CatCode module that listens.
			 *
			 * @param array<string,mixed> $lead Lead.
			 */
			do_action( 'catcode_lead_captured', $lead );
		}
	}

	/**
	 * Where this lead goes: target => '' to deliver, or the reason it is skipped.
	 *
	 * @param array<string,mixed> $lead Lead.
	 * @return array<string,string>
	 */
	private static function targets( array $lead ): array {
		$ids = array();
		$crm = (string) Settings::get( 'crm', '' );
		if ( '' !== $crm ) {
			$ids[] = $crm;
		}
		if ( '1' === (string) Settings::get( 'telegram_on', '0' ) ) {
			$ids[] = 'telegram';
		}

		/**
		 * The targets of this lead, by id.
		 *
		 * @param string[]            $ids  Target ids.
		 * @param array<string,mixed> $lead Lead.
		 */
		$ids = (array) apply_filters( 'catcode_flc_route', $ids, $lead );

		$out = array();
		foreach ( array_unique( array_map( 'strval', $ids ) ) as $id ) {
			$target = Registry::get( $id );
			if ( null === $target || ! $target->configured() ) {
				continue;
			}
			if ( 'telegram' === $id ) {
				if ( Compat::telegram_plugin_sends_forms() ) {
					$out['telegram'] = __( 'Sent by Telegram Order Notifications, which is active on this site.', 'catcode-form-leads-to-crm' );
					continue;
				}
				foreach ( Registry::telegram()->chats() as $chat ) {
					$out[ 'telegram:' . $chat ] = '';
				}
				continue;
			}
			if ( 'keycrm' === $id && 'cf7' === $lead['source'] && Compat::keycrm_catcher_active() ) {
				$out['keycrm'] = __( 'Sent by Form Leads to KeyCRM, which is active on this site. Keep one of the two plugins.', 'catcode-form-leads-to-crm' );
				continue;
			}
			$out[ $id ] = '';
		}

		return $out;
	}
}
