<?php
/**
 * A place a lead can go.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Targets;

use CatCode\FormLeads\Core\Settings;

defined( 'ABSPATH' ) || exit;

abstract class Target {

	const TIMEOUT = 10;

	abstract public function id(): string;

	abstract public function label(): string;

	/**
	 * The settings of this target.
	 *
	 * @return array<int,array<string,mixed>> Each: key, label, type (text|secret|number|select|textarea), help, options.
	 */
	abstract public function fields(): array;

	abstract public function configured(): bool;

	/**
	 * Deliver one lead.
	 *
	 * @param array<string,mixed> $lead Lead.
	 * @param string              $ref  What follows the id in the log (the chat of a bot).
	 * @return array{ok:bool,retry:bool,status:int,remote_id:string,error:string}
	 */
	abstract public function send( array $lead, string $ref = '' ): array;

	/**
	 * Check the connection without creating anything.
	 *
	 * @return array{ok:bool,message:string}
	 */
	abstract public function test(): array;

	/**
	 * Runs after the settings were saved. A message to show the owner, or null.
	 *
	 * @return array{ok:bool,message:string}|null
	 */
	public function after_save() {
		return null;
	}

	/** @return array<string,mixed> */
	protected function cfg(): array {
		return Settings::target( $this->id() );
	}

	/** @param mixed $value Value from the settings. */
	protected function str( $value ): string {
		return trim( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * @param string               $method  HTTP method.
	 * @param string               $url     Address.
	 * @param array<string,string> $headers Headers.
	 * @param array<mixed>|null    $body    JSON body.
	 * @return array{ok:bool,status:int,json:?array,error:string}
	 */
	protected function http( string $method, string $url, array $headers, ?array $body = null ): array {
		$args = array(
			'method'             => $method,
			'timeout'            => self::TIMEOUT,
			// These are public services: an address typed in the settings must not reach the local network.
			'reject_unsafe_urls' => true,
			'headers'            => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'json'   => null,
				'error'  => false !== stripos( $response->get_error_message(), 'timed out' ) ? __( 'The service did not answer in time.', 'catcode-form-leads-to-crm' ) : $response->get_error_message(),
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$json    = is_array( $decoded ) ? $decoded : null;
		$ok      = $status >= 200 && $status < 300;

		return array(
			'ok'     => $ok,
			'status' => $status,
			'json'   => $json,
			'error'  => $ok ? '' : self::error_text( $json, $status ),
		);
	}

	/**
	 * The words a CRM used for the problem. The four of them answer in different shapes.
	 *
	 * @param array<mixed>|null $json   Answer.
	 * @param int               $status HTTP status.
	 */
	protected static function error_text( ?array $json, int $status ): string {
		$text = '';
		if ( is_array( $json ) ) {
			if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
				$parts = array();
				foreach ( $json['errors'] as $field => $messages ) {
					$message = is_array( $messages ) ? implode( ', ', array_filter( $messages, 'is_scalar' ) ) : (string) $messages;
					$parts[] = is_int( $field ) ? $message : $field . ': ' . $message;
				}
				$text = implode( '; ', $parts );
			}
			foreach ( array( 'message', 'description', 'error' ) as $key ) {
				if ( '' === $text && ! empty( $json[ $key ] ) && is_scalar( $json[ $key ] ) ) {
					$text = (string) $json[ $key ];
				}
			}
		}
		if ( 401 === $status ) {
			$text = __( 'The key is not accepted.', 'catcode-form-leads-to-crm' ) . ( '' !== $text ? ' (' . $text . ')' : '' );
		}
		$http = 'HTTP ' . $status;
		return '' === $text ? $http : $http . ': ' . $text;
	}

	/**
	 * Worth another attempt? A refused key or a rejected field will fail the same way
	 * again; no answer, a limit or a server error may not. A repeated attempt after a
	 * lost answer can leave a double in the CRM: for a lead that beats losing it.
	 */
	protected static function retryable( int $status ): bool {
		return 0 === $status || 408 === $status || 425 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * @param array{ok:bool,status:int,json:?array,error:string} $res       Answer.
	 * @param string                                             $remote_id The id in the CRM.
	 * @return array{ok:bool,retry:bool,status:int,remote_id:string,error:string}
	 */
	protected static function result( array $res, string $remote_id = '' ): array {
		return array(
			'ok'        => (bool) $res['ok'],
			'retry'     => ! $res['ok'] && self::retryable( (int) $res['status'] ),
			'status'    => (int) $res['status'],
			'remote_id' => $remote_id,
			'error'     => (string) $res['error'],
		);
	}

	/** @return array{ok:bool,retry:bool,status:int,remote_id:string,error:string} */
	protected static function not_set_up(): array {
		return array(
			'ok'        => false,
			'retry'     => false,
			'status'    => 0,
			'remote_id' => '',
			'error'     => __( 'The connection is not set up.', 'catcode-form-leads-to-crm' ),
		);
	}

	/**
	 * "id Name; id Name": ready to copy into a settings field.
	 *
	 * @param mixed  $items List from a CRM.
	 * @param string $name  Key of the name.
	 */
	protected static function id_list( $items, string $name = 'name' ): string {
		$out = array();
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$out[] = $item['id'] . ' ' . ( isset( $item[ $name ] ) && is_scalar( $item[ $name ] ) ? $item[ $name ] : '' );
			}
		}
		return implode( '; ', array_slice( $out, 0, 30 ) );
	}
}
