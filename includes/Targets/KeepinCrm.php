<?php
/**
 * KeepinCRM. Spec: app.swaggerhub.com/apis/KeepInCRM/keepincrm-api/1.2-oas3
 *
 * The spec names the server without /v1, the real paths need it. A lead becomes a
 * deal with a lead client: POST /agreements (title, total, client_attributes are
 * required; `company` must not be blank; a comment on the client is refused, so it
 * goes on the deal). The API is open on paid plans only: 402 on a free one.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Targets;

use CatCode\FormLeads\Lead\Lead;

defined( 'ABSPATH' ) || exit;

class KeepinCrm extends Target {

	const BASE = 'https://api.keepincrm.com/v1';

	public function id(): string {
		return 'keepincrm';
	}

	public function label(): string {
		return 'KeepinCRM';
	}

	public function fields(): array {
		return array(
			array(
				'key'   => 'api_key',
				'label' => __( 'API token', 'catcode-form-leads-to-crm' ),
				'type'  => 'secret',
				'help'  => __( 'In KeepinCRM: Settings, Company profile, API tab. KeepinCRM opens its API on paid plans only.', 'catcode-form-leads-to-crm' ),
			),
			array(
				'key'   => 'funnel_id',
				'label' => __( 'Funnel ID', 'catcode-form-leads-to-crm' ),
				'type'  => 'number',
				'help'  => __( 'Optional. "Check the connection" lists your funnels and sources with their IDs.', 'catcode-form-leads-to-crm' ),
			),
			array(
				'key'   => 'stage_id',
				'label' => __( 'Stage ID', 'catcode-form-leads-to-crm' ),
				'type'  => 'number',
			),
			array(
				'key'   => 'source_id',
				'label' => __( 'Source ID', 'catcode-form-leads-to-crm' ),
				'type'  => 'number',
			),
		);
	}

	public function configured(): bool {
		return '' !== $this->str( $this->cfg()['api_key'] ?? '' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		return array( 'X-Auth-Token' => $this->str( $this->cfg()['api_key'] ?? '' ) );
	}

	public function send( array $lead, string $ref = '' ): array {
		if ( ! $this->configured() ) {
			return self::not_set_up();
		}
		$cfg  = $this->cfg();
		$name = Lead::display_name( $lead );

		$client = array(
			'company' => $name,
			'person'  => $name,
			'lead'    => true,
		);
		if ( '' !== (string) $lead['email'] ) {
			$client['email'] = (string) $lead['email'];
		}
		if ( '' !== (string) $lead['phone'] ) {
			$client['phones'] = array( (string) $lead['phone'] );
		}

		$payload = array(
			'title'                   => Lead::title( $lead ),
			'total'                   => 0,
			'products_total_as_total' => false,
			'client_attributes'       => $client,
		);
		$comment = Lead::comment_text( $lead );
		if ( '' !== $comment ) {
			$payload['comment'] = $comment;
		}
		foreach ( array( 'funnel_id', 'stage_id', 'source_id' ) as $key ) {
			if ( (int) ( $cfg[ $key ] ?? 0 ) > 0 ) {
				$payload[ $key ] = (int) $cfg[ $key ];
			}
		}

		$res = $this->http( 'POST', self::BASE . '/agreements', $this->headers(), $payload );

		return self::result( $res, isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '' );
	}

	public function test(): array {
		if ( ! $this->configured() ) {
			return array(
				'ok'      => false,
				'message' => self::not_set_up()['error'],
			);
		}
		$res = $this->http( 'GET', self::BASE . '/clients/statuses', $this->headers() );
		if ( ! $res['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}
		$parts   = array( __( 'Connected.', 'catcode-form-leads-to-crm' ) );
		$funnels = $this->http( 'GET', self::BASE . '/agreements/funnels', $this->headers() );
		if ( $funnels['ok'] ) {
			/* translators: %s: list of ids and names */
			$parts[] = sprintf( __( 'Funnels: %s', 'catcode-form-leads-to-crm' ), self::id_list( $funnels['json']['items'] ?? $funnels['json'], 'title' ) );
		}
		$sources = $this->http( 'GET', self::BASE . '/sources', $this->headers() );
		if ( $sources['ok'] ) {
			/* translators: %s: list of ids and names */
			$parts[] = sprintf( __( 'Sources: %s', 'catcode-form-leads-to-crm' ), self::id_list( $sources['json']['items'] ?? $sources['json'] ) );
		}
		return array(
			'ok'      => true,
			'message' => implode( ' ', $parts ),
		);
	}
}
