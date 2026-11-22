<?php
/**
 * SalesDrive. Docs: https://api.salesdrive.me/swagger/openapi-uk.php
 *
 * POST https://<account>/handler/ with X-Api-Key and a flat JSON body. The handler
 * does not deduplicate by externalId, so that field only helps a human trace a lead.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Targets;

use CatCode\FormLeads\Lead\Lead;

defined( 'ABSPATH' ) || exit;

class SalesDrive extends Target {

	public function id(): string {
		return 'salesdrive';
	}

	public function label(): string {
		return 'SalesDrive';
	}

	public function fields(): array {
		return array(
			array(
				'key'   => 'domain',
				'label' => __( 'Account address', 'catcode-form-leads-to-crm' ),
				'type'  => 'text',
				'help'  => __( 'For example yourcompany.salesdrive.me', 'catcode-form-leads-to-crm' ),
			),
			array(
				'key'   => 'api_key',
				'label' => __( 'API key', 'catcode-form-leads-to-crm' ),
				'type'  => 'secret',
				'help'  => __( 'In SalesDrive: Settings, General settings and integrations, Other services, API.', 'catcode-form-leads-to-crm' ),
			),
		);
	}

	/** Only a bare host name: the key is sent there. */
	public static function host( string $value ): string {
		$value = (string) preg_replace( '#^https?://#i', '', trim( $value ) );
		$value = strtolower( (string) preg_replace( '#[/?\#].*$#', '', $value ) );
		return preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $value ) ? $value : '';
	}

	public function configured(): bool {
		$cfg = $this->cfg();
		return '' !== self::host( $this->str( $cfg['domain'] ?? '' ) ) && '' !== $this->str( $cfg['api_key'] ?? '' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		return array( 'X-Api-Key' => $this->str( $this->cfg()['api_key'] ?? '' ) );
	}

	private function base(): string {
		return 'https://' . self::host( $this->str( $this->cfg()['domain'] ?? '' ) );
	}

	public function send( array $lead, string $ref = '' ): array {
		if ( ! $this->configured() ) {
			return self::not_set_up();
		}

		$name  = trim( (string) $lead['name'] );
		$parts = '' === $name ? array() : (array) preg_split( '/\s+/u', $name, 2 );

		$payload = array(
			'getResultData' => 1,
			'fName'         => (string) ( $parts[0] ?? '' ),
			'lName'         => (string) ( $parts[1] ?? '' ),
			'phone'         => (string) $lead['phone'],
			'email'         => (string) $lead['email'],
			'comment'       => Lead::comment_text( $lead, false ),
			'externalId'    => 'flc-' . $lead['key'],
			'sajt'          => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'utmPage'       => (string) $lead['page_url'],
		);
		foreach ( array(
			'source'   => 'utmSource',
			'medium'   => 'utmMedium',
			'campaign' => 'utmCampaign',
			'content'  => 'utmContent',
			'term'     => 'utmTerm',
		) as $key => $field ) {
			if ( ! empty( $lead['utm'][ $key ] ) ) {
				$payload[ $field ] = (string) $lead['utm'][ $key ];
			}
		}
		if ( '' !== (string) $lead['product'] ) {
			$payload['products'] = array(
				array(
					'name'        => (string) $lead['product'],
					'sku'         => (string) $lead['product_sku'],
					'costPerItem' => (float) str_replace( ',', '.', (string) $lead['product_price'] ),
					'amount'      => 1,
				),
			);
		}

		$res = $this->http( 'POST', $this->base() . '/handler/', $this->headers(), $payload );

		// An HTTP 200 may still say {"success":false,...}.
		if ( $res['ok'] && is_array( $res['json'] ) && array_key_exists( 'success', $res['json'] ) && ! $res['json']['success'] ) {
			$res['ok']    = false;
			$res['error'] = self::error_text( $res['json'], (int) $res['status'] );
		}

		return self::result( $res, isset( $res['json']['data']['orderId'] ) ? (string) $res['json']['data']['orderId'] : '' );
	}

	public function test(): array {
		if ( ! $this->configured() ) {
			return array(
				'ok'      => false,
				'message' => self::not_set_up()['error'],
			);
		}
		$res = $this->http( 'GET', $this->base() . '/api/statuses/', $this->headers() );
		return array(
			'ok'      => $res['ok'],
			'message' => $res['ok'] ? __( 'Connected.', 'catcode-form-leads-to-crm' ) : $res['error'],
		);
	}
}
