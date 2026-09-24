<?php
/**
 * VK7K WP Sync GitHub Releases Updater.
 *
 * Integrates WordPress core update mechanism with official GitHub Releases.
 *
 * @package VK7K_WP_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Updater {

	/**
	 * Singleton instance.
	 *
	 * @var VK7K_Sync_Updater|null
	 */
	private static $instance = null;

	/**
	 * GitHub repository in 'owner/repo' format.
	 *
	 * @var string
	 */
	private $repo = 'vk7k/vk7k-wp-sync';

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $slug = 'vk7k-wp-sync';

	/**
	 * Plugin basename (e.g. vk7k-wp-sync/vk7k-wp-sync.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Transient cache key for release data.
	 *
	 * @var string
	 */
	private $transient_key = 'vk7k_sync_github_release_data';

	/**
	 * Cache TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	private $cache_ttl = 43200;

	/**
	 * Get singleton instance.
	 *
	 * @return VK7K_Sync_Updater
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
		$this->plugin_basename = defined( 'VK7K_SYNC_BASENAME' ) ? VK7K_SYNC_BASENAME : 'vk7k-wp-sync/vk7k-wp-sync.php';
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress filters and actions.
	 */
	private function init_hooks() {
		// Hook into WordPress plugin update checks
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ) );

		// Hook into plugin information popup (modal "Ver detalles")
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 20, 3 );

		// Normalize unzipped folder name from GitHub releases/zipballs
		add_filter( 'upgrader_source_selection', array( $this, 'filter_upgrader_source_selection' ), 10, 4 );

		// Clear cached release data upon successful update
		add_action( 'upgrader_process_complete', array( $this, 'action_upgrader_process_complete' ), 10, 2 );

		// Quick plugin action links
		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'filter_plugin_action_links' ) );

		// Handle manual "Force check updates" query var
		add_action( 'admin_init', array( $this, 'action_maybe_force_check' ) );
	}

	/**
	 * Maybe force check updates if requested by admin.
	 */
	public function action_maybe_force_check() {
		if ( isset( $_GET['vk7k_check_updates'] ) && current_user_can( 'update_plugins' ) ) {
			check_admin_referer( 'vk7k_force_update_check' );
			delete_site_transient( $this->transient_key );
			delete_site_transient( 'update_plugins' );
			wp_safe_redirect( remove_query_arg( array( 'vk7k_check_updates', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Add custom links to Plugins page row.
	 *
	 * @param array $links
	 * @return array
	 */
	public function filter_plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=vk7k-wp-sync' );
		$check_url    = wp_nonce_url( admin_url( 'plugins.php?vk7k_check_updates=1' ), 'vk7k_force_update_check' );

		$custom_links = array(
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Sincronizador', 'vk7k-wp-sync' ) . '</a>',
			'<a href="' . esc_url( $check_url ) . '" title="' . esc_attr__( 'Consultar última versión en GitHub Releases', 'vk7k-wp-sync' ) . '">' . esc_html__( 'Buscar actualizaciones', 'vk7k-wp-sync' ) . '</a>',
		);

		return array_merge( $custom_links, $links );
	}

	/**
	 * Fetch latest release from GitHub API with caching.
	 *
	 * @param bool $force_refresh
	 * @return array|false
	 */
	public function get_latest_release( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_site_transient( $this->transient_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$api_url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$args    = array(
			'timeout'    => 10,
			'headers'    => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/VK7K-WP-Sync-' . ( defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.3.0' ),
			),
			'sslverify'  => true,
		);

		$response = wp_remote_get( $api_url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body ) || ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return false;
		}

		// Extract version number from tag (e.g. 'v1.3.0' -> '1.3.0')
		$version = ltrim( trim( $body['tag_name'] ), 'vV' );

		// Determine package download URL.
		// Prefer custom built asset ZIP (e.g. 'vk7k-wp-sync.zip') if available, otherwise fallback to zipball_url.
		$download_url = $body['zipball_url'] ?? '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/vk7k-wp-sync.*\.zip$/i', $asset['name'] ) ) {
					$download_url = $asset['browser_download_url'];
					break;
				}
			}
		}

		$release_data = array(
			'tag_name'     => $body['tag_name'],
			'version'      => $version,
			'name'         => $body['name'] ?? ( 'VK7K WP Sync v' . $version ),
			'body'         => $body['body'] ?? '',
			'download_url' => $download_url,
			'html_url'     => $body['html_url'] ?? ( 'https://github.com/' . $this->repo . '/releases' ),
			'published_at' => $body['published_at'] ?? '',
		);

		set_site_transient( $this->transient_key, $release_data, $this->cache_ttl );
		return $release_data;
	}

	/**
	 * Inject update payload into WordPress update_plugins transient.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function filter_update_plugins_transient( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$force   = isset( $_GET['force-check'] );
		$release = $this->get_latest_release( $force );

		if ( ! $release || empty( $release['version'] ) || empty( $release['download_url'] ) ) {
			return $transient;
		}

		$current_ver = defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.0.0';

		if ( version_compare( $release['version'], $current_ver, '>' ) ) {
			$item = (object) array(
				'id'            => $this->plugin_basename,
				'slug'          => $this->slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $release['version'],
				'url'           => $release['html_url'],
				'package'       => $release['download_url'],
				'icons'         => array(),
				'banners'       => array(),
				'tested'        => '6.7.2',
				'requires_php'  => '7.4',
				'compatibility' => new stdClass(),
			);

			$transient->response[ $this->plugin_basename ] = $item;
			if ( isset( $transient->no_update[ $this->plugin_basename ] ) ) {
				unset( $transient->no_update[ $this->plugin_basename ] );
			}
		} else {
			$item = (object) array(
				'id'            => $this->plugin_basename,
				'slug'          => $this->slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $current_ver,
				'url'           => $release['html_url'],
				'package'       => '',
			);
			$transient->no_update[ $this->plugin_basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Return rich plugin information modal for "Ver detalles de la versión".
	 *
	 * @param false|object|array $res
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function filter_plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $res;
		}

		$release     = $this->get_latest_release();
		$current_ver = defined( 'VK7K_SYNC_VERSION' ) ? VK7K_SYNC_VERSION : '1.3.0';
		$latest_ver  = ( $release && ! empty( $release['version'] ) ) ? $release['version'] : $current_ver;
		$notes       = ( $release && ! empty( $release['body'] ) ) ? nl2br( esc_html( $release['body'] ) ) : 'Consulta los detalles en el repositorio oficial de GitHub.';

		$info                = new stdClass();
		$info->name          = 'VK7K WP Sync';
		$info->slug          = $this->slug;
		$info->version       = $latest_ver;
		$info->author        = '<a href="https://www.victormellado.cl" target="_blank" rel="noopener noreferrer">Victor vk7k Mellado</a>';
		$info->homepage      = 'https://github.com/' . $this->repo;
		$info->requires      = '5.8';
		$info->tested        = '6.7';
		$info->requires_php  = '7.4';
		$info->last_updated  = $release['published_at'] ?? '';
		$info->download_link = $release['download_url'] ?? '';
		$info->sections      = array(
			'description' => '<p>Plugin de sincronización y clonación de sitios WordPress (Push/Pull) perteneciente a la Suite VK7K.</p>' .
				'<ul>' .
				'<li>Sincronización bidireccional de Base de Datos con Search & Replace serializado recursivo.</li>' .
				'<li>Transferencia de archivos diferenciales (Uploads, Temas, Plugins).</li>' .
				'<li>Autenticación criptográfica segura HMAC-SHA256 con nonces y timestamps.</li>' .
				'<li>Actualizaciones oficiales integradas mediante GitHub Releases.</li>' .
				'</ul>',
			'changelog'   => '<h4>Notas de la versión ' . esc_html( $latest_ver ) . '</h4>' . $notes,
		);

		return $info;
	}

	/**
	 * Normalize extracted directory name so WordPress doesn't nest or rename the plugin folder.
	 *
	 * When downloaded from GitHub zipball, directory might be named 'vk7k-vk7k-wp-sync-xxxx/'.
	 * This ensures it remains 'vk7k-wp-sync/'.
	 *
	 * @param string      $source
	 * @param string      $remote_source
	 * @param WP_Upgrader $upgrader
	 * @param array       $hook_extra
	 * @return string
	 */
	public function filter_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		$expected_folder = $this->slug;
		$current_folder  = basename( untrailingslashit( $source ) );

		if ( $current_folder !== $expected_folder ) {
			$correct_destination = trailingslashit( $remote_source ) . $expected_folder;
			if ( $wp_filesystem->move( $source, $correct_destination ) ) {
				return trailingslashit( $correct_destination );
			}
		}

		return $source;
	}

	/**
	 * Clear cache upon update completion.
	 *
	 * @param WP_Upgrader $upgrader
	 * @param array       $hook_extra
	 */
	public function action_upgrader_process_complete( $upgrader, $hook_extra ) {
		if ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin_basename ) {
			delete_site_transient( $this->transient_key );
			delete_site_transient( 'update_plugins' );
		}
	}
}
