<?php
/**
 * VK7K Sync Recipes Engine
 *
 * Handles saving, loading, listing, deleting, and vault-shielding synchronization recipes.
 * Dual-storage architecture: stored in wp_options AND in protected persistent PHP file
 * in WP_CONTENT_DIR or uploads, ensuring recipes survive any database migrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Recipes {

	const OPTION_KEY = 'vk7k_sync_recipes';

	/**
	 * Get candidate persistent disk recipes file paths.
	 * Formatted as protected PHP file: <?php exit; ?> return [...];
	 *
	 * @return array
	 */
	public static function get_recipes_file_paths() {
		$paths = array();

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$paths[] = trailingslashit( WP_CONTENT_DIR ) . '.vk7k_sync_recipes.php';
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			if ( ! empty( $upload_dir['basedir'] ) ) {
				$paths[] = trailingslashit( $upload_dir['basedir'] ) . '.vk7k_sync_recipes.php';
			}
		}

		return array_unique( $paths );
	}

	/**
	 * Get active persistent disk recipes file path.
	 *
	 * @return string
	 */
	public static function get_recipes_file_path() {
		$paths = self::get_recipes_file_paths();
		foreach ( $paths as $p ) {
			if ( file_exists( $p ) ) {
				return $p;
			}
		}
		return reset( $paths );
	}

	/**
	 * Read recipes from disk file.
	 *
	 * @return array
	 */
	public static function get_disk_recipes() {
		$paths = self::get_recipes_file_paths();
		foreach ( $paths as $file ) {
			if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
				continue;
			}
			$recipes = @include $file;
			if ( is_array( $recipes ) ) {
				return $recipes;
			}
		}
		return array();
	}

	/**
	 * Save recipes to disk file.
	 *
	 * @param array $recipes
	 * @return bool
	 */
	public static function save_disk_recipes( $recipes ) {
		if ( ! is_array( $recipes ) ) {
			return false;
		}

		$php_content = "<?php\n" .
			"// VK7K Sync Recipes Vault - Direct web execution prohibited\n" .
			"if ( ! defined( 'ABSPATH' ) ) { exit; }\n" .
			"return " . var_export( $recipes, true ) . ";\n";

		$paths = self::get_recipes_file_paths();
		$saved = false;

		foreach ( $paths as $target_file ) {
			if ( false !== @file_put_contents( $target_file, $php_content ) ) {
				@chmod( $target_file, 0600 );
				$saved = true;
				break;
			}
		}

		return $saved;
	}

	/**
	 * Get all saved recipes (merged from DB and protected disk storage).
	 *
	 * @return array Array of recipes indexed by recipe ID.
	 */
	public static function get_all() {
		$db_recipes   = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $db_recipes ) ) {
			$db_recipes = array();
		}

		$disk_recipes = self::get_disk_recipes();

		// Merge: preserve whichever has the newest updated_at or non-empty entries
		$merged = $db_recipes;
		foreach ( $disk_recipes as $id => $recipe ) {
			if ( ! isset( $merged[ $id ] ) ) {
				$merged[ $id ] = $recipe;
			} else {
				$disk_time = isset( $recipe['updated_at'] ) ? (int) $recipe['updated_at'] : 0;
				$db_time   = isset( $merged[ $id ]['updated_at'] ) ? (int) $merged[ $id ]['updated_at'] : 0;
				if ( $disk_time > $db_time ) {
					$merged[ $id ] = $recipe;
				}
			}
		}

		// Ensure defaults exist if totally empty
		if ( empty( $merged ) ) {
			$defaults = self::get_default_presets();
			foreach ( $defaults as $def ) {
				$merged[ $def['id'] ] = $def;
			}
			self::save_all( $merged );
		}

		return $merged;
	}

	/**
	 * Get a specific recipe by ID.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public static function get( $id ) {
		$recipes = self::get_all();
		return isset( $recipes[ $id ] ) ? $recipes[ $id ] : null;
	}

	/**
	 * Save all recipes into both DB and disk vault.
	 *
	 * @param array $recipes
	 * @return bool
	 */
	public static function save_all( $recipes ) {
		if ( ! is_array( $recipes ) ) {
			return false;
		}

		update_option( self::OPTION_KEY, $recipes, false );
		self::save_disk_recipes( $recipes );
		return true;
	}

	/**
	 * Create or update a recipe.
	 *
	 * @param array $recipe_data
	 * @return array Saved recipe or WP_Error.
	 */
	public static function save_recipe( $recipe_data ) {
		if ( empty( $recipe_data['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'El nombre de la receta es obligatorio.', 'vk7k-wp-sync' ) );
		}

		$recipes = self::get_all();
		$id      = ! empty( $recipe_data['id'] ) ? sanitize_key( $recipe_data['id'] ) : sanitize_title( $recipe_data['name'] );

		if ( empty( $id ) ) {
			$id = 'recipe_' . time();
		}

		$clean_recipe = array(
			'id'          => $id,
			'name'        => sanitize_text_field( $recipe_data['name'] ),
			'description' => ! empty( $recipe_data['description'] ) ? sanitize_textarea_field( $recipe_data['description'] ) : '',
			'direction'   => ! empty( $recipe_data['direction'] ) && in_array( $recipe_data['direction'], array( 'push', 'pull', 'bidirectional' ), true ) ? $recipe_data['direction'] : 'push',
			'entities'    => array(
				'posts'      => ! empty( $recipe_data['entities']['posts'] ) && is_array( $recipe_data['entities']['posts'] ) ? array_map( 'intval', $recipe_data['entities']['posts'] ) : array(),
				'post_types' => ! empty( $recipe_data['entities']['post_types'] ) && is_array( $recipe_data['entities']['post_types'] ) ? array_map( 'sanitize_key', $recipe_data['entities']['post_types'] ) : array(),
				'menus'      => ! empty( $recipe_data['entities']['menus'] ) && is_array( $recipe_data['entities']['menus'] ) ? array_map( 'intval', $recipe_data['entities']['menus'] ) : array(),
				'taxonomies' => ! empty( $recipe_data['entities']['taxonomies'] ) && is_array( $recipe_data['entities']['taxonomies'] ) ? array_map( 'sanitize_key', $recipe_data['entities']['taxonomies'] ) : array(),
				'terms'      => ! empty( $recipe_data['entities']['terms'] ) && is_array( $recipe_data['entities']['terms'] ) ? array_map( 'intval', $recipe_data['entities']['terms'] ) : array(),
				'fse'        => ! empty( $recipe_data['entities']['fse'] ) && is_array( $recipe_data['entities']['fse'] ) ? array_map( 'sanitize_text_field', $recipe_data['entities']['fse'] ) : array(),
				'options'    => ! empty( $recipe_data['entities']['options'] ) && is_array( $recipe_data['entities']['options'] ) ? array_map( 'sanitize_text_field', $recipe_data['entities']['options'] ) : array(),
				'plugins'    => ! empty( $recipe_data['entities']['plugins'] ) && is_array( $recipe_data['entities']['plugins'] ) ? array_map( 'sanitize_file_name', $recipe_data['entities']['plugins'] ) : array(),
				'themes'     => ! empty( $recipe_data['entities']['themes'] ) && is_array( $recipe_data['entities']['themes'] ) ? array_map( 'sanitize_file_name', $recipe_data['entities']['themes'] ) : array(),
				'users'      => ! empty( $recipe_data['entities']['users'] ) && is_array( $recipe_data['entities']['users'] ) ? array_map( 'intval', $recipe_data['entities']['users'] ) : array(),
			),
			'sync_media'  => ! empty( $recipe_data['sync_media'] ),
			'is_builtin'  => ! empty( $recipe_data['is_builtin'] ),
			'updated_at'  => time(),
		);

		$recipes[ $id ] = $clean_recipe;
		self::save_all( $recipes );

		return $clean_recipe;
	}

	/**
	 * Delete a recipe by ID.
	 * Built-in presets cannot be deleted (unless forced).
	 *
	 * @param string $id
	 * @param bool   $force
	 * @return bool|WP_Error
	 */
	public static function delete_recipe( $id, $force = false ) {
		$recipes = self::get_all();
		if ( ! isset( $recipes[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Receta no encontrada.', 'vk7k-wp-sync' ) );
		}

		if ( ! empty( $recipes[ $id ]['is_builtin'] ) && ! $force ) {
			return new WP_Error( 'builtin_protected', __( 'Las recetas por defecto del sistema no pueden eliminarse.', 'vk7k-wp-sync' ) );
		}

		unset( $recipes[ $id ] );
		self::save_all( $recipes );
		return true;
	}

	/**
	 * Built-in preset recipes.
	 *
	 * @return array
	 */
	public static function get_default_presets() {
		return array(
			array(
				'id'          => 'preset_products_only',
				'name'        => __( 'Solo Productos y Medios', 'vk7k-wp-sync' ),
				'description' => __( 'Sincroniza catálogo de productos WooCommerce, categorías y archivos multimedia asociados.', 'vk7k-wp-sync' ),
				'direction'   => 'push',
				'entities'    => array(
					'posts'      => array(),
					'post_types' => array( 'product', 'product_variation' ),
					'menus'      => array(),
					'taxonomies' => array( 'product_cat', 'product_tag' ),
					'terms'      => array(),
					'fse'        => array(),
					'options'    => array(),
				),
				'sync_media'  => true,
				'is_builtin'  => true,
				'updated_at'  => time(),
			),
			array(
				'id'          => 'preset_design_navigation',
				'name'        => __( 'Diseño y Navegación (Menús + FSE)', 'vk7k-wp-sync' ),
				'description' => __( 'Sincroniza menús de navegación, plantillas FSE de bloque y configuraciones visuales del tema activo.', 'vk7k-wp-sync' ),
				'direction'   => 'push',
				'entities'    => array(
					'posts'      => array(),
					'post_types' => array( 'nav_menu_item', 'wp_template', 'wp_template_part' ),
					'menus'      => array(),
					'taxonomies' => array( 'nav_menu' ),
					'terms'      => array(),
					'fse'        => array( 'templates', 'template-parts', 'patterns' ),
					'options'    => array( 'theme_mods_*', 'nav_menu_options' ),
				),
				'sync_media'  => true,
				'is_builtin'  => true,
				'updated_at'  => time(),
			),
		);
	}
}
