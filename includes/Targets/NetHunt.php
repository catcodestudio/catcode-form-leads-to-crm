<?php
/**
 * NetHunt CRM. Spec: https://nethunt.com/api/v2/openapi.json (Bearer nh_live_...).
 *
 * Records live in folders and their fields are keyed by a folder-specific id, so the
 * folder is prepared when the settings are saved and the name => id map is kept.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Targets;

use CatCode\FormLeads\Core\Settings;
use CatCode\FormLeads\Lead\Lead;

defined( 'ABSPATH' ) || exit;

class NetHunt extends Target {

	const BASE = 'https://nethunt.com/api/v2';

	/** Field name in the folder => its type. The record's Name field is built in. */
	const FOLDER_FIELDS = array(
		'Phone'    => 'phoneNumber',
		'Email'    => 'email',
		'Comment'  => 'multiLineText',
		'Form'     => 'singleLineText',
		'Page'     => 'website',
		'UTM'      => 'singleLineText',
		'Lead key' => 'singleLineText',
	);

	public function id(): string {
		return 'nethunt';
	}

	public function label(): string {
		return 'NetHunt';
	}

	public function fields(): array {
		return array(
			array(
				'key'   => 'api_key',
				'label' => __( 'API token', 'catcode-form-leads-to-crm' ),
				'type'  => 'secret',
				'help'  => __( 'In NetHunt: Settings, API & MCP, Create token.', 'catcode-form-leads-to-crm' ),
			),
			array(
				'key'   => 'folder_id',
				'label' => __( 'Folder ID', 'catcode-form-leads-to-crm' ),
				'type'  => 'text',
				'help'  => __( 'Optional. Empty: a folder for the leads is created when you save. The fields it lacks are added either way.', 'catcode-form-leads-to-crm' ),
			),
		);
	}

	public function configured(): bool {
		return '' !== $this->str( $this->cfg()['api_key'] ?? '' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->str( $this->cfg()['api_key'] ?? '' ) );
	}

	/**
	 * Create the folder or add what it lacks, then remember its field ids.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function provision(): array {
		$folder_id = $this->str( $this->cfg()['folder_id'] ?? '' );
		$missing   = array();

		if ( '' === $folder_id ) {
			$fields = array();
			foreach ( self::FOLDER_FIELDS as $name => $type ) {
				$fields[] = array(
					'name' => $name,
					'type' => $type,
				);
			}
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$res  = $this->http(
				'POST',
				self::BASE . '/folders',
				$this->headers(),
				array(
					'name'   => 'Website leads (' . $host . ')',
					'fields' => $fields,
				)
			);
		} else {
			$res = $this->folder( $folder_id );
			if ( $res['ok'] && is_array( $res['json'] ) ) {
				$have = array();
				foreach ( (array) ( $res['json']['fields'] ?? array() ) as $field ) {
					if ( isset( $field['name'] ) ) {
						$have[ mb_strtolower( (string) $field['name'] ) ] = true;
					}
				}
				foreach ( self::FOLDER_FIELDS as $name => $type ) {
					if ( isset( $have[ mb_strtolower( $name ) ] ) ) {
						continue;
					}
					$add = $this->http(
						'POST',
						self::BASE . '/folders/' . rawurlencode( $folder_id ) . '/fields',
						$this->headers(),
						array(
							'name' => $name,
							'type' => $type,
						)
					);
					if ( ! $add['ok'] ) {
						$missing[] = $name;
					}
				}
				// The answer to adding a field is documented as the whole folder, earlier
				// it was the field alone; reading the folder again does not depend on that.
				$res = $this->folder( $folder_id );
			}
		}

		if ( ! $res['ok'] || empty( $res['json']['id'] ) ) {
			return array(
				'ok'      => false,
				'message' => '' !== $res['error'] ? $res['error'] : __( 'NetHunt did not return the folder.', 'catcode-form-leads-to-crm' ),
			);
		}

		$map = array();
		foreach ( (array) ( $res['json']['fields'] ?? array() ) as $field ) {
			if ( isset( $field['name'], $field['id'] ) ) {
				$map[ mb_strtolower( (string) $field['name'] ) ] = (string) $field['id'];
			}
		}

		Settings::update_target(
			$this->id(),
			array(
				'folder_id' => (string) $res['json']['id'],
				'field_map' => $map,
			)
		);

		/* translators: %s: folder name */
		$message = sprintf( __( 'NetHunt folder "%s" is ready.', 'catcode-form-leads-to-crm' ), (string) ( $res['json']['name'] ?? $res['json']['id'] ) );
		if ( array() !== $missing ) {
			/* translators: %s: comma-separated field names */
			$message .= ' ' . sprintf( __( 'These fields could not be added and will go without: %s. Check that the token may edit the folder.', 'catcode-form-leads-to-crm' ), implode( ', ', $missing ) );
		}

		return array(
			'ok'      => true,
			'message' => $message,
		);
	}

	/** @return array{ok:bool,status:int,json:?array,error:string} */
	private function folder( string $id ): array {
		return $this->http( 'GET', self::BASE . '/folders/' . rawurlencode( $id ), $this->headers() );
	}

	public function after_save() {
		return $this->configured() ? $this->provision() : null;
	}

	public function send( array $lead, string $ref = '' ): array {
		if ( ! $this->configured() ) {
			return self::not_set_up();
		}

		$cfg = $this->cfg();
		if ( empty( $cfg['folder_id'] ) || empty( $cfg['field_map'] ) ) {
			$ready = $this->provision();
			if ( ! $ready['ok'] ) {
				return array(
					'ok'        => false,
					'retry'     => true,
					'status'    => 0,
					'remote_id' => '',
					'error'     => $ready['message'],
				);
			}
			$cfg = $this->cfg();
		}

		$map = (array) $cfg['field_map'];
		$utm = array();
		foreach ( array( 'source', 'medium', 'campaign', 'content', 'term' ) as $key ) {
			if ( ! empty( $lead['utm'][ $key ] ) ) {
				$utm[] = $key . '=' . $lead['utm'][ $key ];
			}
		}

		$values = array(
			'phone'    => (string) $lead['phone'],
			'email'    => (string) $lead['email'],
			'comment'  => Lead::comment_text( $lead, false ),
			'form'     => (string) $lead['form_title'],
			'page'     => (string) $lead['page_url'],
			'utm'      => implode( ', ', $utm ),
			'lead key' => 'flc-' . $lead['key'],
		);

		$fields = array( 'name' => Lead::display_name( $lead ) );
		foreach ( $values as $name => $value ) {
			if ( '' !== $value && isset( $map[ $name ] ) ) {
				$fields[ $map[ $name ] ] = $value;
			}
		}

		$res = $this->http( 'POST', self::BASE . '/folders/' . rawurlencode( (string) $cfg['folder_id'] ) . '/records', $this->headers(), array( 'fields' => $fields ) );

		return self::result( $res, isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '' );
	}

	public function test(): array {
		if ( ! $this->configured() ) {
			return array(
				'ok'      => false,
				'message' => self::not_set_up()['error'],
			);
		}
		$res = $this->http( 'GET', self::BASE . '/folders', $this->headers() );
		return array(
			'ok'      => $res['ok'],
			'message' => $res['ok'] ? __( 'Connected.', 'catcode-form-leads-to-crm' ) : $res['error'],
		);
	}
}
