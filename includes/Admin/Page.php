<?php
/**
 * The admin screen: connections, forms, log.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Admin;

use CatCode\FormLeads\Core\Compat;
use CatCode\FormLeads\Core\Crypto;
use CatCode\FormLeads\Core\Settings;
use CatCode\FormLeads\Lead\Lead;
use CatCode\FormLeads\Lead\Queue;
use CatCode\FormLeads\Sources\Registry as Sources;
use CatCode\FormLeads\Targets\Registry as Targets;
use CatCode\FormLeads\Targets\Target;

defined( 'ABSPATH' ) || exit;

class Page {

	const SLUG = 'catcode-form-leads';

	const PER_PAGE = 30;

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_catcode_flc_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_catcode_flc_save_forms', array( __CLASS__, 'save_forms' ) );
		add_action( 'admin_post_catcode_flc_clear', array( __CLASS__, 'clear' ) );
		add_action( 'wp_ajax_catcode_flc_action', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'failed_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CCFLC_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function url( string $tab = 'connections' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab );
	}

	/**
	 * @param string[] $links Links of the plugin row.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'catcode-form-leads-to-crm' ) . '</a>' );
		return $links;
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Form Leads to CRM', 'catcode-form-leads-to-crm' ),
			__( 'Form Leads', 'catcode-form-leads-to-crm' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-feedback',
			58
		);
	}

	/** @param string $hook Current screen. */
	public static function assets( $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'catcode-flc-admin', CCFLC_URL . 'assets/css/admin.css', array(), CCFLC_VERSION );
		wp_enqueue_script( 'catcode-flc-admin', CCFLC_URL . 'assets/js/admin.js', array(), CCFLC_VERSION, true );
		wp_localize_script(
			'catcode-flc-admin',
			'catcodeFlc',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'catcode_flc_action' ),
				'wait'    => __( 'Working...', 'catcode-form-leads-to-crm' ),
				'network' => __( 'The site did not answer. Try again.', 'catcode-form-leads-to-crm' ),
			)
		);
	}

	/** Back to a screen with a message for the next page load. */
	public static function back( string $url, string $text, bool $ok ): void {
		set_transient(
			'catcode_flc_notice_' . get_current_user_id(),
			array(
				'text' => $text,
				'ok'   => $ok,
			),
			60
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'catcode-form-leads-to-crm' ) );
		}
	}

	/**
	 * The posted form, unslashed and sanitised as plain text (line breaks kept for textareas).
	 * The nonce and the capability are checked by guard() before this is called.
	 *
	 * @return array<string,mixed>
	 */
	private static function posted(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() runs check_admin_referer() first.
		return (array) map_deep( wp_unslash( $_POST ), 'sanitize_textarea_field' );
	}

	public static function save(): void {
		self::guard( 'catcode_flc_save' );

		// Every value is cleaned on arrival; each field is then sanitised again by its own type below.
		$post = self::posted();

		$targets = (array) Settings::get( 'targets', array() );
		foreach ( Targets::all() as $id => $target ) {
			$input = isset( $post['targets'][ $id ] ) && is_array( $post['targets'][ $id ] ) ? $post['targets'][ $id ] : array();

			$saved = isset( $targets[ $id ] ) && is_array( $targets[ $id ] ) ? $targets[ $id ] : array();
			foreach ( $target->fields() as $field ) {
				$key   = $field['key'];
				$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
				switch ( $field['type'] ) {
					case 'secret':
						if ( ! empty( $input[ $key . '_remove' ] ) ) {
							$saved[ $key ] = '';
						} elseif ( '' !== $value ) {
							$saved[ $key ] = Crypto::encrypt( sanitize_text_field( $value ) );
						}
						break;
					case 'number':
						$saved[ $key ] = absint( $value ) > 0 ? (string) absint( $value ) : '';
						break;
					case 'select':
						$saved[ $key ] = isset( $field['options'][ $value ] ) ? $value : (string) array_key_first( $field['options'] );
						break;
					case 'textarea':
						$saved[ $key ] = sanitize_textarea_field( $value );
						break;
					default:
						$saved[ $key ] = sanitize_text_field( $value );
				}
			}
			$targets[ $id ] = $saved;
		}

		$crm = isset( $post['crm'] ) ? sanitize_key( (string) $post['crm'] ) : '';

		$values = array(
			'targets'      => $targets,
			'crm'          => isset( Targets::crms()[ $crm ] ) ? $crm : '',
			'telegram_on'  => empty( $post['telegram_on'] ) ? '0' : '1',
			'utm'          => empty( $post['utm'] ) ? '0' : '1',
			'phone_prefix' => (string) preg_replace( '/[^\d+]/', '', isset( $post['phone_prefix'] ) ? (string) $post['phone_prefix'] : '' ),
			'retention'    => (string) max( 1, min( 3650, absint( isset( $post['retention'] ) ? $post['retention'] : 30 ) ) ),
			'delete_data'  => empty( $post['delete_data'] ) ? '0' : '1',
		);

		/**
		 * Other settings of this form.
		 *
		 * @param array<string,mixed> $values Values to save.
		 * @param array<string,mixed> $post   What was posted, unslashed and sanitised.
		 */
		$values = (array) apply_filters( 'catcode_flc_save_settings', $values, $post );

		Settings::update( $values );

		$text = __( 'Saved.', 'catcode-form-leads-to-crm' );
		$ok   = true;
		foreach ( self::active_ids() as $id ) {
			$target = Targets::get( $id );
			$note   = null === $target ? null : $target->after_save();
			if ( is_array( $note ) ) {
				$text .= ' ' . $note['message'];
				$ok    = $ok && $note['ok'];
			}
		}

		self::back( self::url(), $text, $ok );
	}

	/** @return string[] */
	private static function active_ids(): array {
		$ids = array( (string) Settings::get( 'crm', '' ) );
		/** @param string[] $ids CRMs in use. */
		$ids = (array) apply_filters( 'catcode_flc_active_crms', $ids );
		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	public static function save_forms(): void {
		self::guard( 'catcode_flc_save_forms' );

		$post = self::posted();

		// Rows of a form plugin that is switched off right now stay as they were.
		$forms = (array) Settings::get( 'forms', array() );
		foreach ( (array) ( isset( $post['forms'] ) ? $post['forms'] : array() ) as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = sanitize_text_field( (string) $key );

			$current = isset( $forms[ $key ] ) && is_array( $forms[ $key ] ) ? $forms[ $key ] : array();

			$forms[ $key ] = array_merge( $current, array( 'enabled' => empty( $row['enabled'] ) ? '0' : '1' ) );
		}

		/**
		 * Other columns of the Forms tab.
		 *
		 * @param array<string,array<string,mixed>> $forms By form key.
		 * @param array<string,mixed>               $post  What was posted, unslashed and sanitised.
		 */
		$forms = (array) apply_filters( 'catcode_flc_save_forms', $forms, $post );

		Settings::update( array( 'forms' => $forms ) );

		self::back( self::url( 'forms' ), __( 'Saved.', 'catcode-form-leads-to-crm' ), true );
	}

	public static function clear(): void {
		self::guard( 'catcode_flc_clear' );
		Queue::clear();
		self::back( self::url( 'log' ), __( 'The log is empty now. Leads still waiting for delivery stay.', 'catcode-form-leads-to-crm' ), true );
	}

	public static function ajax(): void {
		check_ajax_referer( 'catcode_flc_action' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'catcode-form-leads-to-crm' ) ), 403 );
		}
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$target = Targets::get( isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '' );
		if ( null === $target ) {
			wp_send_json_error( array( 'message' => __( 'Unknown connection.', 'catcode-form-leads-to-crm' ) ), 400 );
		}

		if ( 'test' === $do ) {
			$result = $target->test();
		} elseif ( 'chats' === $do && 'telegram' === $target->id() ) {
			$result = Targets::telegram()->find_chats();
		} elseif ( 'send' === $do ) {
			$result = self::send_sample( $target );
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'catcode-form-leads-to-crm' ) ), 400 );
		}

		wp_send_json_success( $result );
	}

	/** @return array{ok:bool,message:string} */
	private static function send_sample( Target $target ): array {
		$lead = Lead::sample();
		if ( 'telegram' === $target->id() ) {
			$chats = Targets::telegram()->chats();
			if ( array() === $chats ) {
				return array(
					'ok'      => false,
					'message' => __( 'Add at least one chat and save.', 'catcode-form-leads-to-crm' ),
				);
			}
			foreach ( $chats as $chat ) {
				$res = $target->send( $lead, $chat );
				if ( ! $res['ok'] ) {
					return array(
						'ok'      => false,
						'message' => $chat . ': ' . $res['error'],
					);
				}
			}
			return array(
				'ok'      => true,
				'message' => __( 'Sent. Check the chat.', 'catcode-form-leads-to-crm' ),
			);
		}
		$res = $target->send( $lead );
		if ( ! $res['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}
		return array(
			'ok'      => true,
			/* translators: %s: id of the record in the CRM */
			'message' => sprintf( __( 'The test lead is in the CRM, ID %s. Please delete it there.', 'catcode-form-leads-to-crm' ), $res['remote_id'] ),
		);
	}

	/** On the plugin's own screen: leads that did not arrive must not go unnoticed. */
	public static function failed_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_options' ) || ( 'toplevel_page_' . self::SLUG ) !== $screen->id ) {
			return;
		}
		$count = Queue::failed_count();
		if ( $count > 0 ) {
			echo '<div class="notice notice-error"><p>';
			/* translators: %d: number of failed deliveries */
			echo esc_html( sprintf( __( 'Form Leads to CRM: failed deliveries during the last 7 days: %d.', 'catcode-form-leads-to-crm' ), $count ) );
			echo ' <a href="' . esc_url( self::url( 'log' ) . '&status=failed' ) . '">' . esc_html__( 'Open the log', 'catcode-form-leads-to-crm' ) . '</a></p></div>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'connections' => __( 'Connections', 'catcode-form-leads-to-crm' ),
			'forms'       => __( 'Forms', 'catcode-form-leads-to-crm' ),
			'log'         => __( 'Log', 'catcode-form-leads-to-crm' ),
		);
		/** @param array<string,string> $tabs Tabs. */
		$tabs = (array) apply_filters( 'catcode_flc_tabs', $tabs );

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connections'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show.
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'connections';

		echo '<div class="wrap ccflc"><h1>' . esc_html__( 'Form Leads to CRM', 'catcode-form-leads-to-crm' ) . '</h1>';

		$key    = 'catcode_flc_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-' . ( empty( $notice['ok'] ) ? 'error' : 'success' ) . '"><p>' . esc_html( (string) $notice['text'] ) . '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			echo '<a class="nav-tab' . ( $id === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $id ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( 'connections' === $tab ) {
			self::tab_connections();
		} elseif ( 'forms' === $tab ) {
			self::tab_forms();
		} elseif ( 'log' === $tab ) {
			self::tab_log();
		} else {
			/** @param string $tab Tab to draw. */
			do_action( 'catcode_flc_render_tab', $tab );
		}

		echo '</div>';
	}

	private static function tab_connections(): void {
		if ( Compat::keycrm_catcher_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Form Leads to KeyCRM is active. It keeps sending Contact Form 7 to KeyCRM, so this plugin does not send those leads there. Keep one of the two.', 'catcode-form-leads-to-crm' ) . '</p></div>';
		}
		if ( Compat::telegram_plugin_sends_forms() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Telegram Order Notifications is active and sends form leads. The Telegram block below stays off for leads, so that nobody gets the same message twice.', 'catcode-form-leads-to-crm' ) . '</p></div>';
		}

		$crm = (string) Settings::get( 'crm', '' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		wp_nonce_field( 'catcode_flc_save' );
		echo '<input type="hidden" name="action" value="catcode_flc_save">';

		echo '<section class="ccflc-card ccflc-panel"><h2>' . esc_html__( 'Where leads go', 'catcode-form-leads-to-crm' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="ccflc-crm">' . esc_html__( 'CRM', 'catcode-form-leads-to-crm' ) . '</label></th><td>';
		echo '<select id="ccflc-crm" name="crm"><option value="">' . esc_html__( 'None, Telegram only', 'catcode-form-leads-to-crm' ) . '</option>';
		foreach ( Targets::crms() as $id => $target ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $crm, $id, false ) . '>' . esc_html( $target->label() ) . '</option>';
		}
		echo '</select>';
		/** Below the choice of the CRM. */
		do_action( 'catcode_flc_after_crm_select' );
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Telegram', 'catcode-form-leads-to-crm' ) . '</th><td><label><input type="checkbox" id="ccflc-telegram-on" name="telegram_on" value="1"' . checked( '1', (string) Settings::get( 'telegram_on', '0' ), false ) . '> ' . esc_html__( 'Also send every lead to Telegram', 'catcode-form-leads-to-crm' ) . '</label></td></tr></table></section>';

		foreach ( Targets::all() as $id => $target ) {
			self::card( $id, $target );
		}

		echo '<section class="ccflc-card ccflc-panel"><h2>' . esc_html__( 'General', 'catcode-form-leads-to-crm' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="ccflc-prefix">' . esc_html__( 'Country code for local numbers', 'catcode-form-leads-to-crm' ) . '</label></th><td><input id="ccflc-prefix" class="ccflc-short" type="text" name="phone_prefix" value="' . esc_attr( (string) Settings::get( 'phone_prefix', '+380' ) ) . '"><p class="description">' . esc_html__( 'A number typed as 050 123 45 67 gets this code in front. Empty: numbers go as typed.', 'catcode-form-leads-to-crm' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Ad source', 'catcode-form-leads-to-crm' ) . '</th><td><label><input type="checkbox" name="utm" value="1"' . checked( '1', (string) Settings::get( 'utm', '1' ), false ) . '> ' . esc_html__( 'Remember utm tags, gclid and fbclid of the visit in a cookie for 90 days and add them to the lead', 'catcode-form-leads-to-crm' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="ccflc-retention">' . esc_html__( 'Keep the log, days', 'catcode-form-leads-to-crm' ) . '</label></th><td><input id="ccflc-retention" class="ccflc-short" type="number" min="1" max="3650" name="retention" value="' . esc_attr( (string) Settings::get( 'retention', '30' ) ) . '"><p class="description">' . esc_html__( 'The log holds the names and contacts of your visitors. Older rows are deleted.', 'catcode-form-leads-to-crm' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'On removal', 'catcode-form-leads-to-crm' ) . '</th><td><label><input type="checkbox" name="delete_data" value="1"' . checked( '1', (string) Settings::get( 'delete_data', '1' ), false ) . '> ' . esc_html__( 'Delete the settings and the log when the plugin is deleted', 'catcode-form-leads-to-crm' ) . '</label></td></tr>';
		echo '</table></section>';

		submit_button();
		echo '</form>';
	}

	private static function card( string $id, Target $target ): void {
		$cfg = Settings::target( $id );

		echo '<section class="ccflc-card" data-card="' . esc_attr( $id ) . '"><h2>' . esc_html( $target->label() ) . '</h2><table class="form-table" role="presentation">';
		foreach ( $target->fields() as $field ) {
			$key   = (string) $field['key'];
			$name  = 'targets[' . $id . '][' . $key . ']';
			$dom   = 'ccflc-' . $id . '-' . $key;
			$value = isset( $cfg[ $key ] ) && is_scalar( $cfg[ $key ] ) ? (string) $cfg[ $key ] : '';

			echo '<tr><th scope="row"><label for="' . esc_attr( $dom ) . '">' . esc_html( (string) $field['label'] ) . '</label></th><td>';
			switch ( $field['type'] ) {
				case 'secret':
					// A saved key is never sent back to the browser.
					echo '<input id="' . esc_attr( $dom ) . '" class="regular-text" type="password" autocomplete="new-password" name="' . esc_attr( $name ) . '" value="" placeholder="' . esc_attr( '' !== $value ? __( 'Saved. Type a new one to replace it.', 'catcode-form-leads-to-crm' ) : '' ) . '">';
					if ( '' !== $value ) {
						echo ' <label class="ccflc-remove"><input type="checkbox" name="' . esc_attr( 'targets[' . $id . '][' . $key . '_remove]' ) . '" value="1"> ' . esc_html__( 'Remove', 'catcode-form-leads-to-crm' ) . '</label>';
					}
					break;
				case 'select':
					echo '<select id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '">';
					foreach ( (array) $field['options'] as $option => $label ) {
						echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( (string) $label ) . '</option>';
					}
					echo '</select>';
					break;
				case 'textarea':
					echo '<textarea id="' . esc_attr( $dom ) . '" class="large-text code" rows="3" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
					break;
				case 'number':
					echo '<input id="' . esc_attr( $dom ) . '" class="ccflc-short" type="number" min="1" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
					break;
				default:
					echo '<input id="' . esc_attr( $dom ) . '" class="regular-text" type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
			if ( ! empty( $field['help'] ) ) {
				echo '<p class="description">' . esc_html( (string) $field['help'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table><p class="ccflc-actions">';
		echo '<button type="button" class="button" data-do="test" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Check the connection', 'catcode-form-leads-to-crm' ) . '</button> ';
		echo '<button type="button" class="button" data-do="send" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Send a test lead', 'catcode-form-leads-to-crm' ) . '</button> ';
		if ( 'telegram' === $id ) {
			echo '<button type="button" class="button" data-do="chats" data-target="telegram">' . esc_html__( 'Find chats', 'catcode-form-leads-to-crm' ) . '</button> ';
		}
		echo '</p><p class="description">' . esc_html__( 'The buttons use the saved settings: save first.', 'catcode-form-leads-to-crm' ) . '</p>';
		echo '<p class="ccflc-result" role="status"></p></section>';
	}

	private static function tab_forms(): void {
		echo '<p>' . esc_html__( 'Every form is sent unless you switch it off here. A new form is on by default.', 'catcode-form-leads-to-crm' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'catcode_flc_save_forms' );
		echo '<input type="hidden" name="action" value="catcode_flc_save_forms">';

		$any   = false;
		$saved = (array) Settings::get( 'forms', array() );
		foreach ( Sources::all() as $source ) {
			if ( ! $source->active() ) {
				continue;
			}
			$any = true;
			echo '<h2>' . esc_html( $source->label() ) . '</h2>';
			/** Above the forms of one form plugin. */
			do_action( 'catcode_flc_source_note', $source );

			$forms = $source->forms();
			if ( array() === $forms ) {
				echo '<p>' . esc_html__( 'No forms found yet.', 'catcode-form-leads-to-crm' ) . '</p>';
				continue;
			}
			echo '<table class="widefat striped ccflc-table"><thead><tr><th>' . esc_html__( 'Form', 'catcode-form-leads-to-crm' ) . '</th><th>' . esc_html__( 'Send', 'catcode-form-leads-to-crm' ) . '</th>';
			/** Extra column headings. */
			do_action( 'catcode_flc_forms_head' );
			echo '</tr></thead><tbody>';
			foreach ( $forms as $form_id => $title ) {
				$key  = $source->id() . ':' . $form_id;
				$name = 'forms[' . $key . ']';
				echo '<tr><td data-label="' . esc_attr__( 'Form', 'catcode-form-leads-to-crm' ) . '">' . esc_html( '' !== $title ? $title : '#' . $form_id ) . '</td>';
				echo '<td data-label="' . esc_attr__( 'Send', 'catcode-form-leads-to-crm' ) . '"><input type="hidden" name="' . esc_attr( $name . '[enabled]' ) . '" value="0"><input type="checkbox" name="' . esc_attr( $name . '[enabled]' ) . '" value="1" aria-label="' . esc_attr( $title ) . '"' . checked( Settings::form_enabled( $key ), true, false ) . '></td>';
				/**
				 * Extra columns of one form.
				 *
				 * @param string              $key   Form key.
				 * @param array<string,mixed> $saved Its saved settings.
				 */
				do_action( 'catcode_flc_forms_row', $key, isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array() );
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! $any ) {
			echo '<p>' . esc_html__( 'No supported form plugin is active on this site. Install and activate Contact Form 7.', 'catcode-form-leads-to-crm' ) . '</p>';
		} else {
			submit_button();
		}
		echo '</form>';
	}

	/** @return array<string,string> */
	private static function statuses(): array {
		return array(
			'sent'    => __( 'Delivered', 'catcode-form-leads-to-crm' ),
			'failed'  => __( 'Failed', 'catcode-form-leads-to-crm' ),
			'retry'   => __( 'Will retry', 'catcode-form-leads-to-crm' ),
			'queued'  => __( 'In the queue', 'catcode-form-leads-to-crm' ),
			'sending' => __( 'Sending', 'catcode-form-leads-to-crm' ),
			'skipped' => __( 'Skipped', 'catcode-form-leads-to-crm' ),
		);
	}

	private static function tab_log(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters of a read-only list.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$labels = self::statuses();
		$status = isset( $labels[ $status ] ) ? $status : '';
		$total  = Queue::count( $status );

		echo '<ul class="subsubsub ccflc-filter"><li><a href="' . esc_url( self::url( 'log' ) ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'catcode-form-leads-to-crm' ) . '</a></li>';
		foreach ( $labels as $id => $label ) {
			echo '<li> | <a href="' . esc_url( self::url( 'log' ) . '&status=' . $id ) . '"' . ( $id === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul><div class="ccflc-toolbar">';
		/** Buttons above the log. */
		do_action( 'catcode_flc_log_toolbar' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'catcode_flc_clear' );
		echo '<input type="hidden" name="action" value="catcode_flc_clear">';
		submit_button( __( 'Clear the log', 'catcode-form-leads-to-crm' ), 'secondary', 'submit', false );
		echo '</form></div>';

		$rows = Queue::rows( $status, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		if ( array() === $rows ) {
			echo '<p class="ccflc-empty">' . esc_html__( 'Nothing here yet. Leads appear after a form is sent.', 'catcode-form-leads-to-crm' ) . '</p>';
			return;
		}

		$head = array(
			__( 'Time', 'catcode-form-leads-to-crm' ),
			__( 'Form', 'catcode-form-leads-to-crm' ),
			__( 'Lead', 'catcode-form-leads-to-crm' ),
			__( 'Where', 'catcode-form-leads-to-crm' ),
			__( 'Status', 'catcode-form-leads-to-crm' ),
			__( 'Details', 'catcode-form-leads-to-crm' ),
		);
		echo '<table class="widefat striped ccflc-table"><thead><tr>';
		foreach ( $head as $title ) {
			echo '<th>' . esc_html( $title ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$lead = json_decode( (string) $row['lead_data'], true );
			$lead = is_array( $lead ) ? $lead : array();
			$who  = array_filter( array( (string) ( $lead['name'] ?? '' ), (string) ( $lead['phone'] ?? '' ), (string) ( $lead['email'] ?? '' ) ), 'strlen' );

			echo '<tr><td data-label="' . esc_attr( $head[0] ) . '">' . esc_html( wp_date( 'Y-m-d H:i', (int) $row['created_at'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $head[1] ) . '">' . esc_html( (string) $row['form_title'] ) . '</td>';
			echo '<td data-label="' . esc_attr( $head[2] ) . '">' . esc_html( implode( ', ', $who ) );
			if ( isset( $lead['comment'], $lead['extra'], $lead['utm'], $lead['product'], $lead['form_title'], $lead['page_url'], $lead['product_sku'] ) ) {
				$text = Lead::comment_text( $lead );
				if ( '' !== $text ) {
					echo '<details><summary>' . esc_html__( 'Show everything', 'catcode-form-leads-to-crm' ) . '</summary><div class="ccflc-lead">' . nl2br( esc_html( $text ) ) . '</div></details>';
				}
			}
			echo '</td><td data-label="' . esc_attr( $head[3] ) . '">' . esc_html( self::target_label( (string) $row['target'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $head[4] ) . '"><span class="ccflc-status ccflc-status-' . esc_attr( (string) $row['status'] ) . '">' . esc_html( $labels[ (string) $row['status'] ] ?? (string) $row['status'] ) . '</span></td>';
			echo '<td data-label="' . esc_attr( $head[5] ) . '">';
			if ( 'sent' === $row['status'] && '' !== (string) $row['remote_id'] ) {
				echo esc_html( 'ID ' . $row['remote_id'] );
			} elseif ( '' !== (string) $row['error'] ) {
				echo esc_html( (string) $row['error'] );
			}
			if ( 'retry' === $row['status'] ) {
				/* translators: %s: date and time */
				echo '<br>' . esc_html( sprintf( __( 'Next attempt: %s', 'catcode-form-leads-to-crm' ), wp_date( 'Y-m-d H:i', (int) $row['next_try'] ) ) );
			}
			/**
			 * Buttons of one row.
			 *
			 * @param array<string,mixed> $row Row.
			 */
			do_action( 'catcode_flc_log_row', $row );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="ccflc-pages">';
			for ( $i = 1; $i <= min( $pages, 50 ); $i++ ) {
				$url = self::url( 'log' ) . ( '' !== $status ? '&status=' . $status : '' ) . '&paged=' . $i;
				echo $i === $page ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( $url ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
	}

	public static function target_label( string $stored ): string {
		$pair = Targets::for_delivery( $stored );
		if ( null === $pair ) {
			return $stored;
		}
		return '' !== $pair[1] ? $pair[0]->label() . ' ' . $pair[1] : $pair[0]->label();
	}
}
