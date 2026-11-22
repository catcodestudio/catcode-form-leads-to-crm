<?php
/**
 * The list of form plugins.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Sources;

defined( 'ABSPATH' ) || exit;

class Registry {

	/** @var array<string,Source>|null */
	private static $all = null;

	/** @return array<string,Source> */
	public static function all(): array {
		if ( null === self::$all ) {
			/**
			 * Form plugins leads can come from.
			 *
			 * @param array<string,Source> $sources By id.
			 */
			self::$all = (array) apply_filters( 'catcode_flc_sources', array( 'cf7' => new ContactForm7() ) );
		}
		return self::$all;
	}

	public static function register(): void {
		foreach ( self::all() as $source ) {
			$source->register();
		}
	}
}
