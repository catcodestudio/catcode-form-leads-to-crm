<?php
/**
 * Other CatCode plugins that catch the same forms.
 *
 * A lead must reach a CRM or a chat once, so whatever those plugins already
 * deliver is left to them and shows up here as "skipped" with the reason.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Core;

defined( 'ABSPATH' ) || exit;

class Compat {

	/** Telegram Order Notifications is active and sends form leads itself. */
	public static function telegram_plugin_sends_forms(): bool {
		return self::telegram_plugin_active()
			&& class_exists( '\\CatCode\\TelegramNotify\\Core\\Settings' )
			&& 'yes' === \CatCode\TelegramNotify\Core\Settings::get( 'notify_forms', 'no' );
	}

	/** It hooks Contact Form 7 on its own and listens to catcode_lead_captured for the rest. */
	public static function telegram_plugin_active(): bool {
		return class_exists( '\\CatCode\\TelegramNotify\\Forms' );
	}

	/** Form Leads to KeyCRM (the older module) sends Contact Form 7 to KeyCRM itself. */
	public static function keycrm_catcher_active(): bool {
		return function_exists( 'kfcwp_settings' );
	}
}
