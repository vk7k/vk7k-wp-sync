<?php
/**
 * VK7K Sync REST API Controller
 *
 * Registers and handles REST API routes under /wp-json/vk7k-sync/v1/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_API {

	const REST_NAMESPACE = 'vk7k-sync/v1';

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		// Handshake and info
		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_auth_test' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		// Database routes
		register_rest_route(
			self::REST_NAMESPACE,
			'/db/tables',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_tables' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/db/export-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_export_chunk' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/db/import-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_import_chunk' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/db/backup',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_backup' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/db/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_restore' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		// Init / Handshake & Safe-Mode
		register_rest_route(
			self::REST_NAMESPACE,
			'/init',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_init' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		// File routes
		register_rest_route(
			self::REST_NAMESPACE,
			'/files/manifest',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_files_manifest' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/files/upload-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_files_upload_chunk' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/files/download-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_files_download_chunk' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/files/extract-bundle',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_files_extract_bundle' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		// Finalize
		register_rest_route(
			self::REST_NAMESPACE,
			'/finalize',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_finalize' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		// Link Tools
		register_rest_route(
			self::REST_NAMESPACE,
			'/tools/audit-links',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_tools_audit_links' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/tools/fix-links',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_tools_fix_links' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		// Plugin Self-Update & Distribution routes
		register_rest_route(
			self::REST_NAMESPACE,
			'/plugin/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_plugin_update' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/plugin/bundle',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_plugin_get_bundle' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);
	}

	/**
	 * Convert php ini size format (e.g. '64M', '512M', '1G') to integer bytes.
	 *
	 * @param string $size_str
	 * @return int
	 */
	public static function parse_ini_bytes( $size_str ) {
		$size_str = trim( (string) $size_str );
		if ( empty( $size_str ) || '-1' === $size_str ) {
			return 536870912; // 512MB default for unlimited
		}
		$unit = strtolower( substr( $size_str, -1 ) );
		$val  = (float) substr( $size_str, 0, -1 );
		switch ( $unit ) {
			case 'g':
				return (int) ( $val * 1024 * 1024 * 1024 );
			case 'm':
				return (int) ( $val * 1024 * 1024 );
			case 'k':
				return (int) ( $val * 1024 );
			default:
				return (int) $size_str;
		}
	}

	/**
	 * Ensure safe-mode MU-plugin is deployed locally on this instance.
	 */
	public static function ensure_safe_mode_installed() {
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		if ( ! file_exists( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		$file = trailingslashit( $mu_dir ) . 'vk7k-sync-safe-mode.php';
		$code = "<?php\n" .
			"/** Plugin Name: VK7K Sync Safe-Mode & Maintenance Handler */\n" .
			"if ( ! defined( 'ABSPATH' ) ) exit;\n" .
			"if ( isset( \$_SERVER['REQUEST_URI'] ) && false !== strpos( \$_SERVER['REQUEST_URI'], 'vk7k-sync/v1' ) ) {\n" .
			"    add_filter( 'enable_maintenance_mode', '__return_false', 1 );\n" .
			"    add_filter( 'option_active_plugins', function( \$plugins ) {\n" .
			"        return array( 'vk7k-wp-sync/vk7k-wp-sync.php' );\n" .
			"    }, 1 );\n" .
			"}\n";

		if ( ! file_exists( $file ) || file_get_contents( $file ) !== $code ) {
			@file_put_contents( $file, $code );
		}
	}

	/**
	 * Initialize sync: enable maintenance mode & ensure safe mode MU-plugin.
	 */
	public static function handle_init( $request ) {
		self::ensure_safe_mode_installed();

		// Enable maintenance mode for regular traffic during sync
		$maint_file = trailingslashit( wp_normalize_path( ABSPATH ) ) . '.maintenance';
		$code = '<?php $upgrading = ' . ( time() + 3600 ) . '; ?>';
		@file_put_contents( $maint_file, $code );

		return rest_ensure_response( array(
			'success'     => true,
			'maintenance' => true,
			'message'     => 'Sitio remoto en modo mantenimiento y Safe-Mode activo.',
		) );
	}

	/**
	 * Test connection and return system capacity metrics.
	 */
	public static function handle_auth_test( $request ) {
		global $wpdb, $wp_version;

		self::ensure_safe_mode_installed();

		$post_max_bytes   = self::parse_ini_bytes( ini_get( 'post_max_size' ) );
		$upload_max_bytes = (int) wp_max_upload_size();
		$memory_bytes     = self::parse_ini_bytes( ini_get( 'memory_limit' ) );
		$max_exec         = (int) ini_get( 'max_execution_time' );

		return rest_ensure_response( array(
			'success'          => true,
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => site_url(),
			'home_url'         => home_url(),
			'wp_version'       => $wp_version,
			'php_version'      => phpversion(),
			'plugin_version'   => defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.0.0',
			'db_prefix'        => $wpdb->prefix,
			'max_upload_size'  => $upload_max_bytes,
			'post_max_size'    => $post_max_bytes,
			'memory_limit'     => $memory_bytes,
			'max_execution'    => $max_exec,
			'server_time'      => current_time( 'mysql' ),
			'timestamp'        => time(),
		) );
	}

	/**
	 * Return list of DB tables.
	 */
	public static function handle_db_tables( $request ) {
		$params  = $request->get_json_params();
		$options = isset( $params['options'] ) ? (array) $params['options'] : array();
		$tables  = VK7K_Sync_DB::get_tables( $options );

		return rest_ensure_response( array(
			'success' => true,
			'tables'  => $tables,
		) );
	}

	/**
	 * Export chunk for PULL mode.
	 */
	public static function handle_db_export_chunk( $request ) {
		$params         = $request->get_json_params();
		$table_name     = isset( $params['table'] ) ? sanitize_text_field( $params['table'] ) : '';
		$offset         = isset( $params['offset'] ) ? (int) $params['offset'] : 0;
		$limit          = isset( $params['limit'] ) ? (int) $params['limit'] : 1000;
		$replacements   = isset( $params['replacements'] ) ? (array) $params['replacements'] : array();
		$target_prefix  = isset( $params['target_prefix'] ) ? sanitize_text_field( $params['target_prefix'] ) : '';
		$is_schema_only = ! empty( $params['is_schema_only'] );

		$replacer = null;
		if ( ! empty( $replacements ) ) {
			$replacer = new VK7K_Sync_Replacer( $replacements );
		}

		$chunk = VK7K_Sync_DB::export_table_chunk( $table_name, $offset, $limit, $replacer, $target_prefix, $is_schema_only );

		if ( 0 === $offset ) {
			$chunk['schema'] = VK7K_Sync_DB::get_table_schema( $table_name );
		}

		return rest_ensure_response( $chunk );
	}

	/**
	 * Import chunk for PUSH mode.
	 */
	public static function handle_db_import_chunk( $request ) {
		$params         = $request->get_json_params();
		$chunk_data     = isset( $params['chunk_data'] ) ? $params['chunk_data'] : array();
		$is_first_chunk = ! empty( $params['is_first_chunk'] );
		$schema_sql     = isset( $params['schema'] ) ? $params['schema'] : '';

		if ( empty( $chunk_data['table'] ) ) {
			return new WP_Error( 'vk7k_missing_table', 'Nombre de tabla no especificado', array( 'status' => 400 ) );
		}

		$result = VK7K_Sync_DB::import_table_chunk( $chunk_data, $is_first_chunk, $schema_sql );
		return rest_ensure_response( $result );
	}

	/**
	 * Create rollback backup.
	 */
	public static function handle_db_backup( $request ) {
		$result = VK7K_Sync_DB::create_rollback_backup();
		return rest_ensure_response( $result );
	}

	/**
	 * Restore rollback backup.
	 */
	public static function handle_db_restore( $request ) {
		$result = VK7K_Sync_DB::restore_rollback_backup();
		return rest_ensure_response( $result );
	}

	/**
	 * Return files manifest.
	 */
	public static function handle_files_manifest( $request ) {
		$params     = $request->get_json_params();
		$components = isset( $params['components'] ) ? (array) $params['components'] : array( 'uploads' );
		$options    = isset( $params['options'] ) ? (array) $params['options'] : array();
		$manifest   = VK7K_Sync_Files::generate_manifest( $components, $options );

		return rest_ensure_response( array(
			'success'  => true,
			'manifest' => $manifest,
			'count'    => count( $manifest ),
		) );
	}

	/**
	 * Handle file upload chunk (ZIP payload).
	 */
	public static function handle_files_upload_chunk( $request ) {
		$params   = $request->get_json_params();
		$zip_b64  = isset( $params['zip_base64'] ) ? $params['zip_base64'] : '';

		if ( empty( $zip_b64 ) ) {
			return new WP_Error( 'vk7k_missing_zip_data', 'No se recibieron datos de archivo ZIP', array( 'status' => 400 ) );
		}

		$zip_raw = base64_decode( $zip_b64 );
		if ( false === $zip_raw ) {
			return new WP_Error( 'vk7k_invalid_base64', 'Datos base64 corruptos', array( 'status' => 400 ) );
		}

		$temp_dir = VK7K_Sync_Files::get_temp_dir();
		$temp_zip = $temp_dir . 'recv_' . uniqid() . '.zip';
		file_put_contents( $temp_zip, $zip_raw );

		$result = VK7K_Sync_Files::extract_zip_chunk( $temp_zip );
		return rest_ensure_response( $result );
	}

	/**
	 * Handle file download chunk (create ZIP of requested files).
	 */
	public static function handle_files_download_chunk( $request ) {
		$params    = $request->get_json_params();
		$rel_files = isset( $params['files'] ) ? (array) $params['files'] : array();
		$max_mb    = isset( $params['chunk_mb'] ) ? (int) $params['chunk_mb'] : 8;

		$chunk_res = VK7K_Sync_Files::create_zip_chunk( $rel_files, $max_mb * 1024 * 1024 );

		if ( ! empty( $chunk_res['success'] ) && file_exists( $chunk_res['zip_path'] ) ) {
			$chunk_res['zip_base64'] = base64_encode( file_get_contents( $chunk_res['zip_path'] ) );
			@unlink( $chunk_res['zip_path'] );
		}

		return rest_ensure_response( $chunk_res );
	}

	/**
	 * Handle bundle extraction on remote server (Turbo SFTP mode).
	 */
	public static function handle_files_extract_bundle( $request ) {
		$params          = $request->get_json_params();
		$remote_filename = isset( $params['filename'] ) ? sanitize_file_name( $params['filename'] ) : '';

		if ( empty( $remote_filename ) ) {
			return new WP_Error( 'vk7k_missing_filename', 'Nombre de archivo bundle no proporcionado.', array( 'status' => 400 ) );
		}

		$temp_dir = VK7K_Sync_Files::get_temp_dir();
		$zip_path = $temp_dir . $remote_filename;

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'vk7k_bundle_not_found', 'Archivo bundle no encontrado en: ' . $zip_path, array( 'status' => 404 ) );
		}

		$result = VK7K_Sync_Files::extract_zip_chunk( $zip_path );
		return rest_ensure_response( $result );
	}

	/**
	 * Handle finalization: flush cache, permalinks, deep URL normalization, and link audit.
	 */
	public static function handle_finalize( $request ) {
		$params     = $request->get_json_params();
		$target_url = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : '';
		$source_url = isset( $params['source_url'] ) ? esc_url_raw( $params['source_url'] ) : '';
		$deep_clean = ! empty( $params['deep_clean'] );

		// 0. Commit staged shadow tables if any were imported
		VK7K_Sync_DB::commit_staged_tables( $target_url );

		// 1. Run deep search & replace on remote tables if source and target provided
		if ( $deep_clean && ! empty( $source_url ) && ! empty( $target_url ) ) {
			$matrix = VK7K_Sync_Replacer::build_url_matrix( $source_url, $target_url );
			if ( ! empty( $matrix ) ) {
				VK7K_Sync_DB::deep_search_and_replace( $matrix );
			}
		}

		// 2. Ensure siteurl and home are correct if specified
		if ( ! empty( $target_url ) ) {
			$canonical_url = untrailingslashit( $target_url );
			update_option( 'siteurl', $canonical_url );
			update_option( 'home', $canonical_url );
		}

		// 3. Remove maintenance mode
		$maint_file = trailingslashit( wp_normalize_path( ABSPATH ) ) . '.maintenance';
		if ( file_exists( $maint_file ) ) {
			@unlink( $maint_file );
		}

		// 4. Clear Page Builder & Block caches
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( Exception $e ) {
				// Ignore
			}
		}

		// 5. Flush object cache & rewrite rules
		wp_cache_flush();
		flush_rewrite_rules( true );

		// 6. Clean temp files
		VK7K_Sync_Files::cleanup_temp();

		// 7. Audit residual links
		$audit_report = array();
		if ( ! empty( $source_url ) ) {
			$audit_report = VK7K_Sync_DB::audit_residual_urls( array( $source_url ) );
		}

		return rest_ensure_response( array(
			'success'   => true,
			'message'   => 'Sincronización finalizada correctamente.',
			'site_url'  => site_url(),
			'home_url'  => home_url(),
			'audit'     => $audit_report,
		) );
	}

	/**
	 * Handle Link Audit via REST API.
	 */
	public static function handle_tools_audit_links( $request ) {
		$params  = $request->get_json_params();
		$domains = isset( $params['domains'] ) ? (array) $params['domains'] : array();

		if ( empty( $domains ) ) {
			$settings = VK7K_Sync_Auth::get_settings();
			if ( ! empty( $settings['remote_url'] ) ) {
				$domains[] = $settings['remote_url'];
			}
		}

		$report = VK7K_Sync_DB::audit_residual_urls( $domains );
		return rest_ensure_response( array(
			'success' => true,
			'audit'   => $report,
		) );
	}

	/**
	 * Handle Fast Link Fix via REST API.
	 */
	public static function handle_tools_fix_links( $request ) {
		$params     = $request->get_json_params();
		$source_url = isset( $params['source_url'] ) ? esc_url_raw( $params['source_url'] ) : '';
		$target_url = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : home_url();

		if ( empty( $source_url ) || empty( $target_url ) ) {
			return new WP_Error( 'vk7k_missing_urls', 'Se requiere URL de origen y URL de destino.', array( 'status' => 400 ) );
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

		return rest_ensure_response( array(
			'success' => true,
			'stats'   => $stats,
			'audit'   => $audit,
			'message' => 'Reparación de enlaces completada con éxito.',
		) );
	}

	/**
	 * Handle plugin self-update payload (REST API).
	 */
	public static function handle_plugin_update( $request ) {
		$params  = $request->get_json_params();
		$zip_b64 = isset( $params['zip_base64'] ) ? $params['zip_base64'] : '';
		$version = isset( $params['version'] ) ? sanitize_text_field( $params['version'] ) : '';

		if ( empty( $zip_b64 ) ) {
			return new WP_Error( 'vk7k_missing_zip', 'No se recibieron datos de actualización del plugin.', array( 'status' => 400 ) );
		}

		$zip_raw = base64_decode( $zip_b64 );
		if ( false === $zip_raw ) {
			return new WP_Error( 'vk7k_invalid_base64', 'Datos base64 corruptos.', array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'vk7k_missing_ziparchive', 'ZipArchive no disponible en el servidor.', array( 'status' => 500 ) );
		}

		$temp_dir = VK7K_Sync_Files::get_temp_dir();
		$temp_zip = $temp_dir . 'plugin_update_' . uniqid() . '.zip';
		file_put_contents( $temp_zip, $zip_raw );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp_zip ) ) {
			@unlink( $temp_zip );
			return new WP_Error( 'vk7k_zip_open_failed', 'No se pudo abrir el paquete de actualización.', array( 'status' => 500 ) );
		}

		$plugin_dir = defined( 'VK7K_SYNC_PATH' ) ? VK7K_SYNC_PATH : ( WP_PLUGIN_DIR . '/vk7k-wp-sync/' );
		$plugin_dir = trailingslashit( wp_normalize_path( $plugin_dir ) );
		if ( ! is_dir( $plugin_dir ) ) {
			wp_mkdir_p( $plugin_dir );
		}

		$updated_files = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$filename = $zip->getNameIndex( $i );
			if ( false !== strpos( $filename, '..' ) ) {
				continue;
			}
			$dest_file = $plugin_dir . ltrim( $filename, '/' );
			$dest_dir  = dirname( $dest_file );
			if ( ! is_dir( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}
			$stream = $zip->getStream( $filename );
			if ( $stream ) {
				@file_put_contents( $dest_file, stream_get_contents( $stream ) );
				fclose( $stream );
				$updated_files++;
			}
		}

		$zip->close();
		@unlink( $temp_zip );

		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		return rest_ensure_response( array(
			'success'         => true,
			'updated_version' => $version ?: ( defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : 'unknown' ),
			'files_count'     => $updated_files,
			'message'         => 'Plugin actualizado con éxito a v' . ( $version ?: 'actual' ),
		) );
	}

	/**
	 * Return base64 bundle of this plugin for remote pull/update (REST API).
	 */
	public static function handle_plugin_get_bundle( $request ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'vk7k_no_ziparchive', 'ZipArchive no disponible en este servidor.', array( 'status' => 500 ) );
		}

		$bundle_res = VK7K_Sync_Runner::create_plugin_zip_bundle();
		if ( empty( $bundle_res['success'] ) ) {
			return new WP_Error( 'vk7k_bundle_error', $bundle_res['message'] ?? 'Error creando paquete de plugin.', array( 'status' => 500 ) );
		}

		$b64 = base64_encode( file_get_contents( $bundle_res['zip_path'] ) );
		@unlink( $bundle_res['zip_path'] );

		return rest_ensure_response( array(
			'success'        => true,
			'plugin_version' => defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.0.0',
			'zip_base64'     => $b64,
		) );
	}
}
