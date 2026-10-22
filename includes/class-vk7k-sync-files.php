<?php
/**
 * VK7K Sync Files Engine
 *
 * Handles cross-platform file normalization, selective plugin/theme/media filtering,
 * server-side queueing, and reliable chunked ZIP packaging and extraction.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Files {

	const TEMP_DIR_NAME   = 'vk7k-sync-temp';
	const QUEUE_FILE_NAME = 'file_queue.json';

	/**
	 * Get temporary storage directory path.
	 * Hardened with POSIX permissions and multi-webserver protection.
	 *
	 * @return string
	 */
	public static function get_temp_dir() {
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) ) . self::TEMP_DIR_NAME;

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
			@chmod( $temp_dir, 0700 );
		}

		self::secure_temp_dir( $temp_dir );

		return trailingslashit( $temp_dir );
	}

	/**
	 * Secure a directory against direct web disclosure and directory listing.
	 *
	 * @param string $dir
	 */
	public static function secure_temp_dir( $dir ) {
		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		$htaccess_code = "<IfModule mod_authz_core.c>\n" .
			"    Require all denied\n" .
			"</IfModule>\n" .
			"<IfModule !mod_authz_core.c>\n" .
			"    Deny from all\n" .
			"</IfModule>\n";

		if ( ! file_exists( $htaccess_file ) || false === strpos( (string) @file_get_contents( $htaccess_file ), 'Require all denied' ) ) {
			@file_put_contents( $htaccess_file, $htaccess_code );
			@chmod( $htaccess_file, 0644 );
		}

		$index_php = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_php ) ) {
			@file_put_contents( $index_php, "<?php\nhttp_response_code( 403 );\nexit;\n" );
			@chmod( $index_php, 0644 );
		}

		$index_html = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index_html ) ) {
			@file_put_contents( $index_html, '' );
			@chmod( $index_html, 0644 );
		}
	}

	/**
	 * Convert any full filesystem path to relative path from ABSPATH.
	 *
	 * @param string $full_path
	 * @return string
	 */
	public static function get_relative_path( $full_path ) {
		$normalized_full = wp_normalize_path( $full_path );
		$normalized_base = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 === strpos( $normalized_full, $normalized_base ) ) {
			return ltrim( substr( $normalized_full, strlen( $normalized_base ) ), '/' );
		}

		return ltrim( $normalized_full, '/' );
	}

	/**
	 * Convert a relative path to absolute filesystem path.
	 *
	 * @param string $rel_path
	 * @return string
	 */
	public static function get_absolute_path( $rel_path ) {
		$normalized_rel  = wp_normalize_path( $rel_path );
		$normalized_base = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 === strpos( $normalized_rel, $normalized_base ) ) {
			return $normalized_rel;
		}

		return $normalized_base . ltrim( $normalized_rel, '/' );
	}

	/**
	 * Get list of installed plugins with active status.
	 *
	 * @return array
	 */
	public static function get_available_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'active_plugins', 'options' );
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$result         = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$folder = dirname( $plugin_file );
			$slug   = ( '.' !== $folder ) ? $folder : $plugin_file;

			// Skip self
			if ( 'vk7k-wp-sync' === $slug || 0 === strpos( $plugin_file, 'vk7k-wp-sync/' ) ) {
				continue;
			}

			$is_active = in_array( $plugin_file, $active_plugins, true );
			if ( ! $is_active && function_exists( 'is_plugin_active' ) ) {
				$is_active = is_plugin_active( $plugin_file );
			}

			$result[] = array(
				'slug'      => $slug,
				'file'      => $plugin_file,
				'name'      => $plugin_data['Name'],
				'version'   => $plugin_data['Version'],
				'is_active' => (bool) $is_active,
			);
		}

		return $result;
	}

	/**
	 * Get list of installed themes with active status.
	 *
	 * @return array
	 */
	public static function get_available_themes() {
		$themes       = wp_get_themes();
		$active_theme = get_stylesheet();
		$result       = array();

		foreach ( $themes as $slug => $theme ) {
			$result[] = array(
				'slug'      => $slug,
				'name'      => $theme->get( 'Name' ),
				'version'   => $theme->get( 'Version' ),
				'is_active' => ( $slug === $active_theme ),
				'is_fse'    => $theme->is_block_theme(),
			);
		}

		return $result;
	}

	/**
	 * Save file sync queue to server-side JSON file.
	 *
	 * @param array $files
	 * @return bool
	 */
	public static function save_file_queue( $files ) {
		$temp_dir   = self::get_temp_dir();
		$queue_file = $temp_dir . self::QUEUE_FILE_NAME;
		return (bool) file_put_contents( $queue_file, wp_json_encode( array_values( $files ) ) );
	}

	/**
	 * Get file sync queue from server-side JSON file.
	 *
	 * @return array
	 */
	public static function get_file_queue() {
		$temp_dir   = self::get_temp_dir();
		$queue_file = $temp_dir . self::QUEUE_FILE_NAME;
		if ( file_exists( $queue_file ) ) {
			$raw  = file_get_contents( $queue_file );
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return array();
	}

	/**
	 * Clear file sync queue file.
	 */
	public static function clear_file_queue() {
		$temp_dir   = self::get_temp_dir();
		$queue_file = $temp_dir . self::QUEUE_FILE_NAME;
		if ( file_exists( $queue_file ) ) {
			@unlink( $queue_file );
		}
	}

	/**
	 * Generate file manifest with selective filters.
	 *
	 * @param array $components Array like array('uploads', 'plugins', 'themes', 'languages')
	 * @param array $options Filter options (selected_plugins, selected_themes, media_filter)
	 * @return array
	 */
	public static function generate_manifest( $components = array( 'uploads' ), $options = array() ) {
		$manifest = array();

		$selected_plugins  = isset( $options['selected_plugins'] ) ? (array) $options['selected_plugins'] : array();
		$allow_self_plugin = ! empty( $options['allow_self_plugin'] );
		$selected_themes  = isset( $options['selected_themes'] ) ? (array) $options['selected_themes'] : array();
		$media_filter     = isset( $options['media_filter'] ) ? sanitize_key( $options['media_filter'] ) : 'all';

		$paths_to_scan = array();

		// 1. Uploads
		if ( in_array( 'uploads', $components, true ) ) {
			$upload_dir = wp_upload_dir();
			$upload_base = wp_normalize_path( $upload_dir['basedir'] );

			if ( 'current_year' === $media_filter ) {
				$current_year_dir = $upload_base . '/' . date( 'Y' );
				if ( is_dir( $current_year_dir ) ) {
					$paths_to_scan['uploads'] = array( $current_year_dir );
				} else {
					$paths_to_scan['uploads'] = array( $upload_base );
				}
			} else {
				$paths_to_scan['uploads'] = array( $upload_base );
			}
		}

		// 2. Plugins
		if ( in_array( 'plugins', $components, true ) ) {
			$plugin_root = wp_normalize_path( WP_PLUGIN_DIR );
			$paths_to_scan['plugins'] = array();

			if ( ! empty( $selected_plugins ) && ! in_array( 'all', $selected_plugins, true ) ) {
				foreach ( $selected_plugins as $plugin_slug ) {
					$p_dir = $plugin_root . '/' . sanitize_file_name( $plugin_slug );
					if ( is_dir( $p_dir ) ) {
						$paths_to_scan['plugins'][] = $p_dir;
					} elseif ( is_file( $p_dir ) ) {
						// Single file plugin with .php extension
						$paths_to_scan['plugins'][] = $p_dir;
					} elseif ( is_file( $p_dir . '.php' ) ) {
						// Single file plugin without .php extension
						$paths_to_scan['plugins'][] = $p_dir . '.php';
					}
				}
			} else {
				$paths_to_scan['plugins'] = array( $plugin_root );
			}
		}

		// 3. Themes
		if ( in_array( 'themes', $components, true ) ) {
			$theme_root = wp_normalize_path( get_theme_root() );
			$paths_to_scan['themes'] = array();

			if ( ! empty( $selected_themes ) && ! in_array( 'all', $selected_themes, true ) ) {
				foreach ( $selected_themes as $theme_slug ) {
					$t_dir = $theme_root . '/' . sanitize_file_name( $theme_slug );
					if ( is_dir( $t_dir ) ) {
						$paths_to_scan['themes'][] = $t_dir;
					}
				}
			} else {
				$paths_to_scan['themes'] = array( $theme_root );
			}
		}

		// 4. Languages
		if ( in_array( 'languages', $components, true ) ) {
			$paths_to_scan['languages'] = array( wp_normalize_path( WP_LANG_DIR ) );
		}

		// Process all paths
		foreach ( $paths_to_scan as $type => $dir_list ) {
			foreach ( $dir_list as $base_path ) {
				if ( is_file( $base_path ) ) {
					$rel_path = self::get_relative_path( $base_path );
					if ( ! self::is_blacklisted( $rel_path, array( 'allow_self_plugin' => $allow_self_plugin ) ) ) {
						$manifest[ $rel_path ] = array(
							'size'  => filesize( $base_path ),
							'mtime' => filemtime( $base_path ),
							'hash'  => ( filesize( $base_path ) < 3 * 1024 * 1024 ) ? md5_file( $base_path ) : '',
						);
					}
					continue;
				}

				if ( ! is_dir( $base_path ) ) {
					continue;
				}

				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $base_path, RecursiveDirectoryIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $item ) {
					if ( $item->isDir() ) {
						continue;
					}

					$filepath = wp_normalize_path( $item->getPathname() );
					$rel_path = self::get_relative_path( $filepath );

					if ( self::is_blacklisted( $rel_path, array( 'allow_self_plugin' => $allow_self_plugin ) ) ) {
						continue;
					}

					$size  = $item->getSize();
					$mtime = $item->getMTime();

					$manifest[ $rel_path ] = array(
						'size'  => $size,
						'mtime' => $mtime,
						'hash'  => ( $size < 3 * 1024 * 1024 ) ? md5_file( $filepath ) : '',
					);
				}
			}
		}

		return $manifest;
	}

	/**
	 * Check if file path matches blacklist patterns.
	 *
	 * @param string $rel_path
	 * @return bool
	 */
	public static function is_blacklisted( $rel_path, $context = array() ) {
		$rel_path = wp_normalize_path( $rel_path );

		$blacklists = array(
			// The plugin's own code is excluded by default (it must never travel as part of a
			// generic push/pull). Deploying it is an explicit, authenticated operation, so
			// push_plugin() and the deploy handlers pass allow_self_plugin => true.
			'vk7k-wp-sync',
			// Build artifact: never ship it to another site.
			'vk7k-wp-sync.zip',
			'vk7k-sync-temp',
			'wp-content/cache/',
			'wp-content/upgrade/',
			'ai1wm-backups/',
			'wp-content/uploads/uploads.zip',
			'.wpress',
			'.sql',
			'.git/',
			'.github/',
			'.svn/',
			'node_modules/',
			'error_log',
			'wp-config.php',
			'.htaccess',
			'debug.log',
			'wp-content/uploads/wpforms/.cache',
			'.DS_Store',
			'Thumbs.db',
			'.vk7k_sync_vault',
			'preserved_admins',
		);

		if ( ! empty( $context['allow_self_plugin'] ) ) {
			$blacklists = array_values( array_diff( $blacklists, array( 'vk7k-wp-sync' ) ) );
		}

		foreach ( $blacklists as $pattern ) {
			if ( false !== strpos( $rel_path, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare source and destination manifests.
	 *
	 * @param array $source_manifest
	 * @param array $dest_manifest
	 * @return array Array of files to send
	 */
	public static function calculate_diff( $source_manifest, $dest_manifest ) {
		$files_to_send = array();

		foreach ( $source_manifest as $rel_path => $info ) {
			if ( ! isset( $dest_manifest[ $rel_path ] ) ) {
				$files_to_send[] = $rel_path;
			} else {
				$dest_info = $dest_manifest[ $rel_path ];
				if ( $info['size'] !== $dest_info['size'] ) {
					$files_to_send[] = $rel_path;
				} elseif ( ! empty( $info['hash'] ) && ! empty( $dest_info['hash'] ) && $info['hash'] !== $dest_info['hash'] ) {
					$files_to_send[] = $rel_path;
				}
			}
		}

		return $files_to_send;
	}

	/**
	 * Create a ZIP archive for a list of relative file paths.
	 *
	 * @param array $rel_paths Array of relative file paths.
	 * @param int   $max_bytes Max total uncompressed bytes before cutting chunk.
	 * @param int   $max_files Max files per zip chunk.
	 * @return array [ zip_file, included_files, remaining_files ]
	 */
	public static function create_zip_chunk( $rel_paths, $max_bytes = 4194304, $max_files = 80 ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'success' => false,
				'message' => 'La extensión PHP ZipArchive no está habilitada en este servidor.',
			);
		}

		$temp_dir = self::get_temp_dir();
		$zip_name = 'chunk_' . uniqid() . '.zip';
		$zip_path = $temp_dir . $zip_name;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return array(
				'success' => false,
				'message' => 'No se pudo crear el archivo ZIP temporal.',
			);
		}

		$total_bytes    = 0;
		$included_files = array();
		$remaining      = array();

		foreach ( $rel_paths as $idx => $rel_path ) {
			$abs_path = self::get_absolute_path( $rel_path );

			if ( file_exists( $abs_path ) && is_readable( $abs_path ) ) {
				$file_size = (int) filesize( $abs_path );

				if ( ! empty( $included_files ) && ( ( $total_bytes + $file_size ) > $max_bytes || count( $included_files ) >= $max_files ) ) {
					$remaining = array_slice( $rel_paths, $idx );
					break;
				}

				$zip->addFile( $abs_path, $rel_path );
				$total_bytes     += $file_size;
				$included_files[] = $rel_path;
			}
		}

		$zip->close();

		return array(
			'success'        => true,
			'zip_path'       => $zip_path,
			'zip_name'       => $zip_name,
			'zip_size'       => file_exists( $zip_path ) ? filesize( $zip_path ) : 0,
			'included_files' => $included_files,
			'remaining'      => $remaining,
			'is_last_chunk'  => empty( $remaining ),
		);
	}

	/**
	 * Build canonical cURL SFTP URL handling both absolute and relative filesystem paths.
	 * In libcurl, sftp://host:port//var/www/... (double slash) denotes an absolute path from filesystem root,
	 * whereas sftp://host:port/dir/... denotes a path relative to the user's home directory.
	 *
	 * @param string $host
	 * @param int    $port
	 * @param string $user
	 * @param string $pass
	 * @param string $path Remote WordPress base path
	 * @param string $rel_file Target file relative to remote WordPress base
	 * @return string
	 */
	public static function build_sftp_url( $host, $port, $user, $pass, $path, $rel_file ) {
		$port     = ! empty( $port ) ? (int) $port : 22;
		$path     = untrailingslashit( trim( (string) $path ) );
		$rel_file = ltrim( trim( (string) $rel_file ), '/' );

		if ( ! empty( $path ) ) {
			if ( 0 === strpos( $path, '/' ) ) {
				// Absolute path: double leading slash for cURL SFTP
				$full_remote_path = '/' . $path . '/' . $rel_file;
			} else {
				// Relative to home directory
				$full_remote_path = '/' . $path . '/' . $rel_file;
			}
		} else {
			$full_remote_path = '/' . $rel_file;
		}

		return sprintf(
			'sftp://%s:%s@%s:%d%s',
			rawurlencode( $user ),
			rawurlencode( $pass ),
			$host,
			$port,
			$full_remote_path
		);
	}

	/**
	 * Upload a local file via native cURL SFTP with missing directory auto-creation.
	 *
	 * @param string $local_file Path to local file
	 * @param string $remote_filename Target filename on remote
	 * @param array  $ssh_config [ host, port, user, pass, path ]
	 * @return array
	 */
	public static function upload_via_sftp( $local_file, $remote_filename, $ssh_config = array() ) {
		if ( ! file_exists( $local_file ) ) {
			return array(
				'success' => false,
				'message' => 'Archivo local no encontrado: ' . $local_file,
			);
		}

		$settings = VK7K_Sync_Auth::get_settings();
		$host = ! empty( $ssh_config['ssh_host'] ) ? $ssh_config['ssh_host'] : ( $settings['ssh_host'] ?? '' );
		$port = ! empty( $ssh_config['ssh_port'] ) ? (int) $ssh_config['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 );
		$user = ! empty( $ssh_config['ssh_user'] ) ? $ssh_config['ssh_user'] : ( $settings['ssh_user'] ?? '' );
		$pass = ! empty( $ssh_config['ssh_pass'] ) ? $ssh_config['ssh_pass'] : ( $settings['ssh_pass'] ?? '' );
		$path = ! empty( $ssh_config['ssh_path'] ) ? $ssh_config['ssh_path'] : ( $settings['ssh_path'] ?? '' );

		$remote_file_url = self::build_sftp_url(
			$host,
			$port,
			$user,
			$pass,
			$path,
			'wp-content/uploads/vk7k-sync-temp/' . $remote_filename
		);

		$filesize = filesize( $local_file );
		$fh = fopen( $local_file, 'rb' );
		if ( ! $fh ) {
			return array(
				'success' => false,
				'message' => 'No se pudo abrir el archivo local para lectura.',
			);
		}

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $remote_file_url );
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_INFILE, $fh );
		curl_setopt( $ch, CURLOPT_INFILESIZE, $filesize );
		curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP );
		curl_setopt( $ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 300 );
		curl_setopt( $ch, CURLOPT_FTP_CREATE_MISSING_DIRS, 1 );

		$res = curl_exec( $ch );
		$curl_err = curl_error( $ch );
		$curl_code = curl_errno( $ch );
		curl_close( $ch );
		fclose( $fh );

		if ( false === $res || 0 !== $curl_code ) {
			return array(
				'success' => false,
				'message' => 'Fallo en la subida SFTP: ' . ( $curl_err ?: "Código cURL {$curl_code}" ),
			);
		}

		return array(
			'success'         => true,
			'uploaded_size'   => $filesize,
			'remote_filename' => $remote_filename,
		);
	}

	/**
	 * Upload string buffer/content directly to a remote path via SFTP.
	 *
	 * @param string $content
	 * @param string $remote_rel_path (relative to remote WP root, e.g. 'wp-content/.vk7k_sync_vault.json')
	 * @param array  $ssh_config
	 * @return array
	 */
	public static function upload_buffer_via_sftp( $content, $remote_rel_path, $ssh_config = array() ) {
		$settings = VK7K_Sync_Auth::get_settings();
		$host = ! empty( $ssh_config['ssh_host'] ) ? $ssh_config['ssh_host'] : ( $settings['ssh_host'] ?? '' );
		$port = ! empty( $ssh_config['ssh_port'] ) ? (int) $ssh_config['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 );
		$user = ! empty( $ssh_config['ssh_user'] ) ? $ssh_config['ssh_user'] : ( $settings['ssh_user'] ?? '' );
		$pass = ! empty( $ssh_config['ssh_pass'] ) ? $ssh_config['ssh_pass'] : ( $settings['ssh_pass'] ?? '' );
		$path = ! empty( $ssh_config['ssh_path'] ) ? $ssh_config['ssh_path'] : ( $settings['ssh_path'] ?? '' );

		$remote_file_url = self::build_sftp_url(
			$host,
			$port,
			$user,
			$pass,
			$path,
			$remote_rel_path
		);

		$size = strlen( $content );
		$mem  = fopen( 'php://temp', 'r+b' );
		fwrite( $mem, $content );
		rewind( $mem );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $remote_file_url );
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_INFILE, $mem );
		curl_setopt( $ch, CURLOPT_INFILESIZE, $size );
		curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP );
		curl_setopt( $ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_FTP_CREATE_MISSING_DIRS, 1 );

		$res = curl_exec( $ch );
		$curl_err = curl_error( $ch );
		$curl_code = curl_errno( $ch );
		curl_close( $ch );
		fclose( $mem );

		if ( false === $res || 0 !== $curl_code ) {
			return array(
				'success' => false,
				'message' => 'Fallo en la subida SFTP de ' . $remote_rel_path . ': ' . ( $curl_err ?: "Código cURL {$curl_code}" ),
			);
		}

		return array(
			'success' => true,
			'path'    => $remote_rel_path,
			'size'    => $size,
		);
	}

	/**
	 * Delete a remote file via SFTP.
	 *
	 * @param string $remote_rel_path
	 * @param array  $ssh_config
	 * @return bool
	 */
	public static function delete_file_via_sftp( $remote_rel_path, $ssh_config = array() ) {
		$settings = VK7K_Sync_Auth::get_settings();
		$host = ! empty( $ssh_config['ssh_host'] ) ? $ssh_config['ssh_host'] : ( $settings['ssh_host'] ?? '' );
		$port = ! empty( $ssh_config['ssh_port'] ) ? (int) $ssh_config['ssh_port'] : (int) ( $settings['ssh_port'] ?? 22 );
		$user = ! empty( $ssh_config['ssh_user'] ) ? $ssh_config['ssh_user'] : ( $settings['ssh_user'] ?? '' );
		$pass = ! empty( $ssh_config['ssh_pass'] ) ? $ssh_config['ssh_pass'] : ( $settings['ssh_pass'] ?? '' );
		$path = ! empty( $ssh_config['ssh_path'] ) ? $ssh_config['ssh_path'] : ( $settings['ssh_path'] ?? '' );

		if ( empty( $host ) || empty( $user ) || empty( $path ) ) {
			return false;
		}

		$remote_file_url = self::build_sftp_url(
			$host,
			$port,
			$user,
			$pass,
			$path,
			''
		);

		$clean_path = '/' . ltrim( untrailingslashit( $path ) . '/' . ltrim( $remote_rel_path, '/' ), '/' );

		if ( preg_match( '/[\r\n"\'\\\\]/', $clean_path ) ) {
			return false;
		}

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $remote_file_url );
		curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP );
		curl_setopt( $ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_NOBODY, true );
		curl_setopt( $ch, CURLOPT_QUOTE, array( 'rm "' . $clean_path . '"' ) );

		curl_exec( $ch );
		$curl_code = curl_errno( $ch );
		curl_close( $ch );

		return ( 0 === $curl_code );
	}

	/**
	 * Extract ZIP archive onto destination filesystem.
	 *
	 * @param string $zip_path
	 * @return array
	 */
	public static function extract_zip_chunk( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'success' => false,
				'message' => 'Extensión ZipArchive no disponible.',
			);
		}

		if ( ! file_exists( $zip_path ) ) {
			return array(
				'success' => false,
				'message' => 'Archivo ZIP no encontrado en: ' . $zip_path,
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return array(
				'success' => false,
				'message' => 'No se pudo abrir el archivo ZIP recibido.',
			);
		}

		$extracted_count = 0;
		$base_dir        = trailingslashit( wp_normalize_path( ABSPATH ) );

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$filename = wp_normalize_path( $zip->getNameIndex( $i ) );

			if ( false !== strpos( $filename, '../' ) || false !== strpos( $filename, '..\\' ) ) {
				continue;
			}

			if ( self::is_blacklisted( $filename, array( 'allow_self_plugin' => true ) ) ) {
				continue;
			}

			if ( 0 === strpos( $filename, 'uploads/' ) ) {
				$upload_dir = wp_upload_dir();
				$rel_in_uploads = substr( $filename, strlen( 'uploads/' ) );
				$target_file = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) ) . ltrim( $rel_in_uploads, '/' );
				$allowed_root = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
			} else {
				$target_file = $base_dir . ltrim( $filename, '/' );
				$allowed_root = $base_dir;
			}

			// Validate path containment to prevent Zip Slip
			$norm_target = wp_normalize_path( $target_file );
			if ( 0 !== strpos( $norm_target, $allowed_root ) ) {
				continue;
			}

			$target_dir  = dirname( $target_file );

			if ( ! file_exists( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}

			$stream = $zip->getStream( $filename );
			if ( $stream ) {
				$dest_handle = @fopen( $target_file, 'w+b' );
				if ( $dest_handle ) {
					while ( ! feof( $stream ) ) {
						fwrite( $dest_handle, fread( $stream, 8192 ) );
					}
					fclose( $dest_handle );
					$extracted_count++;
				}
				fclose( $stream );
			}
		}

		$zip->close();
		@unlink( $zip_path );

		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		return array(
			'success'   => true,
			'extracted' => $extracted_count,
		);
	}

	/**
	 * Cleanup temp files safely, purging sensitive backups quickly while preserving directory protection files.
	 *
	 * @param bool $force Force removal of all temp files regardless of age.
	 */
	public static function cleanup_temp( $force = false ) {
		$temp_dir = self::get_temp_dir();
		if ( is_dir( $temp_dir ) ) {
			$files = glob( $temp_dir . '*' );
			if ( ! empty( $files ) ) {
				$protected_files = array( '.htaccess', 'index.php', 'index.html' );
				foreach ( $files as $f ) {
					$base = basename( $f );
					if ( in_array( $base, $protected_files, true ) ) {
						continue;
					}
					if ( is_file( $f ) ) {
						$age = time() - filemtime( $f );
						// Sensitive backups expire in 3 minutes; regular temp chunks expire in 10 minutes
						if ( $force || $age > 600 || ( in_array( $base, array( 'preserved_admins.json', 'preserved_options.json', 'preserved_admins.php', 'preserved_options.php' ), true ) && $age > 180 ) ) {
							@unlink( $f );
						}
					}
				}
			}
		}
	}

	/**
	 * Package and send a specific list of relative files (e.g. attachments) to remote.
	 *
	 * @param string $remote_url
	 * @param string $secret_key
	 * @param array  $rel_paths Array of relative paths under uploads/
	 * @param string $remote_ip Optional remote IP address for direct resolution.
	 * @return array|WP_Error
	 */
	public static function send_specific_files_to_remote( $remote_url, $secret_key, $rel_paths, $remote_ip = '' ) {
		if ( empty( $rel_paths ) || ! class_exists( 'ZipArchive' ) ) {
			return array( 'uploaded' => 0 );
		}

		$upload_dir = wp_upload_dir();
		$basedir    = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
		$temp_dir   = self::get_temp_dir();
		$zip_file   = $temp_dir . 'granular_media_' . time() . '.zip';

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_create_failed', 'No se pudo crear archivo ZIP para medios granulares.' );
		}

		$added = 0;
		foreach ( $rel_paths as $rel ) {
			$abs = $basedir . ltrim( $rel, '/' );
			if ( file_exists( $abs ) && is_readable( $abs ) ) {
				$zip->addFile( $abs, 'uploads/' . ltrim( $rel, '/' ) );
				$added++;
			}
		}

		$zip->close();

		if ( $added === 0 || ! file_exists( $zip_file ) ) {
			@unlink( $zip_file );
			return array( 'uploaded' => 0 );
		}

		// Upload chunk to remote via unified client
		$raw_data = file_get_contents( $zip_file );
		@unlink( $zip_file );

		$res = VK7K_Sync_Client::post_binary(
			'files/upload-chunk',
			$raw_data,
			$remote_url,
			$secret_key,
			$remote_ip
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'success'  => true,
			'uploaded' => $added,
		);
	}

	/**
	 * Pull specific media files from remote into local uploads directory.
	 *
	 * @param string $remote_url
	 * @param string $secret_key
	 * @param array  $media_files Array of media info arrays with 'rel_path' or array of relative paths.
	 * @param string $remote_ip   Optional remote IP address for direct resolution.
	 * @return array
	 */
	public static function pull_specific_files_from_remote( $remote_url, $secret_key, $media_files, $remote_ip = '' ) {
		if ( empty( $media_files ) ) {
			return array( 'success' => true, 'downloaded' => 0 );
		}

		$upload_dir = wp_upload_dir();
		$basedir    = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
		$downloaded = 0;

		$rel_paths = array();
		foreach ( $media_files as $mf ) {
			$rel = is_array( $mf ) ? ( $mf['rel_path'] ?? '' ) : $mf;
			if ( ! empty( $rel ) ) {
				$clean_rel = wp_normalize_path( $rel );
				if ( false !== strpos( $clean_rel, '..' ) || 0 === strpos( $clean_rel, '/' ) ) {
					continue;
				}
				$rel_paths[] = ltrim( $clean_rel, '/' );
			}
		}
		$rel_paths = array_unique( $rel_paths );

		// 1. Try batch download via download-chunk endpoint if possible
		$files_for_chunk = array();
		foreach ( $rel_paths as $rel ) {
			$files_for_chunk[] = 'wp-content/uploads/' . $rel;
		}

		$temp_dir = self::get_temp_dir();
		$temp_zip = $temp_dir . 'recv_granular_' . uniqid() . '.zip';

		$dl_res = VK7K_Sync_Client::stream_download(
			'files/download-chunk',
			array(
				'files'       => $files_for_chunk,
				'chunk_mb'    => 32,
				'chunk_files' => 200,
				'stream'      => true,
			),
			$temp_zip,
			$remote_url,
			$secret_key,
			$remote_ip
		);

		if ( ! is_wp_error( $dl_res ) && file_exists( $temp_zip ) && filesize( $temp_zip ) > 0 ) {
			$ext_res = self::extract_zip_chunk( $temp_zip );
			if ( ! empty( $ext_res['success'] ) ) {
				$downloaded = (int) ( $ext_res['extracted'] ?? count( $rel_paths ) );
			}
		}
		@unlink( $temp_zip );

		// 2. Fallback / Verification: ensure each file exists locally, download directly if missing
		$remote_clean_url = untrailingslashit( $remote_url );
		foreach ( $rel_paths as $rel ) {
			$clean_rel = wp_normalize_path( $rel );
			if ( false !== strpos( $clean_rel, '..' ) ) {
				continue;
			}
			$local_file = wp_normalize_path( $basedir . ltrim( $clean_rel, '/' ) );
			if ( 0 !== strpos( $local_file, $basedir ) ) {
				continue;
			}

			if ( ! file_exists( $local_file ) || 0 === filesize( $local_file ) ) {
				$remote_file_url = $remote_clean_url . '/wp-content/uploads/' . ltrim( $clean_rel, '/' );
				$res = wp_safe_remote_get( $remote_file_url, array(
					'timeout'   => 30,
					'sslverify' => false,
				) );

				if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
					$body = wp_remote_retrieve_body( $res );
					if ( ! empty( $body ) ) {
						$target_dir = dirname( $local_file );
						if ( ! file_exists( $target_dir ) ) {
							wp_mkdir_p( $target_dir );
						}
						if ( false !== @file_put_contents( $local_file, $body ) ) {
							$downloaded++;
						}
					}
				}
			}
		}

		return array(
			'success'    => true,
			'downloaded' => $downloaded,
		);
	}
}
