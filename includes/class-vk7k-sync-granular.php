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

		return array(
			'post_types' => array_values( $post_type_list ),
			'menus'      => $menus,
			'taxonomies' => $taxonomies,
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

		// Handle taxonomies tab query
		if ( 'terms' === $type || 'taxonomies' === $type ) {
			$taxonomy = ! empty( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
			return self::query_terms( $taxonomy, $search, $page, $per_page );
		}

		// Standard posts / pages / CPTs query
		$where = "WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash')";
		$params = array( $type );

		if ( ! empty( $search ) ) {
			$where .= " AND (post_title LIKE %s OR post_name LIKE %s)";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM `{$wpdb->posts}` {$where}", ...$params ) );

		$sql = "SELECT ID, post_title, post_name, post_status, post_type, post_date, post_modified 
				FROM `{$wpdb->posts}` {$where} 
				ORDER BY post_modified DESC 
				LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		$items = array();
		foreach ( $rows as $row ) {
			$thumb_id  = get_post_thumbnail_id( $row['ID'] );
			$thumb_url = $thumb_id ? wp_get_attachment_thumb_url( $thumb_id ) : '';

			$items[] = array(
				'id'          => (int) $row['ID'],
				'title'       => ! empty( $row['post_title'] ) ? $row['post_title'] : '(sin título - #' . $row['ID'] . ')',
				'slug'        => $row['post_name'],
				'status'      => $row['post_status'],
				'type'        => $row['post_type'],
				'date'        => $row['post_date'],
				'modified'    => $row['post_modified'],
				'thumb_url'   => $thumb_url ?: '',
				'view_url'    => get_permalink( $row['ID'] ) ?: '',
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
	 * Extract selected entities with automated recursive dependencies:
	 * - Posts / Pages: post row + postmeta + terms + term_relationships + attached media + inline media
	 * - Menus: nav_menu term + term_taxonomy + nav_menu_item posts + item postmeta + theme_mods nav location
	 * - Options: raw option rows
	 *
	 * @param array $selection Configuration array { posts: [], post_types: [], menus: [], terms: [], options: [], sync_media: bool }
	 * @return array Package ready for transmission to /granular/import
	 */
	public static function export_package( $selection = array() ) {
		global $wpdb;

		$selected_post_ids = ! empty( $selection['posts'] ) && is_array( $selection['posts'] ) ? array_map( 'intval', $selection['posts'] ) : array();
		$selected_menus    = ! empty( $selection['menus'] ) && is_array( $selection['menus'] ) ? array_map( 'intval', $selection['menus'] ) : array();
		$selected_options  = ! empty( $selection['options'] ) && is_array( $selection['options'] ) ? array_map( 'sanitize_text_field', $selection['options'] ) : array();
		$selected_terms    = ! empty( $selection['terms'] ) && is_array( $selection['terms'] ) ? array_map( 'intval', $selection['terms'] ) : array();
		$sync_media        = ! empty( $selection['sync_media'] );

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

		$selected_post_ids = array_unique( array_filter( $selected_post_ids ) );

		// 2. Discover media dependencies if requested
		$media_post_ids = array();
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

			// C) WooCommerce gallery images (_product_image_gallery)
			$galleries = $wpdb->get_col( "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE meta_key = '_product_image_gallery' AND post_id IN ({$ids_in})" );
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

			// Add media posts into posts to sync
			$media_post_ids = array_unique( $media_post_ids );
			foreach ( $media_post_ids as $mid ) {
				$selected_post_ids[] = $mid;
				// Collect file path
				$file_path = get_attached_file( $mid );
				if ( $file_path && file_exists( $file_path ) ) {
					$upload_dir = wp_upload_dir();
					$rel_path   = ltrim( str_replace( wp_normalize_path( $upload_dir['basedir'] ), '', wp_normalize_path( $file_path ) ), '/' );
					$media_files[] = array(
						'id'       => $mid,
						'rel_path' => $rel_path,
						'abs_path' => $file_path,
						'size'     => filesize( $file_path ),
					);
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

		return array(
			'source_url'         => home_url(),
			'posts'              => $posts_rows,
			'postmeta'           => $postmeta_rows,
			'term_relationships' => $term_rel_rows,
			'terms'              => $terms_data,
			'options'            => $options_rows,
			'media_files'        => $media_files,
		);
	}
}
