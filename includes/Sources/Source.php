<?php
/**
 * A form plugin the leads come from.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Sources;

defined( 'ABSPATH' ) || exit;

abstract class Source {

	abstract public function id(): string;

	abstract public function label(): string;

	/** Is the form plugin itself on this site? */
	abstract public function active(): bool;

	/** Hook into its submissions. */
	abstract public function register(): void;

	/** @return array<string,string> Form id => title, for the Forms tab. */
	abstract public function forms(): array;

	/** The page the form was sent from, for plugins that do not say. */
	protected static function referer(): string {
		$url = wp_get_referer();
		return is_string( $url ) ? $url : '';
	}
}
