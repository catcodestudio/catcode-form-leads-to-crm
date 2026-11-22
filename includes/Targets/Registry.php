<?php
/**
 * The list of targets.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Targets;

defined( 'ABSPATH' ) || exit;

class Registry {

	/** @var array<string,Target>|null */
	private static $all = null;

	/** @return array<string,Target> */
	public static function all(): array {
		if ( null === self::$all ) {
			self::$all = array(
				'keycrm'     => new KeyCrm(),
				'salesdrive' => new SalesDrive(),
				'nethunt'    => new NetHunt(),
				'keepincrm'  => new KeepinCrm(),
				'telegram'   => new Telegram(),
			);
		}
		return self::$all;
	}

	/** @return array<string,Target> */
	public static function crms(): array {
		$list = self::all();
		unset( $list['telegram'] );
		return $list;
	}

	/** @return Target|null */
	public static function get( string $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function telegram(): Telegram {
		return self::all()['telegram'];
	}

	/**
	 * A row of the log says "keycrm" or "telegram:-100123:7".
	 *
	 * @param string $stored Target from the row.
	 * @return array{0:Target,1:string}|null The target and what followed its id.
	 */
	public static function for_delivery( string $stored ) {
		$parts  = explode( ':', $stored, 2 );
		$target = self::get( $parts[0] );
		return null === $target ? null : array( $target, isset( $parts[1] ) ? $parts[1] : '' );
	}
}
