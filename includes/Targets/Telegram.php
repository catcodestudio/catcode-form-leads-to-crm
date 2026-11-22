<?php
/**
 * Telegram bot. Docs: https://core.telegram.org/bots/api
 *
 * Each chat is a separate row of the log, so a retry never repeats the message in a
 * chat that already got it.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Targets;

use CatCode\FormLeads\Lead\Lead;

defined( 'ABSPATH' ) || exit;

class Telegram extends Target {

	const MAX_LEN = 4096;

	public function id(): string {
		return 'telegram';
	}

	public function label(): string {
		return 'Telegram';
	}

	public function fields(): array {
		return array(
			array(
				'key'   => 'bot_token',
				'label' => __( 'Bot token', 'catcode-form-leads-to-crm' ),
				'type'  => 'secret',
				'help'  => __( 'Create a bot with @BotFather and paste its token.', 'catcode-form-leads-to-crm' ),
			),
			array(
				'key'   => 'chats',
				'label' => __( 'Chats', 'catcode-form-leads-to-crm' ),
				'type'  => 'textarea',
				'help'  => __( 'One chat ID per line. A topic of a group: chat ID, a colon, topic ID. Write to the bot or add it to a group, save, then press "Find chats".', 'catcode-form-leads-to-crm' ),
			),
		);
	}

	private function token(): string {
		return $this->str( $this->cfg()['bot_token'] ?? '' );
	}

	/** @return string[] chat or chat:topic */
	public function chats(): array {
		$out = array();
		foreach ( (array) preg_split( '/[\s,]+/', $this->str( $this->cfg()['chats'] ?? '' ) ) as $chat ) {
			$chat = trim( (string) $chat );
			if ( preg_match( '/^(-?\d+|@[A-Za-z0-9_]{4,})(:\d+)?$/', $chat ) ) {
				$out[] = $chat;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public function configured(): bool {
		return '' !== $this->token() && array() !== $this->chats();
	}

	/**
	 * @param string       $method Bot API method.
	 * @param array<mixed> $body   Body.
	 * @return array{ok:bool,status:int,json:?array,error:string}
	 */
	private function call( string $method, array $body = array() ): array {
		$token = $this->token();
		$res   = $this->http( 'POST', 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/' . $method, array(), $body );

		// HTTP 200 alone proves nothing, and an error may quote the address with the token.
		if ( $res['ok'] && empty( $res['json']['ok'] ) ) {
			$res['ok']    = false;
			$res['error'] = self::error_text( $res['json'], (int) $res['status'] );
		}
		if ( '' !== $token ) {
			$res['error'] = str_replace( array( $token, rawurlencode( $token ) ), '***', (string) $res['error'] );
		}
		return $res;
	}

	/** Only these three matter to the HTML mode of Telegram; esc_html() would show &#039; as is. */
	private static function esc( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/** @param array<string,mixed> $lead Lead. */
	public static function message( array $lead ): string {
		$lines = array( '<b>' . self::esc( Lead::title( $lead ) ) . '</b>' );
		if ( '' !== (string) $lead['phone'] ) {
			$lines[] = self::esc( __( 'Phone', 'catcode-form-leads-to-crm' ) . ': ' . $lead['phone'] );
		}
		if ( '' !== (string) $lead['email'] ) {
			$lines[] = self::esc( __( 'E-mail', 'catcode-form-leads-to-crm' ) . ': ' . $lead['email'] );
		}
		$rest = Lead::comment_text( $lead );
		if ( '' !== $rest ) {
			$lines[] = '';
			$lines[] = self::esc( $rest );
		}
		$text = implode( "\n", $lines );

		if ( mb_strlen( $text ) > self::MAX_LEN ) {
			// Cut the plain text, so a tag or an entity is never left half-open.
			$head = implode( "\n", array_slice( $lines, 0, -1 ) );
			$room = max( 0, self::MAX_LEN - mb_strlen( $head ) - 2 );
			$text = $head . "\n" . self::esc( mb_substr( $rest, 0, (int) floor( $room / 5 ) ) ) . '…';
		}
		return $text;
	}

	public function send( array $lead, string $ref = '' ): array {
		if ( '' === $this->token() || '' === $ref ) {
			return self::not_set_up();
		}
		$parts   = explode( ':', $ref, 2 );
		$payload = array(
			'chat_id'              => $parts[0],
			'text'                 => self::message( $lead ),
			'parse_mode'           => 'HTML',
			'link_preview_options' => array( 'is_disabled' => true ),
		);
		if ( ! empty( $parts[1] ) ) {
			$payload['message_thread_id'] = (int) $parts[1];
		}

		$res = $this->call( 'sendMessage', $payload );

		return self::result( $res, isset( $res['json']['result']['message_id'] ) ? (string) $res['json']['result']['message_id'] : '' );
	}

	public function test(): array {
		if ( '' === $this->token() ) {
			return array(
				'ok'      => false,
				'message' => self::not_set_up()['error'],
			);
		}
		$res = $this->call( 'getMe' );
		if ( ! $res['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}
		return array(
			'ok'      => true,
			/* translators: %s: bot username */
			'message' => sprintf( __( 'Connected: @%s', 'catcode-form-leads-to-crm' ), (string) ( $res['json']['result']['username'] ?? '' ) ),
		);
	}

	/**
	 * Chats that wrote to the bot lately, for the "Find chats" button.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function find_chats(): array {
		if ( '' === $this->token() ) {
			return array(
				'ok'      => false,
				'message' => self::not_set_up()['error'],
			);
		}
		$res = $this->call( 'getUpdates', array( 'limit' => 50 ) );
		if ( ! $res['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}
		$found = array();
		foreach ( (array) ( $res['json']['result'] ?? array() ) as $update ) {
			foreach ( array( 'message', 'channel_post', 'my_chat_member' ) as $kind ) {
				if ( isset( $update[ $kind ]['chat']['id'] ) ) {
					$chat = $update[ $kind ]['chat'];

					$found[ (string) $chat['id'] ] = (string) ( $chat['title'] ?? ( $chat['username'] ?? ( $chat['first_name'] ?? '' ) ) );
				}
			}
		}
		if ( array() === $found ) {
			return array(
				'ok'      => false,
				'message' => __( 'No chats yet. Write to the bot or add it to a group, then try again.', 'catcode-form-leads-to-crm' ),
			);
		}
		$lines = array();
		foreach ( $found as $id => $title ) {
			$lines[] = $id . ' ' . $title;
		}
		return array(
			'ok'      => true,
			'message' => implode( '; ', $lines ),
		);
	}
}
