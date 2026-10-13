<?php
/**
 * VK7K Sync Database Engine
 *
 * Handles table discovery, selective component filtering, chunked schema & data extraction,
 * safe batch SQL injection, and prefix mapping.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_DB {

	/**
	 * Get persistent storage directory for sync temp data.
	 *
	 * @return string
	 */
	public static function get_temp_storage_dir() {
		return VK7K_Sync_Files::get_temp_dir();
	}

	/**
	 * Backup all local administrators and their session tokens to persistent file storage.
	 *
	 * @return bool
	 */
	public static function backup_admin_users() {
		global $wpdb;

		$dir  = self::get_temp_storage_dir();
		$file = $dir . 'preserved_admins.json';

		// If already backed up in this sync session, don't overwrite
		if ( file_exists( $file ) && filesize( $file ) > 10 ) {
			return true;
		}

		// Find ALL local administrators
		$admin_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$wpdb->prefix . 'capabilities',
				'%administrator%'
			)
		);

		if ( empty( $admin_ids ) ) {
			$admin_ids = array();
		}

		// Also include current logged in user if not in list
		$curr = wp_get_current_user();
		if ( ! empty( $curr->ID ) && ! in_array( (int) $curr->ID, array_map( 'intval', $admin_ids ), true ) ) {
			$admin_ids[] = $curr->ID;
		}

		$preserved = array();
		foreach ( $admin_ids as $id ) {
			$id = (int) $id;
			$user_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $id ), ARRAY_A );
			if ( empty( $user_row ) ) {
				continue;
			}
			$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $id ), ARRAY_A );

			$preserved[] = array(
				'user'     => $user_row,
				'usermeta' => $meta_rows,
			);
		}

		if ( ! empty( $preserved ) ) {
			@file_put_contents( $file, wp_json_encode( $preserved ) );
			@chmod( $file, 0600 );
			return true;
		}

		return false;
	}

	/**
	 * Backward compatibility alias for backup_admin_users.
	 */
	public static function backup_current_admin_user() {
		return self::backup_admin_users();
	}

	/**
	 * Re-inject / merge preserved local administrators and session tokens into target tables.
	 *
	 * @param string $table_prefix Target table prefix (e.g. '_vk7k_tmp_wp_' or 'wp_').
	 * @return bool
	 */
	public static function restore_admin_users( $table_prefix = '' ) {
		global $wpdb;

		$pfx = ! empty( $table_prefix ) ? $table_prefix : $wpdb->prefix;
		$users_tbl    = "{$pfx}users";
		$usermeta_tbl = "{$pfx}usermeta";

		$dir  = self::get_temp_storage_dir();
		$file = trailingslashit( $dir ) . 'preserved_admins.json';

		if ( ! file_exists( $file ) ) {
			return false;
		}

		$content = @file_get_contents( $file );
		if ( empty( $content ) ) {
			return false;
		}

		$preserved = json_decode( $content, true );
		if ( empty( $preserved ) || ! is_array( $preserved ) ) {
			return false;
		}

		$all_live_tables = (array) $wpdb->get_col( "SHOW TABLES" );
		$matched_users   = '';
		$matched_meta    = '';
		foreach ( $all_live_tables as $t ) {
			if ( 0 === strcasecmp( $t, $users_tbl ) ) {
				$matched_users = $t;
			}
			if ( 0 === strcasecmp( $t, $usermeta_tbl ) ) {
				$matched_meta = $t;
			}
		}

		if ( empty( $matched_users ) ) {
			return false;
		}

		$users_tbl    = $matched_users;
		$usermeta_tbl = ! empty( $matched_meta ) ? $matched_meta : $usermeta_tbl;
		$exists_meta  = ! empty( $matched_meta ) ? $matched_meta : '';

		foreach ( $preserved as $item ) {
			$u         = $item['user'];
			$meta_rows = isset( $item['usermeta'] ) ? $item['usermeta'] : array();
			$login     = $u['user_login'];
			$orig_id   = (int) $u['ID'];

			// Check if a user with this login already exists in target table
			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM `{$users_tbl}` WHERE user_login = %s", $login ) );

			if ( ! empty( $existing_id ) ) {
				$target_id = (int) $existing_id;
				$u_update  = $u;
				$u_update['ID'] = $target_id;
				$wpdb->update( $users_tbl, $u_update, array( 'ID' => $target_id ) );
			} else {
				// Check if the original ID is taken by someone else
				$id_taken = $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM `{$users_tbl}` WHERE ID = %d", $orig_id ) );
				if ( ! empty( $id_taken ) ) {
					$max_id    = (int) $wpdb->get_var( "SELECT MAX(ID) FROM `{$users_tbl}`" );
					$target_id = max( $max_id + 1, 1000 );
					$u['ID']   = $target_id;
				} else {
					$target_id = $orig_id;
				}
				$wpdb->insert( $users_tbl, $u );
			}

			// Restore usermeta (including session tokens, capabilities, user_level)
			if ( ! empty( $meta_rows ) && $exists_meta === $usermeta_tbl ) {
				foreach ( $meta_rows as $meta ) {
					$k = $meta['meta_key'];
					$v = $meta['meta_value'];

					// If staging prefix is used, adapt the capabilities prefix
					if ( 0 === strpos( $pfx, '_vk7k_tmp_' ) ) {
						$real_pfx = substr( $pfx, strlen( '_vk7k_tmp_' ) );
						if ( $real_pfx !== $wpdb->prefix && strpos( $k, $wpdb->prefix ) === 0 ) {
							$k = str_replace( $wpdb->prefix, $real_pfx, $k );
						}
					}

					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$usermeta_tbl}` WHERE user_id = %d AND meta_key = %s", $target_id, $k ) );
					$wpdb->insert( $usermeta_tbl, array(
						'user_id'    => $target_id,
						'meta_key'   => $k,
						'meta_value' => $v,
					) );
				}
			}
		}

		// Securely purge temporary preserved admins file
		@unlink( $file );

		return true;
	}

	/**
	 * Backward compatibility alias for restore_admin_users.
	 */
	public static function restore_current_admin_user( $table_prefix = '' ) {
		return self::restore_admin_users( $table_prefix );
	}

	/**
	 * Backup critical local options to persistent file storage.
	 *
	 * @return bool
	 */
	public static function backup_preserved_options() {
		$dir  = self::get_temp_storage_dir();
		$file = trailingslashit( $dir ) . 'preserved_options.json';

		if ( file_exists( $file ) && filesize( $file ) > 10 ) {
			return true;
		}

		$current_settings = get_option( 'vk7k_sync_settings', array() );
		$current_targets  = get_option( 'vk7k_sync_targets', array() );
		$current_vault    = get_option( 'vk7k_sync_pin_vault', array() );
		$active_plugins   = get_option( 'active_plugins', array() );

		if ( ! empty( $current_settings ) && is_array( $current_settings ) && ! empty( $current_settings['local_secret_key'] ) ) {
			VK7K_Sync_Auth::save_vault( $current_settings );
		}

		$data = array(
			'vk7k_sync_settings'  => $current_settings,
			'vk7k_sync_targets'   => $current_targets,
			'vk7k_sync_pin_vault' => $current_vault,
			'active_plugins'      => $active_plugins,
		);

		@file_put_contents( $file, wp_json_encode( $data ) );
		@chmod( $file, 0600 );
		return true;
	}

	/**
	 * Restore preserved options directly into staged options table before swap.
	 *
	 * @param string $stage_table Name of the staged options table.
	 * @return bool
	 */
	public static function restore_preserved_options( $stage_table ) {
		global $wpdb;

		$dir  = self::get_temp_storage_dir();
		$file = trailingslashit( $dir ) . 'preserved_options.json';

		if ( ! file_exists( $file ) ) {
			return false;
		}

		$content = @file_get_contents( $file );
		if ( empty( $content ) ) {
			return false;
		}

		$data = json_decode( $content, true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		foreach ( array( 'vk7k_sync_settings', 'vk7k_sync_targets', 'vk7k_sync_pin_vault' ) as $opt_key ) {
			if ( ! empty( $data[ $opt_key ] ) ) {
				$val     = $data[ $opt_key ];
				$val_str = is_array( $val ) || is_object( $val ) ? serialize( $val ) : $val;
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$stage_table}` WHERE option_name = %s", $opt_key ) );
				$wpdb->insert( $stage_table, array(
					'option_name'  => $opt_key,
					'option_value' => $val_str,
					'autoload'     => 'yes',
				) );
			}
		}

		// Ensure our plugin vk7k-wp-sync is ALWAYS preserved in active_plugins
		$staged_active = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$stage_table}` WHERE option_name = 'active_plugins'" ) );
		$plugins_arr   = ! empty( $staged_active ) ? @unserialize( $staged_active ) : array();
		if ( ! is_array( $plugins_arr ) ) {
			$plugins_arr = array();
		}
		if ( ! in_array( 'vk7k-wp-sync/vk7k-wp-sync.php', $plugins_arr, true ) ) {
			$plugins_arr[] = 'vk7k-wp-sync/vk7k-wp-sync.php';
			$wpdb->update(
				$stage_table,
				array( 'option_value' => serialize( $plugins_arr ) ),
				array( 'option_name' => 'active_plugins' )
			);
		}

		// Purge remote transients to prevent poisoning local paths or cache
		$wpdb->query( "DELETE FROM `{$stage_table}` WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" );

		// Securely purge temporary preserved options file
		@unlink( $file );

		return true;
	}

	/**
	 * Check if an option name is an internal VK7K sync option that must never be exported/overwritten.
	 *
	 * @param string $opt_name
	 * @return bool
	 */
	public static function is_internal_option( $opt_name ) {
		if ( empty( $opt_name ) || ! is_string( $opt_name ) ) {
			return false;
		}
		return (
			0 === strpos( $opt_name, 'vk7k_sync_' ) ||
			0 === strpos( $opt_name, '_vk7k_sync_' ) ||
			0 === strpos( $opt_name, '_transient_vk7k_sync_' ) ||
			0 === strpos( $opt_name, '_transient_timeout_vk7k_sync_' )
		);
	}

	/**
	 * Detect if WordPress content or core lives in a subdirectory (e.g. /wp or /wp26).
	 * Checks siteurl vs home, content_url vs home, and samples database post content and attachments.
	 *
	 * @return array Array of unique detected subdirectory names (e.g. ['wp26'])
	 */
	public static function detect_content_subdirectories() {
		global $wpdb;

		$subdirs = array();

		// 1. Check difference between site_url() and home_url()
		$site_path = trim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( ! empty( $site_path ) && $site_path !== $home_path ) {
			$subdirs[] = $site_path;
		}

		// 2. Check content_url() vs home_url()
		$content_url = content_url();
		$home_url    = home_url();
		$home_host   = wp_parse_url( $home_url, PHP_URL_HOST );
		if ( ! empty( $home_host ) && preg_match( '#https?://' . preg_quote( $home_host, '#' ) . '(/[^/\\s"\']+)?/wp-content#i', $content_url, $cm ) ) {
			if ( ! empty( $cm[1] ) ) {
				$sub = trim( $cm[1], '/' );
				if ( ! empty( $sub ) && ! in_array( strtolower( $sub ), array( 'wp-content', 'wp-includes', 'wp-admin' ), true ) ) {
					$subdirs[] = $sub;
				}
			}
		}

		// 3. Sample database (wp_posts and attachment guids) for any recurring subdirectories before /wp-content/
		if ( isset( $wpdb->posts ) ) {
			$samples = $wpdb->get_col(
				"SELECT post_content FROM {$wpdb->posts} 
				 WHERE post_content LIKE '%/wp-content/%' 
				 ORDER BY ID DESC LIMIT 50"
			);
			$guid_samples = $wpdb->get_col(
				"SELECT guid FROM {$wpdb->posts} 
				 WHERE post_type = 'attachment' AND guid LIKE '%/wp-content/%' 
				 ORDER BY ID DESC LIMIT 30"
			);
			$all_samples = array_merge( (array) $samples, (array) $guid_samples );

			$counts = array();
			foreach ( $all_samples as $text ) {
				if ( empty( $text ) ) {
					continue;
				}
				if ( preg_match_all( '#https?://[^/\\s"\']+(/[a-zA-Z0-9_\\.\\-]+)/wp-content/#i', $text, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $m ) {
						$sub = trim( $m[1], '/' );
						if ( ! empty( $sub ) && ! in_array( strtolower( $sub ), array( 'http', 'https', 'wp-content', 'wp-includes', 'wp-admin' ), true ) ) {
							$counts[ $sub ] = ( $counts[ $sub ] ?? 0 ) + 1;
						}
					}
				}
			}

			// If any subdirectory appears more than once in the sample, consider it detected
			foreach ( $counts as $sub => $cnt ) {
				if ( $cnt >= 2 && ! in_array( $sub, $subdirs, true ) ) {
					$subdirs[] = $sub;
				}
			}
		}

		return array_values( array_unique( $subdirs ) );
	}

	/**
	 * Get all tables in database belonging to WordPress or custom plugins with filtering.
	 *
	 * @param array $options Filter options (preserve_users, exclude_logs, selected_components)
	 * @return array Array of table info [ name, rows, size_mb, is_schema_only ]
	 */
	public static function get_tables( $options = array() ) {
		global $wpdb;

		$prefix       = $wpdb->prefix;
		$prefix_lower = strtolower( $prefix );

		// Support positive flags (default: true for content/orders/users, false for heavy logs)
		// Also support legacy flags if passed
		$sync_content    = isset( $options['sync_content'] ) ? (bool) $options['sync_content'] : true;
		$sync_options    = isset( $options['sync_options'] ) ? (bool) $options['sync_options'] : true;
		$sync_taxonomies = isset( $options['sync_taxonomies'] ) ? (bool) $options['sync_taxonomies'] : true;
		$sync_comments   = isset( $options['sync_comments'] ) ? (bool) $options['sync_comments'] : true;
		$sync_wc_orders  = isset( $options['sync_wc_orders'] ) ? (bool) $options['sync_wc_orders'] : ( isset( $options['preserve_orders'] ) ? ! $options['preserve_orders'] : true );
		$sync_users      = isset( $options['sync_users'] ) ? (bool) $options['sync_users'] : ( isset( $options['preserve_users'] ) ? ! $options['preserve_users'] : true );
		$sync_logs       = isset( $options['sync_logs'] ) ? (bool) $options['sync_logs'] : ( isset( $options['exclude_logs'] ) ? ! $options['exclude_logs'] : false );

		$results = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
		$tables  = array();

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$table_name       = $row['Name'];
				$table_name_lower = strtolower( $table_name );

				// Skip temporary backup or staging tables
				if ( 0 === strpos( $table_name_lower, '_vk7k_bak_' ) || 0 === strpos( $table_name_lower, '_vk7k_tmp_' ) || 0 === strpos( $table_name_lower, '_vk7k_old_' ) ) {
					continue;
				}

				// Check Users & Meta
				if ( ! $sync_users && (
					$table_name_lower === "{$prefix_lower}users" ||
					$table_name_lower === "{$prefix_lower}usermeta" ||
					$table_name_lower === "{$prefix_lower}wc_customer_lookup"
				) ) {
					continue;
				}

				// Check WooCommerce Orders & Transactions
				if ( ! $sync_wc_orders ) {
					if (
						0 === strpos( $table_name_lower, "{$prefix_lower}wc_order" ) ||
						$table_name_lower === "{$prefix_lower}wc_orders" ||
						$table_name_lower === "{$prefix_lower}wc_orders_meta" ||
						$table_name_lower === "{$prefix_lower}wc_order_addresses" ||
						$table_name_lower === "{$prefix_lower}wc_order_operational_data" ||
						$table_name_lower === "{$prefix_lower}wc_order_coupon_lookup" ||
						$table_name_lower === "{$prefix_lower}wc_order_product_lookup" ||
						$table_name_lower === "{$prefix_lower}wc_order_stats" ||
						$table_name_lower === "{$prefix_lower}wc_order_tax_lookup" ||
						0 === strpos( $table_name_lower, "{$prefix_lower}woocommerce_order" ) ||
						$table_name_lower === "{$prefix_lower}woocommerce_payment_tokens" ||
						$table_name_lower === "{$prefix_lower}woocommerce_payment_tokenmeta" ||
						$table_name_lower === "{$prefix_lower}woocommerce_sessions" ||
						$table_name_lower === "{$prefix_lower}woocommerce_downloadable_product_permissions"
					) {
						continue;
					}
				}

				// Check General Content (Posts / Postmeta)
				if ( ! $sync_content && (
					$table_name_lower === "{$prefix_lower}posts" ||
					$table_name_lower === "{$prefix_lower}postmeta"
				) ) {
					continue;
				}

				// Check Options
				if ( ! $sync_options && (
					$table_name_lower === "{$prefix_lower}options"
				) ) {
					continue;
				}

				// Check Taxonomies
				if ( ! $sync_taxonomies && (
					$table_name_lower === "{$prefix_lower}terms" ||
					$table_name_lower === "{$prefix_lower}term_taxonomy" ||
					$table_name_lower === "{$prefix_lower}term_relationships" ||
					$table_name_lower === "{$prefix_lower}termmeta"
				) ) {
					continue;
				}

				// Check Comments
				if ( ! $sync_comments && (
					$table_name_lower === "{$prefix_lower}comments" ||
					$table_name_lower === "{$prefix_lower}commentmeta"
				) ) {
					continue;
				}

				// Include tables that match prefix or custom WP tables
				if ( 0 === strpos( $table_name_lower, $prefix_lower ) || false !== strpos( $table_name_lower, 'woocommerce' ) || false !== strpos( $table_name_lower, 'actionscheduler' ) ) {
					$data_size  = isset( $row['Data_length'] ) ? (float) $row['Data_length'] : 0;
					$index_size = isset( $row['Index_length'] ) ? (float) $row['Index_length'] : 0;
					$size_mb    = round( ( $data_size + $index_size ) / ( 1024 * 1024 ), 2 );
					$row_count  = isset( $row['Rows'] ) ? (int) $row['Rows'] : 0;

					// Check if this table is a transient log table (schema only if sync_logs is false)
					$is_schema_only = false;
					if ( ! $sync_logs && (
						$table_name_lower === "{$prefix_lower}actionscheduler_logs" ||
						$table_name_lower === "{$prefix_lower}actionscheduler_actions" ||
						$table_name_lower === "{$prefix_lower}actionscheduler_claims" ||
						$table_name_lower === "{$prefix_lower}woocommerce_log" ||
						$table_name_lower === "{$prefix_lower}wpmailsmtp_debug_events" ||
						$table_name_lower === "{$prefix_lower}wpmailsmtp_tasks_meta"
					) ) {
						$is_schema_only = true;
					}

					$canonical_table_name = $table_name;
					if ( 0 === strpos( $table_name_lower, $prefix_lower ) ) {
						$canonical_table_name = substr_replace( $table_name, $prefix, 0, strlen( $prefix ) );
					}

					$tables[] = array(
						'name'           => $canonical_table_name,
						'rows'           => $is_schema_only ? 0 : $row_count,
						'real_rows'      => $row_count,
						'size_mb'        => $size_mb,
						'is_schema_only' => $is_schema_only,
					);
				}
			}
		}

		return $tables;
	}

	/**
	 * Get CREATE TABLE statement for a specific table.
	 *
	 * @param string $table_name
	 * @return string|false
	 */
	public static function get_table_schema( $table_name ) {
		global $wpdb;
		$table_name = sanitize_text_field( $table_name );
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N );
		if ( ! empty( $row[1] ) ) {
			return $row[1];
		}
		return false;
	}

	/**
	 * Export a chunk of rows from a table with Search & Replace applied.
	 *
	 * @param string $table_name
	 * @param int $offset
	 * @param int $limit
	 * @param VK7K_Sync_Replacer|null $replacer
	 * @param string $target_prefix
	 * @param bool $is_schema_only
	 * @return array
	 */
	public static function export_table_chunk( $table_name, $offset = 0, $limit = 1000, $replacer = null, $target_prefix = '', $is_schema_only = false ) {
		global $wpdb;
		$table_name = sanitize_text_field( $table_name );
		$offset     = (int) $offset;
		$limit      = (int) $limit;

		// If schema-only table, return empty rows immediately
		if ( $is_schema_only ) {
			return array(
				'success'       => true,
				'table'         => $table_name,
				'target_table'  => $table_name,
				'columns'       => array(),
				'rows'          => array(),
				'offset'        => 0,
				'limit'         => $limit,
				'total_rows'    => 0,
				'is_last_chunk' => true,
			);
		}

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`", ARRAY_A );
		if ( empty( $columns ) ) {
			return array(
				'success' => false,
				'message' => "No se pudieron obtener columnas para la tabla {$table_name}",
			);
		}

		$column_names = array();
		$primary_key  = '';
		foreach ( $columns as $col ) {
			$column_names[] = $col['Field'];
			if ( 'PRI' === $col['Key'] && empty( $primary_key ) ) {
				$primary_key = $col['Field'];
			}
		}

		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" );

		$order_by = ! empty( $primary_key ) ? "ORDER BY `{$primary_key}` ASC" : "";
		$rows = $wpdb->get_results( "SELECT * FROM `{$table_name}` {$order_by} LIMIT {$offset}, {$limit}", ARRAY_A );
		$scanned_count = ! empty( $rows ) ? count( $rows ) : 0;

		$is_options = ( 0 === strcasecmp( $table_name, $wpdb->options ) || 0 === strcasecmp( $table_name, "{$wpdb->prefix}options" ) || 'wp_options' === strtolower( $table_name ) );
		$processed_rows = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				// Exclude internal sync settings and target profiles from export so destination identity and credentials are never overwritten
				if ( $is_options && isset( $row['option_name'] ) ) {
					if ( self::is_internal_option( $row['option_name'] ) ) {
						continue;
					}
				}

				$processed_row = array();
				foreach ( $row as $col_key => $col_val ) {
					if ( is_null( $col_val ) ) {
						$processed_row[ $col_key ] = null;
					} else {
						if ( $replacer instanceof VK7K_Sync_Replacer ) {
							$processed_row[ $col_key ] = $replacer->replace( $col_val );
						} else {
							$processed_row[ $col_key ] = $col_val;
						}
					}
				}
				$processed_rows[] = $processed_row;
			}
		}

		$dest_table_name = $table_name;
		if ( ! empty( $target_prefix ) && 0 !== strcasecmp( $target_prefix, $wpdb->prefix ) ) {
			if ( 0 === stripos( $table_name, $wpdb->prefix ) ) {
				$dest_table_name = $target_prefix . substr( $table_name, strlen( $wpdb->prefix ) );
			}
		}

		return array(
			'success'       => true,
			'table'         => $table_name,
			'target_table'  => $dest_table_name,
			'columns'       => $column_names,
			'rows'          => $processed_rows,
			'scanned_rows'  => $scanned_count,
			'offset'        => $offset,
			'limit'         => $limit,
			'total_rows'    => $total_rows,
			'is_last_chunk' => ( $scanned_count < $limit ) || ( ( $offset + $scanned_count ) >= $total_rows ),
		);
	}

	/**
	 * Import a chunk into local database using Staged Shadow Tables (_vk7k_tmp_*).
	 * Ensures live database is NEVER modified mid-sync, preventing session loss or timeouts.
	 *
	 * @param array $chunk_data
	 * @param bool  $is_first_chunk
	 * @param string $schema_sql
	 * @return array
	 */
	public static function import_table_chunk( $chunk_data, $is_first_chunk = false, $schema_sql = '' ) {
		global $wpdb;

		$incoming_table  = sanitize_text_field( $chunk_data['table'] );
		$all_live_tables = (array) $wpdb->get_col( "SHOW TABLES" );

		// Resolve incoming table name case-insensitively against live tables in this database
		$real_table = $incoming_table;
		foreach ( $all_live_tables as $live_t ) {
			if ( 0 === strcasecmp( $live_t, $incoming_table ) ) {
				$real_table = $live_t;
				break;
			}
		}

		if ( $real_table === $incoming_table && ! in_array( $real_table, $all_live_tables, true ) ) {
			$candidate = preg_replace( '/^[a-zA-Z0-9]+_/', $wpdb->prefix, $incoming_table );
			foreach ( $all_live_tables as $live_t ) {
				if ( 0 === strcasecmp( $live_t, $candidate ) ) {
					$real_table = $live_t;
					break;
				}
			}
		}

		$stage_table = '_vk7k_tmp_' . $real_table;
		$columns     = ! empty( $chunk_data['columns'] ) ? (array) $chunk_data['columns'] : array();
		$rows        = ! empty( $chunk_data['rows'] ) ? (array) $chunk_data['rows'] : array();
		$is_options  = ( 0 === strcasecmp( $real_table, "{$wpdb->prefix}options" ) || 0 === strcasecmp( $real_table, 'wp_options' ) || 'wp_options' === strtolower( $real_table ) );
		$is_users    = ( 0 === strcasecmp( $real_table, "{$wpdb->prefix}users" ) || 0 === strcasecmp( $real_table, 'wp_users' ) || 'wp_users' === strtolower( $real_table ) );

		// Preserve local destination options & current admin users before first chunks
		if ( $is_first_chunk ) {
			if ( $is_options ) {
				self::backup_preserved_options();
			}

			if ( $is_users ) {
				self::backup_admin_users();
			}
		}

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=0;" );
		$wpdb->query( "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';" );

		// Stage Table Setup
		if ( $is_first_chunk ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$stage_table}`" );

			$created = false;
			if ( ! empty( $schema_sql ) ) {
				$stage_schema = preg_replace(
					'/(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)(?:`' . preg_quote( $incoming_table, '/' ) . '`|`' . preg_quote( $real_table, '/' ) . '`|' . preg_quote( $incoming_table, '/' ) . '|' . preg_quote( $real_table, '/' ) . ')/i',
					'$1`' . $stage_table . '`',
					$schema_sql
				);
				$wpdb->query( $stage_schema );
				$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $stage_table ) );
				$created = ( $exists === $stage_table );
			}

			if ( ! $created ) {
				$real_exists = in_array( $real_table, $all_live_tables, true );
				if ( $real_exists ) {
					$wpdb->query( "CREATE TABLE `{$stage_table}` LIKE `{$real_table}`" );
				} else {
					$wpdb->query( $schema_sql );
				}
				$wpdb->query( "TRUNCATE TABLE `{$stage_table}`" );
			}
		}

		if ( ! empty( $rows ) && ! empty( $columns ) ) {
			// Filter out incoming vk7k internal sync options
			if ( $is_options ) {
				$filtered_rows = array();
				foreach ( $rows as $row ) {
					$opt_name = isset( $row['option_name'] ) ? $row['option_name'] : '';
					if ( self::is_internal_option( $opt_name ) ) {
						continue;
					}
					$filtered_rows[] = $row;
				}
				$rows = $filtered_rows;
			}

			if ( ! empty( $rows ) ) {
				$escaped_cols = array_map( function( $c ) {
					return '`' . sanitize_key( $c ) . '`';
				}, $columns );
				$cols_str = implode( ', ', $escaped_cols );

				$chunks = array_chunk( $rows, 100 );
				foreach ( $chunks as $batch ) {
					$values_clauses = array();
					$flattened_vals = array();

					foreach ( $batch as $row ) {
						$placeholders = array();
						foreach ( $columns as $col ) {
							$val = isset( $row[ $col ] ) ? $row[ $col ] : null;
							if ( is_null( $val ) ) {
								$placeholders[] = 'NULL';
							} else {
								$placeholders[]   = '%s';
								$flattened_vals[] = $val;
							}
						}
						$values_clauses[] = '(' . implode( ', ', $placeholders ) . ')';
					}

					$sql = "REPLACE INTO `{$stage_table}` ({$cols_str}) VALUES " . implode( ', ', $values_clauses );
					if ( ! empty( $flattened_vals ) ) {
						$prepared = $wpdb->prepare( $sql, $flattened_vals );
						$res = $wpdb->query( $prepared );
					} else {
						$res = $wpdb->query( $sql );
					}

					if ( false === $res ) {
						return array(
							'success' => false,
							'message' => "Error al insertar en {$stage_table}: " . $wpdb->last_error,
						);
					}
				}
			}
		}

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=1;" );

		return array(
			'success'        => true,
			'table'          => $real_table,
			'imported_rows'  => count( $rows ),
		);
	}

	/**
	 * Fallback commit: replace the rows of a live table with the rows of its staged table
	 * (used when RENAME TABLE is denied by the DB user). Runs in a transaction when supported.
	 *
	 * @param string $stage_table Staged table name.
	 * @param string $real_table  Live table name.
	 * @return array
	 */
	protected static function copy_stage_into_live( $stage_table, $real_table ) {
		global $wpdb;

		$stage_cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$stage_table}`" );
		$real_cols  = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$real_table}`" );
		$cols       = array_values( array_intersect( $real_cols, $stage_cols ) );
		if ( empty( $cols ) ) {
			return array( 'success' => false, 'message' => 'No common columns' );
		}
		$col_list = '`' . implode( '`, `', $cols ) . '`';

		$wpdb->query( 'START TRANSACTION' );
		$del = $wpdb->query( "DELETE FROM `{$real_table}`" );
		$ins = ( false !== $del ) ? $wpdb->query( "INSERT INTO `{$real_table}` ({$col_list}) SELECT {$col_list} FROM `{$stage_table}`" ) : false;

		if ( false === $del || false === $ins ) {
			$msg = $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			return array( 'success' => false, 'message' => $msg );
		}

		$wpdb->query( 'COMMIT' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$stage_table}`" );
		return array( 'success' => true, 'rows' => (int) $ins );
	}

	/**
	 * Atomically commit all staged shadow tables (_vk7k_tmp_*) into active WordPress tables.
	 *
	 * @param string $target_url Canonical target URL.
	 * @return array
	 */
	public static function commit_staged_tables( $target_url = '' ) {
		global $wpdb;

		$results = $wpdb->get_results( "SHOW TABLES LIKE '_vk7k_tmp_%'", ARRAY_N );
		if ( empty( $results ) ) {
			return array( 'success' => true, 'swapped' => 0 );
		}

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=0;" );

		$all_live_tables = (array) $wpdb->get_col( "SHOW TABLES" );
		$swapped_tables  = array();
		$errors          = array();

		foreach ( $results as $row ) {
			$stage_table     = $row[0];
			$candidate_table = substr( $stage_table, strlen( '_vk7k_tmp_' ) );

			// Find matching live table case-insensitively
			$real_table = '';
			foreach ( $all_live_tables as $live_t ) {
				if ( 0 === strcasecmp( $live_t, $candidate_table ) ) {
					$real_table = $live_t;
					break;
				}
			}

			// If not found directly, try matching with active $wpdb->prefix
			if ( empty( $real_table ) ) {
				$candidate_with_prefix = preg_replace( '/^[a-zA-Z0-9]+_/', $wpdb->prefix, $candidate_table );
				foreach ( $all_live_tables as $live_t ) {
					if ( 0 === strcasecmp( $live_t, $candidate_with_prefix ) ) {
						$real_table = $live_t;
						break;
					}
				}
			}

			if ( empty( $real_table ) ) {
				$real_table = $candidate_table;
			}

			// 1. If options table, restore preserved settings & URLs directly into staged table before swap
			$is_options = ( 0 === strcasecmp( $real_table, "{$wpdb->prefix}options" ) || 0 === strcasecmp( $real_table, 'wp_options' ) || 'wp_options' === strtolower( $real_table ) );
			if ( $is_options ) {
				self::restore_preserved_options( $stage_table );

				if ( ! empty( $target_url ) ) {
					$canonical = untrailingslashit( $target_url );
					$wpdb->query( $wpdb->prepare( "UPDATE `{$stage_table}` SET option_value = %s WHERE option_name = 'siteurl' OR option_name = 'home'", $canonical ) );
				}
			}

			// 2. If users / usermeta, re-inject local admin users into staged table before swap
			$is_users = ( 0 === strcasecmp( $real_table, "{$wpdb->prefix}users" ) || 0 === strcasecmp( $real_table, 'wp_users' ) || 'wp_users' === strtolower( $real_table ) );
			if ( $is_users ) {
				$stage_pfx = preg_replace( '/users$/i', '', $stage_table );
				self::restore_admin_users( $stage_pfx );
			}

			// 3. Atomic Table Swap
			$old_table = '_vk7k_old_' . $real_table;
			$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );

			$real_exists = in_array( $real_table, $all_live_tables, true );
			if ( $real_exists ) {
				$swap_res = $wpdb->query( "RENAME TABLE `{$real_table}` TO `{$old_table}`, `{$stage_table}` TO `{$real_table}`" );
			} else {
				$swap_res = $wpdb->query( "RENAME TABLE `{$stage_table}` TO `{$real_table}`" );
			}

			if ( false !== $swap_res ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );
				$swapped_tables[] = $real_table;
			} else {
				$swap_error = $wpdb->last_error;

				// Fallback for hosts whose MySQL user lacks ALTER/DROP-RENAME privileges (RENAME TABLE denied):
				// copy staged rows into the live table inside a transaction, then drop the stage table.
				$copy_res = $real_exists ? self::copy_stage_into_live( $stage_table, $real_table ) : array( 'success' => false, 'message' => 'Live table missing' );
				if ( ! empty( $copy_res['success'] ) ) {
					$swapped_tables[] = $real_table;
				} else {
					$errors[] = "Failed swapping {$stage_table} to {$real_table}: {$swap_error} | copy fallback: " . ( $copy_res['message'] ?? '' );
				}
			}
		}

		// 4. Fail-safe: ensure active users table has all local administrators restored with session tokens
		self::restore_admin_users( $wpdb->prefix );

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=1;" );

		wp_cache_flush();
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		// Cleanup persisted temp state files after successful commit
		$dir = self::get_temp_storage_dir();
		@unlink( $dir . '/preserved_admins.json' );
		@unlink( $dir . '/preserved_options.json' );

		return array(
			'success' => empty( $errors ),
			'swapped' => count( $swapped_tables ),
			'tables'  => $swapped_tables,
			'errors'  => $errors,
		);
	}

	/**
	 * Cleanup any orphaned staging or old backup tables and temp state files.
	 */
	public static function cleanup_staged_tables() {
		global $wpdb;
		$results = $wpdb->get_results( "SHOW TABLES LIKE '_vk7k_tmp_%'", ARRAY_N );
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$row[0]}`" );
			}
		}
		$old_results = $wpdb->get_results( "SHOW TABLES LIKE '_vk7k_old_%'", ARRAY_N );
		if ( ! empty( $old_results ) ) {
			foreach ( $old_results as $row ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$row[0]}`" );
			}
		}
		$dir = self::get_temp_storage_dir();
		@unlink( $dir . '/preserved_admins.json' );
		@unlink( $dir . '/preserved_options.json' );
	}

	/**
	 * Create backup of existing database tables.
	 *
	 * @return array
	 */
	public static function create_rollback_backup() {
		global $wpdb;

		$tables = self::get_tables();
		$backed_up = array();

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=0;" );

		foreach ( $tables as $table_info ) {
			$table = $table_info['name'];
			$bak_table = '_vk7k_bak_' . $table;

			$wpdb->query( "DROP TABLE IF EXISTS `{$bak_table}`" );
			$wpdb->query( "CREATE TABLE `{$bak_table}` LIKE `{$table}`" );
			$wpdb->query( "INSERT INTO `{$bak_table}` SELECT * FROM `{$table}`" );

			$backed_up[] = $table;
		}

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=1;" );

		return array(
			'success' => true,
			'tables'  => $backed_up,
			'time'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Restore rollback backup tables.
	 *
	 * @return array
	 */
	public static function restore_rollback_backup() {
		global $wpdb;

		$results = $wpdb->get_results( "SHOW TABLES LIKE '_vk7k_bak_%'", ARRAY_N );
		$restored = array();

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=0;" );

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$bak_table = $row[0];
				$orig_table = str_replace( '_vk7k_bak_', '', $bak_table );

				$wpdb->query( "DROP TABLE IF EXISTS `{$orig_table}`" );
				$wpdb->query( "CREATE TABLE `{$orig_table}` LIKE `{$bak_table}`" );
				$wpdb->query( "INSERT INTO `{$orig_table}` SELECT * FROM `{$bak_table}`" );
				$wpdb->query( "DROP TABLE IF EXISTS `{$bak_table}`" );

				$restored[] = $orig_table;
			}
		}

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=1;" );

		return array(
			'success' => true,
			'tables'  => $restored,
		);
	}

	/**
	 * Run deep search and replace directly on database tables with full serialization preservation.
	 *
	 * @param array $replacements Search => Replace matrix.
	 * @param array $tables Optional specific tables. If empty, runs on core content/option tables.
	 * @return array Operation statistics.
	 */
	public static function deep_search_and_replace( $replacements = array(), $tables = array() ) {
		global $wpdb;

		if ( empty( $replacements ) || ! is_array( $replacements ) ) {
			return array(
				'success' => true,
				'updated' => 0,
				'scanned' => 0,
				'message' => 'No hay reemplazos especificados.',
			);
		}

		$replacer = new VK7K_Sync_Replacer( $replacements );

		if ( empty( $tables ) ) {
			$all_prefix_tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
			if ( ! empty( $all_prefix_tables ) ) {
				$tables = array_filter( $all_prefix_tables, function( $t ) {
					return ( 0 !== strpos( $t, '_vk7k_bak_' ) && 0 !== strpos( $t, '_vk7k_tmp_' ) && 0 !== strpos( $t, '_vk7k_old_' ) );
				} );
			} else {
				$tables = array(
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->options,
					$wpdb->termmeta,
					$wpdb->comments,
					$wpdb->commentmeta,
				);
			}
		}

		$total_scanned = 0;
		$total_updated = 0;
		$processed_tables = array();

		foreach ( $tables as $table ) {
			$table = sanitize_text_field( $table );
			if ( empty( $table ) ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
			if ( empty( $columns ) ) {
				continue;
			}

			$primary_key = '';
			$text_columns = array();

			foreach ( $columns as $col ) {
				$field_name = $col['Field'];
				$field_type = strtolower( $col['Type'] );

				if ( 'PRI' === $col['Key'] && empty( $primary_key ) ) {
					$primary_key = $field_name;
				}

				if ( preg_match( '/(char|text|blob|longtext|mediumtext|json)/i', $field_type ) ) {
					$text_columns[] = $field_name;
				}
			}

			if ( empty( $primary_key ) || empty( $text_columns ) ) {
				continue;
			}

			$is_options = ( 0 === strcasecmp( $table, $wpdb->options ) || 0 === strcasecmp( $table, "{$wpdb->prefix}options" ) || 'wp_options' === strtolower( $table ) );
			$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			$chunk_size = 500;
			$table_updated = 0;

			for ( $offset = 0; $offset < $total_rows; $offset += $chunk_size ) {
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `{$primary_key}` ASC LIMIT {$offset}, {$chunk_size}", ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$total_scanned++;

					if ( $is_options && isset( $row['option_name'] ) && self::is_internal_option( $row['option_name'] ) ) {
						continue;
					}

					$updates = array();
					foreach ( $text_columns as $col_name ) {
						if ( ! isset( $row[ $col_name ] ) || is_null( $row[ $col_name ] ) || '' === $row[ $col_name ] ) {
							continue;
						}

						$original_val = $row[ $col_name ];
						$new_val      = $replacer->replace( $original_val );

						if ( $new_val !== $original_val ) {
							$updates[ $col_name ] = $new_val;
						}
					}

					if ( ! empty( $updates ) ) {
						$res = $wpdb->update(
							$table,
							$updates,
							array( $primary_key => $row[ $primary_key ] )
						);
						if ( false !== $res ) {
							$table_updated++;
							$total_updated++;
						}
					}
				}
			}

			$processed_tables[ $table ] = $table_updated;
		}

		return array(
			'success'          => true,
			'scanned'          => $total_scanned,
			'updated'          => $total_updated,
			'processed_tables' => $processed_tables,
		);
	}

	/**
	 * Audit residual URLs in core tables.
	 *
	 * @param array|string $domains Domain string or array of domain variants to audit.
	 * @return array
	 */
	public static function audit_residual_urls( $domains = array() ) {
		global $wpdb;

		if ( is_string( $domains ) ) {
			$domains = array( $domains );
		}

		$domains_clean = array();
		foreach ( (array) $domains as $d ) {
			$d_host = wp_parse_url( (string) $d, PHP_URL_HOST ) ?: (string) $d;
			$d_host = preg_replace( '#^https?://#i', '', $d_host );
			$d_host = untrailingslashit( trim( $d_host ) );
			if ( ! empty( $d_host ) && strlen( $d_host ) >= 4 ) {
				$domains_clean[] = $d_host;
				if ( 0 === strpos( $d_host, 'www.' ) ) {
					$domains_clean[] = substr( $d_host, 4 );
				} else if ( false !== strpos( $d_host, '.' ) ) {
					$domains_clean[] = 'www.' . $d_host;
				}
			}
		}
		$domains_clean = array_unique( array_filter( $domains_clean ) );

		if ( empty( $domains_clean ) ) {
			return array(
				'total_residual' => 0,
				'breakdown'      => array(),
				'clean'          => true,
			);
		}

		$breakdown = array();
		$total_residual = 0;

		$tables_to_check = array(
			'wp_posts'    => array(
				'table' => $wpdb->posts,
				'cols'  => array( 'post_content', 'guid' ),
				'where' => "1=1",
			),
			'wp_postmeta' => array(
				'table' => $wpdb->postmeta,
				'cols'  => array( 'meta_value' ),
				'where' => "1=1",
			),
			'wp_options'  => array(
				'table' => $wpdb->options,
				'cols'  => array( 'option_value' ),
				'where' => "option_name NOT LIKE '%vk7k%'",
			),
			'wp_comments' => array(
				'table' => $wpdb->comments,
				'cols'  => array( 'comment_content', 'comment_author_url' ),
				'where' => "1=1",
			),
		);

		if ( ! empty( $wpdb->termmeta ) ) {
			$tables_to_check['wp_termmeta'] = array(
				'table' => $wpdb->termmeta,
				'cols'  => array( 'meta_value' ),
				'where' => "1=1",
			);
		}

		foreach ( $tables_to_check as $label => $info ) {
			$tbl = $info['table'];
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl ) );
			if ( $exists !== $tbl ) {
				continue;
			}

			$or_conditions = array();
			foreach ( $info['cols'] as $col ) {
				foreach ( $domains_clean as $dom ) {
					$like = '%' . $wpdb->esc_like( $dom ) . '%';
					$or_conditions[] = $wpdb->prepare( "`{$col}` LIKE %s", $like );
				}
			}

			if ( empty( $or_conditions ) ) {
				continue;
			}

			$sql = "SELECT COUNT(*) FROM `{$tbl}` WHERE ({$info['where']}) AND (" . implode( ' OR ', $or_conditions ) . ")";
			$cnt = (int) $wpdb->get_var( $sql );

			$breakdown[ $label ] = $cnt;
			$total_residual += $cnt;
		}

		return array(
			'total_residual' => $total_residual,
			'breakdown'      => $breakdown,
			'domains_checked'=> $domains_clean,
			'clean'          => ( 0 === $total_residual ),
		);
	}

	/**
	 * Export granular FSE design bundle:
	 * - FSE & Menu post types (wp_navigation, nav_menu_item, wp_template_part, wp_template, wp_global_styles, wp_block, wp_font_family, wp_font_face)
	 * - Associated postmeta
	 * - Classic nav_menu terms, taxonomies, relationships
	 * - Active theme options (theme_mods_{stylesheet}, theme_mods_{template}, nav_menu_options, widget_nav_menu)
	 *
	 * @param string $source_url Source site URL for domain replacements.
	 * @param string $target_url Target site URL for domain replacements.
	 * @return array
	 */
	public static function export_fse_bundle( $source_url = '', $target_url = '' ) {
		global $wpdb;

		$replacer = null;
		if ( ! empty( $source_url ) && ! empty( $target_url ) ) {
			$matrix   = VK7K_Sync_Replacer::build_url_matrix( $source_url, $target_url );
			$replacer = new VK7K_Sync_Replacer( $matrix );
		}

		$fse_types = array(
			'wp_navigation',
			'nav_menu_item',
			'wp_template_part',
			'wp_template',
			'wp_global_styles',
			'wp_block',
			'wp_font_family',
			'wp_font_face',
		);
		$in_types = "'" . implode( "', '", array_map( 'esc_sql', $fse_types ) ) . "'";
		$posts    = $wpdb->get_results( "SELECT * FROM `{$wpdb->posts}` WHERE post_type IN ({$in_types}) ORDER BY ID ASC", ARRAY_A );
		if ( empty( $posts ) ) {
			$posts = array();
		}

		$post_ids = wp_list_pluck( $posts, 'ID' );
		$postmeta = array();
		if ( ! empty( $post_ids ) ) {
			$in_ids   = implode( ',', array_map( 'intval', $post_ids ) );
			$postmeta = $wpdb->get_results( "SELECT * FROM `{$wpdb->postmeta}` WHERE post_id IN ({$in_ids}) ORDER BY meta_id ASC", ARRAY_A );
			if ( empty( $postmeta ) ) {
				$postmeta = array();
			}
		}

		// Terms & Taxonomies for nav_menu, wp_theme, and wp_template_part_area
		$terms = $wpdb->get_results(
			"SELECT t.*, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count 
			 FROM `{$wpdb->terms}` t 
			 JOIN `{$wpdb->term_taxonomy}` tt ON t.term_id = tt.term_id 
			 WHERE tt.taxonomy IN ('nav_menu', 'wp_theme', 'wp_template_part_area')",
			ARRAY_A
		);
		if ( empty( $terms ) ) {
			$terms = array();
		}

		$term_taxonomy_ids = wp_list_pluck( $terms, 'term_taxonomy_id' );
		$relationships     = array();
		if ( ! empty( $term_taxonomy_ids ) ) {
			$in_tt_ids     = implode( ',', array_map( 'intval', $term_taxonomy_ids ) );
			$relationships = $wpdb->get_results( "SELECT * FROM `{$wpdb->term_relationships}` WHERE term_taxonomy_id IN ({$in_tt_ids})", ARRAY_A );
			if ( empty( $relationships ) ) {
				$relationships = array();
			}
		}

		// Options: active theme mods & nav menu options
		$stylesheet = get_stylesheet();
		$template   = get_template();
		$opts_query = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload 
				 FROM `{$wpdb->options}` 
				 WHERE option_name LIKE %s 
				    OR option_name LIKE %s 
				    OR option_name IN ('nav_menu_options', 'widget_nav_menu')",
				$wpdb->esc_like( 'theme_mods_' . $stylesheet ) . '%',
				$wpdb->esc_like( 'theme_mods_' . $template ) . '%'
			),
			ARRAY_A
		);
		if ( empty( $opts_query ) ) {
			$opts_query = array();
		}

		// Apply domain replacements
		if ( $replacer instanceof VK7K_Sync_Replacer ) {
			foreach ( $posts as &$p ) {
				foreach ( $p as $col => $val ) {
					if ( is_string( $val ) && ( 'post_content' === $col || 'guid' === $col ) ) {
						$p[ $col ] = $replacer->replace( $val );
					}
				}
			}
			unset( $p );

			foreach ( $postmeta as &$pm ) {
				if ( isset( $pm['meta_value'] ) && is_string( $pm['meta_value'] ) ) {
					$pm['meta_value'] = $replacer->replace( $pm['meta_value'] );
				}
			}
			unset( $pm );

			foreach ( $opts_query as &$opt ) {
				if ( isset( $opt['option_value'] ) && is_string( $opt['option_value'] ) ) {
					$opt['option_value'] = $replacer->replace( $opt['option_value'] );
				}
			}
			unset( $opt );
		}

		return array(
			'success'       => true,
			'stylesheet'    => $stylesheet,
			'template'      => $template,
			'posts'         => $posts,
			'postmeta'      => $postmeta,
			'terms'         => $terms,
			'relationships' => $relationships,
			'options'       => $opts_query,
			'timestamp'     => time(),
		);
	}

	/**
	 * Import granular FSE design bundle into destination database.
	 * Safely updates FSE posts, postmeta, nav_menu terms, and theme_mods without touching pages, posts or orders.
	 *
	 * @param array  $bundle
	 * @param string $source_url Source site URL for domain replacements if not replaced yet.
	 * @param string $target_url Target site URL for domain replacements.
	 * @return array
	 */
	public static function import_fse_bundle( $bundle, $source_url = '', $target_url = '' ) {
		global $wpdb;

		if ( empty( $bundle ) || ! is_array( $bundle ) ) {
			return array( 'success' => false, 'message' => 'Paquete FSE inválido o vacío.' );
		}

		$replacer = null;
		if ( ! empty( $source_url ) && ! empty( $target_url ) ) {
			$matrix   = VK7K_Sync_Replacer::build_url_matrix( $source_url, $target_url );
			$replacer = new VK7K_Sync_Replacer( $matrix );
		}

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=0;" );

		$fse_types = array(
			'wp_navigation',
			'nav_menu_item',
			'wp_template_part',
			'wp_template',
			'wp_global_styles',
			'wp_block',
			'wp_font_family',
			'wp_font_face',
		);
		$in_types = "'" . implode( "', '", array_map( 'esc_sql', $fse_types ) ) . "'";

		// 1. Purge existing FSE posts and their postmeta so old menus/templates don't collide or become zombies
		$old_ids = $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type IN ({$in_types})" );
		if ( ! empty( $old_ids ) ) {
			$ids_str = implode( ',', array_map( 'intval', $old_ids ) );
			$wpdb->query( "DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN ({$ids_str})" );
			$wpdb->query( "DELETE FROM `{$wpdb->posts}` WHERE ID IN ({$ids_str})" );
		}

		// 2. Insert incoming FSE posts
		$posts = ! empty( $bundle['posts'] ) ? (array) $bundle['posts'] : array();
		$inserted_posts = 0;
		foreach ( $posts as $p ) {
			if ( $replacer instanceof VK7K_Sync_Replacer ) {
				foreach ( $p as $col => $val ) {
					if ( is_string( $val ) && ( 'post_content' === $col || 'guid' === $col ) ) {
						$p[ $col ] = $replacer->replace( $val );
					}
				}
			}
			$wpdb->insert( $wpdb->posts, $p );
			$inserted_posts++;
		}

		// 3. Insert incoming postmeta
		$postmeta = ! empty( $bundle['postmeta'] ) ? (array) $bundle['postmeta'] : array();
		$inserted_meta = 0;
		foreach ( $postmeta as $pm ) {
			if ( $replacer instanceof VK7K_Sync_Replacer && isset( $pm['meta_value'] ) && is_string( $pm['meta_value'] ) ) {
				$pm['meta_value'] = $replacer->replace( $pm['meta_value'] );
			}
			$wpdb->insert( $wpdb->postmeta, $pm );
			$inserted_meta++;
		}

		// 4. Upsert Terms and Taxonomies for nav_menu
		$terms = ! empty( $bundle['terms'] ) ? (array) $bundle['terms'] : array();
		foreach ( $terms as $t ) {
			$wpdb->replace( $wpdb->terms, array(
				'term_id'    => (int) $t['term_id'],
				'name'       => $t['name'],
				'slug'       => $t['slug'],
				'term_group' => (int) $t['term_group'],
			) );

			$wpdb->replace( $wpdb->term_taxonomy, array(
				'term_taxonomy_id' => (int) $t['term_taxonomy_id'],
				'term_id'          => (int) $t['term_id'],
				'taxonomy'         => $t['taxonomy'],
				'description'      => $t['description'] ?? '',
				'parent'           => (int) ( $t['parent'] ?? 0 ),
				'count'            => (int) ( $t['count'] ?? 0 ),
			) );
		}

		// 5. Upsert term_relationships
		$relationships = ! empty( $bundle['relationships'] ) ? (array) $bundle['relationships'] : array();
		foreach ( $relationships as $rel ) {
			$wpdb->replace( $wpdb->term_relationships, array(
				'object_id'        => (int) $rel['object_id'],
				'term_taxonomy_id' => (int) $rel['term_taxonomy_id'],
				'term_order'       => (int) ( $rel['term_order'] ?? 0 ),
			) );
		}

		// 6. Update options (theme_mods_*, nav_menu_options)
		$options = ! empty( $bundle['options'] ) ? (array) $bundle['options'] : array();
		foreach ( $options as $opt ) {
			$opt_name = $opt['option_name'];
			$opt_val  = $opt['option_value'];
			if ( $replacer instanceof VK7K_Sync_Replacer && is_string( $opt_val ) ) {
				$opt_val = $replacer->replace( $opt_val );
			}
			$unserialized = maybe_unserialize( $opt_val );
			update_option( $opt_name, $unserialized );
		}

		$wpdb->query( "SET FOREIGN_KEY_CHECKS=1;" );

		wp_cache_flush();
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( Exception $e ) {
				// Ignore
			}
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		return array(
			'success'        => true,
			'posts_synced'   => $inserted_posts,
			'meta_synced'    => $inserted_meta,
			'options_synced' => count( $options ),
			'message'        => 'Diseño, menús y plantillas FSE sincronizados con éxito.',
		);
	}
}

