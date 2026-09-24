<?php
/**
 * Plugin Name: VK7K WP Sync
 * Plugin URI: https://github.com/vk7k/vk7k-wp-sync
 * Description: Sincronización y clonación bidireccional (Push/Pull) de WordPress. Sincroniza bases de datos complejas (WooCommerce, postmeta, options) con Search & Replace serializado recursivo y transferencia de archivos por lotes (Uploads, Temas, Plugins) vía REST API autenticada por HMAC.
 * Version: 1.3.0
 * Author: Victor vk7k Mellado
 * Author URI: https://www.victormellado.cl
 * License: GPLv2 or later
 * Text Domain: vk7k-wp-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'VK7K_SYNC_VERSION', '1.3.0' );
define( 'VK7K_SYNC_FILE', __FILE__ );
define( 'VK7K_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'VK7K_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'VK7K_SYNC_BASENAME', plugin_basename( __FILE__ ) );

// Require includes
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-auth.php';
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-replacer.php';
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-db.php';
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-files.php';
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-api.php';
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-runner.php';
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-updater.php';
require_once VK7K_SYNC_PATH . 'includes/class-vk7k-sync-core.php';

if ( is_admin() && file_exists( VK7K_SYNC_PATH . 'admin/class-vk7k-sync-admin.php' ) ) {
	require_once VK7K_SYNC_PATH . 'admin/class-vk7k-sync-admin.php';
}

// Activation hook
register_activation_hook( __FILE__, array( 'VK7K_Sync_Core', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'VK7K_Sync_Core', 'on_deactivation' ) );

// Initialize plugin
function vk7k_wp_sync_init() {
	VK7K_Sync_Core::get_instance();
	VK7K_Sync_Updater::get_instance();
}
add_action( 'plugins_loaded', 'vk7k_wp_sync_init' );
