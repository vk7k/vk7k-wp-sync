<?php
/**
 * VK7K Sync Admin View & Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_legacy_slug_redirect' ) );
	}

	/**
	 * Redirect legacy/short slug to official page slug.
	 */
	public function handle_legacy_slug_redirect() {
		if ( isset( $_GET['page'] ) && 'vk7k-sync' === $_GET['page'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=vk7k-wp-sync' ) );
			exit;
		}
	}

	/**
	 * Register Admin Menu.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'VK7K WP Sync', 'vk7k-wp-sync' ),
			__( 'VK7K Sync', 'vk7k-wp-sync' ),
			'manage_options',
			'vk7k-wp-sync',
			array( $this, 'render_admin_page' ),
			'dashicons-randomize',
			75
		);
	}

	/**
	 * Enqueue Admin CSS & JS.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_vk7k-wp-sync' !== $hook ) {
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
			<header class="vk7k-header">
				<div class="vk7k-header-brand">
					<div class="vk7k-logo-badge">VK7K</div>
					<div class="vk7k-header-title">
						<h1>WP Sync <span class="vk7k-badge">v<?php echo esc_html( VK7K_SYNC_VERSION ); ?></span></h1>
						<p class="vk7k-subtitle"><?php esc_html_e( 'Sincronización y clonación bidireccional segura entre entornos WordPress', 'vk7k-wp-sync' ); ?></p>
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

						<!-- Card 2: Design & Templates -->
						<div class="vk7k-sync-type-card" data-type="design_theme">
							<div class="vk7k-type-badge-pill"><?php esc_html_e( 'FSE + Temas', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">🎨</div>
							<h3 class="vk7k-type-title">
								<span class="vk7k-verb"><?php echo 'slave' === $current_role ? esc_html__( 'Traer', 'vk7k-wp-sync' ) : esc_html__( 'Enviar', 'vk7k-wp-sync' ); ?></span>
								<?php esc_html_e( 'Diseño y Plantillas', 'vk7k-wp-sync' ); ?>
							</h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Plantillas del editor del sitio (FSE), estilos globales, opciones de tema/customizer y carpeta del tema activo.', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-type-check-indicator"><span class="dashicons dashicons-yes-alt"></span></div>
						</div>

						<!-- Card 3: Full Safe Content -->
						<div class="vk7k-sync-type-card" data-type="full_content_safe">
							<div class="vk7k-type-badge-pill is-safe"><?php esc_html_e( 'Todo el Contenido Seguro', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">📦</div>
							<h3 class="vk7k-type-title">
								<span class="vk7k-verb"><?php echo 'slave' === $current_role ? esc_html__( 'Traer', 'vk7k-wp-sync' ) : esc_html__( 'Enviar', 'vk7k-wp-sync' ); ?></span>
								<?php esc_html_e( 'Todo el Contenido (Seguro)', 'vk7k-wp-sync' ); ?>
							</h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Entradas, páginas, productos, taxonomías, opciones y biblioteca multimedia. Preserva clientes, pedidos y usuarios.', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-type-check-indicator"><span class="dashicons dashicons-yes-alt"></span></div>
						</div>

						<!-- Card 4: Advanced Custom -->
						<div class="vk7k-sync-type-card is-advanced" data-type="custom_advanced">
							<div class="vk7k-type-badge-pill is-advanced"><?php esc_html_e( 'Control Total', 'vk7k-wp-sync' ); ?></div>
							<div class="vk7k-type-icon">⚙️</div>
							<h3 class="vk7k-type-title"><?php esc_html_e( 'Personalizado (Avanzado)', 'vk7k-wp-sync' ); ?></h3>
							<p class="vk7k-type-desc">
								<?php esc_html_e( 'Despliega el control granular completo para seleccionar manualmente cada tabla, usuarios, pedidos, plugins y temas.', 'vk7k-wp-sync' ); ?>
							</p>
							<div class="vk7k-type-check-indicator"><span class="dashicons dashicons-yes-alt"></span></div>
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
						<button type="button" id="vk7k-btn-view-advanced" class="button button-small button-secondary">
							<span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Ver / Ajustar en Modo Avanzado', 'vk7k-wp-sync' ); ?>
						</button>
					</div>

					<!-- Granular Advanced Panel (Initially Hidden) -->
					<div id="vk7k-advanced-panel" style="display:none; margin-top: 20px;">
						<div class="vk7k-advanced-panel-header">
							<h4><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Ajustes Granulares de Sincronización', 'vk7k-wp-sync' ); ?></h4>
							<p class="description"><?php esc_html_e( 'Marca o desmarca los componentes individuales según tus necesidades específicas.', 'vk7k-wp-sync' ); ?></p>
						</div>

						<div class="vk7k-accordion-group">
						
						<!-- 1. Database Block (Positive Logic) -->
						<div class="vk7k-accordion-item is-open">
							<div class="vk7k-accordion-header">
								<label class="vk7k-master-check">
									<input type="checkbox" id="vk7k-opt-db" checked />
									<strong class="vk7k-acc-title" id="vk7k-title-db" data-base-title="<?php esc_attr_e( 'Base de Datos (WooCommerce, Posts, Meta, Opciones, FSE)', 'vk7k-wp-sync' ); ?>">
										<?php echo 'slave' === $current_role ? esc_html__( '⬇️ Traer Base de Datos', 'vk7k-wp-sync' ) : esc_html__( '⬆️ Enviar Base de Datos', 'vk7k-wp-sync' ); ?>
									</strong>
								</label>
								<span class="vk7k-acc-arrow dashicons dashicons-arrow-down-alt2"></span>
							</div>
							<div class="vk7k-accordion-body">
								<div class="vk7k-db-subgroups-grid">
									
									<!-- Subgroup A: General Content -->
									<div class="vk7k-db-subgroup-card">
										<h4 class="vk7k-db-subgroup-title">
											<span class="dashicons dashicons-admin-post"></span>
											<?php esc_html_e( 'Contenido General y Configuración', 'vk7k-wp-sync' ); ?>
										</h4>
										<div class="vk7k-db-subgroup-options">
											<label class="vk7k-sub-check">
												<input type="checkbox" id="vk7k-opt-sync-content" checked />
												<span><?php esc_html_e( 'Entradas, Páginas y CPTs (wp_posts, wp_postmeta)', 'vk7k-wp-sync' ); ?></span>
											</label>
											<label class="vk7k-sub-check">
												<input type="checkbox" id="vk7k-opt-sync-options" checked />
												<span><?php esc_html_e( 'Opciones y Plantillas FSE (wp_options)', 'vk7k-wp-sync' ); ?></span>
											</label>
											<label class="vk7k-sub-check">
												<input type="checkbox" id="vk7k-opt-sync-taxonomies" checked />
												<span><?php esc_html_e( 'Categorías y Taxonomías (wp_terms, taxonomies)', 'vk7k-wp-sync' ); ?></span>
											</label>
											<label class="vk7k-sub-check">
												<input type="checkbox" id="vk7k-opt-sync-comments" checked />
												<span><?php esc_html_e( 'Comentarios y Valoraciones (wp_comments)', 'vk7k-wp-sync' ); ?></span>
											</label>
										</div>
									</div>

									<!-- Subgroup B: WooCommerce & Commerce -->
									<div class="vk7k-db-subgroup-card">
										<h4 class="vk7k-db-subgroup-title">
											<span class="dashicons dashicons-cart"></span>
											<?php esc_html_e( 'WooCommerce y Comercio', 'vk7k-wp-sync' ); ?>
										</h4>
										<div class="vk7k-db-subgroup-options">
											<label class="vk7k-sub-check">
												<input type="checkbox" id="vk7k-opt-sync-wc-orders" />
												<span>
													<strong><?php esc_html_e( 'Pedidos y Ventas (wp_wc_orders, items, cupones)', 'vk7k-wp-sync' ); ?></strong>
													<em class="vk7k-tag-subnote"><?php esc_html_e( '(Desmarcado por defecto para no alterar ventas)', 'vk7k-wp-sync' ); ?></em>
												</span>
											</label>
											<label class="vk7k-sub-check">
												<input type="checkbox" id="vk7k-opt-sync-users" />
												<span>
													<strong><?php esc_html_e( 'Cuentas de Usuarios y Clientes (wp_users, usermeta)', 'vk7k-wp-sync' ); ?></strong>
													<em class="vk7k-tag-subnote"><?php esc_html_e( '(Desmarcado por seguridad: preserva administradores y sesiones locales)', 'vk7k-wp-sync' ); ?></em>
												</span>
											</label>
										</div>
									</div>

									<!-- Subgroup C: Logs & Transients -->
									<div class="vk7k-db-subgroup-card is-logs">
										<h4 class="vk7k-db-subgroup-title">
											<span class="dashicons dashicons-warning"></span>
											<?php esc_html_e( 'Logs y Registros Temporales', 'vk7k-wp-sync' ); ?>
										</h4>
										<div class="vk7k-db-subgroup-options">
											<label class="vk7k-sub-check">
												<input type="checkbox" id="vk7k-opt-sync-logs" />
												<span>
													<?php esc_html_e( 'Transferir Logs de ActionScheduler y WooCommerce', 'vk7k-wp-sync' ); ?>
													<em class="vk7k-tag-warn"><?php esc_html_e( 'No recomendado (puede ralentizar)', 'vk7k-wp-sync' ); ?></em>
												</span>
											</label>
										</div>
									</div>

								</div>
								<p class="description" style="margin-top: 10px;">
									<span class="dashicons dashicons-yes-alt" style="color:#0d9488;font-size:16px;vertical-align:middle;"></span>
									<?php esc_html_e( 'Incluye reemplazo seguro de URLs serializadas en todas las opciones, posts y metadatos.', 'vk7k-wp-sync' ); ?>
								</p>
							</div>
						</div>

						<!-- 2. Media Uploads Block -->
						<div class="vk7k-accordion-item is-open">
							<div class="vk7k-accordion-header">
								<label class="vk7k-master-check">
									<input type="checkbox" id="vk7k-opt-uploads" checked />
									<strong class="vk7k-acc-title" id="vk7k-title-uploads" data-base-title="<?php esc_attr_e( 'Archivos Multimedia (wp-content/uploads)', 'vk7k-wp-sync' ); ?>">
										<?php echo 'slave' === $current_role ? esc_html__( '⬇️ Traer Archivos Multimedia', 'vk7k-wp-sync' ) : esc_html__( '⬆️ Enviar Archivos Multimedia', 'vk7k-wp-sync' ); ?>
									</strong>
								</label>
								<span class="vk7k-acc-arrow dashicons dashicons-arrow-down-alt2"></span>
							</div>
							<div class="vk7k-accordion-body">
								<div class="vk7k-media-filter-row">
									<label>
										<input type="radio" name="media_filter" value="all" checked />
										<?php esc_html_e( 'Toda la biblioteca de medios', 'vk7k-wp-sync' ); ?>
									</label>
									<label>
										<input type="radio" name="media_filter" value="current_year" />
										<?php esc_html_e( 'Solo archivos del año actual (' . date( 'Y' ) . ')', 'vk7k-wp-sync' ); ?>
									</label>
								</div>
							</div>
						</div>

						<!-- 3. Selective Plugins Block -->
						<div class="vk7k-accordion-item is-open">
							<div class="vk7k-accordion-header">
								<label class="vk7k-master-check">
									<input type="checkbox" id="vk7k-opt-plugins" checked />
									<strong class="vk7k-acc-title" id="vk7k-title-plugins" data-base-title="<?php esc_attr_e( 'Plugins Personalizados y de la Suite', 'vk7k-wp-sync' ); ?>">
										<?php echo 'slave' === $current_role ? esc_html__( '⬇️ Traer Plugins', 'vk7k-wp-sync' ) : esc_html__( '⬆️ Enviar Plugins', 'vk7k-wp-sync' ); ?>
									</strong>
								</label>
								<span class="vk7k-acc-arrow dashicons dashicons-arrow-down-alt2"></span>
							</div>
							<div class="vk7k-accordion-body">
								<div class="vk7k-plugins-toolbar">
									<div class="vk7k-plugins-search-wrap">
										<input type="text" id="vk7k-plugin-search" placeholder="<?php esc_attr_e( 'Filtrar plugins...', 'vk7k-wp-sync' ); ?>" class="vk7k-search-input" />
									</div>
									<div class="vk7k-plugins-actions">
										<button type="button" class="button button-small" id="vk7k-plugins-select-all"><?php esc_html_e( 'Todos', 'vk7k-wp-sync' ); ?></button>
										<button type="button" class="button button-small" id="vk7k-plugins-select-active"><?php esc_html_e( 'Solo Activos', 'vk7k-wp-sync' ); ?></button>
										<button type="button" class="button button-small" id="vk7k-plugins-deselect"><?php esc_html_e( 'Ninguno', 'vk7k-wp-sync' ); ?></button>
									</div>
								</div>
								<div class="vk7k-plugin-list">
									<?php foreach ( $plugins as $p ) : ?>
										<label class="vk7k-plugin-item">
											<input type="checkbox" class="vk7k-plugin-cb" value="<?php echo esc_attr( $p['slug'] ); ?>" <?php checked( $p['is_active'] ); ?> data-active="<?php echo $p['is_active'] ? '1' : '0'; ?>" />
											<span class="vk7k-plugin-name"><?php echo esc_html( $p['name'] ); ?></span>
											<div class="vk7k-item-badges">
												<?php if ( ! empty( $p['version'] ) ) : ?>
													<span class="vk7k-badge-ver">v<?php echo esc_html( $p['version'] ); ?></span>
												<?php endif; ?>
												<?php if ( $p['is_active'] ) : ?>
													<span class="vk7k-badge-active"><?php esc_html_e( 'Activo', 'vk7k-wp-sync' ); ?></span>
												<?php endif; ?>
											</div>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<!-- 4. Themes Block -->
						<div class="vk7k-accordion-item is-open">
							<div class="vk7k-accordion-header">
								<label class="vk7k-master-check">
									<input type="checkbox" id="vk7k-opt-themes" checked />
									<strong class="vk7k-acc-title" id="vk7k-title-themes" data-base-title="<?php esc_attr_e( 'Temas y Plantillas FSE (wp-content/themes)', 'vk7k-wp-sync' ); ?>">
										<?php echo 'slave' === $current_role ? esc_html__( '⬇️ Traer Temas y Plantillas', 'vk7k-wp-sync' ) : esc_html__( '⬆️ Enviar Temas y Plantillas', 'vk7k-wp-sync' ); ?>
									</strong>
								</label>
								<span class="vk7k-acc-arrow dashicons dashicons-arrow-down-alt2"></span>
							</div>
							<div class="vk7k-accordion-body">
								<div class="vk7k-theme-list">
									<?php foreach ( $themes as $t ) : ?>
										<label class="vk7k-theme-item">
											<input type="checkbox" class="vk7k-theme-cb" value="<?php echo esc_attr( $t['slug'] ); ?>" <?php checked( $t['is_active'] ); ?> />
											<span class="vk7k-theme-name"><?php echo esc_html( $t['name'] ); ?></span>
											<div class="vk7k-item-badges">
												<?php if ( ! empty( $t['is_fse'] ) ) : ?>
													<span class="vk7k-badge-fse"><?php esc_html_e( 'FSE', 'vk7k-wp-sync' ); ?></span>
												<?php endif; ?>
												<?php if ( $t['is_active'] ) : ?>
													<span class="vk7k-badge-active"><?php esc_html_e( 'Tema Activo', 'vk7k-wp-sync' ); ?></span>
												<?php endif; ?>
											</div>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<!-- End Granular Advanced Panel -->

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
		</div>
		<?php
	}
}

new VK7K_Sync_Admin();
