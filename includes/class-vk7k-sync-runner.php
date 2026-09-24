<?php
/**
 * VK7K Sync Runner Engine
 *
 * Orchestrates step-by-step synchronization operations (Push / Pull),
 * handles remote HTTP communication, custom IP resolution, adaptive capacity negotiation,
 * and granular component filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Runner {

	/**
	 * Send authenticated HTTP request to remote WordPress.
	 */
	public static function send_remote_request( $endpoint, $body = array(), $remote_url = '', $secret_key = '', $remote_ip = '' ) {
		$settings = VK7K_Sync_Auth::get_settings();

		if ( empty( $remote_url ) ) {
			$remote_url = $settings['remote_url'];
		}
		if ( empty( $secret_key ) ) {
			$secret_key = ! empty( $settings['remote_secret_key'] ) ? $settings['remote_secret_key'] : $settings['local_secret_key'];
		}
		if ( empty( $remote_ip ) ) {
			$remote_ip = ! empty( $settings['remote_ip'] ) ? $settings['remote_ip'] : '';
		}

		$remote_url = untrailingslashit( $remote_url );
		$api_url    = $remote_url . '/index.php?rest_route=/vk7k-sync/v1/' . ltrim( $endpoint, '/' );

		$headers = VK7K_Sync_Auth::generate_auth_headers( $endpoint, $body, $secret_key );
		$headers['Content-Type'] = 'application/json; charset=utf-8';
		$headers['Accept']       = 'application/json';

		$curl_hook = null;
		if ( ! empty( $remote_ip ) ) {
			$parsed = parse_url( $remote_url );
			$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
			$port   = isset( $parsed['port'] ) ? $parsed['port'] : ( ( isset( $parsed['scheme'] ) && 'https' === $parsed['scheme'] ) ? 443 : 80 );

			if ( ! empty( $host ) ) {
				$curl_hook = function( &$handle ) use ( $host, $port, $remote_ip ) {
					if ( defined( 'CURLOPT_RESOLVE' ) ) {
						curl_setopt( $handle, CURLOPT_RESOLVE, array( "{$host}:{$port}:{$remote_ip}" ) );
					}
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, false );
				};
				add_action( 'http_api_curl', $curl_hook, 10, 1 );
			}
		}

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout'     => 120,
				'redirection' => 5,
				'httpversion' => '1.1',
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'sslverify'   => false,
			)
		);

		if ( $curl_hook ) {
			remove_action( 'http_api_curl', $curl_hook, 10 );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code ) {
			// If HMAC signature failed and SSH is enabled, auto-repair remote vault once and retry
			static $retried = false;
			$active_target  = VK7K_Sync_Auth::get_active_target();
			$ssh_host       = ! empty( $settings['ssh_host'] ) ? $settings['ssh_host'] : ( $active_target['ssh_host'] ?? '' );
			$ssh_pass       = ! empty( $settings['ssh_pass'] ) ? $settings['ssh_pass'] : ( $active_target['ssh_pass'] ?? '' );
			$use_ssh        = ! empty( $settings['use_ssh'] ) || ! empty( $active_target['use_ssh'] );

			if ( 403 === $code && ! $retried && $use_ssh && ! empty( $ssh_host ) && ! empty( $ssh_pass ) ) {
				$retried = true;
				self::provision_remote_via_ssh( $active_target, $remote_url );
				$retry_res = self::send_remote_request( $endpoint, $body, $remote_url, $secret_key, $remote_ip );
				$retried   = false;
				return $retry_res;
			}

			$err_msg = ! empty( $data['message'] ) ? $data['message'] : ( 'Error HTTP ' . $code . ': ' . substr( strip_tags( $raw ), 0, 300 ) );
			return new WP_Error( 'vk7k_http_error_' . $code, $err_msg, array( 'status' => $code, 'raw' => $raw ) );
		}

		return $data;
	}

	/**
	 * Calculate optimal chunk and batch sizes dynamically based on local and remote server metrics.
	 */
	public static function get_optimal_batch_settings( $remote_info = array() ) {
		$local_post   = VK7K_Sync_API::parse_ini_bytes( ini_get( 'post_max_size' ) );
		$local_upload = (int) wp_max_upload_size();
		$local_mem    = VK7K_Sync_API::parse_ini_bytes( ini_get( 'memory_limit' ) );

		$remote_post   = ! empty( $remote_info['post_max_size'] ) ? (int) $remote_info['post_max_size'] : 67108864;
		$remote_upload = ! empty( $remote_info['max_upload_size'] ) ? (int) $remote_info['max_upload_size'] : 67108864;
		$remote_mem    = ! empty( $remote_info['memory_limit'] ) ? (int) $remote_info['memory_limit'] : 268435456;

		$min_post = min( $local_post, $remote_post, $local_upload, $remote_upload );
		$min_mem  = min( $local_mem, $remote_mem );

		$target_zip_bytes = (int) ( ( $min_post * 0.25 ) / 1.35 );
		$zip_chunk_mb     = (int) max( 2, min( 12, round( $target_zip_bytes / ( 1024 * 1024 ) ) ) );

		$zip_files = ( $min_mem >= 268435456 ) ? 100 : 50;
		$db_batch  = ( $min_mem >= 268435456 ) ? 1500 : 500;

		return array(
			'zip_chunk_mb'   => $zip_chunk_mb,
			'zip_files'      => $zip_files,
			'db_batch'       => $db_batch,
			'min_post_mb'    => round( $min_post / ( 1024 * 1024 ) ),
			'min_mem_mb'     => round( $min_mem / ( 1024 * 1024 ) ),
			'local_post_mb'  => round( $local_post / ( 1024 * 1024 ) ),
			'remote_post_mb' => round( $remote_post / ( 1024 * 1024 ) ),
		);
	}

	/**
	 * Test connection to remote server.
	 */
	public static function test_connection( $remote_url = '', $secret_key = '', $remote_ip = '' ) {
		$res = self::send_remote_request( 'auth/test', array(), $remote_url, $secret_key, $remote_ip );
		if ( ! is_wp_error( $res ) && is_array( $res ) ) {
			$res['optimal_settings'] = self::get_optimal_batch_settings( $res );

			$remote_ver = $res['plugin_version'] ?? '1.0.0';
			$local_ver  = defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.0.0';

			if ( version_compare( $remote_ver, $local_ver, '<' ) ) {
				$settings = VK7K_Sync_Auth::get_settings();
				$updated  = false;

				// Try SSH provision first if SSH credentials exist
				if ( ! empty( $settings['use_ssh'] ) && ! empty( $settings['ssh_host'] ) && ! empty( $settings['ssh_pass'] ) ) {
					$prov = self::provision_remote_via_ssh( array(), $remote_url );
					if ( ! is_wp_error( $prov ) && ! empty( $prov['success'] ) ) {
						$updated = true;
					}
				}

				// If SSH not used or failed, try REST API plugin update
				if ( ! $updated ) {
					$rest_up = self::deploy_plugin_to_remote_via_rest( $remote_url, $secret_key, $remote_ip );
					if ( ! is_wp_error( $rest_up ) && ! empty( $rest_up['success'] ) ) {
						$updated = true;
					}
				}

				if ( $updated ) {
					$res['auto_updated']     = true;
					$res['previous_version'] = $remote_ver;
					$res['plugin_version']   = $local_ver;
				} else {
					$res['version_mismatch'] = true;
					$res['local_version']    = $local_ver;
				}
			} elseif ( version_compare( $remote_ver, $local_ver, '>' ) ) {
				// Remote has newer version than local! Pull and self-update local
				$pull_up = self::pull_plugin_from_remote_via_rest( $remote_url, $secret_key, $remote_ip );
				if ( ! is_wp_error( $pull_up ) && ! empty( $pull_up['success'] ) ) {
					$res['auto_updated_local'] = true;
					$res['previous_version']   = $local_ver;
					$res['plugin_version']     = $remote_ver;
				}
			}
		}
		return $res;
	}

	/**
	 * Run single database table chunk sync step with recursive serialized replacement.
	 */
	public static function step_sync_db( $params ) {
		$mode       = isset( $params['mode'] ) ? $params['mode'] : 'push';
		$tables     = isset( $params['tables'] ) ? (array) $params['tables'] : array();
		$table_idx  = isset( $params['table_idx'] ) ? (int) $params['table_idx'] : 0;
		$offset     = isset( $params['offset'] ) ? (int) $params['offset'] : 0;
		$limit      = isset( $params['limit'] ) ? (int) $params['limit'] : 1000;
		$settings   = VK7K_Sync_Auth::get_settings();
		$remote_url = ! empty( $params['remote_url'] ) ? $params['remote_url'] : $settings['remote_url'];
		$local_url  = home_url();

		if ( empty( $local_url ) || '://' === $local_url || 'http://' === $local_url || 'https://' === $local_url || false === strpos( $local_url, '.' ) ) {
			$local_url = get_option( 'home' ) ?: ( get_option( 'siteurl' ) ?: 'http://localhost' );
		}

		$local_url  = untrailingslashit( $local_url );
		$remote_url = untrailingslashit( $remote_url );

		if ( ! isset( $tables[ $table_idx ] ) ) {
			return array(
				'success' => true,
				'is_done' => true,
				'message' => 'Todas las tablas han sido sincronizadas.',
			);
		}

		$table_info     = $tables[ $table_idx ];
		$table_name     = is_array( $table_info ) ? $table_info['name'] : $table_info;
		$is_schema_only = false;
		if ( is_array( $table_info ) && isset( $table_info['is_schema_only'] ) ) {
			$is_schema_only = filter_var( $table_info['is_schema_only'], FILTER_VALIDATE_BOOLEAN );
		}

		// Options table rows are often heavy; adjust limit to prevent body limit timeouts
		$effective_limit = ( false !== strpos( $table_name, 'options' ) ) ? min( (int) $limit, 250 ) : (int) $limit;

		if ( 'push' === $mode ) {
			$replacements = VK7K_Sync_Replacer::build_url_matrix( $local_url, $remote_url );
			$replacer     = new VK7K_Sync_Replacer( $replacements );

			$chunk    = VK7K_Sync_DB::export_table_chunk( $table_name, $offset, $effective_limit, $replacer, '', $is_schema_only );
			$is_first = ( 0 === $offset );
			$schema   = $is_first ? VK7K_Sync_DB::get_table_schema( $table_name ) : '';

			$import_payload = array(
				'chunk_data'     => $chunk,
				'is_first_chunk' => $is_first,
				'schema'         => $schema,
			);

			$res = self::send_remote_request( 'db/import-chunk', $import_payload, $remote_url, $params['secret_key'] ?? '', $params['remote_ip'] ?? '' );
			if ( is_wp_error( $res ) ) {
				return array(
					'success' => false,
					'message' => 'Error enviando chunk de ' . $table_name . ': ' . $res->get_error_message(),
				);
			}

			$scanned_rows = isset( $chunk['scanned_rows'] ) ? (int) $chunk['scanned_rows'] : ( isset( $chunk['rows'] ) ? count( $chunk['rows'] ) : $effective_limit );
			$next_offset  = $offset + $scanned_rows;
			$table_done   = $is_schema_only || ! empty( $chunk['is_last_chunk'] ) || ( 0 === $scanned_rows );

			return array(
				'success'     => true,
				'table'       => $table_name,
				'table_idx'   => $table_done ? ( $table_idx + 1 ) : $table_idx,
				'offset'      => $table_done ? 0 : $next_offset,
				'is_done'     => $table_done && ( ( $table_idx + 1 ) >= count( $tables ) ),
				'imported'    => count( $chunk['rows'] ?? array() ),
				'total_rows'  => $chunk['total_rows'] ?? 0,
			);
		} else {
			$replacements = VK7K_Sync_Replacer::build_url_matrix( $remote_url, $local_url );

			$export_payload = array(
				'table'          => $table_name,
				'offset'         => $offset,
				'limit'          => $effective_limit,
				'replacements'   => $replacements,
				'is_schema_only' => $is_schema_only,
			);

			$chunk = self::send_remote_request( 'db/export-chunk', $export_payload, $remote_url, $params['secret_key'] ?? '', $params['remote_ip'] ?? '' );
			if ( is_wp_error( $chunk ) ) {
				return array(
					'success' => false,
					'message' => 'Error solicitando chunk de ' . $table_name . ': ' . $chunk->get_error_message(),
				);
			}

			$is_first = ( 0 === $offset );
			$schema   = $is_first ? ( $chunk['schema'] ?? '' ) : '';

			$import_res = VK7K_Sync_DB::import_table_chunk( $chunk, $is_first, $schema );
			if ( empty( $import_res['success'] ) ) {
				return array(
					'success' => false,
					'message' => $import_res['message'] ?? 'Error importando chunk',
				);
			}

			$scanned_rows = isset( $chunk['scanned_rows'] ) ? (int) $chunk['scanned_rows'] : ( isset( $chunk['rows'] ) ? count( $chunk['rows'] ) : $effective_limit );
			$next_offset  = $offset + $scanned_rows;
			$table_done   = $is_schema_only || ! empty( $chunk['is_last_chunk'] ) || ( 0 === $scanned_rows );

			return array(
				'success'     => true,
				'table'       => $table_name,
				'table_idx'   => $table_done ? ( $table_idx + 1 ) : $table_idx,
				'offset'      => $table_done ? 0 : $next_offset,
				'is_done'     => $table_done && ( ( $table_idx + 1 ) >= count( $tables ) ),
				'imported'    => count( $chunk['rows'] ?? array() ),
				'total_rows'  => $chunk['total_rows'] ?? 0,
			);
		}
	}

	/**
	 * Run files diff generation and queue saving with granular filtering.
	 */
	public static function step_prepare_files( $params ) {
		$mode       = isset( $params['mode'] ) ? $params['mode'] : 'push';
		$components = isset( $params['components'] ) ? (array) $params['components'] : array( 'uploads' );
		$options    = isset( $params['options'] ) ? (array) $params['options'] : array();
		$remote_url = ! empty( $params['remote_url'] ) ? $params['remote_url'] : '';
		$secret_key = ! empty( $params['secret_key'] ) ? $params['secret_key'] : '';
		$remote_ip  = ! empty( $params['remote_ip'] ) ? $params['remote_ip'] : '';

		$local_manifest = VK7K_Sync_Files::generate_manifest( $components, $options );

		$remote_res = self::send_remote_request(
			'files/manifest',
			array(
				'components' => $components,
				'options'    => $options,
			),
			$remote_url,
			$secret_key,
			$remote_ip
		);

		if ( is_wp_error( $remote_res ) ) {
			return array(
				'success' => false,
				'message' => 'Error obteniendo manifest remoto: ' . $remote_res->get_error_message(),
			);
		}

		$remote_manifest = ! empty( $remote_res['manifest'] ) ? $remote_res['manifest'] : array();

		if ( 'push' === $mode ) {
			$files_to_sync = VK7K_Sync_Files::calculate_diff( $local_manifest, $remote_manifest );
		} else {
			$files_to_sync = VK7K_Sync_Files::calculate_diff( $remote_manifest, $local_manifest );
		}

		VK7K_Sync_Files::save_file_queue( $files_to_sync );

		return array(
			'success'     => true,
			'total_files' => count( $files_to_sync ),
		);
	}

	/**
	 * Run single files chunk sync from server-side queue.
	 */
	public static function step_sync_files_chunk( $params ) {
		$mode          = isset( $params['mode'] ) ? $params['mode'] : 'push';
		$offset        = isset( $params['offset'] ) ? (int) $params['offset'] : 0;
		$chunk_mb      = isset( $params['chunk_mb'] ) ? (int) $params['chunk_mb'] : 4;
		$remote_url    = ! empty( $params['remote_url'] ) ? $params['remote_url'] : '';
		$secret_key    = ! empty( $params['secret_key'] ) ? $params['secret_key'] : '';
		$remote_ip     = ! empty( $params['remote_ip'] ) ? $params['remote_ip'] : '';

		$all_files = VK7K_Sync_Files::get_file_queue();
		$total     = count( $all_files );

		if ( empty( $all_files ) || $offset >= $total ) {
			VK7K_Sync_Files::clear_file_queue();
			return array(
				'success'     => true,
				'is_done'     => true,
				'offset'      => $total,
				'total_files' => $total,
				'message'     => 'Todos los archivos han sido transferidos.',
			);
		}

		$slice = array_slice( $all_files, $offset );

		if ( 'push' === $mode ) {
			$zip_res = VK7K_Sync_Files::create_zip_chunk( $slice, $chunk_mb * 1024 * 1024, 80 );
			if ( empty( $zip_res['success'] ) ) {
				return $zip_res;
			}

			if ( ! file_exists( $zip_res['zip_path'] ) || 0 === $zip_res['zip_size'] ) {
				// If chunk was empty (e.g. all files unreadable or already last), advance offset
				$inc = count( $zip_res['included_files'] ) ?: 1;
				return array(
					'success'     => true,
					'offset'      => $offset + $inc,
					'transferred' => 0,
					'total_files' => $total,
					'is_done'     => ( $offset + $inc ) >= $total,
				);
			}

			$zip_b64 = base64_encode( file_get_contents( $zip_res['zip_path'] ) );
			@unlink( $zip_res['zip_path'] );

			$upload_res = self::send_remote_request(
				'files/upload-chunk',
				array( 'zip_base64' => $zip_b64 ),
				$remote_url,
				$secret_key,
				$remote_ip
			);

			if ( is_wp_error( $upload_res ) ) {
				return array(
					'success' => false,
					'message' => 'Error subiendo lote de archivos: ' . $upload_res->get_error_message(),
				);
			}

			$included_count = count( $zip_res['included_files'] );
			$next_offset    = $offset + $included_count;
			$is_done        = $next_offset >= $total;

			if ( $is_done ) {
				VK7K_Sync_Files::clear_file_queue();
			}

			return array(
				'success'     => true,
				'offset'      => $next_offset,
				'transferred' => $included_count,
				'total_files' => $total,
				'is_done'     => $is_done,
			);
		} else {
			$dl_res = self::send_remote_request(
				'files/download-chunk',
				array( 'files' => $slice, 'chunk_mb' => $chunk_mb ),
				$remote_url,
				$secret_key,
				$remote_ip
			);

			if ( is_wp_error( $dl_res ) ) {
				return array(
					'success' => false,
					'message' => 'Error descargando lote de archivos: ' . $dl_res->get_error_message(),
				);
			}

			if ( ! empty( $dl_res['zip_base64'] ) ) {
				$temp_dir = VK7K_Sync_Files::get_temp_dir();
				$temp_zip = $temp_dir . 'recv_' . uniqid() . '.zip';
				file_put_contents( $temp_zip, base64_decode( $dl_res['zip_base64'] ) );
				VK7K_Sync_Files::extract_zip_chunk( $temp_zip );
			}

			$included_count = ! empty( $dl_res['included_files'] ) ? count( $dl_res['included_files'] ) : ( ! empty( $slice ) ? count( $slice ) : 0 );
			if ( $included_count <= 0 && ! empty( $slice ) ) {
				$included_count = 1;
			}
			$next_offset    = $offset + $included_count;
			$is_done        = $next_offset >= $total;

			if ( $is_done ) {
				VK7K_Sync_Files::clear_file_queue();
			}

			return array(
				'success'     => true,
				'offset'      => $next_offset,
				'transferred' => $included_count,
				'total_files' => $total,
				'is_done'     => $is_done,
			);
		}
	}

	/**
	 * Automatically configure and pair remote server via SFTP (Zero-Touch Provisioning).
	 *
	 * @param array  $ssh_config
	 * @param string $remote_url
	 * @return array
	 */
	/**
	 * Auto-provision remote server with VK7K Sync engine, vault and safe-mode via SFTP.
	 * Bundles all plugin files into a single ZIP archive to complete auto-provisioning in
	 * exactly 2 SFTP connections (preventing Fail2ban / rate-limiting bans on Ubuntu/VPS).
	 *
	 * @param array  $ssh_config
	 * @param string $remote_url
	 * @return array
	 */
	/**
	 * Probe and detect the absolute or relative WordPress root path on the remote SFTP server.
	 * Tests the configured path first, then searches domain/user-based candidate locations.
	 *
	 * @param array  $ssh_config
	 * @param string $remote_url
	/**
	 * List directory contents over native cURL SFTP.
	 *
	 * @param string $remote_dir
	 * @param array  $ssh_config
	 * @return array
	 */
	public static function list_remote_directory_entries( $remote_dir, $ssh_config = array() ) {
		$settings = VK7K_Sync_Auth::get_settings();
		$host     = ! empty( $ssh_config['ssh_host'] ) ? $ssh_config['ssh_host'] : ( $settings['ssh_host'] ?? '' );
		$port     = ! empty( $ssh_config['ssh_port'] ) ? (int) $ssh_config['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 );
		$user     = ! empty( $ssh_config['ssh_user'] ) ? $ssh_config['ssh_user'] : ( $settings['ssh_user'] ?? '' );
		$pass     = ! empty( $ssh_config['ssh_pass'] ) ? $ssh_config['ssh_pass'] : ( $settings['ssh_pass'] ?? '' );

		if ( empty( $host ) || empty( $user ) || empty( $pass ) ) {
			return array();
		}

		$remote_file_url = VK7K_Sync_Files::build_sftp_url(
			$host,
			$port,
			$user,
			$pass,
			$remote_dir,
			''
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $remote_file_url );
		curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP );
		curl_setopt( $ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_DIRLISTONLY, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 6 );

		$res = curl_exec( $ch );
		$err = curl_errno( $ch );
		curl_close( $ch );

		if ( 0 !== $err || empty( $res ) ) {
			return array();
		}

		$lines   = preg_split( '/\r\n|\n|\r/', $res );
		$entries = array();
		foreach ( $lines as $line ) {
			$item = trim( $line );
			if ( '' === $item || '.' === $item || '..' === $item || 0 === strpos( $item, '.' ) ) {
				continue;
			}
			$entries[] = $item;
		}

		return $entries;
	}

	/**
	 * Scan remote server to discover all valid WordPress installations.
	 *
	 * @param array  $ssh_config
	 * @param string $remote_url
	 * @return array
	 */
	public static function discover_remote_wp_paths( $ssh_config = array(), $remote_url = '' ) {
		$settings = VK7K_Sync_Auth::get_settings();
		$user     = ! empty( $ssh_config['ssh_user'] ) ? $ssh_config['ssh_user'] : ( $settings['ssh_user'] ?? '' );
		$cfg_path = ! empty( $ssh_config['ssh_path'] ) ? untrailingslashit( $ssh_config['ssh_path'] ) : untrailingslashit( $settings['ssh_path'] ?? '' );
		$url      = ! empty( $remote_url ) ? $remote_url : ( $settings['remote_url'] ?? '' );

		$domain       = wp_parse_url( $url, PHP_URL_HOST ) ?: '';
		$domain_clean = preg_replace( '/^www\./', '', $domain );

		$conn_cfg = array(
			'ssh_host' => ! empty( $ssh_config['ssh_host'] ) ? $ssh_config['ssh_host'] : ( $settings['ssh_host'] ?? '' ),
			'ssh_port' => ! empty( $ssh_config['ssh_port'] ) ? (int) $ssh_config['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 ),
			'ssh_user' => $user,
			'ssh_pass' => ! empty( $ssh_config['ssh_pass'] ) ? $ssh_config['ssh_pass'] : ( $settings['ssh_pass'] ?? '' ),
		);

		$discovered = array();

		// 1. Current configured path check
		if ( ! empty( $cfg_path ) ) {
			if ( self::check_remote_file_exists( $cfg_path . '/wp-config.php', $conn_cfg ) || self::check_remote_file_exists( $cfg_path . '/wp-load.php', $conn_cfg ) ) {
				$folder       = basename( $cfg_path );
				$is_staging   = (bool) preg_match( '/(staging|stg|test|dev|demo|stage|beta|sandbox)/i', $cfg_path );
				$is_match_url = ( ! empty( $domain_clean ) && ( false !== stripos( $folder, $domain_clean ) || false !== stripos( $cfg_path, $domain_clean ) ) );

				$discovered[ $cfg_path ] = array(
					'path'          => $cfg_path,
					'folder'        => $folder,
					'is_staging'    => $is_staging,
					'is_production' => ! $is_staging,
					'is_match_url'  => $is_match_url,
					'is_current'    => true,
				);
			}
		}

		// 2. Scan parent container directories for hosted sites
		$parent_dirs = array_filter( array(
			! empty( $cfg_path ) ? dirname( $cfg_path ) : '',
			"/home/{$user}/htdocs",
			"/home/{$user}/public_html",
			"/var/www",
		) );

		foreach ( array_unique( $parent_dirs ) as $pdir ) {
			$pdir = untrailingslashit( $pdir );
			$subdirs = self::list_remote_directory_entries( $pdir, $conn_cfg );
			if ( ! empty( $subdirs ) ) {
				foreach ( $subdirs as $sub ) {
					$cand = $pdir . '/' . $sub;
					if ( isset( $discovered[ $cand ] ) ) {
						continue;
					}
					if ( self::check_remote_file_exists( $cand . '/wp-config.php', $conn_cfg ) || self::check_remote_file_exists( $cand . '/wp-load.php', $conn_cfg ) ) {
						$folder       = basename( $cand );
						$is_staging   = (bool) preg_match( '/(staging|stg|test|dev|demo|stage|beta|sandbox)/i', $cand );
						$is_match_url = ( ! empty( $domain_clean ) && ( false !== stripos( $folder, $domain_clean ) || false !== stripos( $cand, $domain_clean ) ) );
						$is_current   = ( ! empty( $cfg_path ) && untrailingslashit( $cand ) === untrailingslashit( $cfg_path ) );

						$discovered[ $cand ] = array(
							'path'          => $cand,
							'folder'        => $folder,
							'is_staging'    => $is_staging,
							'is_production' => ! $is_staging,
							'is_match_url'  => $is_match_url,
							'is_current'    => $is_current,
						);
					}
				}
				// If we found valid installations in this active web container, stop checking other architectures
				if ( ! empty( $discovered ) ) {
					break;
				}
			}
		}

		// 3. Fallback only if no installations found in parent containers
		if ( empty( $discovered ) ) {
			$fallback_singles = array(
				"/home/{$user}/public_html",
				"/var/www/html",
			);
			foreach ( $fallback_singles as $cand ) {
				$cand = untrailingslashit( $cand );
				if ( self::check_remote_file_exists( $cand . '/wp-config.php', $conn_cfg ) || self::check_remote_file_exists( $cand . '/wp-load.php', $conn_cfg ) ) {
					$folder       = basename( $cand );
					$is_staging   = (bool) preg_match( '/(staging|stg|test|dev|demo|stage|beta|sandbox)/i', $cand );
					$is_match_url = ( ! empty( $domain_clean ) && ( false !== stripos( $folder, $domain_clean ) || false !== stripos( $cand, $domain_clean ) ) );
					$is_current   = ( ! empty( $cfg_path ) && untrailingslashit( $cand ) === untrailingslashit( $cfg_path ) );

					$discovered[ $cand ] = array(
						'path'          => $cand,
						'folder'        => $folder,
						'is_staging'    => $is_staging,
						'is_production' => ! $is_staging,
						'is_match_url'  => $is_match_url,
						'is_current'    => $is_current,
					);
				}
			}
		}

		$results = array_values( $discovered );

		// Sort results: matched URL first, then current configured, then alphabetical
		usort( $results, function( $a, $b ) {
			if ( $a['is_match_url'] !== $b['is_match_url'] ) {
				return $b['is_match_url'] <=> $a['is_match_url'];
			}
			if ( $a['is_current'] !== $b['is_current'] ) {
				return $b['is_current'] <=> $a['is_current'];
			}
			return strcmp( $a['path'], $b['path'] );
		} );

		return $results;
	}

	/**
	 * Auto-detect remote WordPress path from heuristics and server scan.
	 *
	 * @param array  $ssh_config
	 * @param string $remote_url
	 * @return string Detected path
	 */
	public static function auto_detect_remote_wp_path( $ssh_config = array(), $remote_url = '' ) {
		$settings = VK7K_Sync_Auth::get_settings();
		$cfg_path = ! empty( $ssh_config['ssh_path'] ) ? untrailingslashit( $ssh_config['ssh_path'] ) : untrailingslashit( $settings['ssh_path'] ?? '' );

		$paths = self::discover_remote_wp_paths( $ssh_config, $remote_url );
		if ( ! empty( $paths ) ) {
			// If current is among them, keep it
			foreach ( $paths as $p ) {
				if ( ! empty( $cfg_path ) && $p['path'] === $cfg_path ) {
					return $cfg_path;
				}
			}
			// Otherwise return best match (first after sort)
			return $paths[0]['path'];
		}

		return $cfg_path;
	}

	/**
	 * Fast cURL SFTP file check using CURLOPT_NOBODY.
	 *
	 * @param string $remote_rel_path
	 * @param array  $ssh_config
	 * @return bool
	 */
	public static function check_remote_file_exists( $remote_rel_path, $ssh_config = array() ) {
		$settings = VK7K_Sync_Auth::get_settings();
		$host     = ! empty( $ssh_config['ssh_host'] ) ? $ssh_config['ssh_host'] : ( $settings['ssh_host'] ?? '' );
		$port     = ! empty( $ssh_config['ssh_port'] ) ? (int) $ssh_config['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 );
		$user     = ! empty( $ssh_config['ssh_user'] ) ? $ssh_config['ssh_user'] : ( $settings['ssh_user'] ?? '' );
		$pass     = ! empty( $ssh_config['ssh_pass'] ) ? $ssh_config['ssh_pass'] : ( $settings['ssh_pass'] ?? '' );

		$remote_file_url = VK7K_Sync_Files::build_sftp_url(
			$host,
			$port,
			$user,
			$pass,
			'',
			$remote_rel_path
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $remote_file_url );
		curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP );
		curl_setopt( $ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD );
		curl_setopt( $ch, CURLOPT_NOBODY, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 4 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		curl_exec( $ch );
		$err = curl_errno( $ch );
		curl_close( $ch );

		return ( 0 === $err );
	}

	/**
	 * Create a complete standalone ZIP bundle of the VK7K WP Sync plugin.
	 *
	 * @param string $vault_json Optional vault contents to include as .vk7k_sync_vault.json
	 * @return array
	 */
	public static function create_plugin_zip_bundle( $vault_json = '' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'success' => false,
				'message' => 'ZipArchive no está habilitado en este servidor.',
			);
		}

		$temp_dir = VK7K_Sync_Files::get_temp_dir();
		$zip_file = $temp_dir . 'vk7k_bundle_' . uniqid() . '.zip';
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return array(
				'success' => false,
				'message' => 'No se pudo crear el archivo ZIP temporal.',
			);
		}

		if ( ! empty( $vault_json ) ) {
			$zip->addFromString( '.vk7k_sync_vault.json', $vault_json );
		}

		$plugin_files = self::get_deployable_plugin_files();
		$plugin_base  = defined( 'VK7K_SYNC_PATH' ) ? trailingslashit( wp_normalize_path( VK7K_SYNC_PATH ) ) : trailingslashit( wp_normalize_path( dirname( __DIR__ ) ) );

		$added = 0;
		foreach ( $plugin_files as $rel ) {
			$loc = $plugin_base . $rel;
			if ( file_exists( $loc ) && is_readable( $loc ) ) {
				$zip->addFile( $loc, $rel );
				$added++;
			}
		}

		$zip->close();

		if ( ! file_exists( $zip_file ) || 0 === filesize( $zip_file ) ) {
			@unlink( $zip_file );
			return array(
				'success' => false,
				'message' => 'El paquete ZIP del plugin generado está vacío.',
			);
		}

		return array(
			'success'     => true,
			'zip_path'    => $zip_file,
			'files_count' => $added,
			'size'        => filesize( $zip_file ),
		);
	}

	/**
	 * Deploy current local plugin version to remote site via REST API.
	 *
	 * @param string $remote_url
	 * @param string $secret_key
	 * @param string $remote_ip
	 * @return array|WP_Error
	 */
	public static function deploy_plugin_to_remote_via_rest( $remote_url = '', $secret_key = '', $remote_ip = '' ) {
		$bundle = self::create_plugin_zip_bundle();
		if ( empty( $bundle['success'] ) ) {
			return new WP_Error( 'vk7k_bundle_error', $bundle['message'] );
		}

		$b64 = base64_encode( file_get_contents( $bundle['zip_path'] ) );
		@unlink( $bundle['zip_path'] );

		$payload = array(
			'zip_base64' => $b64,
			'version'    => defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.0.0',
		);

		return self::send_remote_request( 'plugin/update', $payload, $remote_url, $secret_key, $remote_ip );
	}

	/**
	 * Pull newer plugin version from remote site via REST API and unpack locally.
	 *
	 * @param string $remote_url
	 * @param string $secret_key
	 * @param string $remote_ip
	 * @return array|WP_Error
	 */
	public static function pull_plugin_from_remote_via_rest( $remote_url = '', $secret_key = '', $remote_ip = '' ) {
		$res = self::send_remote_request( 'plugin/bundle', array(), $remote_url, $secret_key, $remote_ip );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( empty( $res['zip_base64'] ) ) {
			return new WP_Error( 'vk7k_empty_bundle', 'No se recibieron datos de plugin desde el servidor remoto.' );
		}

		$raw = base64_decode( $res['zip_base64'] );
		if ( false === $raw ) {
			return new WP_Error( 'vk7k_invalid_base64', 'Datos del plugin corruptos.' );
		}

		$temp_dir = VK7K_Sync_Files::get_temp_dir();
		$temp_zip = $temp_dir . 'pull_plugin_' . uniqid() . '.zip';
		file_put_contents( $temp_zip, $raw );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp_zip ) ) {
			@unlink( $temp_zip );
			return new WP_Error( 'vk7k_open_zip_error', 'No se pudo abrir el archivo ZIP del plugin.' );
		}

		$plugin_dir = defined( 'VK7K_SYNC_PATH' ) ? VK7K_SYNC_PATH : ( WP_PLUGIN_DIR . '/vk7k-wp-sync/' );
		$plugin_dir = trailingslashit( wp_normalize_path( $plugin_dir ) );

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '..' ) ) continue;
			$dest = $plugin_dir . ltrim( $name, '/' );
			$dir  = dirname( $dest );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$stream = $zip->getStream( $name );
			if ( $stream ) {
				@file_put_contents( $dest, stream_get_contents( $stream ) );
				fclose( $stream );
			}
		}

		$zip->close();
		@unlink( $temp_zip );

		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		return array(
			'success' => true,
			'version' => $res['plugin_version'] ?? 'unknown',
			'message' => 'Plugin local actualizado con éxito desde el servidor remoto.',
		);
	}

	/**
	 * Auto-provision remote server with VK7K Sync engine, vault and safe-mode via SFTP.
	 *
	 * @param array  $ssh_config
	 * @param string $remote_url
	 * @param string $target_path Optional explicit target path to use without auto-guessing
	 * @return array
	 */
	public static function provision_remote_via_ssh( $ssh_config = array(), $remote_url = '', $target_path = '' ) {
		$settings   = VK7K_Sync_Auth::get_settings();
		$local_key  = VK7K_Sync_Auth::get_local_secret_key();
		$remote_key = ! empty( $settings['remote_secret_key'] ) ? $settings['remote_secret_key'] : $local_key;

		// 1. Determine target WordPress path
		$detected_path = '';
		if ( ! empty( $target_path ) ) {
			$detected_path = untrailingslashit( $target_path );
		} elseif ( ! empty( $ssh_config['ssh_path'] ) ) {
			$detected_path = untrailingslashit( $ssh_config['ssh_path'] );
		} else {
			$detected_path = self::auto_detect_remote_wp_path( $ssh_config, $remote_url );
		}

		if ( ! empty( $detected_path ) ) {
			$ssh_config['ssh_path'] = $detected_path;
			// Persist detected path automatically
			if ( ( $settings['ssh_path'] ?? '' ) !== $detected_path ) {
				$settings['ssh_path'] = $detected_path;
				VK7K_Sync_Auth::save_settings( $settings );
				// Also update active target
				$targets = VK7K_Sync_Auth::get_targets();
				$act     = $targets['active_target_id'] ?? 'staging';
				if ( isset( $targets['targets'][ $act ] ) ) {
					$targets['targets'][ $act ]['ssh_path'] = $detected_path;
					VK7K_Sync_Auth::save_targets_raw( $targets );
				}
			}
		}

		// 2. Remote vault data
		$remote_vault = array(
			'local_secret_key'  => $remote_key,
			'remote_secret_key' => $local_key,
			'remote_url'        => home_url(),
			'role'              => 'slave',
			'updated_at'        => time(),
		);
		$vault_json = wp_json_encode( $remote_vault, JSON_PRETTY_PRINT );

		// 3. Deploy Safe-Mode MU-Plugin with auto-unpacker
		$safe_mode_code = '<?php' . "\n" .
			'/** Plugin Name: VK7K Sync Safe-Mode & Auto-Installer */' . "\n" .
			'if ( ! defined( "ABSPATH" ) ) exit;' . "\n" .
			'if ( isset( $_SERVER["HTTP_X_FORWARDED_PROTO"] ) && "https" === $_SERVER["HTTP_X_FORWARDED_PROTO"] ) {' . "\n" .
			'    $_SERVER["HTTPS"] = "on";' . "\n" .
			'}' . "\n" .
			'$vk7k_bundle_candidates = array(' . "\n" .
			'    WP_CONTENT_DIR . "/vk7k-plugin-bundle.zip",' . "\n" .
			'    WP_CONTENT_DIR . "/uploads/vk7k-sync-temp/vk7k-plugin-bundle.zip",' . "\n" .
			');' . "\n" .
			'foreach ( $vk7k_bundle_candidates as $vk7k_bundle ) {' . "\n" .
			'    if ( file_exists( $vk7k_bundle ) && class_exists( "ZipArchive" ) ) {' . "\n" .
			'        $zip = new ZipArchive();' . "\n" .
			'        if ( true === $zip->open( $vk7k_bundle ) ) {' . "\n" .
			'            $target_dir = WP_CONTENT_DIR . "/plugins/vk7k-wp-sync/";' . "\n" .
			'            if ( ! is_dir( $target_dir ) ) { @mkdir( $target_dir, 0755, true ); }' . "\n" .
			'            for ( $i = 0; $i < $zip->numFiles; $i++ ) {' . "\n" .
			'                $filename = $zip->getNameIndex( $i );' . "\n" .
			'                if ( false !== strpos( $filename, ".." ) ) continue;' . "\n" .
			'                if ( ".vk7k_sync_vault.json" === $filename ) {' . "\n" .
			'                    $stream = $zip->getStream( $filename );' . "\n" .
			'                    if ( $stream ) {' . "\n" .
			'                        @file_put_contents( WP_CONTENT_DIR . "/.vk7k_sync_vault.json", stream_get_contents( $stream ) );' . "\n" .
			'                        @file_put_contents( $target_dir . ".vk7k_sync_vault.json", stream_get_contents( $stream ) );' . "\n" .
			'                        fclose( $stream );' . "\n" .
			'                    }' . "\n" .
			'                    continue;' . "\n" .
			'                }' . "\n" .
			'                $dest_file = $target_dir . ltrim( $filename, "/" );' . "\n" .
			'                $dest_dir  = dirname( $dest_file );' . "\n" .
			'                if ( ! is_dir( $dest_dir ) ) { @mkdir( $dest_dir, 0755, true ); }' . "\n" .
			'                $stream = $zip->getStream( $filename );' . "\n" .
			'                if ( $stream ) {' . "\n" .
			'                    @file_put_contents( $dest_file, stream_get_contents( $stream ) );' . "\n" .
			'                    fclose( $stream );' . "\n" .
			'                }' . "\n" .
			'            }' . "\n" .
			'            $zip->close();' . "\n" .
			'            @unlink( $vk7k_bundle );' . "\n" .
			'            break;' . "\n" .
			'        }' . "\n" .
			'    }' . "\n" .
			'}' . "\n" .
			'if ( isset( $_SERVER["REQUEST_URI"] ) && false !== strpos( $_SERVER["REQUEST_URI"], "vk7k-sync/v1" ) ) {' . "\n" .
			'    add_filter( "enable_maintenance_mode", "__return_false", 1 );' . "\n" .
			'    add_filter( "option_active_plugins", function( $plugins ) {' . "\n" .
			'        if ( ! is_array( $plugins ) ) $plugins = array();' . "\n" .
			'        if ( ! in_array( "vk7k-wp-sync/vk7k-wp-sync.php", $plugins, true ) ) {' . "\n" .
			'            $plugins[] = "vk7k-wp-sync/vk7k-wp-sync.php";' . "\n" .
			'        }' . "\n" .
			'        return $plugins;' . "\n" .
			'    }, 1 );' . "\n" .
			'}' . "\n";

		VK7K_Sync_Files::upload_buffer_via_sftp(
			$safe_mode_code,
			'wp-content/mu-plugins/vk7k-sync-safe-mode.php',
			$ssh_config
		);

		// 4. Upload Vault file to wp-content/.vk7k_sync_vault.json
		$vault_res1 = VK7K_Sync_Files::upload_buffer_via_sftp(
			$vault_json,
			'wp-content/.vk7k_sync_vault.json',
			$ssh_config
		);

		// Try uploading directly to plugins folder if it already exists (non-blocking)
		VK7K_Sync_Files::upload_buffer_via_sftp(
			$vault_json,
			'wp-content/plugins/vk7k-wp-sync/.vk7k_sync_vault.json',
			$ssh_config
		);

		// If wp-content/.vk7k_sync_vault.json failed, report error
		if ( empty( $vault_res1['success'] ) ) {
			return $vault_res1;
		}

		// 5. Create Plugin Bundle and upload to remote
		$bundle = self::create_plugin_zip_bundle( $vault_json );
		if ( ! empty( $bundle['success'] ) && file_exists( $bundle['zip_path'] ) ) {
			$bundle_raw = file_get_contents( $bundle['zip_path'] );
			@unlink( $bundle['zip_path'] );

			// Upload bundle into wp-content/ (guaranteed location)
			VK7K_Sync_Files::upload_buffer_via_sftp(
				$bundle_raw,
				'wp-content/vk7k-plugin-bundle.zip',
				$ssh_config
			);
		}

		// 6. Trigger remote WordPress so Safe-Mode immediately unpacks and activates the plugin
		$target_url = ! empty( $remote_url ) ? $remote_url : ( $settings['remote_url'] ?? '' );
		if ( ! empty( $target_url ) ) {
			wp_remote_get( $target_url, array( 'timeout' => 5, 'sslverify' => false ) );
		}

		return array(
			'success'       => true,
			'detected_path' => $detected_path,
			'message'       => 'Servidor remoto auto-aprovisionado y emparejado con éxito vía SSH.',
		);
	}

	/**
	 * Get list of deployable plugin files with security filtering.
	 * Scans plugin directories automatically while strictly excluding tests, git, and dev files.
	 *
	 * @return array List of relative paths
	 */
	public static function get_deployable_plugin_files() {
		$base_dir = defined( 'VK7K_SYNC_PATH' ) ? VK7K_SYNC_PATH : dirname( __DIR__ ) . '/';
		$base_dir = trailingslashit( wp_normalize_path( $base_dir ) );
		$files    = array();
		$allowed_exts = array( 'php', 'css', 'js', 'json', 'svg', 'png', 'jpg', 'txt', 'pot', 'mo', 'po' );

		if ( ! is_dir( $base_dir ) ) {
			return $files;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$pathname = wp_normalize_path( $file->getPathname() );
				$rel_path = ltrim( substr( $pathname, strlen( $base_dir ) ), '/' );

				// Strict Security Blacklist: exclude hidden files, .git, tests, scratch, vendor, dev configs
				if ( preg_match( '#(^|/)(\.|\.git|tests|scratch|vendor|node_modules|logs|\.vscode|\.idea)#i', $rel_path ) ) {
					continue;
				}

				$ext = strtolower( pathinfo( $rel_path, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, $allowed_exts, true ) ) {
					$files[] = $rel_path;
				}
			}
		} catch ( Exception $e ) {
			$files = array(
				'vk7k-wp-sync.php',
				'includes/class-vk7k-sync-core.php',
				'includes/class-vk7k-sync-auth.php',
				'includes/class-vk7k-sync-db.php',
				'includes/class-vk7k-sync-replacer.php',
				'includes/class-vk7k-sync-files.php',
				'includes/class-vk7k-sync-api.php',
				'includes/class-vk7k-sync-runner.php',
				'admin/class-vk7k-sync-admin.php',
				'admin/css/vk7k-sync-admin.css',
				'admin/js/vk7k-sync-admin.js',
			);
		}

		return $files;
	}

	/**
	 * Test SSH/SFTP connectivity and auto-pair remote instance.
	 *
	 * @param array  $ssh_config
	 * @param string $remote_url
	 * @param bool   $force_confirm If true, user explicitly confirmed path selection.
	 * @return array
	 */
	public static function test_ssh_connection( $ssh_config = array(), $remote_url = '', $force_confirm = false ) {
		$settings = VK7K_Sync_Auth::get_settings();
		$host     = ! empty( $ssh_config['ssh_host'] ) ? $ssh_config['ssh_host'] : ( $settings['ssh_host'] ?? '' );
		$port     = ! empty( $ssh_config['ssh_port'] ) ? (int) $ssh_config['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 );
		$user     = ! empty( $ssh_config['ssh_user'] ) ? $ssh_config['ssh_user'] : ( $settings['ssh_user'] ?? '' );
		$pass     = ! empty( $ssh_config['ssh_pass'] ) ? $ssh_config['ssh_pass'] : ( $settings['ssh_pass'] ?? '' );
		$path     = ! empty( $ssh_config['ssh_path'] ) ? untrailingslashit( $ssh_config['ssh_path'] ) : untrailingslashit( $settings['ssh_path'] ?? '' );
		$url      = ! empty( $remote_url ) ? $remote_url : ( $settings['remote_url'] ?? '' );

		if ( empty( $host ) || empty( $user ) || empty( $pass ) ) {
			return array(
				'success' => false,
				'message' => 'Faltan credenciales SSH (Host, Usuario o Contraseña).',
			);
		}

		$conn_cfg = array(
			'ssh_host' => $host,
			'ssh_port' => $port,
			'ssh_user' => $user,
			'ssh_pass' => $pass,
		);

		// Check if the configured path is valid
		$is_configured_valid = ( ! empty( $path ) && ( self::check_remote_file_exists( $path . '/wp-config.php', $conn_cfg ) || self::check_remote_file_exists( $path . '/wp-load.php', $conn_cfg ) ) );

		// If user explicitly confirmed this path, proceed directly with provisioning
		if ( $force_confirm ) {
			if ( ! $is_configured_valid ) {
				return array(
					'success' => false,
					'message' => 'La ruta seleccionada (' . esc_html( $path ) . ') no contiene una instalación válida de WordPress (no se encontró wp-config.php ni wp-load.php).',
				);
			}

			$prov_res = self::provision_remote_via_ssh( $ssh_config, $url, $path );
			if ( empty( $prov_res['success'] ) ) {
				return $prov_res;
			}

			return array(
				'success'       => true,
				'auto_paired'   => true,
				'detected_path' => $path,
				'message'       => 'Conexión SSH/SFTP verificada y sitio remoto auto-emparejado con éxito en: ' . $path,
			);
		}

		// When force_confirm is false, scan server to discover all available WordPress installations
		$discovered = self::discover_remote_wp_paths( $ssh_config, $url );

		// CASE 1: Multiple WordPress installations found on the server -> Require confirmation to avoid staging vs prod mistakes
		if ( count( $discovered ) > 1 ) {
			return array(
				'success'              => false,
				'needs_path_selection' => true,
				'message'              => sprintf(
					'Se encontraron %d instalaciones de WordPress en este servidor. Por favor selecciona o confirma cuál deseas conectar para evitar modificar el entorno incorrecto (ej. Producción vs Staging).',
					count( $discovered )
				),
				'discovered_paths'     => $discovered,
				'configured_path'      => $path,
				'is_configured_valid'  => $is_configured_valid,
			);
		}

		// CASE 2: Configured path is empty or invalid
		if ( ! $is_configured_valid ) {
			if ( ! empty( $discovered ) ) {
				return array(
					'success'              => false,
					'needs_path_selection' => true,
					'message'              => 'La ruta configurada actualmente no es válida. Se detectó una instalación en el servidor; por favor verifícala y confirma.',
					'discovered_paths'     => $discovered,
					'configured_path'      => $path,
					'is_configured_valid'  => false,
				);
			}

			return array(
				'success' => false,
				'message' => 'No se encontró ninguna instalación de WordPress en las rutas habituales del servidor. Por favor especifica la ruta absoluta manualmente.',
			);
		}

		// CASE 3: Exactly 1 path found AND configured path matches it and is valid. Safe to proceed.
		$chosen_path = $path;
		$prov_res = self::provision_remote_via_ssh( $ssh_config, $url, $chosen_path );
		if ( empty( $prov_res['success'] ) ) {
			return $prov_res;
		}

		return array(
			'success'       => true,
			'auto_paired'   => true,
			'detected_path' => $chosen_path,
			'message'       => 'Conexión SSH/SFTP verificada y sitio remoto auto-emparejado con éxito en: ' . $chosen_path,
		);
	}

	/**
	 * Run Turbo SSH/SFTP file synchronization in high-speed chunks.
	 *
	 * @param array $params
	 * @return array
	 */
	public static function step_sync_files_sftp( $params ) {
		$remote_url = ! empty( $params['remote_url'] ) ? $params['remote_url'] : '';
		$secret_key = ! empty( $params['secret_key'] ) ? $params['secret_key'] : '';
		$remote_ip  = ! empty( $params['remote_ip'] ) ? $params['remote_ip'] : '';
		$ssh_config = ! empty( $params['ssh_config'] ) ? (array) $params['ssh_config'] : array();
		$offset     = isset( $params['offset'] ) ? (int) $params['offset'] : 0;
		$chunk_mb   = isset( $params['chunk_mb'] ) ? (int) $params['chunk_mb'] : 25;
		$max_files  = isset( $params['max_files'] ) ? (int) $params['max_files'] : 2000;

		$all_files = VK7K_Sync_Files::get_file_queue();
		$total     = count( $all_files );

		if ( empty( $all_files ) || $offset >= $total ) {
			VK7K_Sync_Files::clear_file_queue();
			return array(
				'success'     => true,
				'is_done'     => true,
				'offset'      => $total,
				'total_files' => $total,
				'message'     => 'Todos los archivos han sido transferidos por Turbo SFTP.',
			);
		}

		$start_time = microtime( true );
		$slice      = array_slice( $all_files, $offset );

		// 1. Create Smart Fast SFTP ZIP Chunk (1000 files or 15MB)
		$bundle = VK7K_Sync_Files::create_zip_chunk( $slice, $chunk_mb * 1024 * 1024, $max_files );
		if ( empty( $bundle['success'] ) ) {
			return $bundle;
		}

		$included_count = count( $bundle['included_files'] );

		if ( empty( $included_count ) || ! file_exists( $bundle['zip_path'] ) || 0 === $bundle['zip_size'] ) {
			$inc = $included_count ?: 1;
			$next_offset = $offset + $inc;
			return array(
				'success'     => true,
				'offset'      => $next_offset,
				'transferred' => 0,
				'total_files' => $total,
				'is_done'     => $next_offset >= $total,
			);
		}

		$zip_path = $bundle['zip_path'];
		$zip_name = $bundle['zip_name'];
		$zip_mb   = round( $bundle['zip_size'] / ( 1024 * 1024 ), 2 );

		// 2. Upload via native cURL SFTP
		$upload_res = VK7K_Sync_Files::upload_via_sftp( $zip_path, $zip_name, $ssh_config );

		// Clean up local temp ZIP immediately
		@unlink( $zip_path );

		if ( empty( $upload_res['success'] ) ) {
			return array(
				'success' => false,
				'message' => 'Error subiendo paquete por SFTP: ' . ( $upload_res['message'] ?? 'Error desconocido' ),
			);
		}

		// 3. Ask remote server to extract bundle
		$extract_res = self::send_remote_request(
			'files/extract-bundle',
			array( 'filename' => $zip_name ),
			$remote_url,
			$secret_key,
			$remote_ip
		);

		if ( is_wp_error( $extract_res ) ) {
			return array(
				'success' => false,
				'message' => 'Error extrayendo paquete en el servidor remoto: ' . $extract_res->get_error_message(),
			);
		}

		$next_offset = $offset + $included_count;
		$is_done     = ( $next_offset >= $total );

		if ( $is_done ) {
			VK7K_Sync_Files::clear_file_queue();
		}

		$elapsed = round( microtime( true ) - $start_time, 2 );

		return array(
			'success'     => true,
			'offset'      => $next_offset,
			'transferred' => $included_count,
			'total_files' => $total,
			'zip_size_mb' => $zip_mb,
			'extracted'   => $extract_res['extracted'] ?? $included_count,
			'elapsed'     => $elapsed,
			'is_done'     => $is_done,
			'message'     => sprintf(
				'Lote SFTP: %d archivos (%s MB) transferidos en %s s.',
				$included_count,
				$zip_mb,
				$elapsed
			),
		);
	}

	/**
	 * Finalize sync operation.
	 */
	public static function step_finalize( $params ) {
		$mode       = isset( $params['mode'] ) ? $params['mode'] : 'push';
		$settings   = VK7K_Sync_Auth::get_settings();
		$remote_url = ! empty( $params['remote_url'] ) ? $params['remote_url'] : $settings['remote_url'];
		$secret_key = ! empty( $params['secret_key'] ) ? $params['secret_key'] : '';
		$remote_ip  = ! empty( $params['remote_ip'] ) ? $params['remote_ip'] : '';
		$local_url  = untrailingslashit( home_url() );

		$audit_report = array();

		if ( 'push' === $mode ) {
			$res = self::send_remote_request(
				'finalize',
				array(
					'target_url' => $remote_url,
					'source_url' => $local_url,
					'deep_clean' => true,
				),
				$remote_url,
				$secret_key,
				$remote_ip
			);
			if ( is_wp_error( $res ) ) {
				return array(
					'success' => false,
					'message' => 'Error al finalizar en remoto: ' . $res->get_error_message(),
				);
			}
			$audit_report = isset( $res['audit'] ) ? $res['audit'] : array();
		} else {
			// In PULL mode, commit staged shadow tables atomically into active WordPress tables
			VK7K_Sync_DB::commit_staged_tables( $local_url );

			// Run deep link normalization and verification on local database
			$matrix = VK7K_Sync_Replacer::build_url_matrix( $remote_url, $local_url );
			if ( ! empty( $matrix ) ) {
				VK7K_Sync_DB::deep_search_and_replace( $matrix );
			}

			// Ensure local siteurl and home are correct
			update_option( 'siteurl', $local_url );
			update_option( 'home', $local_url );

			// Clear Elementor CSS cache if active
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				try {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				} catch ( Exception $e ) {
					// Ignore
				}
			}

			// Flush object cache, OPcache, and rewrite rules
			wp_cache_flush();
			if ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset();
			}
			flush_rewrite_rules( true );
			VK7K_Sync_Files::cleanup_temp();

			// Audit residual links
			$audit_report = VK7K_Sync_DB::audit_residual_urls( array( $remote_url ) );
		}

		$settings['last_sync_time']   = current_time( 'mysql' );
		$settings['last_sync_status'] = 'Completado con éxito';
		VK7K_Sync_Auth::save_settings( $settings );

		$residual_count = isset( $audit_report['total_residual'] ) ? (int) $audit_report['total_residual'] : 0;
		$msg = ( 0 === $residual_count )
			? 'Sincronización finalizada con éxito. Enlaces 100% verificados y normalizados.'
			: sprintf( 'Sincronización finalizada. Advertencia: Se detectaron %d enlaces residuales en tablas auxiliares.', $residual_count );

		return array(
			'success' => true,
			'message' => $msg,
			'time'    => current_time( 'mysql' ),
			'audit'   => $audit_report,
		);
	}
}
