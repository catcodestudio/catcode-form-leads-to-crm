<?php
/**
 * Wires the plugin together.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Core;

use CatCode\FormLeads\Admin\Page;
use CatCode\FormLeads\Lead\Queue;
use CatCode\FormLeads\Lead\Utm;
use CatCode\FormLeads\Sources\Registry as Sources;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function boot(): void {
		Installer::maybe_upgrade();

		add_filter( 'cron_schedules', array( Installer::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minutes, the retry step.
		add_action( Installer::CRON, array( Queue::class, 'tick' ) );
		add_action( 'init', array( Installer::class, 'ensure_cron' ) );


		Sources::register();
		( new Utm() )->register();

		/*
		 * For custom forms and themes: do_action( 'catcode_flc_capture', array(
		 *   'source' => 'custom', 'form_id' => 'quote', 'form_title' => 'Quote',
		 *   'fields' => array( array( 'name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'value' => '...' ) ),
		 * ) );
		 */
		add_action( 'catcode_flc_capture', array( \CatCode\FormLeads\Lead\Capture::class, 'submit' ) );

		if ( is_admin() ) {
			Page::register();
		}
	}
}
