<?php
/**
 * VK7K Sync Admin View & Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Admin {

	/**
	 * Menu hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_legacy_slug_redirect' ) );
	}

	/**
	 * Redirect legacy/short slug to official page slug.
	 */
	public function handle_legacy_slug_redirect() {
		if ( isset( $_GET['page'] ) ) {
			$page = sanitize_key( $_GET['page'] );
			if ( 'vk7k-sync' === $page || 'vk7k-wp-sync' === $page ) {
				wp_safe_redirect( admin_url( 'admin.php?page=uxcribe-sync' ) );
				exit;
			}
		}
	}

	/**
	 * Register Admin Menu under the shared Uxcribe parent menu.
	 */
	public function register_admin_menu() {
		if ( class_exists( 'Uxcribe_Admin_Menu' ) ) {
			$this->hook_suffix = Uxcribe_Admin_Menu::add_subpage(
				__( 'uxcribe.cl Sync', 'vk7k-wp-sync' ),
				__( 'Sync', 'vk7k-wp-sync' ),
				'manage_options',
				'uxcribe-sync',
				array( $this, 'render_admin_page' )
			);
		} else {
			$this->hook_suffix = add_menu_page(
				__( 'uxcribe.cl Sync', 'vk7k-wp-sync' ),
				__( 'uxcribe.cl Sync', 'vk7k-wp-sync' ),
				'manage_options',
				'uxcribe-sync',
				array( $this, 'render_admin_page' ),
				'dashicons-randomize',
				75
			);
		}

		// Register legacy slug aliases as hidden pages to prevent "No tienes permisos" error
		add_submenu_page(
			null,
			__( 'uxcribe.cl Sync', 'vk7k-wp-sync' ),
			__( 'Sync', 'vk7k-wp-sync' ),
			'manage_options',
			'vk7k-wp-sync',
			array( $this, 'render_admin_page' )
		);
		add_submenu_page(
			null,
			__( 'uxcribe.cl Sync', 'vk7k-wp-sync' ),
			__( 'Sync', 'vk7k-wp-sync' ),
			'manage_options',
			'vk7k-sync',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue Admin CSS & JS.
	 */
	public function enqueue_assets( $hook ) {
		$is_sync_page = false;
		if ( ! empty( $this->hook_suffix ) && $hook === $this->hook_suffix ) {
			$is_sync_page = true;
		} elseif ( false !== strpos( $hook, 'uxcribe-sync' ) || false !== strpos( $hook, 'vk7k-wp-sync' ) ) {
			$is_sync_page = true;
		}

		if ( ! $is_sync_page ) {
			return;
		}

		wp_enqueue_style(
			'vk7k-sync-admin-css',
			VK7K_SYNC_URL . 'admin/css/vk7k-sync-admin.css',
			array(),
			VK7K_SYNC_VERSION
		);

		wp_enqueue_script(
			'vk7k-sync-admin-js',
			VK7K_SYNC_URL . 'admin/js/vk7k-sync-admin.js',
			array( 'jquery' ),
			VK7K_SYNC_VERSION,
			true
		);

		$has_pin           = VK7K_Sync_Auth::has_pin();
		$is_vault_unlocked = VK7K_Sync_Auth::is_vault_unlocked();
		$targets_state     = VK7K_Sync_Auth::get_targets();
		$active_target     = VK7K_Sync_Auth::get_active_target();
		$settings          = VK7K_Sync_Auth::get_settings();

		wp_localize_script(
			'vk7k-sync-admin-js',
			'vk7kSyncData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'vk7k_sync_admin_nonce' ),
				'siteUrl'         => home_url(),
				'siteHost'        => wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost',
				'role'            => ! empty( $settings['role'] ) ? $settings['role'] : 'master',
				'secretKey'       => VK7K_Sync_Auth::get_local_secret_key(),
				'hasPin'          => $has_pin,
				'isVaultUnlocked' => $is_vault_unlocked,
				'targetsState'    => $targets_state,
				'activeTarget'    => $active_target,
				'i18n'            => array(
					'roleMaster'            => __( '👑 Modo: MAESTRO (Fuente)', 'vk7k-wp-sync' ),
					'roleSlave'             => __( '📥 Modo: ESCLAVO (Receptor)', 'vk7k-wp-sync' ),
					'pullDisabledForMaster' => __( '🔒 PULL bloqueado en rol Maestro para proteger este sitio.', 'vk7k-wp-sync' ),
					'pushDisabledForSlave'  => __( '🔒 PUSH bloqueado en rol Esclavo. Los entornos esclavo solo pueden recibir datos (PULL).', 'vk7k-wp-sync' ),
					'masterNotice'          => __( '<strong>👑 Modo Maestro Activo:</strong> Este sitio es la fuente de la verdad. La acción autorizada es <strong>ENVIAR (Push)</strong> hacia el servidor remoto. El comando PULL está bloqueado para prevenir sobrescrituras accidentales.', 'vk7k-wp-sync' ),
					'slaveNotice'           => __( '<strong>📥 Modo Esclavo Activo:</strong> Este sitio es un receptor / staging. La acción autorizada es <strong>TRAER (Pull)</strong> desde la fuente remota. El comando PUSH está bloqueado.', 'vk7k-wp-sync' ),
					'vaultLockedAlert'      => __( 'La bóveda de seguridad está bloqueada. Desbloquéala con tu PIN para acceder o sincronizar.', 'vk7k-wp-sync' ),
				),
			)
		);
	}

	/**
	 * Enqueue Block Editor (Gutenberg / FSE) assets for single-content Push/Pull.
	 */
	public function enqueue_block_editor_assets() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$active_target = VK7K_Sync_Auth::get_active_target();
		$settings      = VK7K_Sync_Auth::get_settings();
		$remote_url    = ! empty( $active_target['url'] ) ? $active_target['url'] : ( $settings['remote_url'] ?? '' );
		$target_name   = ! empty( $active_target['name'] ) ? $active_target['name'] : ( ! empty( $remote_url ) ? wp_parse_url( $remote_url, PHP_URL_HOST ) : '' );

		global $post;
		$current_post_data = array(
			'id'    => $post ? (int) $post->ID : 0,
			'title' => $post ? get_the_title( $post ) : '',
			'slug'  => $post ? $post->post_name : '',
			'type'  => $post ? $post->post_type : 'post',
		);

		wp_enqueue_style(
			'vk7k-sync-editor-css',
			VK7K_SYNC_URL . 'admin/css/vk7k-sync-editor.css',
			array(),
			VK7K_SYNC_VERSION
		);

		wp_enqueue_script(
			'vk7k-sync-editor-js',
			VK7K_SYNC_URL . 'admin/js/vk7k-sync-editor.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			VK7K_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'vk7k-sync-editor-js',
			'vk7kSyncEditor',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'vk7k_sync_admin_nonce' ),
				'remoteUrl'     => $remote_url,
				'targetName'    => $target_name,
				'isConfigured'  => ! empty( $remote_url ),
				'settingsUrl'   => admin_url( 'admin.php?page=uxcribe-sync' ),
				'pluginVersion' => VK7K_SYNC_VERSION,
				'post'          => $current_post_data,
				'i18n'         => array(
					'pluginTitle'       => __( 'uxcribe.cl Sync', 'vk7k-wp-sync' ),
					'menuLabel'         => __( 'uxcribe.cl Sync: Push / Pull', 'vk7k-wp-sync' ),
					'pushToRemote'      => __( 'Push a Remoto', 'vk7k-wp-sync' ),
					'pullFromRemote'    => __( 'Pull desde Remoto', 'vk7k-wp-sync' ),
					'syncMedia'         => __( 'Sincronizar medios y adjuntos (imágenes, galerías)', 'vk7k-wp-sync' ),
					'confirmPull'       => __( '¿Estás seguro de que deseas traer el contenido desde el sitio remoto? Se sobrescribirá el contenido, metadatos y medios locales de este contenido.', 'vk7k-wp-sync' ),
					'pushing'           => __( 'Subiendo a remoto...', 'vk7k-wp-sync' ),
					'pulling'           => __( 'Trayendo desde remoto...', 'vk7k-wp-sync' ),
					'success'           => __( 'Sincronización completada con éxito', 'vk7k-wp-sync' ),
					'reloadEditor'      => __( 'Recargar Editor', 'vk7k-wp-sync' ),
					'notConfigured'     => __( 'Servidor remoto no configurado. Configúralo en los ajustes de uxcribe.cl Sync.', 'vk7k-wp-sync' ),
				),
			)
		);
	}

	/**
	 * Render Admin Page.
	 */
	public function render_admin_page() {
		$settings          = VK7K_Sync_Auth::get_settings();
		$current_role      = ! empty( $settings['role'] ) ? $settings['role'] : 'master';
		$local_key         = VK7K_Sync_Auth::get_local_secret_key();
		$has_pin           = VK7K_Sync_Auth::has_pin();
		$is_vault_unlocked = VK7K_Sync_Auth::is_vault_unlocked();
		$targets_state     = VK7K_Sync_Auth::get_targets();
		$active_target_id  = $targets_state['active_target_id'] ?? 'staging';
		$active_target     = VK7K_Sync_Auth::get_active_target();
		$db_tables         = VK7K_Sync_DB::get_tables();
		$total_rows        = array_sum( array_column( $db_tables, 'rows' ) );
		$total_size        = round( array_sum( array_column( $db_tables, 'size_mb' ) ), 2 );
		$plugins           = VK7K_Sync_Files::get_available_plugins();
		$themes            = VK7K_Sync_Files::get_available_themes();

		$has_ssh2 = function_exists( 'ssh2_connect' );
		?>
		<div class="wrap vk7k-sync-wrap <?php echo 'slave' === $current_role ? 'is-theme-slave' : 'is-theme-master'; ?>" id="vk7k-main-wrap">

			<!-- ============================================== -->
			<!-- SLAVE / RECEPTOR MINIMAL VIEW (CLIENT SAFE)   -->
			<!-- ============================================== -->
			<div id="vk7k-slave-view" style="<?php echo 'slave' === $current_role ? '' : 'display:none;'; ?>">
				<header class="vk7k-header vk7k-slave-header">
					<div class="vk7k-header-brand">
						<div class="vk7k-logo-badge">UXCRIBE</div>
						<div class="vk7k-header-title">
							<h1>uxcribe.cl Sync <span class="vk7k-badge">v<?php echo esc_html( VK7K_SYNC_VERSION ); ?></span></h1>
							<p class="vk7k-subtitle"><?php esc_html_e( 'Servidor Receptor de Sincronización', 'vk7k-wp-sync' ); ?></p>
						</div>
					</div>
					<div class="vk7k-status-pill is-slave">
						<span class="dot"></span>
						<span><?php esc_html_e( '📥 Receptor Activo', 'vk7k-wp-sync' ); ?></span>
					</div>
				</header>

				<div class="vk7k-slave-minimal-card">
					<div class="vk7k-slave-hero-icon">
						<span class="dashicons dashicons-shield-alt"></span>
					</div>
					<h2><?php esc_html_e( 'Sitio Receptor Configurado', 'vk7k-wp-sync' ); ?></h2>
					<p class="vk7k-slave-description">
						<?php esc_html_e( 'Este sitio web está configurado como receptor para recibir de forma segura actualizaciones de diseño, plantillas y contenidos desde tu entorno de desarrollo o maestro autorizado.', 'vk7k-wp-sync' ); ?>
					</p>

					<div class="vk7k-slave-key-box">
						<label for="vk7k-slave-local-key">
							<strong><?php esc_html_e( 'Clave Secreta de Conexión (Para conectar desde tu Maestro):', 'vk7k-wp-sync' ); ?></strong>
						</label>
						<div class="vk7k-input-group">
							<input type="text" id="vk7k-slave-local-key" class="vk7k-input vk7k-code vk7k-local-key-input" value="<?php echo esc_attr( $local_key ); ?>" readonly />
							<button type="button" class="button button-primary vk7k-copy-btn" data-target="#vk7k-slave-local-key" title="<?php esc_attr_e( 'Copiar Clave', 'vk7k-wp-sync' ); ?>">
								<span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Copiar Clave', 'vk7k-wp-sync' ); ?>
							</button>
							<button type="button" class="button vk7k-regen-key-btn" title="<?php esc_attr_e( 'Regenerar Clave', 'vk7k-wp-sync' ); ?>">
								<span class="dashicons dashicons-update"></span>
							</button>
						</div>
						<span class="description"><?php esc_html_e( 'Pega esta clave secreta en el panel de uxcribe.cl Sync de tu WordPress maestro para vincular ambos sitios.', 'vk7k-wp-sync' ); ?></span>
					</div>

					<div class="vk7k-slave-url-box">
						<label for="vk7k-slave-site-url"><strong><?php esc_html_e( 'URL de este WordPress:', 'vk7k-wp-sync' ); ?></strong></label>
						<div class="vk7k-input-group">
							<input type="text" id="vk7k-slave-site-url" class="vk7k-input vk7k-code" value="<?php echo esc_url( home_url() ); ?>" readonly />
							<button type="button" class="button vk7k-copy-btn" data-target="#vk7k-slave-site-url" title="<?php esc_attr_e( 'Copiar URL', 'vk7k-wp-sync' ); ?>">
								<span class="dashicons dashicons-admin-page"></span>
							</button>
						</div>
					</div>

					<div class="vk7k-slave-diag-row">
						<div class="vk7k-env-diagnostic-pill">
							<span class="dashicons dashicons-yes-alt" style="color:#10b981;"></span>
							<span>REST API: <code>/wp-json/vk7k-sync/v1/</code> ✓ • HMAC-SHA256 ✓ • PHP <?php echo esc_html( PHP_VERSION ); ?></span>
						</div>
					</div>

					<div class="vk7k-slave-footer-switch">
						<p class="vk7k-slave-hint">
							<?php esc_html_e( '¿Necesitas enviar datos o clonar desde este sitio hacia otro?', 'vk7k-wp-sync' ); ?>
						</p>
						<button type="button" id="vk7k-btn-enable-master" class="button button-secondary">
							<span class="dashicons dashicons-admin-generic"></span>
							<?php esc_html_e( 'Activar Modo Maestro (Configuración Avanzada)', 'vk7k-wp-sync' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- ============================================== -->
			<!-- MASTER FULL VIEW (ADVANCED WIZARD & TOOLS)    -->
			<!-- ============================================== -->
			<div id="vk7k-master-view" style="<?php echo 'master' === $current_role ? '' : 'display:none;'; ?>">
				<header class="vk7k-header">
					<div class="vk7k-header-brand">
						<div class="vk7k-logo-badge">UXCRIBE</div>
						<div class="vk7k-header-title">
							<h1>uxcribe.cl Sync <span class="vk7k-badge">v<?php echo esc_html( VK7K_SYNC_VERSION ); ?></span></h1>
							<p class="vk7k-subtitle"><?php esc_html_e( 'Sincronización y clonación bidireccional segura entre entornos WordPress por Uxcribe.cl', 'vk7k-wp-sync' ); ?></p>
						</div>
					</div>
				<div class="vk7k-header-controls">
					<!-- Remote Profile Switcher -->
					<div class="vk7k-target-selector-wrap">
						<label for="vk7k-target-switcher"><strong><?php esc_html_e( 'Servidor Remoto:', 'vk7k-wp-sync' ); ?></strong></label>
						<select id="vk7k-target-switcher" class="vk7k-select vk7k-target-select">
							<?php foreach ( $targets_state['targets'] as $tid => $tinfo ) : ?>
								<option value="<?php echo esc_attr( $tid ); ?>" <?php selected( $active_target_id, $tid ); ?>>
									<?php echo 'production' === ( $tinfo['environment'] ?? '' ) ? '🚀 ' : '🌐 '; ?>
									<?php echo esc_html( $tinfo['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" id="vk7k-btn-manage-targets" class="button button-small" title="<?php esc_attr_e( 'Administrar Servidores Remotos', 'vk7k-wp-sync' ); ?>">
							<span class="dashicons dashicons-admin-generic"></span>
						</button>
					</div>

					<!-- Vault Security Status Pill -->
					<div class="vk7k-vault-status-wrap">
						<?php if ( ! $has_pin ) : ?>
							<button type="button" id="vk7k-btn-open-pin-setup" class="vk7k-vault-btn is-warning">
								<span class="dashicons dashicons-lock"></span>
								<span><?php esc_html_e( 'Configurar PIN Bóveda', 'vk7k-wp-sync' ); ?></span>
							</button>
						<?php elseif ( $is_vault_unlocked ) : ?>
							<div class="vk7k-vault-btn is-unlocked">
								<span class="dashicons dashicons-unlock"></span>
								<span><?php esc_html_e( 'Bóveda Desbloqueada', 'vk7k-wp-sync' ); ?></span>
								<button type="button" id="vk7k-btn-lock-vault" class="vk7k-mini-lock" title="<?php esc_attr_e( 'Bloquear Bóveda', 'vk7k-wp-sync' ); ?>">
									<span class="dashicons dashicons-lock"></span>
								</button>
								<button type="button" id="vk7k-btn-open-pin-manage" class="vk7k-mini-lock" title="<?php esc_attr_e( 'Ajustes de PIN', 'vk7k-wp-sync' ); ?>">
									<span class="dashicons dashicons-admin-generic"></span>
								</button>
							</div>
						<?php else : ?>
							<button type="button" id="vk7k-btn-open-pin-unlock" class="vk7k-vault-btn is-locked">
								<span class="dashicons dashicons-lock"></span>
								<span><?php esc_html_e( 'Bóveda Protegida (Desbloquear)', 'vk7k-wp-sync' ); ?></span>
							</button>
						<?php endif; ?>
					</div>

					<!-- Role status Pill -->
					<div class="vk7k-status-pill <?php echo 'slave' === $current_role ? 'is-slave' : 'is-master'; ?>" id="vk7k-header-role-pill">
						<span class="dot"></span>
						<span id="vk7k-header-role-text"><?php echo 'slave' === $current_role ? esc_html__( '📥 Modo: ESCLAVO (Receptor)', 'vk7k-wp-sync' ) : esc_html__( '👑 Modo: MAESTRO (Fuente)', 'vk7k-wp-sync' ); ?></span>
					</div>

					<button type="button" id="vk7k-btn-switch-to-slave" class="button button-small" title="<?php esc_attr_e( 'Cambiar a Modo Receptor (Vista Simple para Clientes)', 'vk7k-wp-sync' ); ?>">
						<span class="dashicons dashicons-hidden"></span> <?php esc_html_e( 'Modo Receptor', 'vk7k-wp-sync' ); ?>
					</button>
				</div>
			</header>

			<!-- TOP ROW: Two Distinct Server Boxes (Local vs Remote) -->
			<div class="vk7k-servers-grid">
				
				<!-- BOX 1: Servidor Local (Este WordPress) -->
				<div class="vk7k-card vk7k-server-card vk7k-local-card">
					<div class="vk7k-card-header">
						<h2><span class="dashicons dashicons-laptop"></span> <?php esc_html_e( 'Servidor Local (Este WordPress)', 'vk7k-wp-sync' ); ?></h2>
						<span class="vk7k-tag-badge is-local"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' ); ?></span>
					</div>
					<div class="vk7k-card-body">
						<div class="vk7k-form-group">
							<label><strong><?php esc_html_e( 'Rol de este WordPress:', 'vk7k-wp-sync' ); ?></strong></label>
							<div class="vk7k-role-selector-grid">
								<label class="vk7k-role-card <?php echo 'master' === $current_role ? 'is-selected' : ''; ?>" data-role="master">
									<input type="radio" name="role_toggle" value="master" <?php checked( 'master' === $current_role ); ?> />
									<div class="vk7k-role-content">
										<span class="vk7k-role-icon">👑</span>
										<div>
											<strong class="vk7k-role-name"><?php esc_html_e( 'Modo Maestro', 'vk7k-wp-sync' ); ?></strong>
											<span class="vk7k-role-caption"><?php esc_html_e( 'Fuente de la verdad. Autorizado para SUBIR (Push) cambios.', 'vk7k-wp-sync' ); ?></span>
										</div>
									</div>
								</label>

								<label class="vk7k-role-card <?php echo 'slave' === $current_role ? 'is-selected' : ''; ?>" data-role="slave">
									<input type="radio" name="role_toggle" value="slave" <?php checked( 'slave' === $current_role ); ?> />
									<div class="vk7k-role-content">
										<span class="vk7k-role-icon">📥</span>
										<div>
											<strong class="vk7k-role-name"><?php esc_html_e( 'Modo Esclavo', 'vk7k-wp-sync' ); ?></strong>
											<span class="vk7k-role-caption"><?php esc_html_e( 'Receptor / Staging. Autorizado para TRAER (Pull) datos.', 'vk7k-wp-sync' ); ?></span>
										</div>
									</div>
								</label>
							</div>
							<input type="hidden" id="vk7k-role-select" name="role" value="<?php echo esc_attr( $current_role ); ?>" />
						</div>

						<div class="vk7k-form-group">
							<label><strong><?php esc_html_e( 'Clave Secreta Local (Para conexiones entrantes):', 'vk7k-wp-sync' ); ?></strong></label>
							<div class="vk7k-input-group">
								<input type="text" id="vk7k-local-key" value="<?php echo esc_attr( $local_key ); ?>" readonly class="vk7k-input vk7k-code" />
								<button type="button" class="button vk7k-copy-btn" data-target="#vk7k-local-key" title="<?php esc_attr_e( 'Copiar', 'vk7k-wp-sync' ); ?>">
									<span class="dashicons dashicons-admin-page"></span>
								</button>
								<button type="button" id="vk7k-regen-key-btn" class="button" title="<?php esc_attr_e( 'Regenerar', 'vk7k-wp-sync' ); ?>">
									<span class="dashicons dashicons-update"></span>
								</button>
							</div>
						</div>

						<div class="vk7k-env-diagnostic-pill">
							<span class="dashicons dashicons-info"></span>
							<span><strong><?php esc_html_e( 'Entorno:', 'vk7k-wp-sync' ); ?></strong> PHP <?php echo esc_html( PHP_VERSION ); ?> • cURL ✓ • OpenSSL ✓ • SSH2 <?php echo $has_ssh2 ? '✓' : '⚠️ (No instalada)'; ?></span>
						</div>
					</div>
				</div>

				<!-- BOX 2: Servidor Remoto (Target) -->
				<div class="vk7k-card vk7k-server-card vk7k-remote-card">
					<div class="vk7k-card-header">
						<h2><span class="dashicons dashicons-networking"></span> <?php esc_html_e( 'Servidor Remoto (Target)', 'vk7k-wp-sync' ); ?></h2>
						<span class="vk7k-tag-badge is-remote" id="vk7k-badge-remote-target"><?php echo esc_html( $active_target['name'] ?? 'Remoto' ); ?></span>
					</div>
					<div class="vk7k-card-body">
						<form id="vk7k-settings-form">
							<div class="vk7k-form-group">
								<label for="vk7k-remote-url"><strong><?php esc_html_e( 'URL del WordPress Remoto:', 'vk7k-wp-sync' ); ?></strong></label>
								<input type="url" id="vk7k-remote-url" name="remote_url" value="<?php echo esc_attr( $settings['remote_url'] ); ?>" placeholder="https://ejemplo.com" class="vk7k-input" required />
							</div>

							<div class="vk7k-form-group">
								<label><strong><?php esc_html_e( 'Modo de Conexión y Transporte:', 'vk7k-wp-sync' ); ?></strong></label>
								<div class="vk7k-transport-selector">
									<label class="vk7k-mode-card <?php echo ! empty( $settings['use_ssh'] ) ? 'is-selected' : ''; ?>" data-mode="ssh">
										<input type="radio" name="conn_mode" value="ssh" <?php checked( ! empty( $settings['use_ssh'] ) ); ?> />
										<div>
											<span class="vk7k-mode-title">⚡ Turbo SSH / SFTP</span>
											<span class="vk7k-mode-desc"><?php esc_html_e( 'VPS / CloudPanel / Hetzner (Auto-Emparejado)', 'vk7k-wp-sync' ); ?></span>
										</div>
									</label>
									<label class="vk7k-mode-card <?php echo empty( $settings['use_ssh'] ) ? 'is-selected' : ''; ?>" data-mode="http">
										<input type="radio" name="conn_mode" value="http" <?php checked( empty( $settings['use_ssh'] ) ); ?> />
										<div>
											<span class="vk7k-mode-title">🌐 HTTP REST API</span>
											<span class="vk7k-mode-desc"><?php esc_html_e( 'Hosting Compartido (Claves manuales)', 'vk7k-wp-sync' ); ?></span>
										</div>
									</label>
								</div>
								<input type="hidden" id="vk7k-use-ssh" name="use_ssh" value="<?php echo ! empty( $settings['use_ssh'] ) ? '1' : '0'; ?>" />
							</div>

							<!-- SSH Mode Fields -->
							<div id="vk7k-mode-ssh-section" style="<?php echo empty( $settings['use_ssh'] ) ? 'display:none;' : ''; ?>">
								<div class="vk7k-form-row">
									<div class="vk7k-form-group vk7k-col-8">
										<label for="vk7k-ssh-host"><strong><?php esc_html_e( 'Host SSH / IP:', 'vk7k-wp-sync' ); ?></strong></label>
										<input type="text" id="vk7k-ssh-host" name="ssh_host" value="<?php echo esc_attr( $settings['ssh_host'] ); ?>" placeholder="192.168.1.10" class="vk7k-input" />
									</div>
									<div class="vk7k-form-group vk7k-col-4">
										<label for="vk7k-ssh-port"><strong><?php esc_html_e( 'Puerto:', 'vk7k-wp-sync' ); ?></strong></label>
										<input type="number" id="vk7k-ssh-port" name="ssh_port" value="<?php echo esc_attr( $settings['ssh_port'] ); ?>" placeholder="22" class="vk7k-input" />
									</div>
								</div>

								<div class="vk7k-form-row">
									<div class="vk7k-form-group vk7k-col-6">
										<label for="vk7k-ssh-user"><strong><?php esc_html_e( 'Usuario SSH:', 'vk7k-wp-sync' ); ?></strong></label>
										<input type="text" id="vk7k-ssh-user" name="ssh_user" value="<?php echo esc_attr( $settings['ssh_user'] ); ?>" placeholder="usuario_ssh" class="vk7k-input" />
									</div>
									<div class="vk7k-form-group vk7k-col-6">
										<label for="vk7k-ssh-pass"><strong><?php esc_html_e( 'Contraseña SSH:', 'vk7k-wp-sync' ); ?></strong></label>
										<input type="password" id="vk7k-ssh-pass" name="ssh_pass" value="" placeholder="<?php echo ! empty( $settings['ssh_pass'] ) ? esc_attr__( '•••••••••••• (Guardada de forma segura)', 'vk7k-wp-sync' ) : esc_attr__( 'Ingresa contraseña SSH', 'vk7k-wp-sync' ); ?>" class="vk7k-input" autocomplete="new-password" />
									</div>
								</div>

								<div class="vk7k-form-group">
									<label for="vk7k-ssh-path"><strong><?php esc_html_e( 'Ruta Absoluta Remota de WordPress:', 'vk7k-wp-sync' ); ?></strong></label>
									<div class="vk7k-input-group">
										<input type="text" id="vk7k-ssh-path" name="ssh_path" value="<?php echo esc_attr( $settings['ssh_path'] ); ?>" placeholder="/home/usuario/public_html" class="vk7k-input vk7k-code" />
										<button type="button" id="vk7k-btn-scan-remote-paths" class="button" title="<?php esc_attr_e( 'Escanear instalaciones de WordPress en el servidor SSH', 'vk7k-wp-sync' ); ?>">
											<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Detectar Rutas', 'vk7k-wp-sync' ); ?>
										</button>
									</div>
									<span class="description"><?php esc_html_e( 'Directorio raíz donde reside wp-config.php en el servidor remoto.', 'vk7k-wp-sync' ); ?></span>
								</div>
							</div>

							<!-- HTTP Mode Fields -->
							<div id="vk7k-mode-http-section" style="<?php echo ! empty( $settings['use_ssh'] ) ? 'display:none;' : ''; ?>">
								<div class="vk7k-form-group">
									<label for="vk7k-remote-key"><strong><?php esc_html_e( 'Clave Secreta del WordPress Remoto:', 'vk7k-wp-sync' ); ?></strong></label>
									<input type="password" id="vk7k-remote-key" name="remote_secret_key" value="<?php echo esc_attr( $settings['remote_secret_key'] ); ?>" placeholder="<?php esc_attr_e( 'Pega aquí la clave secreta del servidor remoto', 'vk7k-wp-sync' ); ?>" class="vk7k-input vk7k-code" />
								</div>
							</div>

							<div class="vk7k-form-group">
								<label for="vk7k-remote-ip">
									<strong><?php esc_html_e( 'IP Directa del Servidor (Opcional - Bypass DNS):', 'vk7k-wp-sync' ); ?></strong>
								</label>
								<input type="text" id="vk7k-remote-ip" name="remote_ip" value="<?php echo esc_attr( $settings['remote_ip'] ); ?>" placeholder="91.98.172.110" class="vk7k-input" />
							</div>

							<div class="vk7k-btn-row">
								<button type="button" id="vk7k-save-settings-btn" class="button button-secondary">
									<span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Guardar Ajustes', 'vk7k-wp-sync' ); ?>
								</button>
								<button type="button" id="vk7k-test-connection-btn" class="button button-primary">
									<span class="dashicons dashicons-yes-alt"></span> <span id="vk7k-test-btn-text"><?php echo ! empty( $settings['use_ssh'] ) ? esc_html__( 'Probar y Auto-Emparejar', 'vk7k-wp-sync' ) : esc_html__( 'Probar Conexión REST', 'vk7k-wp-sync' ); ?></span>
								</button>
							</div>
						</form>

						<!-- Real-time Connection Status Banner -->
						<div id="vk7k-connection-result" class="vk7k-alert" style="display:none;"></div>
					</div>
				</div>
			</div>

			<!-- BOTTOM CARD: Sync Content Type Selection & Execution -->
			<div class="vk7k-card vk7k-components-card">
				<div class="vk7k-card-header">
					<h2>
						<span class="dashicons dashicons-randomize"></span>
						<span id="vk7k-sync-type-header-title"><?php echo 'slave' === $current_role ? esc_html__( '¿Qué deseas Traer desde Remoto?', 'vk7k-wp-sync' ) : esc_html__( '¿Qué deseas Enviar a Remoto?', 'vk7k-wp-sync' ); ?></span>
					</h2>
					<div class="vk7k-stats-summary">
						<div class="vk7k-stat-item">
							<strong class="vk7k-stat-value"><?php echo esc_html( count( $db_tables ) ); ?></strong>
							<span class="vk7k-stat-label"><?php esc_html_e( 'TABLAS DB', 'vk7k-wp-sync' ); ?></span>
						</div>
						<div class="vk7k-stat-item">
							<strong class="vk7k-stat-value"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></strong>
							<span class="vk7k-stat-label"><?php esc_html_e( 'FILAS TOTALES', 'vk7k-wp-sync' ); ?></span>
						</div>
						<div class="vk7k-stat-item">
							<strong class="vk7k-stat-value"><?php echo esc_html( $total_size ); ?> MB</strong>
							<span class="vk7k-stat-label"><?php esc_html_e( 'TAMAÑO DB', 'vk7k-wp-sync' ); ?></span>
						</div>
					</div>
				</div>

				<div class="vk7k-card-body">
					<!-- Sync Type Selector Grid (Presets) -->
					<div class="vk7k-sync-type-grid" id="vk7k-sync-type-selector">
						
						<!-- Card 1: WooCommerce Catalog -->
						<div class="vk7k-sync-type-card" data-type="woocommerce_catalog">
							<div class="vk7k-type-badge-pill"><?php esc_html_e( 'Productos + Fotos', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">🛍️</div>
							<h3 class="vk7k-type-title">
								<span class="vk7k-verb"><?php echo 'slave' === $current_role ? esc_html__( 'Traer', 'vk7k-wp-sync' ) : esc_html__( 'Enviar', 'vk7k-wp-sync' ); ?></span>
								<?php esc_html_e( 'Catálogo WooCommerce', 'vk7k-wp-sync' ); ?>
							</h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Sincroniza productos, variaciones, atributos, categorías y galería de medios (Uploads). Protege pedidos y clientes.', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-type-check-indicator"><span class="dashicons dashicons-yes-alt"></span></div>
						</div>

						<!-- Card 2: FSE Design & Menus -->
						<div class="vk7k-sync-type-card is-recommended" data-type="fse_design_menus">
							<div class="vk7k-type-badge-pill is-fse"><?php esc_html_e( 'FSE + Menús + Tema', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">🖌️</div>
							<h3 class="vk7k-type-title">
								<span class="vk7k-verb"><?php echo 'slave' === $current_role ? esc_html__( 'Traer', 'vk7k-wp-sync' ) : esc_html__( 'Enviar', 'vk7k-wp-sync' ); ?></span>
								<?php esc_html_e( 'Diseño, Menús y Plantillas FSE', 'vk7k-wp-sync' ); ?>
							</h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Sincroniza quirúrgicamente el tema en disco, menús de navegación, cabeceras/pies (wp_template_part), plantillas (wp_template), estilos globales y theme_mods. Cero impacto en páginas, posts ni pedidos.', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-type-check-indicator"><span class="dashicons dashicons-yes-alt"></span></div>
						</div>

						<!-- Card 3: Design & Templates (Disk Only) -->
						<div class="vk7k-sync-type-card" data-type="design_theme">
							<div class="vk7k-type-badge-pill"><?php esc_html_e( 'Solo Código de Tema', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">🎨</div>
							<h3 class="vk7k-type-title">
								<span class="vk7k-verb"><?php echo 'slave' === $current_role ? esc_html__( 'Traer', 'vk7k-wp-sync' ) : esc_html__( 'Enviar', 'vk7k-wp-sync' ); ?></span>
								<?php esc_html_e( 'Solo Código y Archivos de Tema', 'vk7k-wp-sync' ); ?>
							</h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Sincroniza únicamente archivos físicos en disco (PHP, CSS, plantillas HTML originales, assets y theme.json). Cero impacto en Base de Datos (no incluye menús ni ajustes del sitio).', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-type-check-indicator"><span class="dashicons dashicons-yes-alt"></span></div>
						</div>

						<!-- Card 3: Full Safe Content -->
						<div class="vk7k-sync-type-card" data-type="full_content_safe">
							<div class="vk7k-type-badge-pill is-safe"><?php esc_html_e( 'Contenido y Diseño Seguro', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">📦</div>
							<h3 class="vk7k-type-title">
								<span class="vk7k-verb"><?php echo 'slave' === $current_role ? esc_html__( 'Traer', 'vk7k-wp-sync' ) : esc_html__( 'Enviar', 'vk7k-wp-sync' ); ?></span>
								<?php esc_html_e( 'Contenido, Menús y Diseño (Seguro)', 'vk7k-wp-sync' ); ?>
							</h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Páginas, entradas, menús de navegación, cabeceras/plantillas FSE, opciones del tema y biblioteca multimedia. Preserva clientes, pedidos y usuarios.', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-type-check-indicator"><span class="dashicons dashicons-yes-alt"></span></div>
						</div>

						<!-- Card 5: Granular DataGrid & Recipes -->
						<div class="vk7k-sync-type-card is-granular is-selected" id="vk7k-card-open-granular" data-type="granular_recipes" style="border-color: #3b82f6; background: #eff6ff;">
							<div class="vk7k-type-badge-pill" style="background:#3b82f6; color:#fff;"><?php esc_html_e( 'Nivel Fila / Excel', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">📑</div>
							<h3 class="vk7k-type-title" style="color: #1e40af;">
								<?php esc_html_e( 'Editor Granular & Recetas', 'vk7k-wp-sync' ); ?>
							</h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Selecciona páginas, posts, menús, opciones, plugins y temas mediante el DataGrid interactivo y ejecuta o guarda recetas reutilizables.', 'vk7k-wp-sync' ); ?>
							</p>
							<button type="button" class="button button-primary vk7k-btn-open-granular-modal" style="width:100%; margin-top:8px;">
								<span class="dashicons dashicons-table-col-after" style="margin-top:2px;"></span> <?php esc_html_e( 'Abrir Selector Granular', 'vk7k-wp-sync' ); ?>
							</button>
						</div>

					</div>

					<!-- Recipe Bar Toolbar -->
					<div class="vk7k-recipe-toolbar" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
						<div style="display: flex; align-items: center; gap: 10px;">
							<span class="dashicons dashicons-clipboard" style="font-size: 22px; color: #3b82f6;"></span>
							<div>
								<strong><?php esc_html_e( 'Receta de Sincronización Protegida:', 'vk7k-wp-sync' ); ?></strong>
								<span id="vk7k-active-recipe-name" style="color: #64748b; font-size: 0.9rem; margin-left: 6px;"><?php esc_html_e( 'Ninguna seleccionada (modo libre)', 'vk7k-wp-sync' ); ?></span>
							</div>
						</div>
						<div style="display: flex; align-items: center; gap: 8px;">
							<select id="vk7k-select-saved-recipes" class="vk7k-select" style="min-width: 220px;">
								<option value=""><?php esc_html_e( '— Cargar Receta Guardada —', 'vk7k-wp-sync' ); ?></option>
							</select>
							<button type="button" class="button button-secondary vk7k-btn-open-granular-modal">
								<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Editar Selección', 'vk7k-wp-sync' ); ?>
							</button>
							<button type="button" id="vk7k-btn-save-current-recipe" class="button button-secondary" style="display:none;">
								<span class="dashicons dashicons-cloud-saved"></span> <?php esc_html_e( 'Guardar Receta', 'vk7k-wp-sync' ); ?>
							</button>
						</div>
					</div>

					<!-- Selected Preset Summary Box (shown when preset 1, 2, or 3 is active) -->
					<div id="vk7k-selected-preset-summary" class="vk7k-preset-summary-box" style="display:none;">
						<div class="vk7k-preset-summary-content">
							<div class="vk7k-preset-summary-header">
								<span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
								<strong id="vk7k-preset-summary-title">Catálogo WooCommerce</strong>
							</div>
							<div id="vk7k-preset-summary-details" class="vk7k-preset-summary-details"></div>
						</div>
						<button type="button" class="button button-small button-secondary vk7k-btn-open-granular-modal">
							<span class="dashicons dashicons-table-col-after"></span> <?php esc_html_e( 'Personalizar en Editor Granular', 'vk7k-wp-sync' ); ?>
						</button>
					</div>

					<!-- Maintenance Mode Row -->
					<div class="vk7k-maintenance-toggle-row" style="margin: 15px 0 20px 0; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
						<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 500;">
							<input type="checkbox" id="vk7k-opt-maintenance" <?php checked( ! isset( $settings['sync_maintenance'] ) || ! empty( $settings['sync_maintenance'] ) ); ?> />
							<span>🔒 <?php esc_html_e( 'Activar Modo Mantenimiento en el sitio receptor durante la sincronización', 'vk7k-wp-sync' ); ?></span>
						</label>
						<span class="description" style="color: #64748b; font-size: 0.8rem;"><?php esc_html_e( 'Protege visitantes y se libera automáticamente al finalizar.', 'vk7k-wp-sync' ); ?></span>
					</div>

				<!-- ACTION EXECUTION SECTION -->
					<div class="vk7k-action-section">
						<div class="vk7k-action-buttons">
							<button type="button" id="vk7k-btn-push" class="vk7k-btn vk7k-btn-push <?php echo 'slave' === $current_role ? 'is-disabled' : ''; ?>" <?php disabled( 'slave' === $current_role ); ?>>
								<span class="dashicons dashicons-upload"></span>
								<span id="vk7k-label-btn-push"><?php esc_html_e( '⬆️ PUSH: Enviar a Remoto', 'vk7k-wp-sync' ); ?></span>
							</button>
							<button type="button" id="vk7k-btn-pull" class="vk7k-btn vk7k-btn-pull <?php echo 'master' === $current_role ? 'is-disabled' : ''; ?>" <?php disabled( 'master' === $current_role ); ?>>
								<span class="dashicons dashicons-download"></span>
								<span id="vk7k-label-btn-pull"><?php esc_html_e( '⬇️ PULL: Traer desde Remoto', 'vk7k-wp-sync' ); ?></span>
							</button>
						</div>

						<div id="vk7k-role-hint" class="vk7k-role-hint">
							<div class="vk7k-flow-info-card">
								<div class="vk7k-flow-item">
									<span class="dashicons dashicons-upload vk7k-flow-icon push"></span>
									<div><strong>PUSH (Subir):</strong> Envía los datos de este sitio local (<code><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' ); ?></code>) hacia el servidor remoto seleccionado.</div>
								</div>
								<div class="vk7k-flow-item">
									<span class="dashicons dashicons-download vk7k-flow-icon pull"></span>
									<div><strong>PULL (Descargar):</strong> Descarga los datos desde el servidor remoto e importa en este WordPress local (ideal para respaldos y clonación).</div>
								</div>
							</div>
						</div>
					</div>

					<!-- FAST LINK TOOLS SECTION -->
					<div class="vk7k-card vk7k-tools-card" style="margin-top: 20px; border-top: 2px solid #2271b1;">
						<div class="vk7k-card-header" style="display: flex; justify-content: space-between; align-items: center;">
							<div style="display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-admin-links" style="font-size: 20px; color: #2271b1;"></span>
								<h3 style="margin: 0; font-size: 1.05rem;"><?php esc_html_e( 'Herramienta de Enlaces y Normalización (Zero-Corruption)', 'vk7k-wp-sync' ); ?></h3>
							</div>
							<span class="vk7k-badge-pill" id="vk7k-link-status-badge" style="background: #f0f0f1; color: #50575e; font-size: 0.8rem; padding: 3px 8px; border-radius: 12px;">
								<?php esc_html_e( 'Listo para auditar', 'vk7k-wp-sync' ); ?>
							</span>
						</div>
						<div class="vk7k-card-body">
							<p style="margin: 0 0 12px; color: #50575e; font-size: 0.9rem;">
								<?php esc_html_e( 'Verifica y repara enlaces residuales, menús de navegación FSE y referencias de dominio en la base de datos sin necesidad de realizar una sincronización completa de archivos.', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-tools-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
								<button type="button" id="vk7k-btn-audit-links" class="button button-secondary">
									<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Auditar Enlaces Residuales', 'vk7k-wp-sync' ); ?>
								</button>
								<button type="button" id="vk7k-btn-fix-links" class="button button-primary">
									<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Reparar y Normalizar URLs Ahora', 'vk7k-wp-sync' ); ?>
								</button>
							</div>
							<div id="vk7k-audit-result-box" style="display: none; margin-top: 12px; padding: 12px; border-radius: 6px; font-size: 0.9rem;"></div>
						</div>
					</div>

				</div>
			</div>

			<!-- EXPLICIT CONFIRMATION SUMMARY MODAL -->
			<div id="vk7k-confirm-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content">
					<div class="vk7k-modal-header">
						<h3 id="vk7k-confirm-title"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Confirmar Sincronización', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" class="vk7k-modal-close-btn">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<div id="vk7k-confirm-flow-banner" class="vk7k-confirm-flow-banner">
							<div class="vk7k-flow-box origin">
								<span class="vk7k-flow-tag"><?php esc_html_e( 'ORIGEN (Lectura)', 'vk7k-wp-sync' ); ?></span>
								<strong id="vk7k-summary-origin-url">https://remoto.ejemplo.com</strong>
							</div>
							<div class="vk7k-flow-arrow">➔</div>
							<div class="vk7k-flow-box destination">
								<span class="vk7k-flow-tag"><?php esc_html_e( 'DESTINO (Se actualizará)', 'vk7k-wp-sync' ); ?></span>
								<strong id="vk7k-summary-dest-url"><?php echo esc_html( home_url() ); ?></strong>
							</div>
						</div>

						<h4 style="margin: 16px 0 8px; font-size: 0.95rem;"><?php esc_html_e( 'Resumen de Componentes a Transferir:', 'vk7k-wp-sync' ); ?></h4>
						<div id="vk7k-confirm-summary-list" class="vk7k-confirm-summary-list"></div>

						<div class="vk7k-alert is-warning" id="vk7k-confirm-warning-box" style="margin-top: 15px;">
							<span class="dashicons dashicons-shield"></span>
							<span id="vk7k-confirm-warning-text"><?php esc_html_e( 'Esta operación importará datos en el sitio de destino. Los registros y archivos existentes seleccionados serán actualizados.', 'vk7k-wp-sync' ); ?></span>
						</div>

						<div class="vk7k-modal-footer">
							<button type="button" class="button button-large vk7k-modal-close-btn"><?php esc_html_e( 'Cancelar', 'vk7k-wp-sync' ); ?></button>
							<button type="button" id="vk7k-btn-confirm-proceed" class="button button-primary button-large"><?php esc_html_e( 'Aceptar e Iniciar Sincronización', 'vk7k-wp-sync' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<!-- LIVE PROGRESS MODAL -->
			<div id="vk7k-progress-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content">
					<div class="vk7k-modal-header">
						<h3 id="vk7k-modal-title"><?php esc_html_e( 'Sincronización en Progreso...', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" id="vk7k-modal-close" class="vk7k-modal-close">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<div class="vk7k-progress-wrapper">
							<div class="vk7k-progress-info">
								<span id="vk7k-current-step-label"><?php esc_html_e( 'Iniciando conexión...', 'vk7k-wp-sync' ); ?></span>
								<span id="vk7k-progress-percentage">0%</span>
							</div>
							<div class="vk7k-progress-bar-bg">
								<div id="vk7k-progress-bar-fill" class="vk7k-progress-bar-fill" style="width: 0%;"></div>
							</div>
						</div>

						<div class="vk7k-steps-indicator">
							<div class="vk7k-step-node is-active" data-step="init">
								<span class="vk7k-step-num">1</span>
								<span class="vk7k-step-name"><?php esc_html_e( 'Conexión', 'vk7k-wp-sync' ); ?></span>
							</div>
							<div class="vk7k-step-node" data-step="db">
								<span class="vk7k-step-num">2</span>
								<span class="vk7k-step-name"><?php esc_html_e( 'Base de Datos', 'vk7k-wp-sync' ); ?></span>
							</div>
							<div class="vk7k-step-node" data-step="files">
								<span class="vk7k-step-num">3</span>
								<span class="vk7k-step-name"><?php esc_html_e( 'Archivos', 'vk7k-wp-sync' ); ?></span>
							</div>
							<div class="vk7k-step-node" data-step="finalize">
								<span class="vk7k-step-num">4</span>
								<span class="vk7k-step-name"><?php esc_html_e( 'Finalización', 'vk7k-wp-sync' ); ?></span>
							</div>
						</div>

						<div class="vk7k-log-wrapper">
							<div class="vk7k-log-header">
								<span><?php esc_html_e( 'Registro de Eventos en Vivo', 'vk7k-wp-sync' ); ?></span>
								<button type="button" id="vk7k-clear-log-btn" class="button button-small"><?php esc_html_e( 'Limpiar', 'vk7k-wp-sync' ); ?></button>
							</div>
							<div id="vk7k-log-console" class="vk7k-log-console"></div>
						</div>

						<div class="vk7k-modal-footer" style="padding: 12px 0 0 0;">
							<button type="button" id="vk7k-modal-cancel-btn" class="button button-secondary"><?php esc_html_e( 'Detener Sincronización', 'vk7k-wp-sync' ); ?></button>
							<button type="button" id="vk7k-modal-done-btn" class="button button-primary" style="display:none;"><?php esc_html_e( 'Cerrar y Recargar', 'vk7k-wp-sync' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<!-- PIN Setup Modal -->
			<div id="vk7k-pin-setup-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content vk7k-modal-sm">
					<div class="vk7k-modal-header">
						<h3><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Crear PIN Maestro de la Bóveda', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" class="vk7k-modal-close-btn">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<p class="description">
							<?php esc_html_e( 'Crea un PIN numérico o frase secreta (mínimo 4 caracteres). Este PIN cifrará tus credenciales SSH en tu máquina local mediante AES-256 (Zero-Knowledge).', 'vk7k-wp-sync' ); ?>
						</p>
						<form id="vk7k-form-setup-pin">
							<div class="vk7k-form-group">
								<label for="vk7k-input-new-pin"><strong><?php esc_html_e( 'Nuevo PIN Maestro:', 'vk7k-wp-sync' ); ?></strong></label>
								<input type="password" id="vk7k-input-new-pin" class="vk7k-input vk7k-pin-input" placeholder="••••••" maxlength="32" required autocomplete="new-password" />
							</div>
							<div class="vk7k-form-group">
								<label for="vk7k-input-confirm-pin"><strong><?php esc_html_e( 'Confirmar PIN:', 'vk7k-wp-sync' ); ?></strong></label>
								<input type="password" id="vk7k-input-confirm-pin" class="vk7k-input vk7k-pin-input" placeholder="••••••" maxlength="32" required autocomplete="new-password" />
							</div>
							<div id="vk7k-setup-pin-alert" class="vk7k-alert" style="display:none;"></div>
							<div class="vk7k-modal-footer">
								<button type="button" class="button vk7k-modal-close-btn"><?php esc_html_e( 'Cancelar', 'vk7k-wp-sync' ); ?></button>
								<button type="submit" id="vk7k-btn-submit-setup-pin" class="button button-primary"><?php esc_html_e( 'Activar Bóveda Cifrada', 'vk7k-wp-sync' ); ?></button>
							</div>
						</form>
					</div>
				</div>
			</div>

			<!-- PIN Unlock Modal -->
			<div id="vk7k-pin-unlock-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content vk7k-modal-sm">
					<div class="vk7k-modal-header">
						<h3><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Desbloquear Bóveda Local', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" class="vk7k-modal-close-btn">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<p class="description">
							<?php esc_html_e( 'Ingresa tu PIN Maestro para desbloquear las credenciales de tus servidores en esta sesión.', 'vk7k-wp-sync' ); ?>
						</p>
						<form id="vk7k-form-unlock-pin">
							<div class="vk7k-form-group">
								<label for="vk7k-input-unlock-pin"><strong><?php esc_html_e( 'PIN Maestro:', 'vk7k-wp-sync' ); ?></strong></label>
								<input type="password" id="vk7k-input-unlock-pin" class="vk7k-input vk7k-pin-input" placeholder="••••••" maxlength="32" autofocus required autocomplete="current-password" />
							</div>
							<div id="vk7k-unlock-pin-alert" class="vk7k-alert" style="display:none;"></div>
							<div class="vk7k-modal-footer">
								<button type="button" id="vk7k-btn-forgot-pin" class="button button-link-delete"><?php esc_html_e( '¿Olvidaste tu PIN?', 'vk7k-wp-sync' ); ?></button>
								<button type="submit" id="vk7k-btn-submit-unlock-pin" class="button button-primary"><?php esc_html_e( 'Desbloquear', 'vk7k-wp-sync' ); ?></button>
							</div>
						</form>
					</div>
				</div>
			</div>

			<!-- Manage PIN / Reset Modal -->
			<div id="vk7k-pin-manage-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content vk7k-modal-sm">
					<div class="vk7k-modal-header">
						<h3><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Ajustes de la Bóveda de Seguridad', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" class="vk7k-modal-close-btn">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<h4><?php esc_html_e( 'Cambiar PIN Maestro', 'vk7k-wp-sync' ); ?></h4>
						<form id="vk7k-form-change-pin">
							<div class="vk7k-form-group">
								<label for="vk7k-input-old-pin"><?php esc_html_e( 'PIN Actual:', 'vk7k-wp-sync' ); ?></label>
								<input type="password" id="vk7k-input-old-pin" class="vk7k-input" required />
							</div>
							<div class="vk7k-form-group">
								<label for="vk7k-input-change-new-pin"><?php esc_html_e( 'Nuevo PIN:', 'vk7k-wp-sync' ); ?></label>
								<input type="password" id="vk7k-input-change-new-pin" class="vk7k-input" required />
							</div>
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Actualizar PIN', 'vk7k-wp-sync' ); ?></button>
						</form>

						<hr style="margin: 20px 0; border-top: 1px solid #e2e8f0;" />

						<h4><?php esc_html_e( 'Zona de Emergencia / Reset', 'vk7k-wp-sync' ); ?></h4>
						<p class="description">
							<?php esc_html_e( 'Si olvidaste tu PIN, puedes restablecer la bóveda. Esto purgará las contraseñas guardadas por seguridad y te permitirá definir un nuevo PIN.', 'vk7k-wp-sync' ); ?>
						</p>
						<button type="button" id="vk7k-btn-action-reset-vault" class="button button-link-delete">
							<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Restablecer Bóveda de Fábrica', 'vk7k-wp-sync' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Target Profiles Modal -->
			<div id="vk7k-targets-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content">
					<div class="vk7k-modal-header">
						<h3><span class="dashicons dashicons-networking"></span> <?php esc_html_e( 'Administrar Servidores Remotos (Staging & Producción)', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" class="vk7k-modal-close-btn">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<div class="vk7k-targets-grid">
							<div class="vk7k-targets-list-col">
								<h4><?php esc_html_e( 'Destinos Configurados', 'vk7k-wp-sync' ); ?></h4>
								<div id="vk7k-targets-list" class="vk7k-targets-list"></div>
								<button type="button" id="vk7k-btn-add-new-target" class="button button-primary" style="margin-top: 10px; width: 100%;">
									<span class="dashicons dashicons-plus"></span> <?php esc_html_e( 'Agregar Nuevo Servidor', 'vk7k-wp-sync' ); ?>
								</button>
							</div>
							<div class="vk7k-targets-form-col">
								<h4 id="vk7k-target-form-title"><?php esc_html_e( 'Detalles del Servidor Remoto', 'vk7k-wp-sync' ); ?></h4>
								<form id="vk7k-form-edit-target">
									<input type="hidden" id="vk7k-edit-target-id" value="" />
									<div class="vk7k-form-group">
										<label for="vk7k-edit-target-name"><strong><?php esc_html_e( 'Nombre del Servidor:', 'vk7k-wp-sync' ); ?></strong></label>
										<input type="text" id="vk7k-edit-target-name" class="vk7k-input" placeholder="Producción (ejemplo.com)" required />
									</div>
									<div class="vk7k-form-group">
										<label for="vk7k-edit-target-env"><strong><?php esc_html_e( 'Tipo de Entorno:', 'vk7k-wp-sync' ); ?></strong></label>
										<select id="vk7k-edit-target-env" class="vk7k-select">
											<option value="staging"><?php esc_html_e( '🌐 Staging / Demo', 'vk7k-wp-sync' ); ?></option>
											<option value="production"><?php esc_html_e( '🚀 Producción (Live)', 'vk7k-wp-sync' ); ?></option>
											<option value="custom"><?php esc_html_e( '⚡ Personalizado', 'vk7k-wp-sync' ); ?></option>
										</select>
									</div>
									<div class="vk7k-form-group">
										<label for="vk7k-edit-target-url"><strong><?php esc_html_e( 'URL del WordPress Remoto:', 'vk7k-wp-sync' ); ?></strong></label>
										<input type="url" id="vk7k-edit-target-url" class="vk7k-input" placeholder="https://ejemplo.com" required />
									</div>
									<div class="vk7k-form-group">
										<label for="vk7k-edit-target-ip"><?php esc_html_e( 'IP Directa Servidor (Opcional):', 'vk7k-wp-sync' ); ?></label>
										<input type="text" id="vk7k-edit-target-ip" class="vk7k-input" placeholder="192.168.1.10" />
									</div>
									<div class="vk7k-form-row">
										<div class="vk7k-form-group vk7k-col-8">
											<label for="vk7k-edit-target-host"><?php esc_html_e( 'Host SSH / IP:', 'vk7k-wp-sync' ); ?></label>
											<input type="text" id="vk7k-edit-target-host" class="vk7k-input" placeholder="192.168.1.10" />
										</div>
										<div class="vk7k-form-group vk7k-col-4">
											<label for="vk7k-edit-target-port"><?php esc_html_e( 'Puerto:', 'vk7k-wp-sync' ); ?></label>
											<input type="number" id="vk7k-edit-target-port" class="vk7k-input" value="22" />
										</div>
									</div>
									<div class="vk7k-form-row">
										<div class="vk7k-form-group vk7k-col-6">
											<label for="vk7k-edit-target-user"><?php esc_html_e( 'Usuario SSH:', 'vk7k-wp-sync' ); ?></label>
											<input type="text" id="vk7k-edit-target-user" class="vk7k-input" placeholder="usuario_ssh" />
										</div>
										<div class="vk7k-form-group vk7k-col-6">
											<label for="vk7k-edit-target-pass"><?php esc_html_e( 'Contraseña SSH:', 'vk7k-wp-sync' ); ?></label>
											<input type="password" id="vk7k-edit-target-pass" class="vk7k-input" placeholder="••••••••" autocomplete="new-password" />
										</div>
									</div>
									<div class="vk7k-form-group">
										<label for="vk7k-edit-target-path"><?php esc_html_e( 'Ruta Absoluta Remota:', 'vk7k-wp-sync' ); ?></label>
										<div class="vk7k-input-group">
											<input type="text" id="vk7k-edit-target-path" class="vk7k-input vk7k-code" placeholder="/home/usuario/public_html" />
											<button type="button" id="vk7k-btn-scan-target-paths" class="button" title="<?php esc_attr_e( 'Escanear rutas en este servidor', 'vk7k-wp-sync' ); ?>">
												<span class="dashicons dashicons-search"></span>
											</button>
										</div>
									</div>
									<div class="vk7k-modal-footer" style="padding: 12px 0 0 0;">
										<button type="button" id="vk7k-btn-delete-target" class="button button-link-delete" style="display:none;"><?php esc_html_e( 'Eliminar', 'vk7k-wp-sync' ); ?></button>
										<button type="submit" id="vk7k-btn-save-target-submit" class="button button-primary"><?php esc_html_e( 'Guardar Perfil', 'vk7k-wp-sync' ); ?></button>
									</div>
								</form>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Remote WordPress Path Selection & Verification Modal -->
			<div id="vk7k-path-selection-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content" style="max-width: 650px;">
					<div class="vk7k-modal-header">
						<h3><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Seleccionar Entorno / Ruta de WordPress', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" class="vk7k-modal-close-btn">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<div id="vk7k-path-selection-alert" class="vk7k-alert is-warning" style="margin-bottom: 16px;"></div>
						<p class="description" style="margin-bottom: 12px; font-weight: 600;">
							<?php esc_html_e( 'Selecciona la instalación de WordPress a la cual deseas conectar:', 'vk7k-wp-sync' ); ?>
						</p>

						<div id="vk7k-discovered-paths-container" class="vk7k-path-options-list">
							<!-- Populated via JavaScript -->
						</div>

						<div class="vk7k-custom-path-box" style="margin-top: 15px; padding-top: 12px; border-top: 1px dashed #cbd5e1;">
							<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
								<input type="radio" name="vk7k_selected_path_radio" value="__custom__" id="vk7k-radio-custom-path" />
								<strong><?php esc_html_e( 'Ingresar otra ruta manualmente:', 'vk7k-wp-sync' ); ?></strong>
							</label>
							<input type="text" id="vk7k-custom-path-input" class="vk7k-input vk7k-code" placeholder="/home/usuario/htdocs/mi-sitio" style="margin-top: 8px;" />
						</div>
					</div>
					<div class="vk7k-modal-footer">
						<button type="button" class="button button-secondary vk7k-modal-close-action"><?php esc_html_e( 'Cancelar', 'vk7k-wp-sync' ); ?></button>
						<button type="button" id="vk7k-btn-confirm-path-selection" class="button button-primary">
							<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Confirmar y Usar Esta Ruta', 'vk7k-wp-sync' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- DATAGRID LIGHTBOX MODAL (Granular Editor) -->
			<div id="vk7k-granular-modal" class="vk7k-modal vk7k-granular-lightbox" style="display:none;">
				<div class="vk7k-modal-content" style="max-width: 1050px; width: 95vw; height: 88vh; display: flex; flex-direction: column; padding: 0; overflow: hidden; border-radius: 12px;">
					
					<!-- Lightbox Header -->
					<div class="vk7k-modal-header" style="padding: 16px 20px; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: space-between;">
						<div style="display: flex; align-items: center; gap: 12px;">
							<span class="dashicons dashicons-table-col-after" style="font-size: 24px; color: #38bdf8;"></span>
							<div>
								<h3 style="margin: 0; color: #fff; font-size: 1.15rem; font-weight: 600;">
									<?php esc_html_e( 'Editor Granular de Sincronización', 'vk7k-wp-sync' ); ?>
								</h3>
								<p style="margin: 2px 0 0; color: #94a3b8; font-size: 0.8rem;">
									<?php esc_html_e( 'Selecciona entidades específicas para sincronizar sin tocar el resto del sitio.', 'vk7k-wp-sync' ); ?>
								</p>
							</div>
						</div>
						<div style="display: flex; align-items: center; gap: 10px;">
							<button type="button" id="vk7k-btn-save-as-recipe" class="button button-secondary" style="background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.2); color: #fff;">
								<span class="dashicons dashicons-star-filled" style="color: #facc15; margin-top: 3px;"></span> <?php esc_html_e( 'Guardar como Receta', 'vk7k-wp-sync' ); ?>
							</button>
							<button type="button" class="vk7k-modal-close-btn" style="color: #94a3b8; font-size: 24px; line-height: 1;">&times;</button>
						</div>
					</div>

					<!-- Lightbox Body: Tabs & Content -->
					<div class="vk7k-modal-body" style="flex: 1; display: flex; flex-direction: column; padding: 0; overflow: hidden; background: #f8fafc;">
						
						<!-- Sub-Header: Tabs Bar & Search -->
						<div style="padding: 10px 20px; background: #ffffff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
							<div class="vk7k-granular-tabs" style="display: flex; gap: 6px;">
								<button type="button" class="vk7k-tab-btn is-active" data-tab="pages">
									<span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Páginas', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-pages">0</span>
								</button>
								<button type="button" class="vk7k-tab-btn" data-tab="cpts">
									<span class="dashicons dashicons-products"></span> <?php esc_html_e( 'Entradas / CPTs', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-cpts">0</span>
								</button>
								<button type="button" class="vk7k-tab-btn" data-tab="menus">
									<span class="dashicons dashicons-menu-alt3"></span> <?php esc_html_e( 'Menús', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-menus">0</span>
								</button>
								<button type="button" class="vk7k-tab-btn" data-tab="media">
									<span class="dashicons dashicons-admin-media"></span> <?php esc_html_e( 'Medios', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-media">0</span>
								</button>
								<button type="button" class="vk7k-tab-btn" data-tab="taxonomies">
									<span class="dashicons dashicons-tag"></span> <?php esc_html_e( 'Taxonomías', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-taxonomies">0</span>
								</button>
								<button type="button" class="vk7k-tab-btn" data-tab="options">
									<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Opciones (wp_options)', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-options">0</span>
								</button>
								<button type="button" class="vk7k-tab-btn" data-tab="plugins">
									<span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Plugins', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-plugins">0</span>
								</button>
								<button type="button" class="vk7k-tab-btn" data-tab="themes">
									<span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Temas', 'vk7k-wp-sync' ); ?>
									<span class="vk7k-tab-count" id="count-themes">0</span>
								</button>
							</div>

							<!-- Search Bar & Sub-Filters -->
							<div style="display: flex; align-items: center; gap: 8px;">
								<select id="vk7k-cpt-filter-select" class="vk7k-select" style="display:none; font-size: 0.85rem; height: 32px; padding: 2px 8px;">
									<option value="post"><?php esc_html_e( 'Entradas (post)', 'vk7k-wp-sync' ); ?></option>
									<option value="product"><?php esc_html_e( 'Productos (product)', 'vk7k-wp-sync' ); ?></option>
								</select>
								<div id="vk7k-plugin-filter-actions" style="display:none; align-items: center; gap: 4px;">
									<button type="button" class="button button-small" id="vk7k-grid-plugins-all"><?php esc_html_e( 'Todos', 'vk7k-wp-sync' ); ?></button>
									<button type="button" class="button button-small" id="vk7k-grid-plugins-active"><?php esc_html_e( 'Solo Activos', 'vk7k-wp-sync' ); ?></button>
									<button type="button" class="button button-small" id="vk7k-grid-plugins-none"><?php esc_html_e( 'Ninguno', 'vk7k-wp-sync' ); ?></button>
								</div>
								<div class="vk7k-search-box" style="position: relative;">
									<input type="text" id="vk7k-grid-search" placeholder="<?php esc_attr_e( 'Buscar en lista...', 'vk7k-wp-sync' ); ?>" style="height: 32px; font-size: 0.85rem; padding-left: 28px; width: 220px; border-radius: 6px; border: 1px solid #cbd5e1;" />
									<span class="dashicons dashicons-search" style="position: absolute; left: 6px; top: 7px; color: #94a3b8; font-size: 16px;"></span>
								</div>
							</div>
						</div>

						<!-- Selected Counter Banner -->
						<div style="padding: 6px 20px; background: #e0f2fe; border-bottom: 1px solid #bae6fd; font-size: 0.85rem; color: #0369a1; display: flex; align-items: center; justify-content: space-between;">
							<div>
								<strong id="vk7k-total-selected-count">0</strong> <?php esc_html_e( 'elementos seleccionados en total para la receta.', 'vk7k-wp-sync' ); ?>
								<span id="vk7k-selected-breakdown" style="color: #0c4a6e; margin-left: 8px; font-size: 0.8rem;"></span>
							</div>
							<div style="display: flex; align-items: center; gap: 12px;">
								<label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-weight: 500;">
									<input type="checkbox" id="vk7k-chk-include-media" checked />
									<span><?php esc_html_e( 'Incluir fotos/archivos multimedia asociados automáticamente', 'vk7k-wp-sync' ); ?></span>
								</label>
								<button type="button" id="vk7k-btn-clear-selection" class="button button-link" style="color: #0284c7; text-decoration: none; padding: 0; font-size: 0.8rem;">
									<?php esc_html_e( 'Limpiar todo', 'vk7k-wp-sync' ); ?>
								</button>
							</div>
						</div>

						<!-- DataGrid Container (Scrollable Table) -->
						<div class="vk7k-datagrid-container" style="flex: 1; overflow-y: auto; padding: 12px 20px; background: #fff;">
							<table class="vk7k-datagrid-table widefat striped" id="vk7k-granular-table">
								<thead>
									<tr>
										<th style="width: 40px; text-align: center;"><input type="checkbox" id="vk7k-grid-select-all" /></th>
										<th style="width: 60px;"><?php esc_html_e( 'ID', 'vk7k-wp-sync' ); ?></th>
										<th><?php esc_html_e( 'Título / Nombre', 'vk7k-wp-sync' ); ?></th>
										<th style="width: 140px;"><?php esc_html_e( 'Slug / Identificador', 'vk7k-wp-sync' ); ?></th>
										<th style="width: 100px;"><?php esc_html_e( 'Estado', 'vk7k-wp-sync' ); ?></th>
										<th style="width: 140px;"><?php esc_html_e( 'Modificado', 'vk7k-wp-sync' ); ?></th>
									</tr>
								</thead>
								<tbody id="vk7k-grid-tbody">
									<tr>
										<td colspan="6" style="text-align: center; padding: 30px; color: #64748b;">
											<span class="spinner is-active" style="float:none; margin: 0 auto 10px;"></span>
											<p><?php esc_html_e( 'Cargando elementos...', 'vk7k-wp-sync' ); ?></p>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Pagination Bar -->
						<div style="padding: 8px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; font-size: 0.85rem; color: #64748b;">
							<div id="vk7k-grid-pagination-info"><?php esc_html_e( 'Mostrando 0 de 0', 'vk7k-wp-sync' ); ?></div>
							<div style="display: flex; align-items: center; gap: 6px;">
								<button type="button" class="button button-small" id="vk7k-grid-prev-page" disabled>&laquo; <?php esc_html_e( 'Anterior', 'vk7k-wp-sync' ); ?></button>
								<span id="vk7k-grid-current-page" style="font-weight: 600; padding: 0 4px;">1</span>
								<button type="button" class="button button-small" id="vk7k-grid-next-page" disabled><?php esc_html_e( 'Siguiente', 'vk7k-wp-sync' ); ?> &raquo;</button>
							</div>
						</div>

					</div>

					<!-- Lightbox Footer -->
					<div class="vk7k-modal-footer" style="padding: 14px 20px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
						<button type="button" class="button button-secondary vk7k-modal-close-action" style="height: 38px;">
							<?php esc_html_e( 'Cerrar sin Sincronizar', 'vk7k-wp-sync' ); ?>
						</button>
						<div style="display: flex; align-items: center; gap: 10px;">
							<button type="button" id="vk7k-btn-run-granular-sync" class="button button-primary" style="height: 38px; padding: 0 20px; font-weight: 600; background: #2563eb; border-color: #1d4ed8;">
								<span class="dashicons dashicons-randomize" style="margin-top: 4px;"></span> <?php esc_html_e( 'Ejecutar Sincronización Granular Ahora', 'vk7k-wp-sync' ); ?>
							</button>
						</div>
					</div>

				</div>
			</div>

			<!-- RECIPE SAVE MODAL (Bóveda Shielded) -->
			<div id="vk7k-save-recipe-modal" class="vk7k-modal" style="display:none;">
				<div class="vk7k-modal-content" style="max-width: 520px;">
					<div class="vk7k-modal-header">
						<h3><span class="dashicons dashicons-star-filled" style="color: #facc15;"></span> <?php esc_html_e( 'Guardar Receta en Bóveda Protegida', 'vk7k-wp-sync' ); ?></h3>
						<button type="button" class="vk7k-modal-close-btn">&times;</button>
					</div>
					<div class="vk7k-modal-body">
						<form id="vk7k-recipe-save-form">
							<div class="vk7k-form-group">
								<label for="vk7k-recipe-input-name"><strong><?php esc_html_e( 'Nombre de la Receta:', 'vk7k-wp-sync' ); ?></strong></label>
								<input type="text" id="vk7k-recipe-input-name" class="vk7k-input" required placeholder="<?php esc_attr_e( 'Ej: Productos Nuevos Octubre y Menú', 'vk7k-wp-sync' ); ?>" />
							</div>
							<div class="vk7k-form-group">
								<label for="vk7k-recipe-input-desc"><?php esc_html_e( 'Descripción (Opcional):', 'vk7k-wp-sync' ); ?></label>
								<textarea id="vk7k-recipe-input-desc" class="vk7k-input" rows="2" placeholder="<?php esc_attr_e( 'Sincroniza solo las páginas de toldos y el menú principal...', 'vk7k-wp-sync' ); ?>"></textarea>
							</div>
							<div class="vk7k-alert is-info" style="margin-top: 12px; font-size: 0.85rem;">
								<span class="dashicons dashicons-shield"></span>
								<?php esc_html_e( 'Esta receta quedará blindada contra migraciones y sobrescrituras tanto en la base de datos como en el archivo protegido de la bóveda.', 'vk7k-wp-sync' ); ?>
							</div>
							<div class="vk7k-modal-footer" style="padding: 12px 0 0; margin-top: 16px;">
								<button type="button" class="button button-secondary vk7k-modal-close-action"><?php esc_html_e( 'Cancelar', 'vk7k-wp-sync' ); ?></button>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar Receta', 'vk7k-wp-sync' ); ?></button>
							</div>
						</form>
					</div>
				</div>
			</div>
			</div><!-- /#vk7k-master-view -->
		</div><!-- /#vk7k-main-wrap -->
		<?php
	}
}

new VK7K_Sync_Admin();
