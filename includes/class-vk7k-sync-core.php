<?php
/**
 * VK7K Sync Core Class
 *
 * Handles plugin bootstrap, AJAX handlers, and REST API hookup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Core {

	/**
	 * Singleton instance.
	 *
	 * @var VK7K_Sync_Core
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return VK7K_Sync_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Register REST API routes
		add_action( 'rest_api_init', array( 'VK7K_Sync_API', 'register_routes' ) );

		// Register Admin AJAX actions
		add_action( 'wp_ajax_vk7k_sync_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_vk7k_sync_test_ssh', array( $this, 'ajax_test_ssh' ) );
		add_action( 'wp_ajax_vk7k_sync_scan_remote_paths', array( $this, 'ajax_scan_remote_paths' ) );
		add_action( 'wp_ajax_vk7k_sync_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_vk7k_sync_get_tables', array( $this, 'ajax_get_tables' ) );
		add_action( 'wp_ajax_vk7k_sync_db_step', array( $this, 'ajax_sync_db_step' ) );
		add_action( 'wp_ajax_vk7k_sync_prepare_files', array( $this, 'ajax_prepare_files' ) );
		add_action( 'wp_ajax_vk7k_sync_files_step', array( $this, 'ajax_sync_files_step' ) );
		add_action( 'wp_ajax_vk7k_sync_files_sftp', array( $this, 'ajax_sync_files_sftp' ) );
		add_action( 'wp_ajax_vk7k_sync_finalize', array( $this, 'ajax_finalize' ) );
		add_action( 'wp_ajax_vk7k_sync_backup_db', array( $this, 'ajax_backup_db' ) );
		add_action( 'wp_ajax_vk7k_sync_restore_db', array( $this, 'ajax_restore_db' ) );
		add_action( 'wp_ajax_vk7k_sync_regenerate_key', array( $this, 'ajax_regenerate_key' ) );

		// PIN Vault actions
		add_action( 'wp_ajax_vk7k_sync_setup_pin', array( $this, 'ajax_setup_pin' ) );
		add_action( 'wp_ajax_vk7k_sync_unlock_vault', array( $this, 'ajax_unlock_vault' ) );
		add_action( 'wp_ajax_vk7k_sync_lock_vault', array( $this, 'ajax_lock_vault' ) );
		add_action( 'wp_ajax_vk7k_sync_change_pin', array( $this, 'ajax_change_pin' ) );
		add_action( 'wp_ajax_vk7k_sync_reset_vault', array( $this, 'ajax_reset_vault' ) );

		// Target profile actions
		add_action( 'wp_ajax_vk7k_sync_switch_target', array( $this, 'ajax_switch_target' ) );
		add_action( 'wp_ajax_vk7k_sync_save_target', array( $this, 'ajax_save_target' ) );
		add_action( 'wp_ajax_vk7k_sync_delete_target', array( $this, 'ajax_delete_target' ) );

		// Fast Link Tools actions
		add_action( 'wp_ajax_vk7k_sync_audit_links', array( $this, 'ajax_audit_links' ) );
		add_action( 'wp_ajax_vk7k_sync_fix_links', array( $this, 'ajax_fix_links' ) );

		// Manual deployment of plugin code to remote
		add_action( 'wp_ajax_vk7k_sync_deploy_plugin_to_remote', array( $this, 'ajax_deploy_plugin_to_remote' ) );
	}

	/**
	 * Plugin activation.
	 */
	public static function on_activation() {
		VK7K_Sync_Auth::get_local_secret_key();
		VK7K_Sync_Files::get_temp_dir();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function on_deactivation() {
		VK7K_Sync_Files::cleanup_temp();
	}

	/**
	 * Check admin AJAX permissions and nonce.
	 */
	private function check_ajax_permissions() {
		// 1. Check standard WP Nonce (without dying to allow fallback or structured JSON error)
		$nonce_valid = check_ajax_referer( 'vk7k_sync_admin_nonce', 'nonce', false );
		if ( $nonce_valid && current_user_can( 'manage_options' ) ) {
			return true;
		}

		// 2. Direct logged in Administrator Check
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		// 3. Fallback to local secret key validation for ongoing sync runners
		$auth_key = isset( $_REQUEST['sync_auth_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sync_auth_key'] ) ) : '';
		if ( ! empty( $auth_key ) && hash_equals( VK7K_Sync_Auth::get_local_secret_key(), $auth_key ) ) {
			return true;
		}

		wp_send_json_error( array(
			'message' => 'Tu sesión de WordPress ha caducado o no tienes permisos de administrador. Por favor, recarga o inicia sesión.',
			'code'    => 'session_expired',
		), 403 );
	}

	/**
	 * AJAX: Test connection.
	 */
	public function ajax_test_connection() {
		$this->check_ajax_permissions();

		$remote_url = isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '';
		$secret_key = isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '';
		$remote_ip  = isset( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : '';

		$res = VK7K_Sync_Runner::test_connection( $remote_url, $secret_key, $remote_ip );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array(
				'message' => $res->get_error_message(),
				'code'    => $res->get_error_code(),
			) );
		}

		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Save settings.
	 */
	public function ajax_save_settings() {
		$this->check_ajax_permissions();

		$settings = VK7K_Sync_Auth::get_settings();
		if ( isset( $_POST['role'] ) ) {
			$settings['role'] = ( 'slave' === $_POST['role'] ) ? 'slave' : 'master';
		}
		if ( isset( $_POST['remote_url'] ) ) {
			$settings['remote_url'] = esc_url_raw( wp_unslash( $_POST['remote_url'] ) );
		}
		if ( isset( $_POST['remote_secret_key'] ) && '' !== trim( $_POST['remote_secret_key'] ) ) {
			$settings['remote_secret_key'] = sanitize_text_field( wp_unslash( $_POST['remote_secret_key'] ) );
		}
		if ( isset( $_POST['remote_ip'] ) ) {
			$settings['remote_ip'] = sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) );
		}
		if ( isset( $_POST['use_ssh'] ) ) {
			$settings['use_ssh'] = (int) $_POST['use_ssh'];
		}
		if ( isset( $_POST['ssh_host'] ) ) {
			$settings['ssh_host'] = sanitize_text_field( wp_unslash( $_POST['ssh_host'] ) );
		}
		if ( isset( $_POST['ssh_port'] ) ) {
			$settings['ssh_port'] = (int) $_POST['ssh_port'];
		}
		if ( isset( $_POST['ssh_user'] ) ) {
			$settings['ssh_user'] = sanitize_text_field( wp_unslash( $_POST['ssh_user'] ) );
		}
		if ( isset( $_POST['ssh_pass'] ) && '' !== trim( $_POST['ssh_pass'] ) ) {
			$settings['ssh_pass'] = sanitize_text_field( wp_unslash( $_POST['ssh_pass'] ) );
		}
		if ( isset( $_POST['ssh_path'] ) ) {
			$settings['ssh_path'] = sanitize_text_field( wp_unslash( $_POST['ssh_path'] ) );
		}
		if ( isset( $_POST['sync_db'] ) ) {
			$settings['sync_db'] = (int) $_POST['sync_db'];
		}
		if ( isset( $_POST['sync_uploads'] ) ) {
			$settings['sync_uploads'] = (int) $_POST['sync_uploads'];
		}
		if ( isset( $_POST['sync_plugins'] ) ) {
			$settings['sync_plugins'] = (int) $_POST['sync_plugins'];
		}
		if ( isset( $_POST['sync_themes'] ) ) {
			$settings['sync_themes'] = (int) $_POST['sync_themes'];
		}

		VK7K_Sync_Auth::save_settings( $settings );

		// Also keep active target profile in sync
		$state     = VK7K_Sync_Auth::get_targets();
		$active_id = $state['active_target_id'] ?? 'staging';
		if ( isset( $state['targets'][ $active_id ] ) ) {
			$state['targets'][ $active_id ]['remote_url'] = $settings['remote_url'];
			$state['targets'][ $active_id ]['remote_ip']  = $settings['remote_ip'];
			$state['targets'][ $active_id ]['use_ssh']    = (int) $settings['use_ssh'];
			$state['targets'][ $active_id ]['ssh_host']   = $settings['ssh_host'];
			$state['targets'][ $active_id ]['ssh_port']   = (int) $settings['ssh_port'];
			$state['targets'][ $active_id ]['ssh_user']   = $settings['ssh_user'];
			if ( ! empty( $settings['ssh_pass'] ) ) {
				$state['targets'][ $active_id ]['ssh_pass'] = VK7K_Sync_Auth::encrypt( $settings['ssh_pass'] );
			}
			$state['targets'][ $active_id ]['ssh_path'] = $settings['ssh_path'];
			if ( ! empty( $settings['remote_secret_key'] ) ) {
				$state['targets'][ $active_id ]['remote_secret_key'] = VK7K_Sync_Auth::encrypt( $settings['remote_secret_key'] );
			}
			VK7K_Sync_Auth::save_targets_raw( $state );
		}

		wp_send_json_success( array(
			'message'       => 'Configuración guardada correctamente.',
			'settings'      => $settings,
			'targets_state' => $state,
			'active_target' => VK7K_Sync_Auth::get_active_target(),
		) );
	}

	/**
	 * AJAX: Get DB tables list.
	 */
	public function ajax_get_tables() {
		$this->check_ajax_permissions();
		$mode       = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'push';
		$db_options = array(
			'sync_content'    => isset( $_POST['sync_content'] ) ? ! empty( $_POST['sync_content'] ) : true,
			'sync_options'    => isset( $_POST['sync_options'] ) ? ! empty( $_POST['sync_options'] ) : true,
			'sync_taxonomies' => isset( $_POST['sync_taxonomies'] ) ? ! empty( $_POST['sync_taxonomies'] ) : true,
			'sync_comments'   => isset( $_POST['sync_comments'] ) ? ! empty( $_POST['sync_comments'] ) : true,
			'sync_wc_orders'  => isset( $_POST['sync_wc_orders'] ) ? ! empty( $_POST['sync_wc_orders'] ) : true,
			'sync_users'      => isset( $_POST['sync_users'] ) ? ! empty( $_POST['sync_users'] ) : true,
			'sync_logs'       => isset( $_POST['sync_logs'] ) ? ! empty( $_POST['sync_logs'] ) : false,
		);

		if ( 'push' === $mode ) {
			$tables = VK7K_Sync_DB::get_tables( $db_options );
			wp_send_json_success( array( 'tables' => $tables ) );
		} else {
			$remote_url = isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '';
			$secret_key = isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '';
			$remote_ip  = isset( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : '';

			$res = VK7K_Sync_Runner::send_remote_request( 'db/tables', array( 'options' => $db_options ), $remote_url, $secret_key, $remote_ip );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			wp_send_json_success( $res );
		}
	}

	/**
	 * AJAX: Execute DB chunk sync step.
	 */
	public function ajax_sync_db_step() {
		$this->check_ajax_permissions();

		$params = array(
			'mode'       => isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'push',
			'tables'     => isset( $_POST['tables'] ) ? (array) $_POST['tables'] : array(),
			'table_idx'  => isset( $_POST['table_idx'] ) ? (int) $_POST['table_idx'] : 0,
			'offset'     => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
			'limit'      => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 1000,
			'remote_url' => isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '',
			'secret_key' => isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '',
			'remote_ip'  => isset( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : '',
		);

		$res = VK7K_Sync_Runner::step_sync_db( $params );
		if ( empty( $res['success'] ) ) {
			wp_send_json_error( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Prepare file manifest and diff.
	 */
	public function ajax_prepare_files() {
		$this->check_ajax_permissions();

		$components       = isset( $_POST['components'] ) ? (array) $_POST['components'] : array( 'uploads' );
		$selected_plugins = isset( $_POST['selected_plugins'] ) ? (array) $_POST['selected_plugins'] : array();
		$selected_themes  = isset( $_POST['selected_themes'] ) ? (array) $_POST['selected_themes'] : array();
		$media_filter     = isset( $_POST['media_filter'] ) ? sanitize_key( $_POST['media_filter'] ) : 'all';

		$options = array(
			'selected_plugins' => $selected_plugins,
			'selected_themes'  => $selected_themes,
			'media_filter'     => $media_filter,
		);

		$params = array(
			'mode'       => isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'push',
			'components' => $components,
			'options'    => $options,
			'remote_url' => isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '',
			'secret_key' => isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '',
			'remote_ip'  => isset( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : '',
		);

		$res = VK7K_Sync_Runner::step_prepare_files( $params );
		if ( empty( $res['success'] ) ) {
			wp_send_json_error( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: File sync chunk step (HTTP REST fallback).
	 */
	public function ajax_sync_files_step() {
		$this->check_ajax_permissions();

		$offset = 0;
		if ( isset( $_POST['offset'] ) ) {
			$offset = (int) $_POST['offset'];
		} elseif ( isset( $_POST['file_idx'] ) ) {
			$offset = (int) $_POST['file_idx'];
		}

		$params = array(
			'mode'       => isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'push',
			'offset'     => $offset,
			'chunk_mb'   => isset( $_POST['chunk_mb'] ) ? (int) $_POST['chunk_mb'] : 4,
			'remote_url' => isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '',
			'secret_key' => isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '',
			'remote_ip'  => isset( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : '',
		);

		$res = VK7K_Sync_Runner::step_sync_files_chunk( $params );
		if ( empty( $res['success'] ) ) {
			wp_send_json_error( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Test SSH/SFTP connection and auto-pair.
	 */
	public function ajax_test_ssh() {
		$this->check_ajax_permissions();
		$settings = VK7K_Sync_Auth::get_settings();

		$ssh_config = array(
			'ssh_host' => ! empty( $_POST['ssh_host'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_host'] ) ) : ( $settings['ssh_host'] ?? '' ),
			'ssh_port' => ! empty( $_POST['ssh_port'] ) ? (int) $_POST['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 ),
			'ssh_user' => ! empty( $_POST['ssh_user'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_user'] ) ) : ( $settings['ssh_user'] ?? '' ),
			'ssh_pass' => ( isset( $_POST['ssh_pass'] ) && '' !== trim( $_POST['ssh_pass'] ) ) ? sanitize_text_field( wp_unslash( $_POST['ssh_pass'] ) ) : ( $settings['ssh_pass'] ?? '' ),
			'ssh_path' => ! empty( $_POST['ssh_path'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_path'] ) ) : ( $settings['ssh_path'] ?? '' ),
		);

		$remote_url    = ! empty( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : ( $settings['remote_url'] ?? '' );
		$force_confirm = ! empty( $_POST['force_confirm'] );

		$res = VK7K_Sync_Runner::test_ssh_connection( $ssh_config, $remote_url, $force_confirm );
		if ( empty( $res['success'] ) ) {
			wp_send_json_error( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Scan remote server over SFTP to discover all WordPress installations.
	 */
	public function ajax_scan_remote_paths() {
		$this->check_ajax_permissions();
		$settings = VK7K_Sync_Auth::get_settings();

		$ssh_config = array(
			'ssh_host' => ! empty( $_POST['ssh_host'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_host'] ) ) : ( $settings['ssh_host'] ?? '' ),
			'ssh_port' => ! empty( $_POST['ssh_port'] ) ? (int) $_POST['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 ),
			'ssh_user' => ! empty( $_POST['ssh_user'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_user'] ) ) : ( $settings['ssh_user'] ?? '' ),
			'ssh_pass' => ( isset( $_POST['ssh_pass'] ) && '' !== trim( $_POST['ssh_pass'] ) ) ? sanitize_text_field( wp_unslash( $_POST['ssh_pass'] ) ) : ( $settings['ssh_pass'] ?? '' ),
			'ssh_path' => ! empty( $_POST['ssh_path'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_path'] ) ) : ( $settings['ssh_path'] ?? '' ),
		);

		$remote_url = ! empty( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : ( $settings['remote_url'] ?? '' );

		if ( empty( $ssh_config['ssh_host'] ) || empty( $ssh_config['ssh_user'] ) || empty( $ssh_config['ssh_pass'] ) ) {
			wp_send_json_error( array(
				'message' => 'Faltan credenciales SSH (Host, Usuario o Contraseña) para escanear el servidor.',
			) );
		}

		$discovered = VK7K_Sync_Runner::discover_remote_wp_paths( $ssh_config, $remote_url );

		wp_send_json_success( array(
			'discovered_paths' => $discovered,
			'count'            => count( $discovered ),
			'configured_path'  => $ssh_config['ssh_path'],
		) );
	}

	/**
	 * AJAX: File sync via Turbo SSH/SFTP bundle upload.
	 */
	public function ajax_sync_files_sftp() {
		$this->check_ajax_permissions();
		$settings = VK7K_Sync_Auth::get_settings();

		$ssh_config = array(
			'ssh_host' => ! empty( $_POST['ssh_host'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_host'] ) ) : ( $settings['ssh_host'] ?? '' ),
			'ssh_port' => ! empty( $_POST['ssh_port'] ) ? (int) $_POST['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 ),
			'ssh_user' => ! empty( $_POST['ssh_user'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_user'] ) ) : ( $settings['ssh_user'] ?? '' ),
			'ssh_pass' => ( isset( $_POST['ssh_pass'] ) && '' !== trim( $_POST['ssh_pass'] ) ) ? sanitize_text_field( wp_unslash( $_POST['ssh_pass'] ) ) : ( $settings['ssh_pass'] ?? '' ),
			'ssh_path' => ! empty( $_POST['ssh_path'] ) ? sanitize_text_field( wp_unslash( $_POST['ssh_path'] ) ) : ( $settings['ssh_path'] ?? '' ),
		);

		$params = array(
			'remote_url' => ! empty( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : ( $settings['remote_url'] ?? '' ),
			'secret_key' => ! empty( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : ( $settings['remote_secret_key'] ?? '' ),
			'remote_ip'  => ! empty( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : ( $settings['remote_ip'] ?? '' ),
			'ssh_config' => $ssh_config,
			'offset'     => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
			'chunk_mb'   => isset( $_POST['chunk_mb'] ) ? (int) $_POST['chunk_mb'] : 15,
			'max_files'  => isset( $_POST['max_files'] ) ? (int) $_POST['max_files'] : 1000,
		);

		$res = VK7K_Sync_Runner::step_sync_files_sftp( $params );
		if ( empty( $res['success'] ) ) {
			wp_send_json_error( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Finalize sync.
	 */
	public function ajax_finalize() {
		$this->check_ajax_permissions();

		$params = array(
			'mode'       => isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'push',
			'remote_url' => isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '',
			'secret_key' => isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '',
			'remote_ip'  => isset( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : '',
		);

		$res = VK7K_Sync_Runner::step_finalize( $params );
		if ( empty( $res['success'] ) ) {
			wp_send_json_error( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Backup DB.
	 */
	public function ajax_backup_db() {
		$this->check_ajax_permissions();
		$res = VK7K_Sync_DB::create_rollback_backup();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Restore DB.
	 */
	public function ajax_restore_db() {
		$this->check_ajax_permissions();
		$res = VK7K_Sync_DB::restore_rollback_backup();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Regenerate Key.
	 */
	public function ajax_regenerate_key() {
		$this->check_ajax_permissions();
		$new_key = VK7K_Sync_Auth::regenerate_local_secret_key();
		wp_send_json_success( array( 'key' => $new_key ) );
	}

	/* =========================================================================
	 *  PIN VAULT AJAX HANDLERS
	 * ========================================================================= */

	/**
	 * AJAX: Setup Master PIN.
	 */
	public function ajax_setup_pin() {
		$this->check_ajax_permissions();
		$pin = isset( $_POST['pin'] ) ? (string) $_POST['pin'] : '';

		$res = VK7K_Sync_Auth::setup_pin( $pin );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'   => 'PIN Maestro configurado y bóveda activada con éxito.',
			'is_unlocked' => true,
		) );
	}

	/**
	 * AJAX: Unlock Vault with PIN.
	 */
	public function ajax_unlock_vault() {
		$this->check_ajax_permissions();
		$pin = isset( $_POST['pin'] ) ? (string) $_POST['pin'] : '';

		$res = VK7K_Sync_Auth::unlock_vault( $pin );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$active_target = VK7K_Sync_Auth::get_active_target();

		wp_send_json_success( array(
			'message'       => 'Bóveda desbloqueada con éxito.',
			'is_unlocked'   => true,
			'active_target' => $active_target,
		) );
	}

	/**
	 * AJAX: Lock Vault.
	 */
	public function ajax_lock_vault() {
		$this->check_ajax_permissions();
		VK7K_Sync_Auth::lock_vault();
		wp_send_json_success( array(
			'message'     => 'Bóveda bloqueada.',
			'is_unlocked' => false,
		) );
	}

	/**
	 * AJAX: Change Master PIN.
	 */
	public function ajax_change_pin() {
		$this->check_ajax_permissions();
		$old_pin = isset( $_POST['old_pin'] ) ? (string) $_POST['old_pin'] : '';
		$new_pin = isset( $_POST['new_pin'] ) ? (string) $_POST['new_pin'] : '';

		$res = VK7K_Sync_Auth::change_pin( $old_pin, $new_pin );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'PIN Maestro actualizado con éxito.' ) );
	}

	/**
	 * AJAX: Reset Vault.
	 */
	public function ajax_reset_vault() {
		$this->check_ajax_permissions();
		VK7K_Sync_Auth::reset_vault();
		wp_send_json_success( array( 'message' => 'Bóveda restablecida de fábrica. Define un nuevo PIN.' ) );
	}

	/* =========================================================================
	 *  TARGET PROFILES AJAX HANDLERS
	 * ========================================================================= */

	/**
	 * AJAX: Switch Active Target.
	 */
	/**
	 * AJAX: Switch Active Target.
	 */
	public function ajax_switch_target() {
		$this->check_ajax_permissions();
		$target_id = isset( $_POST['target_id'] ) ? sanitize_key( $_POST['target_id'] ) : 'staging';

		$res = VK7K_Sync_Auth::switch_active_target( $target_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$active = VK7K_Sync_Auth::get_active_target();
		$state  = VK7K_Sync_Auth::get_targets();
		wp_send_json_success( array(
			'message'       => 'Destino cambiado a ' . ( $active['name'] ?? $target_id ),
			'active_target' => $active,
			'target'        => $active,
			'targets_state' => $state,
		) );
	}

	/**
	 * AJAX: Save Target Profile.
	 */
	public function ajax_save_target() {
		$this->check_ajax_permissions();

		$raw_data = isset( $_POST['target_data'] ) && is_array( $_POST['target_data'] ) ? $_POST['target_data'] : $_POST;

		$target_data = array(
			'id'                => isset( $_POST['target_id'] ) ? sanitize_key( $_POST['target_id'] ) : ( isset( $raw_data['id'] ) ? sanitize_key( $raw_data['id'] ) : '' ),
			'name'              => isset( $raw_data['name'] ) ? sanitize_text_field( wp_unslash( $raw_data['name'] ) ) : '',
			'environment'       => isset( $raw_data['environment'] ) ? sanitize_key( $raw_data['environment'] ) : 'staging',
			'remote_url'        => isset( $raw_data['remote_url'] ) ? esc_url_raw( wp_unslash( $raw_data['remote_url'] ) ) : '',
			'remote_ip'         => isset( $raw_data['remote_ip'] ) ? sanitize_text_field( wp_unslash( $raw_data['remote_ip'] ) ) : '',
			'use_ssh'           => ! empty( $raw_data['use_ssh'] ) ? 1 : 0,
			'ssh_host'          => isset( $raw_data['ssh_host'] ) ? sanitize_text_field( wp_unslash( $raw_data['ssh_host'] ) ) : '',
			'ssh_port'          => isset( $raw_data['ssh_port'] ) ? (int) $raw_data['ssh_port'] : 22,
			'ssh_user'          => isset( $raw_data['ssh_user'] ) ? sanitize_text_field( wp_unslash( $raw_data['ssh_user'] ) ) : '',
			'ssh_pass'          => isset( $raw_data['ssh_pass'] ) && '' !== trim( $raw_data['ssh_pass'] ) ? sanitize_text_field( wp_unslash( $raw_data['ssh_pass'] ) ) : '',
			'ssh_path'          => isset( $raw_data['ssh_path'] ) ? sanitize_text_field( wp_unslash( $raw_data['ssh_path'] ) ) : '',
			'remote_secret_key' => isset( $raw_data['remote_secret_key'] ) && '' !== trim( $raw_data['remote_secret_key'] ) ? sanitize_text_field( wp_unslash( $raw_data['remote_secret_key'] ) ) : '',
		);

		VK7K_Sync_Auth::save_target( $target_data );
		$state  = VK7K_Sync_Auth::get_targets();
		$active = VK7K_Sync_Auth::get_active_target();

		wp_send_json_success( array(
			'message'       => 'Perfil de destino guardado con éxito.',
			'targets_state' => $state,
			'active_target' => $active,
			'target'        => $active,
		) );
	}

	/**
	 * AJAX: Delete Target Profile.
	 */
	public function ajax_delete_target() {
		$this->check_ajax_permissions();
		$target_id = isset( $_POST['target_id'] ) ? sanitize_key( $_POST['target_id'] ) : '';

		$res = VK7K_Sync_Auth::delete_target( $target_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$state  = VK7K_Sync_Auth::get_targets();
		$active = VK7K_Sync_Auth::get_active_target();
		wp_send_json_success( array(
			'message'       => 'Perfil de destino eliminado.',
			'targets_state' => $state,
			'active_target' => $active,
			'target'        => $active,
		) );
	}

	/**
	 * AJAX: Audit links in local database.
	 */
	public function ajax_audit_links() {
		$this->check_ajax_permissions();

		$target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
		if ( empty( $target_url ) ) {
			$active_target = VK7K_Sync_Auth::get_active_target();
			$target_url    = ! empty( $active_target['remote_url'] ) ? $active_target['remote_url'] : '';
		}

		if ( empty( $target_url ) ) {
			$settings   = VK7K_Sync_Auth::get_settings();
			$target_url = ! empty( $settings['remote_url'] ) ? $settings['remote_url'] : '';
		}

		$audit = VK7K_Sync_DB::audit_residual_urls( array( $target_url ) );

		wp_send_json_success( array(
			'audit'       => $audit,
			'target_url'  => $target_url,
			'local_url'   => home_url(),
		) );
	}

	/**
	 * AJAX: Fast fix links in local database.
	 */
	public function ajax_fix_links() {
		$this->check_ajax_permissions();

		$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
		$target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : home_url();

		if ( empty( $source_url ) ) {
			$active_target = VK7K_Sync_Auth::get_active_target();
			$source_url    = ! empty( $active_target['remote_url'] ) ? $active_target['remote_url'] : '';
		}

		if ( empty( $source_url ) ) {
			$settings   = VK7K_Sync_Auth::get_settings();
			$source_url = ! empty( $settings['remote_url'] ) ? $settings['remote_url'] : '';
		}

		if ( empty( $source_url ) || empty( $target_url ) ) {
			wp_send_json_error( array( 'message' => 'Se requiere URL de origen y URL de destino.' ) );
		}

		$matrix = VK7K_Sync_Replacer::build_url_matrix( $source_url, $target_url );
		$stats  = VK7K_Sync_DB::deep_search_and_replace( $matrix );

		update_option( 'siteurl', untrailingslashit( $target_url ) );
		update_option( 'home', untrailingslashit( $target_url ) );

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( Exception $e ) {
				// Ignore
			}
		}

		wp_cache_flush();
		flush_rewrite_rules( true );

		$audit = VK7K_Sync_DB::audit_residual_urls( array( $source_url ) );

		wp_send_json_success( array(
			'message' => 'Reparación y normalización de enlaces completada.',
			'stats'   => $stats,
			'audit'   => $audit,
		) );
	}

	/**
	 * AJAX: Manually deploy plugin code to remote instance.
	 */
	public function ajax_deploy_plugin_to_remote() {
		$this->check_ajax_permissions();

		$settings   = VK7K_Sync_Auth::get_settings();
		$remote_url = ! empty( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : ( $settings['remote_url'] ?? '' );
		$secret_key = ! empty( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : ( $settings['remote_secret_key'] ?? '' );
		$remote_ip  = ! empty( $_POST['remote_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_ip'] ) ) : ( $settings['remote_ip'] ?? '' );

		if ( empty( $remote_url ) || empty( $secret_key ) ) {
			wp_send_json_error( array( 'message' => 'Faltan credenciales del servidor remoto.' ), 400 );
		}

		$deployed = false;
		// Try SSH provision first if SSH credentials exist
		if ( ! empty( $settings['use_ssh'] ) && ! empty( $settings['ssh_host'] ) && ! empty( $settings['ssh_pass'] ) ) {
			$prov = VK7K_Sync_Runner::provision_remote_via_ssh( array(), $remote_url );
			if ( ! is_wp_error( $prov ) && ! empty( $prov['success'] ) ) {
				$deployed = true;
			}
		}

		// Fallback to REST API deploy
		if ( ! $deployed ) {
			$res = VK7K_Sync_Runner::deploy_plugin_to_remote_via_rest( $remote_url, $secret_key, $remote_ip );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			if ( empty( $res['success'] ) ) {
				wp_send_json_error( array( 'message' => $res['message'] ?? 'Error desconocido al desplegar plugin vía REST.' ) );
			}
		}

		wp_send_json_success( array(
			'message'        => 'Plugin desplegado y actualizado con éxito en el servidor remoto a la versión ' . VK7K_SYNC_VERSION,
			'plugin_version' => VK7K_SYNC_VERSION,
		) );
	}
}
