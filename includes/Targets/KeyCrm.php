<?php
/**
 * KeyCRM. Docs: https://docs.keycrm.app/ (api.yaml, Bearer key, 20 requests a minute).
 *
 * A lead becomes an order (POST /order) or a pipeline card (POST /pipelines/cards).
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Targets;

use CatCode\FormLeads\Lead\Lead;

defined( 'ABSPATH' ) || exit;

class KeyCrm extends Target {

	const BASE = 'https://openapi.keycrm.app/v1';

	public function id(): string {
		return 'keycrm';
	}

	public function label(): string {
		return 'KeyCRM';
	}

	public function fields(): array {
		return array(
			array(
				'key'   => 'api_key',
				'label' => __( 'API key', 'catcode-form-leads-to-crm' ),
				'type'  => 'secret',
				'help'  => __( 'In KeyCRM: Settings, General, API key.', 'catcode-form-leads-to-crm' ),
			),
			array(
				'key'     => 'mode',
				'label'   => __( 'Create in KeyCRM', 'catcode-form-leads-to-crm' ),
				'type'    => 'select',
				'options' => array(
					'order' => __( 'An order', 'catcode-form-leads-to-crm' ),
					'card'  => __( 'A card in a pipeline', 'catcode-form-leads-to-crm' ),
				),
			),
			array(
				'key'   => 'source_id',
				'label' => __( 'Source ID', 'catcode-form-leads-to-crm' ),
				'type'  => 'number',
				'help'  => __( 'Optional. "Check the connection" lists your sources and pipelines with their IDs. Empty: the first source in your list is used.', 'catcode-form-leads-to-crm' ),
			),
			array(
				'key'   => 'pipeline_id',
				'label' => __( 'Pipeline ID', 'catcode-form-leads-to-crm' ),
				'type'  => 'number',
				'help'  => __( 'For cards only. Empty: KeyCRM uses your first pipeline.', 'catcode-form-leads-to-crm' ),
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

	public function send( array $lead, string $ref = '' ): array {
		if ( ! $this->configured() ) {
			return self::not_set_up();
		}
		$cfg  = $this->cfg();
		$card = 'card' === ( $cfg['mode'] ?? 'order' );

		$person = array_filter(
			array(
				'full_name' => Lead::display_name( $lead ),
				'phone'     => (string) $lead['phone'],
				'email'     => (string) $lead['email'],
			),
			'strlen'
		);
		$utm    = array();
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $key ) {
			if ( ! empty( $lead['utm'][ $key ] ) ) {
				$utm[ 'utm_' . $key ] = (string) $lead['utm'][ $key ];
			}
		}
		$comment = Lead::comment_text( $lead, false );

		if ( $card ) {
			$payload = array_merge(
				array(
					'title'   => Lead::title( $lead ),
					'contact' => $person,
				),
				$utm
			);
			if ( (int) ( $cfg['pipeline_id'] ?? 0 ) > 0 ) {
				$payload['pipeline_id'] = (int) $cfg['pipeline_id'];
			}
			if ( (int) ( $cfg['source_id'] ?? 0 ) > 0 ) {
				$payload['source_id'] = (int) $cfg['source_id'];
			}
			$path = '/pipelines/cards';
		} else {
			$payload = array(
				'source_uuid' => 'flc-' . $lead['key'],
				'buyer'       => $person,
			);
			$source  = $this->source_id( $cfg );
			if ( $source > 0 ) {
				$payload['source_id'] = $source;
			} else {
				$payload['source_name'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			}
			if ( array() !== $utm ) {
				$payload['marketing'] = $utm;
			}
			if ( '' !== (string) $lead['product'] ) {
				$payload['products'] = array(
					array(
						'name'     => (string) $lead['product'],
						'sku'      => (string) $lead['product_sku'],
						'price'    => (float) str_replace( ',', '.', (string) $lead['product_price'] ),
						'quantity' => 1,
					),
				);
			}
			$path = '/order';
		}
		if ( '' !== $comment ) {
			$payload['manager_comment'] = $comment;
		}

		$res = $this->http( 'POST', self::BASE . $path, $this->headers(), $payload );

		return self::result( $res, is_array( $res['json'] ) && isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '' );
	}

	/**
	 * The documentation requires source_id for an order. With none in the settings the
	 * first source of the account is used, and remembered for a day.
	 *
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function source_id( array $cfg ): int {
		$id = (int) ( $cfg['source_id'] ?? 0 );
		if ( $id > 0 ) {
			return $id;
		}
		$cached = get_transient( 'catcode_flc_keycrm_source' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$res = $this->http( 'GET', self::BASE . '/order/source?limit=1&sort=id', $this->headers() );
		$id  = $res['ok'] && isset( $res['json']['data'][0]['id'] ) ? (int) $res['json']['data'][0]['id'] : 0;
		if ( $id > 0 ) {
			set_transient( 'catcode_flc_keycrm_source', $id, DAY_IN_SECONDS );
		}
		return $id;
	}

	public function after_save() {
		delete_transient( 'catcode_flc_keycrm_source' );
		return null;
	}

	public function test(): array {
		if ( ! $this->configured() ) {
			return array(
				'ok'      => false,
				'message' => self::not_set_up()['error'],
			);
		}
		$res = $this->http( 'GET', self::BASE . '/order/source?limit=50', $this->headers() );
		if ( ! $res['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}
		$parts = array(
			/* translators: %s: list of ids and names */
			sprintf( __( 'Connected. Sources: %s', 'catcode-form-leads-to-crm' ), self::id_list( $res['json']['data'] ?? array() ) ),
		);
		// Pipelines may be switched off in the account: that only limits the card mode.
		$pipes = $this->http( 'GET', self::BASE . '/pipelines?limit=50', $this->headers() );
		if ( $pipes['ok'] ) {
			/* translators: %s: list of ids and names */
			$parts[] = sprintf( __( 'Pipelines: %s', 'catcode-form-leads-to-crm' ), self::id_list( $pipes['json']['data'] ?? array() ) );
		} elseif ( 'card' === ( $this->cfg()['mode'] ?? 'order' ) ) {
			return array(
				'ok'      => false,
				'message' => $pipes['error'],
			);
		}
		return array(
			'ok'      => true,
			'message' => implode( '. ', $parts ),
		);
	}
}
