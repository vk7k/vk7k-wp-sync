<?php
/**
 * VK7K Sync Granular Data Inspector & Exporter
 *
 * Provides inspection of WordPress entities (Posts, Pages, CPTs, Menus, Taxonomies, FSE, Options)
 * and selective extraction with automated dependency resolution (postmeta, terms, media).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Granular {

	/**
	 * Inspect local entities for the DataGrid Lightbox.
	 *
	 * @return array
	 */
	public static function inspect_entities() {
		global $wpdb;

		// 1. Post types available
		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$post_type_list = array();
		foreach ( $all_post_types as $pt => $obj ) {
			if ( in_array( $pt, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				continue;
			}
			$post_type_list[ $pt ] = array(
				'name'  => $pt,
				'label' => $obj->labels->name ?? $pt,
				'count' => (int) wp_count_posts( $pt )->publish,
			);
		}

		// Add common internal CPTs like wp_template, wp_navigation
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_navigation' ) as $internal_cpt ) {
			if ( post_type_exists( $internal_cpt ) ) {
				$cnt = wp_count_posts( $internal_cpt );
				$post_type_list[ $internal_cpt ] = array(
					'name'  => $internal_cpt,
					'label' => ucwords( str_replace( array( 'wp_', '_' ), array( '', ' ' ), $internal_cpt ) ),
					'count' => (int) ( $cnt->publish ?? 0 ),
				);
			}
		}

		// 2. Navigation Menus
		$menus_raw = wp_get_nav_menus();
		$menus     = array();
		foreach ( $menus_raw as $m ) {
			$items_count = wp_get_nav_menu_items( $m->term_id );
			$menus[]     = array(
				'term_id'    => (int) $m->term_id,
				'name'       => $m->name,
				'slug'       => $m->slug,
				'item_count' => is_array( $items_count ) ? count( $items_count ) : 0,
			);
		}

		// 3. Taxonomies
		$taxonomies_raw = get_taxonomies( array( 'public' => true ), 'objects' );
		$taxonomies     = array();
		foreach ( $taxonomies_raw as $tax_slug => $tax_obj ) {
			if ( in_array( $tax_slug, array( 'nav_menu', 'post_format' ), true ) ) {
				continue;
			}
			$terms_count = wp_count_terms( array( 'taxonomy' => $tax_slug, 'hide_empty' => false ) );
			$taxonomies[] = array(
				'name'  => $tax_slug,
				'label' => $tax_obj->labels->name ?? $tax_slug,
				'count' => is_wp_error( $terms_count ) ? 0 : (int) $terms_count,
			);
		}

		$plugins_count = count( VK7K_Sync_Files::get_available_plugins() );
		$themes_count  = count( VK7K_Sync_Files::get_available_themes() );
		$media_count   = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM `{$wpdb->posts}` WHERE post_type = 'attachment' AND post_status != 'trash'" );
		$users_data    = count_users();
		$users_count   = (int) ( $users_data['total_users'] ?? 0 );

		$products_count = 0;
		if ( post_type_exists( 'product' ) ) {
			$p_cnt          = wp_count_posts( 'product' );
			$products_count = (int) ( $p_cnt->publish ?? 0 );
		}

		return array(
			'post_types'     => array_values( $post_type_list ),
			'menus'          => $menus,
			'taxonomies'     => $taxonomies,
			'plugins_count'  => $plugins_count,
			'themes_count'   => $themes_count,
			'media_count'    => $media_count,
			'users_count'    => $users_count,
			'products_count' => $products_count,
		);
	}

	/**
	 * Query posts/items for a specific entity tab with pagination, search and filtering.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function query_items( $args = array() ) {
		global $wpdb;

		$type    = ! empty( $args['type'] ) ? sanitize_key( $args['type'] ) : 'page';
		$search  = ! empty( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$page    = ! empty( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$per_page= ! empty( $args['per_page'] ) ? min( 100, max( 10, (int) $args['per_page'] ) ) : 25;
		$offset  = ( $page - 1 ) * $per_page;

		// Handle wp_options tab query
		if ( 'options' === $type ) {
			return self::query_options( $search, $page, $per_page );
		}

		// Handle plugins tab query
		if ( 'plugins' === $type ) {
			return self::query_plugins( $search, $page, $per_page );
		}

		// Handle themes tab query
		if ( 'themes' === $type ) {
			return self::query_themes( $search, $page, $per_page );
		}

		// Handle users tab query
		if ( 'users' === $type ) {
			return self::query_users( $search, $page, $per_page );
		}

		// Handle taxonomies tab query
		if ( 'terms' === $type || 'taxonomies' === $type ) {
			$taxonomy = ! empty( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
			return self::query_terms( $taxonomy, $search, $page, $per_page );
		}

		// Standard posts / pages / CPTs / media query
		$where = 'attachment' === $type 
			? "WHERE post_type = 'attachment' AND post_status != 'trash'" 
			: "WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash')";
		$params = 'attachment' === $type ? array() : array( $type );

		if ( ! empty( $search ) ) {
			$where .= " AND (post_title LIKE %s OR post_name LIKE %s)";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$total = ! empty( $params ) 
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM `{$wpdb->posts}` {$where}", ...$params ) )
			: (int) $wpdb->get_var( "SELECT COUNT(ID) FROM `{$wpdb->posts}` {$where}" );

		$sql = "SELECT ID, post_title, post_name, post_status, post_type, post_date, post_modified, post_mime_type 
				FROM `{$wpdb->posts}` {$where} 
				ORDER BY post_modified DESC 
				LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		$items = array();
		foreach ( $rows as $row ) {
			$thumb_url = '';
			if ( 'attachment' === $row['post_type'] ) {
				$thumb_url = wp_get_attachment_thumb_url( $row['ID'] );
				if ( ! $thumb_url && wp_attachment_is_image( $row['ID'] ) ) {
					$thumb_url = wp_get_attachment_url( $row['ID'] );
				}
			} else {
				$thumb_id  = get_post_thumbnail_id( $row['ID'] );
				$thumb_url = $thumb_id ? wp_get_attachment_thumb_url( $thumb_id ) : '';
			}

			$title = ! empty( $row['post_title'] ) ? $row['post_title'] : '(sin título - #' . $row['ID'] . ')';
			if ( 'attachment' === $row['post_type'] && ! empty( $row['post_mime_type'] ) ) {
				$title .= ' (' . $row['post_mime_type'] . ')';
			} elseif ( 'product' === $row['post_type'] ) {
				$sku = get_post_meta( $row['ID'], '_sku', true );
				if ( ! empty( $sku ) ) {
					$title .= ' [' . $sku . ']';
				}
			}

			$items[] = array(
				'id'          => (int) $row['ID'],
				'title'       => $title,
				'slug'        => $row['post_name'],
				'status'      => 'attachment' === $row['post_type'] ? 'inherit' : $row['post_status'],
				'type'        => $row['post_type'],
				'date'        => $row['post_date'],
				'modified'    => $row['post_modified'],
				'thumb_url'   => $thumb_url ?: '',
				'view_url'    => get_permalink( $row['ID'] ) ?: ( 'attachment' === $row['post_type'] ? wp_get_attachment_url( $row['ID'] ) : '' ),
			);
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => ceil( $total / $per_page ),
			'items'    => $items,
		);
	}

	/**
	 * Query taxonomies terms.
	 */
	public static function query_terms( $taxonomy, $search = '', $page = 1, $per_page = 25 ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
		);

		if ( ! empty( $search ) ) {
			$args['search'] = $search;
		}

		$total = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'search' => $search ) );
		$terms = get_terms( $args );

		$items = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$items[] = array(
					'id'       => (int) $t->term_id,
					'name'     => $t->name,
					'slug'     => $t->slug,
					'count'    => (int) $t->count,
					'taxonomy' => $t->taxonomy,
				);
			}
		}

		return array(
			'total'    => is_wp_error( $total ) ? 0 : (int) $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => is_wp_error( $total ) ? 1 : ceil( (int) $total / $per_page ),
			'items'    => $items,
		);
	}

	/**
	 * Query selectable wp_options safely (filtering out credentials, salts, siteurls, etc.).
	 */
	public static function query_options( $search = '', $page = 1, $per_page = 50 ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;
		$where  = "WHERE option_name NOT LIKE '\_transient\_%' 
				   AND option_name NOT LIKE '\_site\_transient\_%' 
				   AND option_name NOT LIKE 'vk7k\_sync\_%'
				   AND option_name NOT IN ('siteurl', 'home', 'active_plugins', 'cron', 'nonce_salt', 'auth_salt', 'logged_in_salt', 'secure_auth_salt')";

		$params = array();
		if ( ! empty( $search ) ) {
			$where .= " AND option_name LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(option_id) FROM `{$wpdb->options}` {$where}", ...$params ) );

		$sql = "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` {$where} ORDER BY option_name ASC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		$items = array();
		foreach ( $rows as $r ) {
			$val = $r['option_value'];
			$preview = strlen( $val ) > 80 ? substr( $val, 0, 80 ) . '...' : $val;
			$items[] = array(
				'id'       => $r['option_name'],
				'name'     => $r['option_name'],
				'autoload' => $r['autoload'],
				'preview'  => esc_html( $preview ),
				'size'     => strlen( $val ),
			);
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => ceil( $total / $per_page ),
			'items'    => $items,
		);
	}

	/**
	 * Query installed plugins for the DataGrid Lightbox.
	 *
	 * @param string $search
	 * @param int    $page
	 * @param int    $per_page
	 * @return array
	 */
	public static function query_plugins( $search = '', $page = 1, $per_page = 25 ) {
		$all_plugins = VK7K_Sync_Files::get_available_plugins();
		$filtered    = array();

		foreach ( $all_plugins as $p ) {
			if ( ! empty( $search ) ) {
				$match_name = false !== stripos( $p['name'], $search );
				$match_slug = false !== stripos( $p['slug'], $search );
				$match_file = false !== stripos( $p['file'], $search );
				if ( ! $match_name && ! $match_slug && ! $match_file ) {
					continue;
				}
			}
			$filtered[] = $p;
		}

		$total  = count( $filtered );
		$offset = ( $page - 1 ) * $per_page;
		$sliced = array_slice( $filtered, $offset, $per_page );

		$items = array();
		foreach ( $sliced as $p ) {
			$items[] = array(
				'id'        => $p['slug'],
				'title'     => $p['name'],
				'name'      => $p['name'],
				'slug'      => $p['slug'],
				'file'      => $p['file'],
				'version'   => ! empty( $p['version'] ) ? 'v' . $p['version'] : '',
				'status'    => ! empty( $p['is_active'] ) ? 'active' : 'inactive',
				'is_active' => ! empty( $p['is_active'] ),
				'type'      => 'plugin',
				'modified'  => $p['file'],
			);
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => ceil( $total / $per_page ),
			'items'    => $items,
		);
	}

	/**
	 * Query installed themes for the DataGrid Lightbox.
	 *
	 * @param string $search
	 * @param int    $page
	 * @param int    $per_page
	 * @return array
	 */
	public static function query_themes( $search = '', $page = 1, $per_page = 25 ) {
		$all_themes = VK7K_Sync_Files::get_available_themes();
		$filtered   = array();

		foreach ( $all_themes as $t ) {
			if ( ! empty( $search ) ) {
				$match_name = false !== stripos( $t['name'], $search );
				$match_slug = false !== stripos( $t['slug'], $search );
				if ( ! $match_name && ! $match_slug ) {
					continue;
				}
			}
			$filtered[] = $t;
		}

		$total  = count( $filtered );
		$offset = ( $page - 1 ) * $per_page;
		$sliced = array_slice( $filtered, $offset, $per_page );

		$items = array();
		foreach ( $sliced as $t ) {
			$items[] = array(
				'id'        => $t['slug'],
				'title'     => $t['name'],
				'name'      => $t['name'],
				'slug'      => $t['slug'],
				'version'   => ! empty( $t['version'] ) ? 'v' . $t['version'] : '',
				'status'    => ! empty( $t['is_active'] ) ? 'active' : 'inactive',
				'is_active' => ! empty( $t['is_active'] ),
				'type'      => 'theme',
				'modified'  => 'wp-content/themes/' . $t['slug'],
			);
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => ceil( $total / $per_page ),
			'items'    => $items,
		);
	}

	/**
	 * Query WordPress users for the DataGrid Lightbox.
	 *
	 * @param string $search
	 * @param int    $page
	 * @param int    $per_page
	 * @return array
	 */
	public static function query_users( $search = '', $page = 1, $per_page = 25 ) {
		$args = array(
			'number'      => $per_page,
			'offset'      => ( $page - 1 ) * $per_page,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
		);

		if ( ! empty( $search ) ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}

		$user_query = new WP_User_Query( $args );
		$users      = $user_query->get_results();
		$total      = (int) $user_query->get_total();
		if ( ! $total && ! empty( $users ) ) {
			$total = count( $users );
		}

		$items = array();
		foreach ( $users as $u ) {
			$roles = ! empty( $u->roles ) && is_array( $u->roles ) ? implode( ', ', $u->roles ) : 'sin rol';
			$items[] = array(
				'id'        => (int) $u->ID,
				'title'     => $u->display_name ? $u->display_name : $u->user_login,
				'name'      => $u->display_name,
				'slug'      => $u->user_login,
				'email'     => $u->user_email,
				'status'    => strtoupper( $roles ),
				'role'      => $roles,
				'date'      => $u->user_registered,
				'modified'  => $u->user_registered,
				'thumb_url' => get_avatar_url( $u->ID, array( 'size' => 48 ) ),
				'preview'   => esc_html( $u->user_email ),
				'type'      => 'user',
			);
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => ceil( $total / $per_page ),
			'items'    => $items,
		);
	}

	/**
	 * Extract selected entities with automated recursive dependencies:
	 * - Posts / Pages: post row + postmeta + terms + term_relationships + attached media + inline media
	 * - Menus: nav_menu term + term_taxonomy + nav_menu_item posts + item postmeta + theme_mods nav location
	 * - Options: raw option rows
	 * - Plugins & Themes: folder slugs for file synchronization
	 *
	 * @param array $selection Configuration array { posts: [], post_types: [], menus: [], terms: [], options: [], plugins: [], themes: [], sync_media: bool }
	 * @return array Package ready for transmission to /granular/import
	 */
	public static function export_package( $selection = array() ) {
		global $wpdb;

		$selected_post_ids = ! empty( $selection['posts'] ) && is_array( $selection['posts'] ) ? array_map( 'intval', $selection['posts'] ) : array();
		$selected_prod_ids = ! empty( $selection['products'] ) && is_array( $selection['products'] ) ? array_map( 'intval', $selection['products'] ) : array();
		if ( ! empty( $selected_prod_ids ) ) {
			$selected_post_ids = array_merge( $selected_post_ids, $selected_prod_ids );
		}
		$selected_media_ids= ! empty( $selection['media'] ) && is_array( $selection['media'] ) ? array_map( 'intval', $selection['media'] ) : array();
		$selected_menus    = ! empty( $selection['menus'] ) && is_array( $selection['menus'] ) ? array_map( 'intval', $selection['menus'] ) : array();
		$selected_options  = ! empty( $selection['options'] ) && is_array( $selection['options'] ) ? array_map( 'sanitize_text_field', $selection['options'] ) : array();
		$selected_terms    = ! empty( $selection['terms'] ) && is_array( $selection['terms'] ) ? array_map( 'intval', $selection['terms'] ) : array();
		$selected_plugins  = ! empty( $selection['plugins'] ) && is_array( $selection['plugins'] ) ? array_map( 'sanitize_file_name', $selection['plugins'] ) : array();
		$selected_themes   = ! empty( $selection['themes'] ) && is_array( $selection['themes'] ) ? array_map( 'sanitize_file_name', $selection['themes'] ) : array();
		$selected_user_ids = ! empty( $selection['users'] ) && is_array( $selection['users'] ) ? array_map( 'intval', $selection['users'] ) : array();
		$sync_media        = ! empty( $selection['sync_media'] ) || ! empty( $selected_media_ids );

		// Merge explicitly selected media IDs into post IDs
		if ( ! empty( $selected_media_ids ) ) {
			$selected_post_ids = array_merge( $selected_post_ids, $selected_media_ids );
		}

		// 0. Fallback lookup by slug + post_type (e.g. for cross-environment Pull and FSE templates)
		if ( ! empty( $selection['slug'] ) && ! empty( $selection['post_type'] ) ) {
			$found_by_slug = self::resolve_post_id( $selection['slug'], $selection['post_type'] );
			if ( $found_by_slug > 0 ) {
				$selected_post_ids[] = $found_by_slug;
			}
		}

		// 0.1 Expand post_types if present (e.g. from preset recipes like 'Solo Productos y Medios')
		if ( ! empty( $selection['post_types'] ) && is_array( $selection['post_types'] ) ) {
			$escaped_pts = "'" . implode( "', '", array_map( 'esc_sql', $selection['post_types'] ) ) . "'";
			$pt_ids = $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type IN ({$escaped_pts}) AND post_status NOT IN ('trash', 'auto-draft')" );
			if ( ! empty( $pt_ids ) ) {
				$selected_post_ids = array_merge( $selected_post_ids, array_map( 'intval', $pt_ids ) );
			}
		}

		// 0.2 Expand taxonomies if present (e.g. from preset recipes)
		if ( ! empty( $selection['taxonomies'] ) && is_array( $selection['taxonomies'] ) ) {
			$escaped_taxes = "'" . implode( "', '", array_map( 'esc_sql', $selection['taxonomies'] ) ) . "'";
			$tax_term_ids = $wpdb->get_col( "SELECT term_id FROM `{$wpdb->term_taxonomy}` WHERE taxonomy IN ({$escaped_taxes})" );
			if ( ! empty( $tax_term_ids ) ) {
				$selected_terms = array_merge( $selected_terms, array_map( 'intval', $tax_term_ids ) );
			}
		}

		// 1. Expand Menu items
		$menu_term_ids = array();
		if ( ! empty( $selected_menus ) ) {
			foreach ( $selected_menus as $menu_id ) {
				$menu_term_ids[] = (int) $menu_id;
				$items = wp_get_nav_menu_items( $menu_id );
				if ( is_array( $items ) ) {
					foreach ( $items as $it ) {
						$selected_post_ids[] = (int) $it->ID;
					}
				}
			}
		}

		// 1.1 Expand WooCommerce product variations (product_variation child posts)
		if ( ! empty( $selected_post_ids ) ) {
			$p_ids_in = implode( ',', array_map( 'intval', $selected_post_ids ) );
			$variation_ids = $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product_variation' AND post_parent IN ({$p_ids_in})" );
			if ( ! empty( $variation_ids ) ) {
				foreach ( $variation_ids as $var_id ) {
					$selected_post_ids[] = (int) $var_id;
				}
			}
		}

		$selected_post_ids = array_unique( array_filter( $selected_post_ids ) );

		// 2. Discover media dependencies if requested
		$media_post_ids = ! empty( $selected_media_ids ) ? $selected_media_ids : array();
		$media_files    = array();

		if ( $sync_media && ! empty( $selected_post_ids ) ) {
			$ids_in = implode( ',', $selected_post_ids );

			// A) Featured images (_thumbnail_id)
			$thumb_ids = $wpdb->get_col( "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE meta_key = '_thumbnail_id' AND post_id IN ({$ids_in})" );
			foreach ( $thumb_ids as $tid ) {
				$tid = (int) $tid;
				if ( $tid > 0 ) {
					$media_post_ids[] = $tid;
				}
			}

			// B) Attached images (post_parent)
			$parent_attached = $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'attachment' AND post_parent IN ({$ids_in})" );
			foreach ( $parent_attached as $paid ) {
				$media_post_ids[] = (int) $paid;
			}

			// C) WooCommerce gallery images (_product_image_gallery) and variation galleries
			$galleries = $wpdb->get_col( "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE meta_key IN ('_product_image_gallery', '_wc_additional_variation_images', 'variation_image_gallery') AND post_id IN ({$ids_in})" );
			foreach ( $galleries as $gal ) {
				if ( ! empty( $gal ) ) {
					$parts = explode( ',', $gal );
					foreach ( $parts as $p ) {
						$p = (int) trim( $p );
						if ( $p > 0 ) {
							$media_post_ids[] = $p;
						}
					}
				}
			}

			// D) Inline media embedded in Gutenberg blocks / post_content
			$contents = $wpdb->get_col( "SELECT post_content FROM `{$wpdb->posts}` WHERE ID IN ({$ids_in})" );
			foreach ( $contents as $cnt ) {
				if ( empty( $cnt ) ) {
					continue;
				}
				// wp:image / wp:cover / wp:media-text attributes
				if ( preg_match_all( '/<!--\s*wp:(?:image|cover|media-text)[^>]*\{[^}]*"(?:id|mediaId)":\s*(\d+)/i', $cnt, $matches ) ) {
					foreach ( $matches[1] as $mid ) {
						$media_post_ids[] = (int) $mid;
					}
				}
				// Gutenberg gallery block "ids":[12,34]
				if ( preg_match_all( '/"ids":\s*\[([0-9,\s]+)\]/i', $cnt, $matches ) ) {
					foreach ( $matches[1] as $ids_str ) {
						$parts = explode( ',', $ids_str );
						foreach ( $parts as $p ) {
							$p = (int) trim( $p );
							if ( $p > 0 ) {
								$media_post_ids[] = $p;
							}
						}
					}
				}
				// Standard wp-image-123 classes
				if ( preg_match_all( '/wp-image-(\d+)/i', $cnt, $matches ) ) {
					foreach ( $matches[1] as $mid ) {
						$media_post_ids[] = (int) $mid;
					}
				}
			}

			// E) JSON/serialized media in postmeta (Elementor, ACF, Slider Revolution, etc.)
			$meta_values = $wpdb->get_col( "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id IN ({$ids_in}) AND (meta_key = '_elementor_data' OR meta_value LIKE '%\"id\":%' OR meta_value LIKE '%wp-content/uploads%')" );
			foreach ( $meta_values as $mv ) {
				if ( empty( $mv ) ) {
					continue;
				}
				if ( preg_match_all( '/"(?:image|background_image|url|photo|thumbnail)":\s*\{[^}]*"id":\s*(\d+)/i', $mv, $matches ) ) {
					foreach ( $matches[1] as $mid ) {
						$media_post_ids[] = (int) $mid;
					}
				}
				if ( preg_match_all( '/"id":\s*(\d+)/i', $mv, $matches ) ) {
					foreach ( $matches[1] as $mid ) {
						$mid = (int) $mid;
						if ( $mid > 0 ) {
							$media_post_ids[] = $mid;
						}
					}
				}
			}

			// F) Collect media posts and their physical files (including all resized thumbnails)
			$media_post_ids = array_unique( array_filter( $media_post_ids ) );
			$upload_dir     = wp_upload_dir();
			$upload_basedir = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );

			$seen_paths = array();

			foreach ( $media_post_ids as $mid ) {
				// Verify this is a valid attachment
				$att_post = get_post( $mid );
				if ( ! $att_post || 'attachment' !== $att_post->post_type ) {
					continue;
				}

				$selected_post_ids[] = $mid;

				$file_path = get_attached_file( $mid );
				if ( ! $file_path || ! file_exists( $file_path ) ) {
					continue;
				}

				$file_path = wp_normalize_path( $file_path );
				$dir_path  = dirname( $file_path );

				// Main original/scaled file
				$rel_path = ltrim( str_replace( $upload_basedir, '', $file_path ), '/' );
				if ( ! isset( $seen_paths[ $rel_path ] ) ) {
					$seen_paths[ $rel_path ] = true;
					$media_files[] = array(
						'id'       => $mid,
						'rel_path' => $rel_path,
						'abs_path' => $file_path,
						'size'     => filesize( $file_path ),
					);
				}

				// Intermediate sizes generated by WordPress
				$metadata = wp_get_attachment_metadata( $mid );
				if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size_info ) {
						if ( ! empty( $size_info['file'] ) ) {
							$thumb_abs = wp_normalize_path( $dir_path . '/' . $size_info['file'] );
							if ( file_exists( $thumb_abs ) ) {
								$thumb_rel = ltrim( str_replace( $upload_basedir, '', $thumb_abs ), '/' );
								if ( ! isset( $seen_paths[ $thumb_rel ] ) ) {
									$seen_paths[ $thumb_rel ] = true;
									$media_files[] = array(
										'id'       => $mid,
										'rel_path' => $thumb_rel,
										'abs_path' => $thumb_abs,
										'size'     => filesize( $thumb_abs ),
									);
								}
							}
						}
					}
				}

				// Full unscaled original image if WP created -scaled version
				if ( ! empty( $metadata['original_image'] ) ) {
					$orig_abs = wp_normalize_path( $dir_path . '/' . $metadata['original_image'] );
					if ( file_exists( $orig_abs ) ) {
						$orig_rel = ltrim( str_replace( $upload_basedir, '', $orig_abs ), '/' );
						if ( ! isset( $seen_paths[ $orig_rel ] ) ) {
							$seen_paths[ $orig_rel ] = true;
							$media_files[] = array(
								'id'       => $mid,
								'rel_path' => $orig_rel,
								'abs_path' => $orig_abs,
								'size'     => filesize( $orig_abs ),
							);
						}
					}
				}
			}
		}

		$selected_post_ids = array_unique( $selected_post_ids );

		// 3. Fetch Posts rows
		$posts_rows = array();
		if ( ! empty( $selected_post_ids ) ) {
			$ids_in = implode( ',', $selected_post_ids );
			$posts_rows = $wpdb->get_results( "SELECT * FROM `{$wpdb->posts}` WHERE ID IN ({$ids_in})", ARRAY_A );
		}

		// 4. Fetch Postmeta rows
		$postmeta_rows = array();
		if ( ! empty( $selected_post_ids ) ) {
			$ids_in = implode( ',', $selected_post_ids );
			$postmeta_rows = $wpdb->get_results( "SELECT * FROM `{$wpdb->postmeta}` WHERE post_id IN ({$ids_in})", ARRAY_A );
		}

		// 5. Fetch Term relationships & Terms
		$term_rel_rows = array();
		$terms_data    = array();

		if ( ! empty( $selected_post_ids ) ) {
			$ids_in = implode( ',', $selected_post_ids );
			$term_rel_rows = $wpdb->get_results( "SELECT * FROM `{$wpdb->term_relationships}` WHERE object_id IN ({$ids_in})", ARRAY_A );

			$tt_ids = array_unique( wp_list_pluck( $term_rel_rows, 'term_taxonomy_id' ) );
			if ( ! empty( $tt_ids ) ) {
				$tt_in = implode( ',', array_map( 'intval', $tt_ids ) );
				$terms_data = $wpdb->get_results(
					"SELECT t.*, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count 
					 FROM `{$wpdb->terms}` t 
					 INNER JOIN `{$wpdb->term_taxonomy}` tt ON t.term_id = tt.term_id 
					 WHERE tt.term_taxonomy_id IN ({$tt_in})",
					ARRAY_A
				);
			}
		}

		// Include explicitly selected menu terms if any
		if ( ! empty( $menu_term_ids ) ) {
			$m_in = implode( ',', $menu_term_ids );
			$menu_terms = $wpdb->get_results(
				"SELECT t.*, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count 
				 FROM `{$wpdb->terms}` t 
				 INNER JOIN `{$wpdb->term_taxonomy}` tt ON t.term_id = tt.term_id 
				 WHERE t.term_id IN ({$m_in})",
				ARRAY_A
			);
			$terms_data = array_merge( $terms_data, $menu_terms );
		}

		// 5.1 Fetch Termmeta rows
		$termmeta_rows = array();
		if ( ! empty( $terms_data ) ) {
			$term_ids = array_unique( array_filter( array_map( 'intval', wp_list_pluck( $terms_data, 'term_id' ) ) ) );
			if ( ! empty( $term_ids ) ) {
				$t_in = implode( ',', $term_ids );
				$termmeta_rows = $wpdb->get_results( "SELECT * FROM `{$wpdb->termmeta}` WHERE term_id IN ({$t_in})", ARRAY_A );

				// Discover category thumbnail images if requested
				if ( $sync_media && ! empty( $termmeta_rows ) ) {
					foreach ( $termmeta_rows as $tm ) {
						if ( 'thumbnail_id' === $tm['meta_key'] && (int) $tm['meta_value'] > 0 ) {
							$t_mid = (int) $tm['meta_value'];
							if ( ! in_array( $t_mid, $media_post_ids, true ) ) {
								$media_post_ids[] = $t_mid;
							}
						}
					}
				}
			}
		}

		// 5.2 Fetch WooCommerce Attribute Taxonomies definitions (pa_*)
		$wc_attr_taxonomies = array();
		$attr_tax_names     = array();
		if ( ! empty( $terms_data ) ) {
			foreach ( $terms_data as $td ) {
				if ( ! empty( $td['taxonomy'] ) && 0 === strpos( $td['taxonomy'], 'pa_' ) ) {
					$attr_tax_names[] = substr( $td['taxonomy'], 3 );
				}
			}
		}
		if ( ! empty( $postmeta_rows ) ) {
			foreach ( $postmeta_rows as $pm ) {
				if ( '_product_attributes' === $pm['meta_key'] && ! empty( $pm['meta_value'] ) ) {
					$attrs = maybe_unserialize( $pm['meta_value'] );
					if ( is_array( $attrs ) ) {
						foreach ( array_keys( $attrs ) as $attr_key ) {
							if ( 0 === strpos( $attr_key, 'pa_' ) ) {
								$attr_tax_names[] = substr( $attr_key, 3 );
							}
						}
					}
				}
			}
		}
		$attr_tax_names = array_unique( array_filter( $attr_tax_names ) );
		if ( ! empty( $attr_tax_names ) ) {
			$wc_attr_table = "{$wpdb->prefix}woocommerce_attribute_taxonomies";
			$table_exists  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wc_attr_table ) );
			if ( $table_exists ) {
				$escaped_names = implode( "','", array_map( 'esc_sql', $attr_tax_names ) );
				$wc_attr_taxonomies = $wpdb->get_results( "SELECT * FROM `{$wc_attr_table}` WHERE attribute_name IN ('{$escaped_names}')", ARRAY_A );
			}
		}

		// 5.3 Fetch WooCommerce Product Meta Lookup rows
		$wc_lookup_rows = array();
		if ( ! empty( $selected_post_ids ) ) {
			$lookup_table = "{$wpdb->prefix}wc_product_meta_lookup";
			$has_lookup   = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lookup_table ) );
			if ( $has_lookup ) {
				$p_in = implode( ',', array_map( 'intval', $selected_post_ids ) );
				$wc_lookup_rows = $wpdb->get_results( "SELECT * FROM `{$lookup_table}` WHERE product_id IN ({$p_in})", ARRAY_A );
			}
		}

		// 6. Fetch Options rows
		$options_rows = array();
		if ( ! empty( $selected_options ) ) {
			foreach ( $selected_options as $opt_pattern ) {
				if ( false !== strpos( $opt_pattern, '*' ) ) {
					$like_sql = str_replace( '*', '%', $wpdb->esc_like( $opt_pattern ) );
					$wild_rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name LIKE %s", $like_sql ), ARRAY_A );
					$options_rows = array_merge( $options_rows, $wild_rows );
				} else {
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name = %s", $opt_pattern ), ARRAY_A );
					if ( $row ) {
						$options_rows[] = $row;
					}
				}
			}
		}

		// Also check theme_mods when menus are selected
		if ( ! empty( $selected_menus ) ) {
			$theme = get_stylesheet();
			$theme_mod_key = "theme_mods_{$theme}";
			$t_row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name = %s", $theme_mod_key ), ARRAY_A );
			if ( $t_row ) {
				$options_rows[] = $t_row;
			}
			$nav_opt = $wpdb->get_row( "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name = 'nav_menu_options'", ARRAY_A );
			if ( $nav_opt ) {
				$options_rows[] = $nav_opt;
			}
		}

		// 7. Fetch Users rows & Usermeta rows
		$users_rows    = array();
		$usermeta_rows = array();
		if ( ! empty( $selected_user_ids ) ) {
			$u_in = implode( ',', $selected_user_ids );
			$users_rows    = $wpdb->get_results( "SELECT * FROM `{$wpdb->users}` WHERE ID IN ({$u_in})", ARRAY_A );
			$usermeta_rows = $wpdb->get_results( "SELECT * FROM `{$wpdb->usermeta}` WHERE user_id IN ({$u_in})", ARRAY_A );
		}

		return array(
			'source_url'         => home_url(),
			'source_prefix'      => $wpdb->prefix,
			'posts'              => $posts_rows,
			'postmeta'           => $postmeta_rows,
			'term_relationships' => $term_rel_rows,
			'terms'              => $terms_data,
			'options'            => $options_rows,
			'media_files'        => $media_files,
			'plugins'                 => $selected_plugins,
			'themes'                  => $selected_themes,
			'users'                   => $users_rows,
			'usermeta'                => $usermeta_rows,
			'termmeta'                => $termmeta_rows,
			'wc_attribute_taxonomies' => $wc_attr_taxonomies,
			'wc_product_meta_lookup'  => $wc_lookup_rows,
		);
	}

	/**
	 * Inspect single post dependencies (media, terms, meta) for Editor push/pull panel.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function inspect_single_post( $post_id ) {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$thumb_id  = get_post_thumbnail_id( $post->ID );
		$thumb_url = $thumb_id ? wp_get_attachment_thumb_url( $thumb_id ) : '';

		// Count meta
		$meta_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(meta_id) FROM `{$wpdb->postmeta}` WHERE post_id = %d",
			$post->ID
		) );

		// Count terms
		$terms_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(term_taxonomy_id) FROM `{$wpdb->term_relationships}` WHERE object_id = %d",
			$post->ID
		) );

		// Run export with media discovery to count media dependencies
		$pkg = self::export_package( array(
			'posts'      => array( $post->ID ),
			'sync_media' => true,
		) );

		$media_count = ! empty( $pkg['media_files'] ) ? count( $pkg['media_files'] ) : 0;

		$is_product       = ( 'product' === $post->post_type );
		$product_type     = 'simple';
		$sku              = '';
		$variations_count = 0;
		$gallery_count    = 0;

		if ( $is_product ) {
			$sku = (string) get_post_meta( $post->ID, '_sku', true );
			$terms = wp_get_post_terms( $post->ID, 'product_type' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$product_type = $terms[0]->name;
			}
			$variations_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(ID) FROM `{$wpdb->posts}` WHERE post_type = 'product_variation' AND post_parent = %d",
				$post->ID
			) );
			$gal_meta = get_post_meta( $post->ID, '_product_image_gallery', true );
			if ( ! empty( $gal_meta ) ) {
				$gallery_count = count( array_filter( explode( ',', $gal_meta ) ) );
			}
		}

		return array(
			'id'               => (int) $post->ID,
			'title'            => ! empty( $post->post_title ) ? $post->post_title : '(sin título - #' . $post->ID . ')',
			'slug'             => $post->post_name,
			'type'             => $post->post_type,
			'status'           => $post->post_status,
			'modified'         => $post->post_modified,
			'thumb_url'        => $thumb_url ?: '',
			'media_count'      => $media_count,
			'meta_count'       => $meta_count,
			'terms_count'      => $terms_count,
			'is_product'       => $is_product,
			'product_type'     => $product_type,
			'sku'              => $sku,
			'variations_count' => $variations_count,
			'gallery_count'    => $gallery_count,
		);
	}

	/**
	 * Resolve post ID from slug and post_type, with FSE theme fallback.
	 *
	 * @param string $slug
	 * @param string $post_type
	 * @return int
	 */
	public static function resolve_post_id( $slug, $post_type = 'post' ) {
		global $wpdb;

		if ( empty( $slug ) ) {
			return 0;
		}

		$clean_slug = sanitize_title( $slug );
		$post_type  = sanitize_key( $post_type );

		// 1. Check in wp_posts by exact post_name or prefixed by theme ("theme//slug")
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM `{$wpdb->posts}` WHERE (post_name = %s OR post_name LIKE %s) AND post_type = %s AND post_status NOT IN ('auto-draft', 'trash') ORDER BY ID DESC LIMIT 1",
			$clean_slug,
			'%//' . $wpdb->esc_like( $clean_slug ),
			$post_type
		) );

		if ( $id > 0 ) {
			return $id;
		}

		// 2. Fallback for FSE templates/parts that exist in theme files but have not been saved to DB yet
		if ( in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true ) && function_exists( 'get_block_template' ) ) {
			$t = get_block_template( get_stylesheet() . '//' . $clean_slug, $post_type );
			if ( ! $t && get_stylesheet() !== get_template() ) {
				$t = get_block_template( get_template() . '//' . $clean_slug, $post_type );
			}
			if ( $t && ! empty( $t->content ) ) {
				$new_id = wp_insert_post( array(
					'post_type'    => $post_type,
					'post_status'  => 'publish',
					'post_title'   => ! empty( $t->title ) ? $t->title : $clean_slug,
					'post_name'    => $clean_slug,
					'post_content' => $t->content,
				) );
				if ( $new_id && ! is_wp_error( $new_id ) ) {
					wp_set_post_terms( $new_id, get_stylesheet(), 'wp_theme' );
					return (int) $new_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Import and synchronize users and usermeta into the local/target site.
	 *
	 * @param array  $users
	 * @param array  $usermeta
	 * @param string $source_prefix
	 * @return array
	 */
	public static function import_users( $users = array(), $usermeta = array(), $source_prefix = 'wp_' ) {
		global $wpdb;

		if ( empty( $users ) || ! is_array( $users ) ) {
			return array( 'success' => true, 'users_synced' => 0 );
		}

		// Group usermeta by source user_id
		$meta_by_user = array();
		if ( ! empty( $usermeta ) && is_array( $usermeta ) ) {
			foreach ( $usermeta as $m ) {
				$uid = (int) ( $m['user_id'] ?? 0 );
				if ( $uid > 0 ) {
					$meta_by_user[ $uid ][] = $m;
				}
			}
		}

		$synced_count  = 0;
		$target_prefix = $wpdb->prefix;

		foreach ( $users as $u ) {
			$source_id  = (int) ( $u['ID'] ?? 0 );
			$user_login = sanitize_user( $u['user_login'] ?? '' );
			$user_email = sanitize_email( $u['user_email'] ?? '' );

			if ( empty( $user_login ) || empty( $user_email ) ) {
				continue;
			}

			// Check if user already exists by login or email
			$existing = get_user_by( 'login', $user_login );
			if ( ! $existing ) {
				$existing = get_user_by( 'email', $user_email );
			}

			$target_id = 0;

			if ( $existing ) {
				$target_id = (int) $existing->ID;

				// Update core user fields
				$update_data = array(
					'user_nicename' => $u['user_nicename'] ?? $user_login,
					'user_email'    => $user_email,
					'user_url'      => $u['user_url'] ?? '',
					'display_name'  => $u['display_name'] ?? $user_login,
				);
				if ( ! empty( $u['user_pass'] ) ) {
					$update_data['user_pass'] = $u['user_pass'];
				}

				$wpdb->update( $wpdb->users, $update_data, array( 'ID' => $target_id ) );
			} else {
				// User does not exist, insert
				$id_taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM `{$wpdb->users}` WHERE ID = %d", $source_id ) );
				if ( $source_id > 0 && ! $id_taken ) {
					$target_id   = $source_id;
					$insert_data = array(
						'ID'                  => $target_id,
						'user_login'          => $user_login,
						'user_pass'           => ! empty( $u['user_pass'] ) ? $u['user_pass'] : wp_generate_password( 24 ),
						'user_nicename'       => $u['user_nicename'] ?? $user_login,
						'user_email'          => $user_email,
						'user_url'            => $u['user_url'] ?? '',
						'user_registered'     => ! empty( $u['user_registered'] ) ? $u['user_registered'] : current_time( 'mysql' ),
						'user_activation_key' => $u['user_activation_key'] ?? '',
						'user_status'         => (int) ( $u['user_status'] ?? 0 ),
						'display_name'        => $u['display_name'] ?? $user_login,
					);
					$wpdb->insert( $wpdb->users, $insert_data );
				} else {
					$insert_data = array(
						'user_login'          => $user_login,
						'user_pass'           => ! empty( $u['user_pass'] ) ? $u['user_pass'] : wp_generate_password( 24 ),
						'user_nicename'       => $u['user_nicename'] ?? $user_login,
						'user_email'          => $user_email,
						'user_url'            => $u['user_url'] ?? '',
						'user_registered'     => ! empty( $u['user_registered'] ) ? $u['user_registered'] : current_time( 'mysql' ),
						'user_activation_key' => $u['user_activation_key'] ?? '',
						'user_status'         => (int) ( $u['user_status'] ?? 0 ),
						'display_name'        => $u['display_name'] ?? $user_login,
					);
					$wpdb->insert( $wpdb->users, $insert_data );
					$target_id = (int) $wpdb->insert_id;
				}
			}

			if ( $target_id <= 0 ) {
				continue;
			}

			// Synchronize usermeta
			if ( isset( $meta_by_user[ $source_id ] ) ) {
				$skip_keys = array( 'session_tokens', 'vk7k_vault_unlocked_session' );

				foreach ( $meta_by_user[ $source_id ] as $m_row ) {
					$orig_key = $m_row['meta_key'] ?? '';
					$meta_val = $m_row['meta_value'] ?? '';

					if ( empty( $orig_key ) || in_array( $orig_key, $skip_keys, true ) ) {
						continue;
					}

					// Adapt table prefix in meta_key (e.g. wp_capabilities -> KqV95K_capabilities)
					$target_key = $orig_key;
					if ( ! empty( $source_prefix ) && 0 === strpos( $orig_key, $source_prefix ) ) {
						$target_key = $target_prefix . substr( $orig_key, strlen( $source_prefix ) );
					}

					$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $target_id, 'meta_key' => $target_key ) );
					$wpdb->insert( $wpdb->usermeta, array(
						'user_id'    => $target_id,
						'meta_key'   => $target_key,
						'meta_value' => $meta_val,
					) );
				}
			}

			clean_user_cache( $target_id );
			$synced_count++;
		}

		return array(
			'success'      => true,
			'users_synced' => $synced_count,
		);
	}
}

