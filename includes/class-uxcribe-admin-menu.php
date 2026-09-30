<?php
/**
 * Uxcribe Shared Admin Menu Handler
 *
 * Provides a unified top-level WordPress admin menu for all Uxcribe.cl plugins.
 *
 * @package Uxcribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uxcribe_Admin_Menu' ) ) {

	/**
	 * Class Uxcribe_Admin_Menu
	 */
	class Uxcribe_Admin_Menu {

		/**
		 * Parent menu slug.
		 */
		const PARENT_SLUG = 'uxcribe';

		/**
		 * Parent menu capability.
		 */
		const CAPABILITY = 'manage_options';

		/**
		 * Parent menu position.
		 */
		const POSITION = 65;

		/**
		 * Track registered subpages.
		 *
		 * @var array
		 */
		private static $subpages = array();

		/**
		 * Initialized flag.
		 *
		 * @var bool
		 */
		private static $initialized = false;

		/**
		 * Initialize the menu hooks.
		 */
		public static function init() {
			if ( self::$initialized ) {
				return;
			}
			self::$initialized = true;

			// Register parent menu with early priority (9) so it exists before any plugin registers submenus (10).
			add_action( 'admin_menu', array( __CLASS__, 'register_parent_menu' ), 9 );

			// Clean up duplicate first submenu and handle redirects with late priority (999).
			add_action( 'admin_menu', array( __CLASS__, 'adjust_menu_items' ), 999 );
		}

		/**
		 * Register top-level parent menu for Uxcribe.cl.
		 */
		public static function register_parent_menu() {
			global $admin_page_hooks;

			if ( ! isset( $admin_page_hooks[ self::PARENT_SLUG ] ) ) {
				// Uxcribe icon as inline optimized SVG
				$icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
					'<path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>' .
					'</svg>'
				);

				add_menu_page(
					__( 'Uxcribe.cl', 'uxcribe' ),
					__( 'Uxcribe.cl', 'uxcribe' ),
					self::CAPABILITY,
					self::PARENT_SLUG,
					array( __CLASS__, 'render_parent_page' ),
					$icon_svg,
					self::POSITION
				);
			}
		}

		/**
		 * Register a submenu page under the Uxcribe parent menu.
		 *
		 * @param string   $page_title The text to be displayed in the title tags.
		 * @param string   $menu_title The text to be used for the menu.
		 * @param string   $capability The capability required for this menu.
		 * @param string   $menu_slug  The slug name to refer to this menu by.
		 * @param callable $callback   The function to be called to output the content for this page.
		 * @param int|null $position   The position in the menu order this item should appear.
		 * @return string|false The resulting page's hook_suffix, or false if user lacks capability.
		 */
		public static function add_subpage( $page_title, $menu_title, $capability, $menu_slug, $callback, $position = null ) {
			self::init();

			self::$subpages[] = array(
				'page_title' => $page_title,
				'menu_title' => $menu_title,
				'menu_slug'  => $menu_slug,
			);

			return add_submenu_page(
				self::PARENT_SLUG,
				$page_title,
				$menu_title,
				$capability,
				$menu_slug,
				$callback,
				$position
			);
		}

		/**
		 * Adjust submenu items to ensure clean hierarchy.
		 */
		public static function adjust_menu_items() {
			global $submenu;

			// If the parent menu has submenus, rename the first duplicate item if needed
			if ( isset( $submenu[ self::PARENT_SLUG ] ) && ! empty( $submenu[ self::PARENT_SLUG ] ) ) {
				// If the first submenu item is the exact duplicate of the parent 'Uxcribe.cl', we can adjust its title or remove it if subpages exist
				if ( count( $submenu[ self::PARENT_SLUG ] ) > 1 && $submenu[ self::PARENT_SLUG ][0][2] === self::PARENT_SLUG ) {
					// Remove the redundant top-level duplicate so the first plugin is top item
					unset( $submenu[ self::PARENT_SLUG ][0] );
					$submenu[ self::PARENT_SLUG ] = array_values( $submenu[ self::PARENT_SLUG ] );
				}
			}
		}

		/**
		 * Fallback callback if parent menu is opened directly.
		 */
		public static function render_parent_page() {
			// If we have registered subpages, redirect to the first one
			if ( ! empty( self::$subpages ) ) {
				$first_slug = self::$subpages[0]['menu_slug'];
				wp_safe_redirect( admin_url( 'admin.php?page=' . $first_slug ) );
				exit;
			}

			// Default dashboard view
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Plugins de Uxcribe.cl', 'uxcribe' ); ?></h1>
				<p><?php esc_html_e( 'Bienvenido al panel central de extensiones de Uxcribe.cl.', 'uxcribe' ); ?></p>
			</div>
			<?php
		}
	}
}

// Auto-initialize when file is included
Uxcribe_Admin_Menu::init();
