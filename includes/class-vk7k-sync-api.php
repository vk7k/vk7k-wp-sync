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
			'/db/push-posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_push_posts' ),
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
			'/db/push-post',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_push_post' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/db/push-options',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_db_push_options' ),
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

		// FSE & Navigation Routes
		register_rest_route(
			self::REST_NAMESPACE,
			'/fse/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_fse_export' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/fse/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_fse_import' ),
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

		// Remote Plugins Management routes
		register_rest_route(
			self::REST_NAMESPACE,
			'/plugins/list',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_plugins_list' ),
				'permission_callback' => array( 'VK7K_Sync_Auth', 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/plugins/toggle',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_plugins_toggle' ),
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
			"\$uri   = isset( \$_SERVER['REQUEST_URI'] ) ? \$_SERVER['REQUEST_URI'] : '';\n" .
			"\$route = isset( \$_GET['rest_route'] ) ? \$_GET['rest_route'] : '';\n" .
			"if ( false !== strpos( \$uri, 'vk7k-sync' ) || false !== strpos( \$route, 'vk7k-sync' ) || false !== strpos( \$uri, 'admin-ajax.php' ) || false !== strpos( \$uri, 'wp-admin' ) || isset( \$_SERVER['HTTP_X_VK7K_AUTH'] ) ) {\n" .
			"    add_filter( 'enable_maintenance_mode', '__return_false', 1 );\n" .
			"}\n" .
			"if ( false !== strpos( \$uri, 'vk7k-sync/v1' ) || false !== strpos( \$route, 'vk7k-sync/v1' ) ) {\n" .
			"    add_filter( 'option_active_plugins', function( \$plugins ) {\n" .
			"        if ( ! is_array( \$plugins ) ) \$plugins = array();\n" .
			"        if ( ! in_array( 'vk7k-wp-sync/vk7k-wp-sync.php', \$plugins, true ) ) {\n" .
			"            \$plugins[] = 'vk7k-wp-sync/vk7k-wp-sync.php';\n" .
			"        }\n" .
			"        return \$plugins;\n" .
			"    }, 1 );\n" .
			"}\n";

		if ( ! file_exists( $file ) || file_get_contents( $file ) !== $code ) {
			@file_put_contents( $file, $code );
		}
	}

	/**
	 * Write smart maintenance file that blocks regular visitors but allows vk7k-sync REST API traffic.
	 */
	public static function enable_maintenance_mode() {
		self::ensure_safe_mode_installed();

		$maint_file = trailingslashit( wp_normalize_path( ABSPATH ) ) . '.maintenance';
		$code       = "<?php\n" .
			"\$uri    = isset( \$_SERVER['REQUEST_URI'] ) ? \$_SERVER['REQUEST_URI'] : '';\n" .
			"\$route  = isset( \$_GET['rest_route'] ) ? \$_GET['rest_route'] : '';\n" .
			"\$bypass = ( 'cli' === PHP_SAPI || defined( 'WP_CLI' ) );\n" .
			"if ( ! \$bypass && ( false !== strpos( \$uri, 'vk7k-sync' ) || false !== strpos( \$route, 'vk7k-sync' ) || false !== strpos( \$uri, 'admin-ajax.php' ) || false !== strpos( \$uri, 'wp-admin' ) || isset( \$_SERVER['HTTP_X_VK7K_AUTH'] ) ) ) {\n" .
			"    \$bypass = true;\n" .
			"} elseif ( ! \$bypass && ! empty( \$_COOKIE ) ) {\n" .
			"    foreach ( \$_COOKIE as \$k => \$v ) {\n" .
			"        if ( 0 === strpos( \$k, 'wordpress_logged_in_' ) ) {\n" .
			"            \$bypass = true;\n" .
			"            break;\n" .
			"        }\n" .
			"    }\n" .
			"}\n" .
			"if ( \$bypass ) {\n" .
			"    \$upgrading = 0;\n" .
			"} else {\n" .
			"    \$upgrading = time();\n" .
			"}\n";

		return ( false !== @file_put_contents( $maint_file, $code ) );
	}

	/**
	 * Remove maintenance mode file.
	 */
	public static function disable_maintenance_mode() {
		$maint_file = trailingslashit( wp_normalize_path( ABSPATH ) ) . '.maintenance';
		if ( file_exists( $maint_file ) ) {
			@unlink( $maint_file );
		}
	}

	/**
	 * Initialize sync: enable maintenance mode & ensure safe mode MU-plugin.
	 */
	public static function handle_init( $request ) {
		self::enable_maintenance_mode();

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

		$upload_dir = wp_upload_dir();

		return rest_ensure_response( array(
			'success'                  => true,
			'site_name'                => get_bloginfo( 'name' ),
			'site_url'                 => site_url(),
			'home_url'                 => home_url(),
			'content_url'              => content_url(),
			'upload_url'               => ! empty( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '',
			'detected_content_subdirs' => class_exists( 'VK7K_Sync_DB' ) ? VK7K_Sync_DB::detect_content_subdirectories() : array(),
			'wp_version'               => $wp_version,
			'php_version'              => phpversion(),
			'plugin_version'           => defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.0.0',
			'db_prefix'                => $wpdb->prefix,
			'max_upload_size'          => $upload_max_bytes,
			'post_max_size'            => $post_max_bytes,
			'memory_limit'             => $memory_bytes,
			'max_execution'            => $max_exec,
			'server_time'              => current_time( 'mysql' ),
			'timestamp'                => time(),
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
	/**
	 * Handle single post push (posts + postmeta + term relationships) received from master.
	 */
	public static function handle_db_push_post( $request ) {
		$params = $request->get_json_params();

		// Support either a full payload with post_row or delegating to handle_db_push_posts
		if ( ! empty( $params['posts'] ) && is_array( $params['posts'] ) ) {
			return self::handle_db_push_posts( $request );
		}

		$post_row  = isset( $params['post_row'] ) && is_array( $params['post_row'] ) ? $params['post_row'] : array();
		$meta_rows = isset( $params['meta_rows'] ) && is_array( $params['meta_rows'] ) ? $params['meta_rows'] : array();
		$term_rels = isset( $params['term_rels'] ) && is_array( $params['term_rels'] ) ? $params['term_rels'] : array();
		$terms     = isset( $params['terms'] ) && is_array( $params['terms'] ) ? $params['terms'] : array();

		$post_id = ! empty( $post_row['ID'] ) ? (int) $post_row['ID'] : ( isset( $params['post_id'] ) ? (int) $params['post_id'] : 0 );
		if ( $post_id <= 0 || empty( $post_row ) ) {
			return new WP_Error( 'vk7k_invalid_post_payload', 'Datos de entrada inválidos o vacíos.', array( 'status' => 400 ) );
		}

		$request->set_param( 'posts', array( $post_row ) );
		$request->set_param( 'postmeta', $meta_rows );
		$request->set_param( 'term_relationships', $term_rels );
		$request->set_param( 'terms', $terms );

		return self::handle_db_push_posts( $request );
	}

	/**
	 * Handle batch posts push (posts + postmeta + terms + term_relationships) received from master.
	 */
	public static function handle_db_push_posts( $request ) {
		global $wpdb;

		$params             = $request->get_json_params();
		$posts              = ! empty( $params['posts'] ) ? (array) $params['posts'] : (array) $request->get_param( 'posts' );
		$postmeta           = ! empty( $params['postmeta'] ) ? (array) $params['postmeta'] : (array) $request->get_param( 'postmeta' );
		$term_relationships = ! empty( $params['term_relationships'] ) ? (array) $params['term_relationships'] : (array) $request->get_param( 'term_relationships' );
		$terms              = ! empty( $params['terms'] ) ? (array) $params['terms'] : (array) $request->get_param( 'terms' );
		$purge_post_type    = ! empty( $params['purge_post_type'] ) ? sanitize_key( $params['purge_post_type'] ) : '';

		if ( empty( $posts ) && empty( $purge_post_type ) ) {
			return new WP_Error( 'vk7k_empty_posts', 'No se recibieron entradas para sincronizar.', array( 'status' => 400 ) );
		}

		$source_url = isset( $params['source_url'] ) ? esc_url_raw( $params['source_url'] ) : '';
		$target_url = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : home_url();
		$replacer   = null;
		if ( ! empty( $source_url ) && ! empty( $target_url ) ) {
			$matrix = VK7K_Sync_Replacer::build_url_matrix( $source_url, $target_url );
			if ( ! empty( $matrix ) ) {
				$replacer = new VK7K_Sync_Replacer( $matrix );
			}
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0;' );

		// Optional clean purge of a specific CPT before inserting fresh rows
		if ( ! empty( $purge_post_type ) ) {
			$old_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = %s", $purge_post_type ) );
			if ( ! empty( $old_ids ) ) {
				$ids_in = implode( ',', array_map( 'intval', $old_ids ) );
				$wpdb->query( "DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN ({$ids_in})" );
				$wpdb->query( "DELETE FROM `{$wpdb->term_relationships}` WHERE object_id IN ({$ids_in})" );
				$wpdb->query( "DELETE FROM `{$wpdb->posts}` WHERE ID IN ({$ids_in})" );
			}
		}

		// 1. Upsert terms & term_taxonomy if provided
		foreach ( $terms as $t ) {
			if ( empty( $t['term_id'] ) || empty( $t['term_taxonomy_id'] ) ) {
				continue;
			}
			$wpdb->replace(
				$wpdb->terms,
				array(
					'term_id'    => (int) $t['term_id'],
					'name'       => $t['name'] ?? '',
					'slug'       => $t['slug'] ?? '',
					'term_group' => (int) ( $t['term_group'] ?? 0 ),
				)
			);
			$wpdb->replace(
				$wpdb->term_taxonomy,
				array(
					'term_taxonomy_id' => (int) $t['term_taxonomy_id'],
					'term_id'          => (int) $t['term_id'],
					'taxonomy'         => $t['taxonomy'] ?? '',
					'description'      => $t['description'] ?? '',
					'parent'           => (int) ( $t['parent'] ?? 0 ),
					'count'            => (int) ( $t['count'] ?? 0 ),
				)
			);
		}

		// 2. Upsert posts
		$synced_ids = array();
		foreach ( $posts as $post_row ) {
			if ( empty( $post_row['ID'] ) ) {
				continue;
			}
			$pid = (int) $post_row['ID'];
			if ( $replacer ) {
				foreach ( $post_row as $k => $v ) {
					if ( is_string( $v ) ) {
						$post_row[ $k ] = $replacer->replace( $v );
					}
				}
			}

			// If this is an FSE template/template_part, remove any conflicting post with same post_type + post_name but different ID
			if ( ! empty( $post_row['post_type'] ) && in_array( $post_row['post_type'], array( 'wp_template', 'wp_template_part', 'wp_global_styles' ), true ) && ! empty( $post_row['post_name'] ) ) {
				$conflict_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM `{$wpdb->posts}` WHERE post_type = %s AND post_name = %s AND ID != %d",
						$post_row['post_type'],
						$post_row['post_name'],
						$pid
					)
				);
				if ( ! empty( $conflict_ids ) ) {
					$c_in = implode( ',', array_map( 'intval', $conflict_ids ) );
					$wpdb->query( "DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN ({$c_in})" );
					$wpdb->query( "DELETE FROM `{$wpdb->term_relationships}` WHERE object_id IN ({$c_in})" );
					$wpdb->query( "DELETE FROM `{$wpdb->posts}` WHERE ID IN ({$c_in})" );
				}
			}

			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM `{$wpdb->posts}` WHERE ID = %d", $pid ) );
			if ( $existing_id ) {
				$wpdb->update( $wpdb->posts, $post_row, array( 'ID' => $pid ) );
			} else {
				$wpdb->insert( $wpdb->posts, $post_row );
			}
			$synced_ids[] = $pid;
		}

		// 3. Upsert postmeta for synced posts
		if ( ! empty( $synced_ids ) ) {
			$ids_in = implode( ',', array_map( 'intval', $synced_ids ) );
			$wpdb->query( "DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN ({$ids_in})" );
			$wpdb->query( "DELETE FROM `{$wpdb->term_relationships}` WHERE object_id IN ({$ids_in})" );
		}

		$meta_count = 0;
		foreach ( $postmeta as $m ) {
			unset( $m['meta_id'] );
			if ( $replacer && isset( $m['meta_value'] ) && is_string( $m['meta_value'] ) ) {
				$m['meta_value'] = $replacer->replace( $m['meta_value'] );
			}
			$wpdb->insert( $wpdb->postmeta, $m );
			$meta_count++;
		}

		// 4. Upsert term_relationships
		foreach ( $term_relationships as $tr ) {
			$wpdb->replace(
				$wpdb->term_relationships,
				array(
					'object_id'        => (int) $tr['object_id'],
					'term_taxonomy_id' => (int) $tr['term_taxonomy_id'],
					'term_order'       => (int) ( $tr['term_order'] ?? 0 ),
				)
			);
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1;' );

		wp_cache_flush();
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Exception $e ) {
				// Ignore
			}
		}
		flush_rewrite_rules( true );

		return rest_ensure_response(
			array(
				'success'      => true,
				'posts_synced' => count( $synced_ids ),
				'post_ids'     => $synced_ids,
				'meta_synced'  => $meta_count,
				'message'      => sprintf( '%d entrada(s) y %d metadatos sincronizados con éxito.', count( $synced_ids ), $meta_count ),
			)
		);
	}

	/**
	 * Handle selective options push received from master.
	 */
	public static function handle_db_push_options( $request ) {
		$params     = $request->get_json_params();
		$options    = isset( $params['options'] ) && is_array( $params['options'] ) ? $params['options'] : array();
		$source_url = isset( $params['source_url'] ) ? esc_url_raw( $params['source_url'] ) : '';
		$target_url = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : home_url();

		if ( empty( $options ) ) {
			return new WP_Error( 'vk7k_empty_options', 'No se recibieron opciones para sincronizar.', array( 'status' => 400 ) );
		}

		$replacer = null;
		if ( ! empty( $source_url ) && ! empty( $target_url ) ) {
			$matrix = VK7K_Sync_Replacer::build_url_matrix( $source_url, $target_url );
			if ( ! empty( $matrix ) ) {
				$replacer = new VK7K_Sync_Replacer( $matrix );
			}
		}

		$updated_keys = array();
		foreach ( $options as $opt ) {
			if ( empty( $opt['option_name'] ) ) {
				continue;
			}
			$name = sanitize_text_field( $opt['option_name'] );
			if ( VK7K_Sync_DB::is_internal_option( $name ) ) {
				continue;
			}
			$val = $opt['option_value'] ?? '';
			if ( $replacer && is_string( $val ) ) {
				$val = $replacer->replace( $val );
			}
			$unserialized = maybe_unserialize( $val );
			update_option( $name, $unserialized );
			$updated_keys[] = $name;
		}

		wp_cache_flush();

		return rest_ensure_response(
			array(
				'success'      => true,
				'options_count'=> count( $updated_keys ),
				'updated_keys' => $updated_keys,
				'message'      => sprintf( '%d opción(es) sincronizadas con éxito.', count( $updated_keys ) ),
			)
		);
	}

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
	 * Handle FSE export request.
	 */
	public static function handle_fse_export( $request ) {
		$params     = $request->get_json_params();
		$source_url = isset( $params['source_url'] ) ? esc_url_raw( $params['source_url'] ) : '';
		$target_url = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : '';

		$bundle = VK7K_Sync_DB::export_fse_bundle( $source_url, $target_url );
		return rest_ensure_response( $bundle );
	}

	/**
	 * Handle FSE import request.
	 */
	public static function handle_fse_import( $request ) {
		$params     = $request->get_json_params();
		$bundle     = isset( $params['bundle'] ) ? (array) $params['bundle'] : array();
		$source_url = isset( $params['source_url'] ) ? esc_url_raw( $params['source_url'] ) : '';
		$target_url = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : '';

		$res = VK7K_Sync_DB::import_fse_bundle( $bundle, $source_url, $target_url );
		if ( empty( $res['success'] ) ) {
			return new WP_Error( 'vk7k_fse_import_error', $res['message'] ?? 'Error importando bundle FSE', array( 'status' => 500 ) );
		}
		return rest_ensure_response( $res );
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
	 * Handle file upload chunk (ZIP payload or raw binary stream).
	 */
	public static function handle_files_upload_chunk( $request ) {
		$temp_dir = VK7K_Sync_Files::get_temp_dir();
		$temp_zip = $temp_dir . 'recv_' . uniqid() . '.zip';

		// Support direct binary upload stream (zero RAM Base64 overhead)
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? $_SERVER['CONTENT_TYPE'] : '';
		if ( false !== strpos( $content_type, 'application/zip' ) || false !== strpos( $content_type, 'application/octet-stream' ) ) {
			$input = fopen( 'php://input', 'rb' );
			$dest  = fopen( $temp_zip, 'wb' );
			if ( $input && $dest ) {
				stream_copy_to_stream( $input, $dest );
				fclose( $input );
				fclose( $dest );
			}
			if ( file_exists( $temp_zip ) && filesize( $temp_zip ) > 0 ) {
				$result = VK7K_Sync_Files::extract_zip_chunk( $temp_zip );
				@unlink( $temp_zip );
				return rest_ensure_response( $result );
			}
		}

		// Fallback to JSON Base64 payload
		$params  = $request->get_json_params();
		$zip_b64 = isset( $params['zip_base64'] ) ? $params['zip_base64'] : '';

		if ( empty( $zip_b64 ) ) {
			return new WP_Error( 'vk7k_missing_zip_data', 'No se recibieron datos de archivo ZIP', array( 'status' => 400 ) );
		}

		$zip_raw = base64_decode( $zip_b64 );
		if ( false === $zip_raw ) {
			return new WP_Error( 'vk7k_invalid_base64', 'Datos base64 corruptos', array( 'status' => 400 ) );
		}

		file_put_contents( $temp_zip, $zip_raw );
		$result = VK7K_Sync_Files::extract_zip_chunk( $temp_zip );
		@unlink( $temp_zip );
		return rest_ensure_response( $result );
	}

	/**
	 * Handle file download chunk (create ZIP of requested files with optional zero-RAM streaming).
	 */
	public static function handle_files_download_chunk( $request ) {
		$params    = $request->get_json_params();
		$rel_files = isset( $params['files'] ) ? (array) $params['files'] : array();
		$max_mb    = isset( $params['chunk_mb'] ) ? (int) $params['chunk_mb'] : 16;
		$max_files = isset( $params['chunk_files'] ) ? (int) $params['chunk_files'] : 150;
		$stream    = ! empty( $params['stream'] );

		$chunk_res = VK7K_Sync_Files::create_zip_chunk( $rel_files, $max_mb * 1024 * 1024, $max_files );

		if ( $stream ) {
			if ( empty( $chunk_res['success'] ) ) {
				return rest_ensure_response( $chunk_res );
			}

			$zip_path = $chunk_res['zip_path'] ?? '';
			if ( ! file_exists( $zip_path ) || 0 === filesize( $zip_path ) ) {
				if ( file_exists( $zip_path ) ) {
					@unlink( $zip_path );
				}
				return rest_ensure_response( array(
					'success'         => true,
					'included_files'  => array(),
					'remaining_files' => $chunk_res['remaining_files'] ?? array(),
					'zip_size'        => 0,
				) );
			}

			$zip_size  = filesize( $zip_path );
			$inc_count = ! empty( $chunk_res['included_files'] ) ? count( $chunk_res['included_files'] ) : 0;

			// Direct binary streaming: flush buffers and read directly from disk to network
			while ( ob_get_level() > 0 ) {
				@ob_end_clean();
			}

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="vk7k_chunk_' . uniqid() . '.zip"' );
			header( 'Content-Length: ' . $zip_size );
			header( 'X-VK7K-Included-Count: ' . $inc_count );
			header( 'X-VK7K-Zip-Size: ' . $zip_size );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			readfile( $zip_path );
			@unlink( $zip_path );
			exit;
		}

		// Fallback for non-streamed requests
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
		$commit_result = VK7K_Sync_DB::commit_staged_tables( $target_url );

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
		self::disable_maintenance_mode();

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
			'commit'    => $commit_result,
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

		// Secure and migrate remote vault on update
		if ( class_exists( 'VK7K_Sync_Auth' ) ) {
			$settings = VK7K_Sync_Auth::get_settings();
			VK7K_Sync_Auth::save_vault( $settings );
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

	/**
	 * Handle remote plugin listing.
	 */
	public static function handle_plugins_list( $request ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = VK7K_Sync_Files::get_available_plugins();
		return rest_ensure_response( array(
			'success' => true,
			'plugins' => $plugins,
		) );
	}

	/**
	 * Handle remote plugin activation/deactivation.
	 */
	public static function handle_plugins_toggle( $request ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$params      = $request->get_json_params();
		$plugin_file = isset( $params['plugin_file'] ) ? sanitize_text_field( $params['plugin_file'] ) : '';
		$action      = isset( $params['action'] ) ? sanitize_key( $params['action'] ) : 'activate';

		if ( empty( $plugin_file ) ) {
			return new WP_Error( 'vk7k_missing_plugin', 'Se requiere la ruta del archivo del plugin.', array( 'status' => 400 ) );
		}

		if ( 'activate' === $action ) {
			global $wpdb;
			$valid_res = validate_plugin( $plugin_file );
			if ( is_wp_error( $valid_res ) ) {
				return new WP_Error( 'vk7k_invalid_plugin', $valid_res->get_error_message(), array( 'status' => 400 ) );
			}

			// Get pre-existing active plugins array directly from DB
			$raw_active = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
			$current_active = maybe_unserialize( $raw_active );
			if ( ! is_array( $current_active ) ) {
				$current_active = array();
			}

			$result = activate_plugin( $plugin_file, '', false, true );

			if ( ! in_array( $plugin_file, $current_active, true ) ) {
				$current_active[] = $plugin_file;
			}

			sort( $current_active );
			$serialized = serialize( array_values( array_unique( $current_active ) ) );
			$wpdb->update(
				$wpdb->options,
				array( 'option_value' => $serialized ),
				array( 'option_name' => 'active_plugins' )
			);
			wp_cache_delete( 'active_plugins', 'options' );
			wp_cache_delete( 'alloptions', 'options' );

			$active_list = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) ) );
			return rest_ensure_response( array(
				'success'        => true,
				'message'        => "Plugin {$plugin_file} activado con éxito.",
				'is_active'      => in_array( $plugin_file, (array) $active_list, true ),
				'active_plugins' => $active_list,
				'valid_res'      => $valid_res,
				'act_result'     => $result,
			) );
		} else {
			deactivate_plugins( $plugin_file );
			$active_list = (array) get_option( 'active_plugins', array() );
			return rest_ensure_response( array(
				'success'        => true,
				'message'        => "Plugin {$plugin_file} desactivado con éxito.",
				'is_active'      => in_array( $plugin_file, $active_list, true ),
				'active_plugins' => $active_list,
			) );
		}
	}
}

