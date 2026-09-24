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
		$upload_dir = wp_upload_dir();
		$dir = trailingslashit( $upload_dir['basedir'] ) . 'vk7k-sync-temp';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.php', '<?php // Silence is golden' );
		}
		return $dir;
	}

	/**
	 * Backup all local administrators and their session tokens to persistent file storage.
	 *
	 * @return bool
	 */
	public static function backup_admin_users() {
		global $wpdb;

		$dir  = self::get_temp_storage_dir();
		$file = $dir . '/preserved_admins.json';

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
		$file = $dir . '/preserved_admins.json';

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

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $users_tbl ) );
		if ( $exists !== $users_tbl ) {
			return false;
		}

		$exists_meta = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $usermeta_tbl ) );

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
		$file = $dir . '/preserved_options.json';

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
		$file = $dir . '/preserved_options.json';

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
			0 === strpos( $opt_name, 'vk7k_' ) ||
			0 === strpos( $opt_name, '_vk7k_' ) ||
			0 === strpos( $opt_name, '_transient_vk7k_' ) ||
			0 === strpos( $opt_name, '_transient_timeout_vk7k_' )
		);
	}

	/**
	 * Get all tables in database belonging to WordPress or custom plugins with filtering.
	 *
	 * @param array $options Filter options (preserve_users, exclude_logs, selected_components)
	 * @return array Array of table info [ name, rows, size_mb, is_schema_only ]
	 */
	public static function get_tables( $options = array() ) {
		global $wpdb;

		$prefix = $wpdb->prefix;

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
				$table_name = $row['Name'];

				// Skip temporary backup tables
				if ( 0 === strpos( $table_name, '_vk7k_bak_' ) ) {
					continue;
				}

				// Check Users & Meta
				if ( ! $sync_users && ( $table_name === "{$prefix}users" || $table_name === "{$prefix}usermeta" || $table_name === "{$prefix}wc_customer_lookup" ) ) {
					continue;
				}

				// Check WooCommerce Orders & Transactions
				if ( ! $sync_wc_orders ) {
					if (
						0 === strpos( $table_name, "{$prefix}wc_order" ) ||
						$table_name === "{$prefix}wc_orders" ||
						$table_name === "{$prefix}wc_orders_meta" ||
						$table_name === "{$prefix}wc_order_addresses" ||
						$table_name === "{$prefix}wc_order_operational_data" ||
						$table_name === "{$prefix}wc_order_coupon_lookup" ||
						$table_name === "{$prefix}wc_order_product_lookup" ||
						$table_name === "{$prefix}wc_order_stats" ||
						$table_name === "{$prefix}wc_order_tax_lookup" ||
						0 === strpos( $table_name, "{$prefix}woocommerce_order" ) ||
						$table_name === "{$prefix}woocommerce_payment_tokens" ||
						$table_name === "{$prefix}woocommerce_payment_tokenmeta" ||
						$table_name === "{$prefix}woocommerce_sessions" ||
						$table_name === "{$prefix}woocommerce_downloadable_product_permissions"
					) {
						continue;
					}
				}

				// Check General Content (Posts / Postmeta)
				if ( ! $sync_content && ( $table_name === "{$prefix}posts" || $table_name === "{$prefix}postmeta" ) ) {
					continue;
				}

				// Check Options
				if ( ! $sync_options && ( $table_name === "{$prefix}options" ) ) {
					continue;
				}

				// Check Taxonomies
				if ( ! $sync_taxonomies && (
					$table_name === "{$prefix}terms" ||
					$table_name === "{$prefix}term_taxonomy" ||
					$table_name === "{$prefix}term_relationships" ||
					$table_name === "{$prefix}termmeta"
				) ) {
					continue;
				}

				// Check Comments
				if ( ! $sync_comments && ( $table_name === "{$prefix}comments" || $table_name === "{$prefix}commentmeta" ) ) {
					continue;
				}

				// Include tables that match prefix or custom WP tables
				if ( 0 === strpos( $table_name, $prefix ) || false !== strpos( $table_name, 'woocommerce' ) || false !== strpos( $table_name, 'actionscheduler' ) ) {
					$data_size  = isset( $row['Data_length'] ) ? (float) $row['Data_length'] : 0;
					$index_size = isset( $row['Index_length'] ) ? (float) $row['Index_length'] : 0;
					$size_mb    = round( ( $data_size + $index_size ) / ( 1024 * 1024 ), 2 );
					$row_count  = isset( $row['Rows'] ) ? (int) $row['Rows'] : 0;

					// Check if this table is a transient log table (schema only if sync_logs is false)
					$is_schema_only = false;
					if ( ! $sync_logs && (
						$table_name === "{$prefix}actionscheduler_logs" ||
						$table_name === "{$prefix}actionscheduler_actions" ||
						$table_name === "{$prefix}actionscheduler_claims" ||
						$table_name === "{$prefix}woocommerce_log" ||
						$table_name === "{$prefix}wpmailsmtp_debug_events" ||
						$table_name === "{$prefix}wpmailsmtp_tasks_meta"
					) ) {
						$is_schema_only = true;
					}

					$tables[] = array(
						'name'           => $table_name,
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

		$is_options = ( $table_name === $wpdb->options || $table_name === "{$wpdb->prefix}options" || 'wp_options' === $table_name );
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
		if ( ! empty( $target_prefix ) && $target_prefix !== $wpdb->prefix ) {
			if ( 0 === strpos( $table_name, $wpdb->prefix ) ) {
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

		$real_table  = sanitize_text_field( $chunk_data['table'] );
		$stage_table = '_vk7k_tmp_' . $real_table;
		$columns     = ! empty( $chunk_data['columns'] ) ? (array) $chunk_data['columns'] : array();
		$rows        = ! empty( $chunk_data['rows'] ) ? (array) $chunk_data['rows'] : array();
		$is_options  = ( $real_table === "{$wpdb->prefix}options" || $real_table === 'wp_options' );
		$is_users    = ( $real_table === "{$wpdb->prefix}users" || $real_table === 'wp_users' );

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
					'/(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)(?:`' . preg_quote( $real_table, '/' ) . '`|' . preg_quote( $real_table, '/' ) . ')/i',
					'$1`' . $stage_table . '`',
					$schema_sql
				);
				$wpdb->query( $stage_schema );
				$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $stage_table ) );
				$created = ( $exists === $stage_table );
			}

			if ( ! $created ) {
				$real_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $real_table ) );
				if ( $real_exists === $real_table ) {
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

		$swapped_tables = array();

		foreach ( $results as $row ) {
			$stage_table = $row[0];
			$real_table  = substr( $stage_table, strlen( '_vk7k_tmp_' ) );

			// 1. If options table, restore preserved settings & URLs directly into staged table before swap
			$is_options = ( $real_table === "{$wpdb->prefix}options" || $real_table === 'wp_options' );
			if ( $is_options ) {
				self::restore_preserved_options( $stage_table );

				if ( ! empty( $target_url ) ) {
					$canonical = untrailingslashit( $target_url );
					$wpdb->query( $wpdb->prepare( "UPDATE `{$stage_table}` SET option_value = %s WHERE option_name = 'siteurl' OR option_name = 'home'", $canonical ) );
				}
			}

			// 2. If users / usermeta, re-inject local admin users into staged table before swap
			if ( $real_table === "{$wpdb->prefix}users" || $real_table === 'wp_users' ) {
				self::restore_admin_users( '_vk7k_tmp_' . $wpdb->prefix );
			}

			// 3. Atomic Table Swap
			$old_table = '_vk7k_old_' . $real_table;
			$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );
			$wpdb->query( "RENAME TABLE `{$real_table}` TO `{$old_table}`, `{$stage_table}` TO `{$real_table}`" );
			$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );

			$swapped_tables[] = $real_table;
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
			'success' => true,
			'swapped' => count( $swapped_tables ),
			'tables'  => $swapped_tables,
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
			$tables = array(
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->options,
				$wpdb->termmeta,
				$wpdb->comments,
				$wpdb->commentmeta,
			);
			if ( ! empty( $wpdb->links ) ) {
				$tables[] = $wpdb->links;
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

			$is_options = ( $table === $wpdb->options || $table === "{$wpdb->prefix}options" || 'wp_options' === $table );
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
}

