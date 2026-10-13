<?php
/**
 * VK7K Sync CLI Controller (WP-CLI & Direct PHP CLI Runner)
 *
 * Provides granular, high-efficiency CLI commands for deploying plugins,
 * individual posts/templates, custom post types, options, and FSE bundles
 * without overwriting the entire remote database.
 *
 * Usage via WP-CLI:
 *   wp vk7k-sync status
 *   wp vk7k-sync deploy-self
 *   wp vk7k-sync push-plugin <slug> [--activate]
 *   wp vk7k-sync push-post <id1,id2,...>
 *   wp vk7k-sync push-post-type <post_type>
 *   wp vk7k-sync push-options <prefix_or_keys> [--prefix]
 *   wp vk7k-sync push-fse
 *   wp vk7k-sync finalize
 *
 * Usage via Direct PHP CLI:
 *   php wp-content/plugins/vk7k-wp-sync/includes/class-vk7k-sync-cli.php <command> [args...]
 */

if ( ! defined( 'ABSPATH' ) && 'cli' === PHP_SAPI ) {
	// Allow direct CLI invocation from WordPress root or plugin directory
	$wp_load_candidates = array(
		getcwd() . '/wp-load.php',
		dirname( __DIR__, 4 ) . '/wp-load.php',
	);
	foreach ( $wp_load_candidates as $cand ) {
		if ( file_exists( $cand ) ) {
			if ( empty( $_SERVER['HTTP_HOST'] ) ) {
				$_SERVER['HTTP_HOST'] = basename( dirname( $cand ) ) . '.test';
			}
			if ( empty( $_SERVER['REQUEST_SCHEME'] ) ) {
				$_SERVER['REQUEST_SCHEME'] = 'http';
			}
			require_once $cand;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_CLI {

	/**
	 * Output helper for both WP_CLI and standalone PHP CLI.
	 *
	 * @param mixed $data
	 */
	private static function output( $data ) {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			if ( is_array( $data ) && isset( $data['success'] ) && ! $data['success'] ) {
				\WP_CLI::error( $data['message'] ?? wp_json_encode( $data ) );
			} else {
				\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			}
		} else {
			echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
			if ( is_array( $data ) && isset( $data['success'] ) && ! $data['success'] ) {
				exit( 1 );
			}
		}
	}

	/**
	 * Check connection status and remote server information.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync status
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		$res = VK7K_Sync_Runner::test_connection();
		if ( is_wp_error( $res ) ) {
			self::output( array( 'success' => false, 'message' => $res->get_error_message() ) );
			return;
		}
		self::output( $res );
	}

	/**
	 * Deploy local vk7k-wp-sync plugin code to remote server via REST API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync deploy-self
	 */
	public function deploy_self( $args = array(), $assoc_args = array() ) {
		$res = VK7K_Sync_Runner::deploy_plugin_to_remote_via_rest();
		if ( is_wp_error( $res ) ) {
			self::output( array( 'success' => false, 'message' => $res->get_error_message() ) );
			return;
		}
		self::output( $res );
	}

	/**
	 * Push a specific plugin's files to remote and optionally activate it.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin folder slug (e.g. vk7k-customer-reviews).
	 *
	 * [--activate]
	 * : Activate the plugin on the remote site after transferring files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync push-plugin vk7k-customer-reviews --activate
	 */
	public function push_plugin( $args = array(), $assoc_args = array() ) {
		$slug     = $args[0] ?? '';
		$activate = isset( $assoc_args['activate'] ) ? (bool) $assoc_args['activate'] : true;
		$res      = VK7K_Sync_Runner::push_plugin( $slug, $activate );
		self::output( $res );
	}

	/**
	 * Push one or more posts/pages/templates by ID (including postmeta and taxonomy terms).
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : One or more post IDs (space or comma separated).
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync push-post 7
	 *     wp vk7k-sync push-post 7,1164,1165
	 */
	public function push_post( $args = array(), $assoc_args = array() ) {
		$ids = array();
		foreach ( (array) $args as $arg ) {
			foreach ( explode( ',', (string) $arg ) as $part ) {
				$id = (int) trim( $part );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		$res = VK7K_Sync_Runner::push_posts( $ids );
		self::output( $res );
	}

	/**
	 * Push all posts of a given Custom Post Type (e.g. vk7k_review) to remote.
	 *
	 * ## OPTIONS
	 *
	 * <post_type>
	 * : Post type slug (e.g. vk7k_review).
	 *
	 * [--no-purge]
	 * : Do not purge existing remote posts of this post_type before inserting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync push-post-type vk7k_review
	 */
	public function push_post_type( $args = array(), $assoc_args = array() ) {
		$post_type = $args[0] ?? '';
		$purge     = empty( $assoc_args['no-purge'] );
		$res       = VK7K_Sync_Runner::push_post_type( $post_type, $purge );
		self::output( $res );
	}

	/**
	 * Push specific wp_options by option names or prefix to remote.
	 *
	 * ## OPTIONS
	 *
	 * <keys_or_prefix>
	 * : Comma-separated option names, or an option prefix when --prefix is passed.
	 *
	 * [--prefix]
	 * : Treat the argument as an option_name prefix (LIKE 'prefix%').
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync push-options vk7k_cr_ --prefix
	 */
	public function push_options( $args = array(), $assoc_args = array() ) {
		$target    = $args[0] ?? '';
		$is_prefix = ! empty( $assoc_args['prefix'] );
		$res       = VK7K_Sync_Runner::push_options( $target, $is_prefix );
		self::output( $res );
	}

	/**
	 * Push FSE design bundle (templates, template parts, global styles, navigation).
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync push-fse
	 */
	public function push_fse( $args = array(), $assoc_args = array() ) {
		$res = VK7K_Sync_Runner::step_sync_fse( array( 'mode' => 'push' ) );
		self::output( $res );
	}

	/**
	 * Run finalization on remote site (flush cache, rewrite rules, and link audit).
	 *
	 * ## EXAMPLES
	 *
	 *     wp vk7k-sync finalize
	 */
	public function finalize( $args = array(), $assoc_args = array() ) {
		$res = VK7K_Sync_Runner::step_finalize( array( 'mode' => 'push' ) );
		self::output( $res );
	}

	/**
	 * Direct PHP CLI runner when invoked outside WP-CLI.
	 *
	 * @param array $argv
	 */
	public static function run_direct_cli( $argv ) {
		$subcmd     = $argv[1] ?? 'status';
		$pos_args   = array();
		$assoc_args = array();

		for ( $i = 2; $i < count( $argv ); $i++ ) {
			$token = $argv[ $i ];
			if ( 0 === strpos( $token, '--' ) ) {
				$eq = strpos( $token, '=' );
				if ( false !== $eq ) {
					$k                = substr( $token, 2, $eq - 2 );
					$v                = substr( $token, $eq + 1 );
					$assoc_args[ $k ] = $v;
				} else {
					$k                = substr( $token, 2 );
					$assoc_args[ $k ] = true;
				}
			} else {
				$pos_args[] = $token;
			}
		}

		$instance = new self();
		$method   = str_replace( '-', '_', $subcmd );

		if ( method_exists( $instance, $method ) ) {
			$instance->$method( $pos_args, $assoc_args );
		} else {
			self::output(
				array(
					'success'            => false,
					'message'            => "Comando desconocido: {$subcmd}",
					'available_commands' => array(
						'status',
						'deploy-self',
						'push-plugin <slug> [--activate]',
						'push-post <id1,id2,...>',
						'push-post-type <post_type>',
						'push-options <prefix_or_keys> [--prefix]',
						'push-fse',
						'finalize',
					),
				)
			);
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'vk7k-sync', 'VK7K_Sync_CLI' );
} elseif ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	VK7K_Sync_CLI::run_direct_cli( $argv );
}
