<?php
/**
 * VK7K Sync Auth Handler
 *
 * Handles API key generation, HMAC-SHA256 signatures and handshake verification.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Auth {

	const OPTION_KEY             = 'vk7k_sync_settings';
	const TARGETS_OPTION_KEY     = 'vk7k_sync_targets';
	const PIN_VAULT_OPTION_KEY   = 'vk7k_sync_pin_vault';
	const NONCE_TRANSIENT_PREFIX = 'vk7k_nonce_';
	const UNLOCK_TRANSIENT_KEY   = 'vk7k_vault_unlocked_session';

	/**
	 * Get candidate persistent disk vault file paths.
	 *
	 * @return array
	 */
	public static function get_vault_file_paths() {
		$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( __DIR__, 2 );
		$plugin_dir  = defined( 'VK7K_SYNC_PATH' ) ? VK7K_SYNC_PATH : dirname( __DIR__ );
		return array(
			trailingslashit( $content_dir ) . '.vk7k_sync_vault.json',
			trailingslashit( $plugin_dir ) . '.vk7k_sync_vault.json',
		);
	}

	/**
	 * Get active persistent disk vault file path.
	 *
	 * @return string
	 */
	public static function get_vault_file_path() {
		$paths = self::get_vault_file_paths();
		foreach ( $paths as $p ) {
			if ( file_exists( $p ) ) {
				return $p;
			}
		}
		return reset( $paths );
	}

	/**
	 * Save persistent disk vault.
	 *
	 * @param array $settings
	 * @return bool
	 */
	public static function save_vault( $settings ) {
		$paths    = self::get_vault_file_paths();
		$existing = self::get_vault();

		$local_key  = ! empty( $settings['local_secret_key'] ) ? $settings['local_secret_key'] : ( $existing['local_secret_key'] ?? '' );
		$remote_key = ! empty( $settings['remote_secret_key'] ) ? $settings['remote_secret_key'] : ( $existing['remote_secret_key'] ?? '' );
		$remote_url = ! empty( $settings['remote_url'] ) ? $settings['remote_url'] : ( $existing['remote_url'] ?? '' );
		$role       = ! empty( $settings['role'] ) ? $settings['role'] : ( $existing['role'] ?? 'master' );

		if ( empty( $local_key ) && empty( $remote_key ) ) {
			return false; // Prevent wiping valid vault with empty data
		}

		$vault_data = array(
			'local_secret_key'  => $local_key,
			'remote_secret_key' => $remote_key,
			'remote_url'        => $remote_url,
			'role'              => $role,
			'updated_at'        => time(),
		);
		$json = wp_json_encode( $vault_data, JSON_PRETTY_PRINT );

		$saved = false;
		foreach ( $paths as $file ) {
			if ( false !== @file_put_contents( $file, $json ) ) {
				$saved = true;
			}
		}
		return $saved;
	}

	/**
	 * Read persistent disk vault.
	 *
	 * @return array
	 */
	public static function get_vault() {
		$paths = self::get_vault_file_paths();
		foreach ( $paths as $file ) {
			if ( file_exists( $file ) ) {
				$raw = @file_get_contents( $file );
				if ( ! empty( $raw ) ) {
					$data = json_decode( $raw, true );
					if ( is_array( $data ) && ! empty( $data ) ) {
						return $data;
					}
				}
			}
		}
		return array();
	}

	/* =========================================================================
	 *  ZERO-KNOWLEDGE MASTER PIN VAULT ENGINE
	 * ========================================================================= */

	/**
	 * Check if Master PIN is configured.
	 *
	 * @return bool
	 */
	public static function has_pin() {
		$vault = get_option( self::PIN_VAULT_OPTION_KEY, array() );
		return ( ! empty( $vault['has_pin'] ) && ! empty( $vault['salt'] ) && ! empty( $vault['verifier'] ) );
	}

	/**
	 * Derive a 256-bit encryption key from PIN using PBKDF2 with 100,000 iterations.
	 *
	 * @param string $pin
	 * @param string $salt_hex
	 * @return string 32-byte binary key
	 */
	private static function derive_pin_key( $pin, $salt_hex ) {
		$salt = hex2bin( $salt_hex );
		return hash_pbkdf2( 'sha256', (string) $pin, $salt, 100000, 32, true );
	}

	/**
	 * Generate PIN verification hash.
	 *
	 * @param string $derived_key
	 * @return string
	 */
	private static function get_verifier_hash( $derived_key ) {
		return hash_hmac( 'sha256', 'vk7k_zero_knowledge_pin_verifier', $derived_key );
	}

	/**
	 * Setup a new Master PIN for the vault and encrypt current secrets.
	 *
	 * @param string $pin
	 * @return bool|WP_Error
	 */
	public static function setup_pin( $pin ) {
		$pin = trim( (string) $pin );
		if ( strlen( $pin ) < 4 ) {
			return new WP_Error( 'invalid_pin', __( 'El PIN debe tener al menos 4 caracteres o dígitos.', 'vk7k-wp-sync' ) );
		}

		$salt_bytes = openssl_random_pseudo_bytes( 32 );
		$salt_hex   = bin2hex( $salt_bytes );
		$derived    = self::derive_pin_key( $pin, $salt_hex );
		$verifier   = self::get_verifier_hash( $derived );

		$pin_vault = array(
			'has_pin'    => true,
			'salt'       => $salt_hex,
			'verifier'   => $verifier,
			'created_at' => time(),
			'updated_at' => time(),
		);

		update_option( self::PIN_VAULT_OPTION_KEY, $pin_vault );

		// Set active session unlock
		self::set_unlocked_session( $derived );

		// Re-encrypt existing targets and settings with new key
		self::reencrypt_all_targets();

		return true;
	}

	/**
	 * Verify if provided PIN is correct.
	 *
	 * @param string $pin
	 * @return bool
	 */
	public static function verify_pin( $pin ) {
		$vault = get_option( self::PIN_VAULT_OPTION_KEY, array() );
		if ( empty( $vault['salt'] ) || empty( $vault['verifier'] ) ) {
			return false;
		}

		$derived  = self::derive_pin_key( $pin, $vault['salt'] );
		$expected = self::get_verifier_hash( $derived );

		return hash_equals( $vault['verifier'], $expected );
	}

	/**
	 * Unlock the vault for current session using Master PIN.
	 *
	 * @param string $pin
	 * @return bool|WP_Error
	 */
	public static function unlock_vault( $pin ) {
		if ( ! self::has_pin() ) {
			return true;
		}

		if ( ! self::verify_pin( $pin ) ) {
			return new WP_Error( 'invalid_pin', __( 'El PIN ingresado es incorrecto.', 'vk7k-wp-sync' ) );
		}

		$vault   = get_option( self::PIN_VAULT_OPTION_KEY, array() );
		$derived = self::derive_pin_key( $pin, $vault['salt'] );
		self::set_unlocked_session( $derived );

		return true;
	}

	/**
	 * Lock the vault and destroy in-memory unlock session tokens.
	 *
	 * @return bool
	 */
	public static function lock_vault() {
		delete_transient( self::UNLOCK_TRANSIENT_KEY );
		return true;
	}

	/**
	 * Check if the vault is currently unlocked in session.
	 *
	 * @return bool
	 */
	public static function is_vault_unlocked() {
		if ( ! self::has_pin() ) {
			return true; // No PIN configured = unlocked by default
		}
		$token = get_transient( self::UNLOCK_TRANSIENT_KEY );
		return ! empty( $token );
	}

	/**
	 * Store encrypted session token in memory/transient.
	 *
	 * @param string $derived_key
	 */
	private static function set_unlocked_session( $derived_key ) {
		$wp_salt   = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vk7k_vault_salt';
		$token_enc = 'vk7k_sess:' . base64_encode( $derived_key ^ substr( hash( 'sha256', $wp_salt, true ), 0, 32 ) );
		set_transient( self::UNLOCK_TRANSIENT_KEY, $token_enc, 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Get derived key from active unlocked session.
	 *
	 * @return string|null
	 */
	private static function get_session_derived_key() {
		$token = get_transient( self::UNLOCK_TRANSIENT_KEY );
		if ( empty( $token ) || 0 !== strpos( $token, 'vk7k_sess:' ) ) {
			return null;
		}
		$raw     = base64_decode( substr( $token, 10 ) );
		$wp_salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vk7k_vault_salt';
		return $raw ^ substr( hash( 'sha256', $wp_salt, true ), 0, 32 );
	}

	/**
	 * Change Master PIN and re-encrypt all stored secrets.
	 *
	 * @param string $old_pin
	 * @param string $new_pin
	 * @return bool|WP_Error
	 */
	public static function change_pin( $old_pin, $new_pin ) {
		if ( ! self::verify_pin( $old_pin ) ) {
			return new WP_Error( 'invalid_old_pin', __( 'El PIN actual es incorrecto.', 'vk7k-wp-sync' ) );
		}

		$new_pin = trim( (string) $new_pin );
		if ( strlen( $new_pin ) < 4 ) {
			return new WP_Error( 'invalid_new_pin', __( 'El nuevo PIN debe tener al menos 4 caracteres.', 'vk7k-wp-sync' ) );
		}

		// Unlock temporarily with old PIN
		self::unlock_vault( $old_pin );

		// Read all targets in decrypted form
		$targets_data = self::get_targets();

		// Setup new PIN
		$res = self::setup_pin( $new_pin );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Save targets re-encrypted with new PIN
		self::save_targets_raw( $targets_data );

		return true;
	}

	/**
	 * Emergency Vault Reset (clears PIN and purges encrypted secrets for security).
	 *
	 * @return bool
	 */
	public static function reset_vault() {
		delete_option( self::PIN_VAULT_OPTION_KEY );
		delete_transient( self::UNLOCK_TRANSIENT_KEY );

		// Clear secrets in settings and targets to prevent decryption errors
		$settings = self::get_settings();
		$settings['ssh_pass']          = '';
		$settings['remote_secret_key'] = '';
		self::save_settings( $settings );

		$targets_state = self::get_targets();
		foreach ( $targets_state['targets'] as $k => $t ) {
			$targets_state['targets'][ $k ]['ssh_pass']          = '';
			$targets_state['targets'][ $k ]['remote_secret_key'] = '';
		}
		self::save_targets_raw( $targets_state );

		return true;
	}

	/* =========================================================================
	 *  CRYPTOGRAPHIC ENCRYPTION / DECRYPTION (AES-256-CBC)
	 * ========================================================================= */

	/**
	 * Get cryptographic key for credential encryption.
	 * Combines active PIN key (if available) with WP salts.
	 *
	 * @return string 32-byte binary key
	 */
	private static function get_crypto_key() {
		$session_key = self::get_session_derived_key();
		if ( ! empty( $session_key ) ) {
			return $session_key;
		}

		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vk7k_default_secure_salt_key';
		if ( function_exists( 'wp_salt' ) ) {
			$salt .= wp_salt( 'secure_auth' );
		}
		return hash( 'sha256', $salt . '_vk7k_vault', true );
	}

	/**
	 * Encrypt sensitive string with AES-256-CBC.
	 *
	 * @param string $plaintext
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		if ( empty( $plaintext ) ) {
			return '';
		}

		if ( 0 === strpos( $plaintext, 'vk7k_enc:' ) ) {
			return $plaintext;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}

		$key    = self::get_crypto_key();
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return 'vk7k_enc:' . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt sensitive string with AES-256-CBC.
	 *
	 * @param string $encrypted
	 * @return string
	 */
	public static function decrypt( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		if ( self::has_pin() && ! self::is_vault_unlocked() ) {
			return '';
		}

		if ( 0 !== strpos( $encrypted, 'vk7k_enc:' ) ) {
			return $encrypted;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $encrypted;
		}

		$raw = base64_decode( substr( $encrypted, 9 ) );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$iv        = substr( $raw, 0, 16 );
		$cipher    = substr( $raw, 16 );
		$key       = self::get_crypto_key();
		$plaintext = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return ( false !== $plaintext ) ? $plaintext : '';
	}

	/* =========================================================================
	 *  MULTI-TARGET PROFILES ENGINE (Staging, Production & Custom)
	 * ========================================================================= */

	/**
	 * Get all configured target profiles.
	 *
	 * @return array
	 */
	public static function get_targets() {
		$data = get_option( self::TARGETS_OPTION_KEY, null );

		if ( ! is_array( $data ) || empty( $data['targets'] ) ) {
			// Initialize default targets: Staging and Production
			$current_settings = get_option( self::OPTION_KEY, array() );

			$data = array(
				'active_target_id' => 'staging',
				'targets'          => array(
					'staging'    => array(
						'id'                => 'staging',
						'name'              => 'Staging',
						'environment'       => 'staging',
						'remote_url'        => ! empty( $current_settings['remote_url'] ) ? $current_settings['remote_url'] : '',
						'remote_ip'         => ! empty( $current_settings['remote_ip'] ) ? $current_settings['remote_ip'] : '',
						'use_ssh'           => 1,
						'ssh_host'          => ! empty( $current_settings['ssh_host'] ) ? $current_settings['ssh_host'] : '',
						'ssh_port'          => ! empty( $current_settings['ssh_port'] ) ? (int) $current_settings['ssh_port'] : 22,
						'ssh_user'          => ! empty( $current_settings['ssh_user'] ) ? $current_settings['ssh_user'] : '',
						'ssh_pass'          => ! empty( $current_settings['ssh_pass'] ) ? $current_settings['ssh_pass'] : '',
						'ssh_path'          => ! empty( $current_settings['ssh_path'] ) ? $current_settings['ssh_path'] : '',
						'remote_secret_key' => ! empty( $current_settings['remote_secret_key'] ) ? $current_settings['remote_secret_key'] : '',
					),
					'production' => array(
						'id'                => 'production',
						'name'              => 'Producción',
						'environment'       => 'production',
						'remote_url'        => '',
						'remote_ip'         => '',
						'use_ssh'           => 1,
						'ssh_host'          => '',
						'ssh_port'          => 22,
						'ssh_user'          => '',
						'ssh_pass'          => '',
						'ssh_path'          => '',
						'remote_secret_key' => '',
					),
				),
			);

			self::save_targets_raw( $data );
		}

		// If vault is locked, strip secret fields so they are never exposed to frontend
		if ( self::has_pin() && ! self::is_vault_unlocked() ) {
			foreach ( $data['targets'] as $k => $t ) {
				$data['targets'][ $k ]['ssh_pass']          = '';
				$data['targets'][ $k ]['remote_secret_key'] = '';
			}
		}

		return $data;
	}

	/**
	 * Get decrypted active target profile.
	 *
	 * @return array
	 */
	public static function get_active_target() {
		$state  = self::get_targets();
		$act_id = $state['active_target_id'] ?? 'staging';
		$target = $state['targets'][ $act_id ] ?? reset( $state['targets'] );

		if ( ! empty( $target['ssh_pass'] ) ) {
			$target['ssh_pass'] = self::decrypt( $target['ssh_pass'] );
		}
		if ( ! empty( $target['remote_secret_key'] ) ) {
			$target['remote_secret_key'] = self::decrypt( $target['remote_secret_key'] );
		}

		return $target;
	}

	/**
	 * Switch active target profile and update active settings.
	 *
	 * @param string $target_id
	 * @return bool|WP_Error
	 */
	public static function switch_active_target( $target_id ) {
		$state = self::get_targets();
		if ( ! isset( $state['targets'][ $target_id ] ) ) {
			return new WP_Error( 'target_not_found', __( 'El perfil de destino seleccionado no existe.', 'vk7k-wp-sync' ) );
		}

		$state['active_target_id'] = $target_id;
		update_option( self::TARGETS_OPTION_KEY, $state );

		// Sync active settings option
		$target   = $state['targets'][ $target_id ];
		$settings = self::get_settings();

		$settings['remote_url']        = $target['remote_url'];
		$settings['remote_ip']         = $target['remote_ip'];
		$settings['use_ssh']           = (int) $target['use_ssh'];
		$settings['ssh_host']          = $target['ssh_host'];
		$settings['ssh_port']          = (int) $target['ssh_port'];
		$settings['ssh_user']          = $target['ssh_user'];
		$settings['ssh_pass']          = self::decrypt( $target['ssh_pass'] );
		$settings['ssh_path']          = $target['ssh_path'];
		$settings['remote_secret_key'] = self::decrypt( $target['remote_secret_key'] );

		self::save_settings( $settings );
		return true;
	}

	/**
	 * Save or update a target profile.
	 *
	 * @param array $target_data
	 * @return bool
	 */
	public static function save_target( $target_data ) {
		$state = self::get_targets();
		$id    = ! empty( $target_data['id'] ) ? sanitize_key( $target_data['id'] ) : 'target_' . time();

		$target_entry = array(
			'id'                => $id,
			'name'              => sanitize_text_field( $target_data['name'] ?? ( 'production' === $id ? 'Producción' : 'Staging' ) ),
			'environment'       => sanitize_key( $target_data['environment'] ?? 'custom' ),
			'remote_url'        => esc_url_raw( $target_data['remote_url'] ?? '' ),
			'remote_ip'         => sanitize_text_field( $target_data['remote_ip'] ?? '' ),
			'use_ssh'           => ! empty( $target_data['use_ssh'] ) ? 1 : 0,
			'ssh_host'          => sanitize_text_field( $target_data['ssh_host'] ?? '' ),
			'ssh_port'          => ! empty( $target_data['ssh_port'] ) ? (int) $target_data['ssh_port'] : 22,
			'ssh_user'          => sanitize_text_field( $target_data['ssh_user'] ?? '' ),
			'ssh_pass'          => ! empty( $target_data['ssh_pass'] ) ? self::encrypt( $target_data['ssh_pass'] ) : ( $state['targets'][ $id ]['ssh_pass'] ?? '' ),
			'ssh_path'          => sanitize_text_field( $target_data['ssh_path'] ?? '' ),
			'remote_secret_key' => ! empty( $target_data['remote_secret_key'] ) ? self::encrypt( $target_data['remote_secret_key'] ) : ( $state['targets'][ $id ]['remote_secret_key'] ?? '' ),
		);

		$state['targets'][ $id ] = $target_entry;

		if ( empty( $state['active_target_id'] ) ) {
			$state['active_target_id'] = $id;
		}

		self::save_targets_raw( $state );

		// If saving active target, refresh active settings
		if ( ( $state['active_target_id'] ?? '' ) === $id ) {
			self::switch_active_target( $id );
		}

		return true;
	}

	/**
	 * Delete a target profile.
	 *
	 * @param string $target_id
	 * @return bool|WP_Error
	 */
	public static function delete_target( $target_id ) {
		$state = self::get_targets();
		if ( ! isset( $state['targets'][ $target_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Perfil de destino no encontrado.', 'vk7k-wp-sync' ) );
		}

		if ( count( $state['targets'] ) <= 1 ) {
			return new WP_Error( 'cannot_delete_last', __( 'No puedes eliminar el único perfil de destino existente.', 'vk7k-wp-sync' ) );
		}

		unset( $state['targets'][ $target_id ] );

		if ( $state['active_target_id'] === $target_id ) {
			$state['active_target_id'] = array_key_first( $state['targets'] );
			self::switch_active_target( $state['active_target_id'] );
		}

		self::save_targets_raw( $state );
		return true;
	}

	/**
	 * Save raw targets array with encryption.
	 *
	 * @param array $state
	 * @return bool
	 */
	public static function save_targets_raw( $state ) {
		return update_option( self::TARGETS_OPTION_KEY, $state );
	}

	/**
	 * Re-encrypt all targets with current active crypto key.
	 */
	private static function reencrypt_all_targets() {
		$state = self::get_targets();
		foreach ( $state['targets'] as $id => $t ) {
			if ( ! empty( $t['ssh_pass'] ) ) {
				$plain = self::decrypt( $t['ssh_pass'] );
				$state['targets'][ $id ]['ssh_pass'] = self::encrypt( $plain );
			}
			if ( ! empty( $t['remote_secret_key'] ) ) {
				$plain = self::decrypt( $t['remote_secret_key'] );
				$state['targets'][ $id ]['remote_secret_key'] = self::encrypt( $plain );
			}
		}
		self::save_targets_raw( $state );
	}

	/* =========================================================================
	 *  SETTINGS & AUTH HEADERS
	 * ========================================================================= */

	/**
	 * Get site's local secret key.
	 * Generates one if it doesn't exist yet.
	 *
	 * @return string
	 */
	public static function get_local_secret_key() {
		$settings = self::get_settings();
		if ( ! empty( $settings['local_secret_key'] ) ) {
			return $settings['local_secret_key'];
		}

		$vault = self::get_vault();
		if ( ! empty( $vault['local_secret_key'] ) ) {
			$settings['local_secret_key'] = $vault['local_secret_key'];
			self::save_settings( $settings );
			return $vault['local_secret_key'];
		}

		$key = wp_generate_password( 64, false, false );
		$settings['local_secret_key'] = $key;
		self::save_settings( $settings );
		return $key;
	}

	/**
	 * Regenerate local secret key.
	 *
	 * @return string
	 */
	public static function regenerate_local_secret_key() {
		$settings = self::get_settings();
		$key      = wp_generate_password( 64, false, false );
		$settings['local_secret_key'] = $key;
		self::save_settings( $settings );
		return $key;
	}

	/**
	 * Get all plugin settings with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'role'               => 'master',
			'local_secret_key'   => '',
			'remote_url'         => '',
			'remote_secret_key'  => '',
			'remote_ip'          => '',
			'use_ssh'            => 1,
			'ssh_host'           => '',
			'ssh_port'           => 22,
			'ssh_user'           => '',
			'ssh_pass'           => '',
			'ssh_path'           => '',
			'sync_db'            => 1,
			'sync_uploads'       => 1,
			'sync_plugins'       => 1,
			'sync_themes'        => 1,
			'batch_size'         => 1000,
			'zip_chunk_mb'       => 8,
			'last_sync_time'     => '',
			'last_sync_status'   => '',
		);

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		if ( empty( $saved['local_secret_key'] ) ) {
			$vault = self::get_vault();
			if ( ! empty( $vault['local_secret_key'] ) ) {
				$saved['local_secret_key'] = $vault['local_secret_key'];
				if ( empty( $saved['remote_secret_key'] ) && ! empty( $vault['remote_secret_key'] ) ) {
					$saved['remote_secret_key'] = $vault['remote_secret_key'];
				}
				if ( empty( $saved['remote_url'] ) && ! empty( $vault['remote_url'] ) ) {
					$saved['remote_url'] = $vault['remote_url'];
				}
				if ( empty( $saved['role'] ) && ! empty( $vault['role'] ) ) {
					$saved['role'] = $vault['role'];
				}
			}
		}

		if ( ! empty( $saved['ssh_pass'] ) ) {
			$saved['ssh_pass'] = self::decrypt( $saved['ssh_pass'] );
		}
		if ( ! empty( $saved['remote_secret_key'] ) ) {
			$saved['remote_secret_key'] = self::decrypt( $saved['remote_secret_key'] );
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save plugin settings with encrypted credentials and disk vault persistence.
	 *
	 * @param array $settings
	 * @return bool
	 */
	public static function save_settings( $settings ) {
		$to_save = $settings;

		self::save_vault( $to_save );

		if ( ! empty( $to_save['ssh_pass'] ) ) {
			$to_save['ssh_pass'] = self::encrypt( $to_save['ssh_pass'] );
		}
		if ( ! empty( $to_save['remote_secret_key'] ) ) {
			$to_save['remote_secret_key'] = self::encrypt( $to_save['remote_secret_key'] );
		}

		return update_option( self::OPTION_KEY, $to_save );
	}

	/**
	 * Generate headers with HMAC-SHA256 signature for outgoing request.
	 *
	 * @param string $action Endpoint action name or route
	 * @param mixed  $payload Data being sent (array or string)
	 * @param string $secret_key Secret key to use (defaults to remote_secret_key)
	 * @return array Headers array
	 */
	public static function generate_auth_headers( $action, $payload = '', $secret_key = '' ) {
		if ( empty( $secret_key ) ) {
			$settings   = self::get_settings();
			$secret_key = ! empty( $settings['remote_secret_key'] ) ? $settings['remote_secret_key'] : $settings['local_secret_key'];
		}

		$timestamp = time();
		$nonce     = wp_generate_password( 32, false, false );

		$payload_string = is_string( $payload ) ? $payload : wp_json_encode( $payload );
		$data_to_sign   = $timestamp . '|' . $nonce . '|' . $action . '|' . $payload_string;
		$signature      = hash_hmac( 'sha256', $data_to_sign, $secret_key );

		return array(
			'X-VK7K-Timestamp' => (string) $timestamp,
			'X-VK7K-Nonce'     => $nonce,
			'X-VK7K-Action'    => $action,
			'X-VK7K-Signature' => $signature,
			'X-VK7K-Site-URL'  => home_url(),
		);
	}

	/**
	 * Verify incoming REST API request authentication.
	 * Supports multi-key validation (DB, Vault, Paired Keys) and auto-rehydrates settings if missing.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public static function verify_request( $request ) {
		$timestamp = $request->get_header( 'x_vk7k_timestamp' );
		$nonce     = $request->get_header( 'x_vk7k_nonce' );
		$action    = $request->get_header( 'x_vk7k_action' );
		$signature = $request->get_header( 'x_vk7k_signature' );

		if ( empty( $timestamp ) || empty( $nonce ) || empty( $signature ) ) {
			return new WP_Error(
				'vk7k_auth_missing_headers',
				__( 'Cabeceras de autenticación VK7K faltantes.', 'vk7k-wp-sync' ),
				array( 'status' => 401 )
			);
		}

		// Check timestamp drift (5 minutes max)
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new WP_Error(
				'vk7k_auth_timestamp_expired',
				__( 'La solicitud ha expirado o existe desincronización de reloj (> 5 min).', 'vk7k-wp-sync' ),
				array( 'status' => 401 )
			);
		}

		// Check nonce to prevent replay attacks
		$transient_name = self::NONCE_TRANSIENT_PREFIX . md5( $nonce );
		if ( get_transient( $transient_name ) ) {
			return new WP_Error(
				'vk7k_auth_replay_detected',
				__( 'Nonce duplicado detectado. Posible ataque de retransmisión.', 'vk7k-wp-sync' ),
				array( 'status' => 401 )
			);
		}
		set_transient( $transient_name, 1, 600 ); // Store for 10 minutes

		// Build payload string
		$raw_body = $request->get_body();
		if ( empty( $raw_body ) ) {
			$params = $request->get_params();
			$raw_body = ! empty( $params ) ? wp_json_encode( $params ) : '';
		}

		$data_to_sign = $timestamp . '|' . $nonce . '|' . $action . '|' . $raw_body;

		// Collect all possible valid keys for verification
		$settings   = self::get_settings();
		$vault      = self::get_vault();
		$local_key  = self::get_local_secret_key();
		$remote_key = ! empty( $settings['remote_secret_key'] ) ? $settings['remote_secret_key'] : '';

		$candidate_keys = array();
		if ( ! empty( $local_key ) ) {
			$candidate_keys[] = $local_key;
		}
		if ( ! empty( $vault['local_secret_key'] ) ) {
			$candidate_keys[] = $vault['local_secret_key'];
		}
		if ( ! empty( $remote_key ) ) {
			$candidate_keys[] = $remote_key;
		}
		if ( ! empty( $vault['remote_secret_key'] ) ) {
			$candidate_keys[] = $vault['remote_secret_key'];
		}

		$candidate_keys = array_unique( array_filter( $candidate_keys ) );
		$valid = false;

		foreach ( $candidate_keys as $key ) {
			$expected_sig = hash_hmac( 'sha256', $data_to_sign, $key );
			if ( hash_equals( $expected_sig, $signature ) ) {
				$valid = true;
				break;
			}
		}

		if ( ! $valid ) {
			return new WP_Error(
				'vk7k_auth_invalid_signature',
				__( 'Firma HMAC inválida. Las claves secretas no coinciden.', 'vk7k-wp-sync' ),
				array( 'status' => 403 )
			);
		}

		// If authenticated and DB was missing settings, auto-heal DB options
		$db_settings = get_option( self::OPTION_KEY );
		if ( empty( $db_settings ) || empty( $db_settings['local_secret_key'] ) ) {
			self::save_settings( $settings );
		}

		return true;
	}
}
