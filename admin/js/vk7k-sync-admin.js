/**
 * VK7K Sync Admin JavaScript Engine
 */

(function($) {
	'use strict';

	let isSyncRunning = false;
	let shouldCancel = false;
	let isConnectionVerified = false;
	let pendingSyncMode = null;
	let selectedSyncType = null;
	let initialCheckTimer = null;

	$(document).ready(function() {
		initClipboard();
		initRegenerateKey();
		initSaveSettings();
		initTestConnection();
		initSshControls();
		initRoleControls();
		initSyncTypeSelector();
		initAccordionAndSelectors();
		initSyncActions();
		initModalControls();
		initPinVaultControls();
		initTargetControls();
		initUrlChangeListeners();
		initLinkTools();
		initRemotePathScanner();
		initPathSelectionModalEvents();

		// Auto check connection after 5 seconds to prevent rate-limiting and multiple connections on page refresh
		initialCheckTimer = setTimeout(runInitialConnectionCheck, 5000);
	});

	function getErrorMessage(err) {
		if (!err) return 'Error desconocido';
		if (typeof err === 'string') return err;
		if (err.responseJSON && err.responseJSON.data && err.responseJSON.data.message) {
			return err.responseJSON.data.message;
		}
		if (err.responseJSON && err.responseJSON.message) {
			return err.responseJSON.message;
		}
		if (err.data && err.data.message) {
			return err.data.message;
		}
		if (err.message) return err.message;
		if (err.responseText) {
			try {
				const parsed = JSON.parse(err.responseText);
				if (parsed.data && parsed.data.message) return parsed.data.message;
				if (parsed.message) return parsed.message;
			} catch(e) {
				return err.responseText.substring(0, 200);
			}
		}
		if (err.statusText) return `HTTP ${err.status}: ${err.statusText}`;
		return JSON.stringify(err);
	}

	function getCleanHost(url) {
		if (!url) return '';
		try {
			const u = new URL(url);
			return u.hostname || url;
		} catch(e) {
			return url.replace(/^https?:\/\//, '').split('/')[0];
		}
	}

	function escapeHtml(str) {
		if (!str) return '';
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function initClipboard() {
		$('.vk7k-copy-btn').on('click', function(e) {
			e.preventDefault();
			const target = $(this).data('target');
			const input = $(target)[0];
			input.select();
			input.setSelectionRange(0, 99999);
			navigator.clipboard.writeText(input.value).then(function() {
				const $btn = $(e.currentTarget);
				const origHtml = $btn.html();
				$btn.html('<span class="dashicons dashicons-yes"></span>');
				setTimeout(function() {
					$btn.html(origHtml);
				}, 2000);
			});
		});
	}

	function initRegenerateKey() {
		$('#vk7k-regen-key-btn').on('click', function(e) {
			e.preventDefault();
			if (!confirm('¿Estás seguro de regenerar la clave secreta? Deberás actualizarla en el sitio remoto si este se conecta a local.')) {
				return;
			}
			const $btn = $(this);
			$btn.prop('disabled', true);

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_regenerate_key',
				nonce: vk7kSyncData.nonce
			}, function(res) {
				$btn.prop('disabled', false);
				if (res.success && res.data.key) {
					$('#vk7k-local-key').val(res.data.key);
					alert('Nueva clave local generada.');
				}
			});
		});
	}

	function initSshControls() {
		$('input[name="conn_mode"]').on('change', function() {
			const mode = $(this).val();
			$('.vk7k-mode-card').removeClass('is-selected');
			$(this).closest('.vk7k-mode-card').addClass('is-selected');

			if (mode === 'ssh') {
				$('#vk7k-use-ssh').val('1');
				$('#vk7k-mode-ssh-section').slideDown(200);
				$('#vk7k-mode-http-section').slideUp(200);
				$('#vk7k-test-btn-text').text('Probar y Auto-Emparejar');
			} else {
				$('#vk7k-use-ssh').val('0');
				$('#vk7k-mode-ssh-section').slideUp(200);
				$('#vk7k-mode-http-section').slideDown(200);
				$('#vk7k-test-btn-text').text('Probar Conexión REST');
			}
			isConnectionVerified = false;
		});

		$('.vk7k-mode-card').on('click', function() {
			$(this).find('input[type="radio"]').prop('checked', true).trigger('change');
		});
	}

	function updateButtonUrls() {
		const remoteUrl = $('#vk7k-remote-url').val() || '';
		const remoteHost = getCleanHost(remoteUrl) || 'Servidor Remoto';

		$('#vk7k-label-btn-push').text(`⬆️ PUSH: Enviar a ${remoteHost}`);
		$('#vk7k-label-btn-pull').text(`⬇️ PULL: Traer desde ${remoteHost}`);
		$('#vk7k-btn-push').attr('title', `Subir base de datos y archivos desde este sitio local a ${remoteUrl}`);
		$('#vk7k-btn-pull').attr('title', `Descargar base de datos y archivos desde ${remoteUrl} e importar en este sitio local`);
	}

	function initUrlChangeListeners() {
		$('#vk7k-remote-url').on('input change', function() {
			updateButtonUrls();
			isConnectionVerified = false;
		});
		updateButtonUrls();
	}

	function initRoleControls() {
		function applyRoleUi(role, shouldSave) {
			const i18n = vk7kSyncData.i18n || {};
			const $wrap = $('#vk7k-main-wrap');
			const $pill = $('#vk7k-header-role-pill');
			const $pillText = $('#vk7k-header-role-text');

			$('.vk7k-role-card').removeClass('is-selected');
			$(`.vk7k-role-card[data-role="${role}"]`).addClass('is-selected');
			$(`input[name="role_toggle"][value="${role}"]`).prop('checked', true);
			$('#vk7k-role-select').val(role);

			if (role === 'master') {
				$wrap.removeClass('is-theme-slave').addClass('is-theme-master');
				$pill.removeClass('is-slave').addClass('is-master');
				$pillText.text(i18n.roleMaster || '👑 Modo: MAESTRO (Fuente)');

				$('#vk7k-sync-type-header-title').text('¿Qué deseas Enviar a Remoto?');
				$('.vk7k-sync-type-card .vk7k-verb').text('Enviar');

				// Dynamic Section Titles
				$('#vk7k-title-db').text('⬆️ Enviar Base de Datos');
				$('#vk7k-title-uploads').text('⬆️ Enviar Archivos Multimedia');
				$('#vk7k-title-plugins').text('⬆️ Enviar Plugins');
				$('#vk7k-title-themes').text('⬆️ Enviar Temas y Plantillas');

				// Button Security Lock
				$('#vk7k-btn-push').prop('disabled', false).removeClass('is-disabled');
				$('#vk7k-btn-pull').prop('disabled', true).addClass('is-disabled').attr('title', i18n.pullDisabledForMaster || 'Bloqueado en modo Maestro para proteger este sitio.');
			} else {
				$wrap.removeClass('is-theme-master').addClass('is-theme-slave');
				$pill.removeClass('is-master').addClass('is-slave');
				$pillText.text(i18n.roleSlave || '📥 Modo: ESCLAVO (Receptor)');

				$('#vk7k-sync-type-header-title').text('¿Qué deseas Traer desde Remoto?');
				$('.vk7k-sync-type-card .vk7k-verb').text('Traer');

				// Dynamic Section Titles
				$('#vk7k-title-db').text('⬇️ Traer Base de Datos');
				$('#vk7k-title-uploads').text('⬇️ Traer Archivos Multimedia');
				$('#vk7k-title-plugins').text('⬇️ Traer Plugins');
				$('#vk7k-title-themes').text('⬇️ Traer Temas y Plantillas');

				// Button Security Lock
				$('#vk7k-btn-pull').prop('disabled', false).removeClass('is-disabled');
				$('#vk7k-btn-push').prop('disabled', true).addClass('is-disabled').attr('title', i18n.pushDisabledForSlave || 'Bloqueado en modo Esclavo. Solo se permite recibir datos.');
			}

			updateButtonUrls();

			if (shouldSave) {
				$.post(vk7kSyncData.ajaxUrl, {
					action: 'vk7k_sync_save_settings',
					nonce: vk7kSyncData.nonce,
					role: role
				});
			}
		}

		$('input[name="role_toggle"]').on('change', function() {
			const selectedRole = $(this).val();
			applyRoleUi(selectedRole, true);
		});

		$('.vk7k-role-card').on('click', function(e) {
			if (!$(e.target).is('input')) {
				$(this).find('input[type="radio"]').prop('checked', true).trigger('change');
			}
		});

		// Init on load
		applyRoleUi($('#vk7k-role-select').val() || 'master', false);
	}

	function showConnectionAlert(type, message) {
		const $box = $('#vk7k-connection-result');
		$box.removeClass('is-success is-error is-warning is-info')
			.addClass('is-' + type)
			.html(message)
			.slideDown(200);
	}

	async function runInitialConnectionCheck() {
		if (initialCheckTimer) {
			clearTimeout(initialCheckTimer);
			initialCheckTimer = null;
		}

		const remoteUrl = $('#vk7k-remote-url').val();
		const remoteKey = $('#vk7k-remote-key').val();
		const remoteIp  = $('#vk7k-remote-ip').val();

		if (!remoteUrl) {
			showConnectionAlert('warning', '⚠️ Configura la URL del Servidor Remoto y presiona "Probar y Auto-Emparejar".');
			return;
		}

		showConnectionAlert('info', '<span class="dashicons dashicons-update"></span> Verificando conexión en tiempo real con el servidor remoto...');

		try {
			const res = await $.ajax({
				url: vk7kSyncData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_test_connection',
					nonce: vk7kSyncData.nonce,
					remote_url: remoteUrl,
					secret_key: remoteKey,
					remote_ip: remoteIp
				}
			});

			if (res.success) {
				isConnectionVerified = true;
				const siteName = res.data.site_name || getCleanHost(remoteUrl);
				const wpVer = res.data.wp_version ? ` (WP v${res.data.wp_version})` : '';
				const pluginVer = res.data.plugin_version ? ` • Plugin v${res.data.plugin_version}` : '';

				if (res.data.version_mismatch) {
					const rVer = res.data.remote_version || res.data.plugin_version;
					const lVer = res.data.local_version || '1.3.0';
					const deployBtn = `<button type="button" class="button button-small vk7k-manual-deploy-btn" style="margin-left:8px;"><span class="dashicons dashicons-upload" style="vertical-align:middle;font-size:15px;width:15px;height:15px;"></span> Desplegar v${lVer} local al remoto</button>`;
					showConnectionAlert('warning', `<strong>⚠️ Discrepancia de versiones:</strong> ${siteName} tiene Plugin v${rVer} (Local: v${lVer}). Se recomienda actualizar vía GitHub Releases o ${deployBtn}`);
				} else {
					showConnectionAlert('success', `<strong>✓ Conexión establecida:</strong> ${siteName}${wpVer}${pluginVer} está listo para sincronizar.`);
				}
			} else {
				isConnectionVerified = false;
				showConnectionAlert('error', `<strong>⚠️ Sin conexión:</strong> ${getErrorMessage(res)}. Ejecuta <em>"Probar y Auto-Emparejar"</em> para verificar credenciales.`);
			}
		} catch(e) {
			isConnectionVerified = false;
			showConnectionAlert('error', `<strong>⚠️ Error de red:</strong> No se pudo conectar con ${remoteUrl}. Revisa la configuración remota.`);
		}
	}

	function initSaveSettings() {
		$('#vk7k-save-settings-btn').on('click', function(e) {
			e.preventDefault();
			if (initialCheckTimer) {
				clearTimeout(initialCheckTimer);
				initialCheckTimer = null;
			}
			const $btn = $(this);
			$btn.prop('disabled', true).text('Guardando...');

			const data = {
				action: 'vk7k_sync_save_settings',
				nonce: vk7kSyncData.nonce,
				role: $('#vk7k-role-select').val(),
				remote_url: $('#vk7k-remote-url').val(),
				remote_secret_key: $('#vk7k-remote-key').val(),
				remote_ip: $('#vk7k-remote-ip').val(),
				use_ssh: $('#vk7k-use-ssh').val() === '1' ? 1 : 0,
				ssh_host: $('#vk7k-ssh-host').val(),
				ssh_port: $('#vk7k-ssh-port').val(),
				ssh_user: $('#vk7k-ssh-user').val(),
				ssh_pass: $('#vk7k-ssh-pass').val(),
				ssh_path: $('#vk7k-ssh-path').val(),
				sync_db: $('#vk7k-opt-db').is(':checked') ? 1 : 0,
				sync_uploads: $('#vk7k-opt-uploads').is(':checked') ? 1 : 0,
				sync_plugins: $('#vk7k-opt-plugins').is(':checked') ? 1 : 0,
				sync_themes: $('#vk7k-opt-themes').is(':checked') ? 1 : 0
			};

			$.post(vk7kSyncData.ajaxUrl, data, function(res) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Guardar Ajustes');
				if (res.success) {
					if (res.data && res.data.targets_state) {
						vk7kSyncData.targetsState = res.data.targets_state;
					}
					if (res.data && res.data.active_target) {
						vk7kSyncData.activeTarget = res.data.active_target;
					}
					showConnectionAlert('success', res.data.message || 'Ajustes guardados correctamente.');
				} else {
					showConnectionAlert('error', getErrorMessage(res));
				}
			}).fail(function(xhr) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Guardar Ajustes');
				showConnectionAlert('error', 'Error AJAX: ' + getErrorMessage(xhr));
			});
		});
	}

	let pathSelectionConfirmCallback = null;

	function openPathSelectionModal(options) {
		const $modal       = $('#vk7k-path-selection-modal');
		const $alert       = $('#vk7k-path-selection-alert');
		const $list        = $('#vk7k-discovered-paths-container');
		const $customRadio = $('#vk7k-radio-custom-path');
		const $customInput = $('#vk7k-custom-path-input');

		pathSelectionConfirmCallback = options.onConfirm || null;

		$alert.html(options.alertMessage || 'Por favor selecciona la ruta de WordPress que deseas conectar:');
		$list.empty();
		$customRadio.prop('checked', false);
		$customInput.val('');

		const paths = options.paths || [];
		const currentPath = options.configuredPath || '';
		let hasChecked = false;

		paths.forEach(function(item, idx) {
			const isCurrent = (currentPath && item.path === currentPath);
			const shouldCheck = !hasChecked && (isCurrent || item.is_match_url || idx === 0);
			if (shouldCheck) hasChecked = true;

			let badgesHtml = '';
			if (item.is_match_url) {
				badgesHtml += '<span class="vk7k-badge-matched">🎯 Coincide con URL</span> ';
			}
			if (item.is_staging) {
				badgesHtml += '<span class="vk7k-badge-staging">🧪 Staging / Test</span> ';
			} else if (item.is_production) {
				badgesHtml += '<span class="vk7k-badge-prod">🚀 Producción</span> ';
			}
			if (isCurrent) {
				badgesHtml += '<span class="vk7k-badge-current">Actual</span> ';
			}

			const cardHtml = `
				<label class="vk7k-path-option-card ${shouldCheck ? 'is-selected' : ''}" data-path="${escapeHtml(item.path)}">
					<input type="radio" name="vk7k_selected_path_radio" value="${escapeHtml(item.path)}" ${shouldCheck ? 'checked' : ''} />
					<div class="vk7k-path-option-info">
						<div class="vk7k-path-option-title-row">
							<span class="vk7k-path-option-name">${escapeHtml(item.folder || item.path)}</span>
							${badgesHtml}
						</div>
						<span class="vk7k-path-option-full">${escapeHtml(item.path)}</span>
					</div>
				</label>
			`;
			$list.append(cardHtml);
		});

		if (currentPath && !paths.some(p => p.path === currentPath)) {
			$customRadio.prop('checked', true);
			$customInput.val(currentPath);
			$('.vk7k-path-option-card').removeClass('is-selected');
		}

		$modal.fadeIn(150).css('display', 'flex');
	}

	function initPathSelectionModalEvents() {
		$(document).on('click', '.vk7k-path-option-card', function(e) {
			if (!$(e.target).is('input[type="radio"]')) {
				$(this).find('input[type="radio"]').prop('checked', true);
			}
			$('.vk7k-path-option-card').removeClass('is-selected');
			$(this).addClass('is-selected');
			$('#vk7k-radio-custom-path').prop('checked', false);
		});

		$('#vk7k-custom-path-input').on('focus input', function() {
			$('#vk7k-radio-custom-path').prop('checked', true);
			$('.vk7k-path-option-card').removeClass('is-selected');
		});

		$('#vk7k-radio-custom-path').on('change', function() {
			if ($(this).is(':checked')) {
				$('.vk7k-path-option-card').removeClass('is-selected');
				$('#vk7k-custom-path-input').focus();
			}
		});

		$('#vk7k-btn-confirm-path-selection').on('click', function() {
			const selectedVal = $('input[name="vk7k_selected_path_radio"]:checked').val();
			let chosenPath = '';
			if (selectedVal === '__custom__') {
				chosenPath = $.trim($('#vk7k-custom-path-input').val());
			} else {
				chosenPath = selectedVal;
			}

			if (!chosenPath) {
				alert('Por favor selecciona una ruta o ingresa una ruta manualmente.');
				return;
			}

			$('#vk7k-path-selection-modal').fadeOut(150);

			if (typeof pathSelectionConfirmCallback === 'function') {
				pathSelectionConfirmCallback(chosenPath);
			}
		});
	}

	function initRemotePathScanner() {
		$('#vk7k-btn-scan-remote-paths').on('click', function(e) {
			e.preventDefault();
			const $btn      = $(this);
			const host      = $('#vk7k-ssh-host').val();
			const port      = $('#vk7k-ssh-port').val();
			const user      = $('#vk7k-ssh-user').val();
			const pass      = $('#vk7k-ssh-pass').val();
			const path      = $('#vk7k-ssh-path').val();
			const remoteUrl = $('#vk7k-remote-url').val();

			if (!host || !user || (!pass && !vk7kSyncData.hasPin)) {
				showConnectionAlert('error', 'Por favor ingresa Host, Usuario y Contraseña SSH antes de escanear rutas.');
				return;
			}

			if (vk7kSyncData.hasPin && !vk7kSyncData.isVaultUnlocked && !pass) {
				$('#vk7k-pin-unlock-modal').fadeIn(150).css('display', 'flex');
				setTimeout(function() { $('#vk7k-input-unlock-pin').focus(); }, 200);
				showConnectionAlert('error', '⚠️ Desbloquea la bóveda con tu PIN para utilizar las credenciales SSH.');
				return;
			}

			const origHtml = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update vk7k-spin"></span> Escaneando...');

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_scan_remote_paths',
				nonce: vk7kSyncData.nonce,
				ssh_host: host,
				ssh_port: port,
				ssh_user: user,
				ssh_pass: pass,
				ssh_path: path,
				remote_url: remoteUrl
			}, function(res) {
				$btn.prop('disabled', false).html(origHtml);
				if (res.success && res.data && res.data.discovered_paths) {
					const paths = res.data.discovered_paths;
					if (paths.length === 0) {
						showConnectionAlert('warning', 'No se encontraron instalaciones de WordPress en las rutas habituales del servidor. Puedes ingresar la ruta absoluta manualmente.');
						return;
					}
					openPathSelectionModal({
						alertMessage: `Se detectaron ${paths.length} instalaciones de WordPress en el servidor. Selecciona cuál deseas configurar:`,
						paths: paths,
						configuredPath: path,
						onConfirm: function(selectedPath) {
							$('#vk7k-ssh-path').val(selectedPath);
							showConnectionAlert('success', `Ruta configurada: <code>${escapeHtml(selectedPath)}</code>. Haz clic en "Probar y Auto-Emparejar".`);
							$('#vk7k-save-settings-btn').trigger('click');
						}
					});
				} else {
					showConnectionAlert('error', 'Error al escanear rutas: ' + getErrorMessage(res));
				}
			}).fail(function(xhr) {
				$btn.prop('disabled', false).html(origHtml);
				showConnectionAlert('error', 'Error de red al escanear rutas: ' + getErrorMessage(xhr));
			});
		});

		$('#vk7k-btn-scan-target-paths').on('click', function(e) {
			e.preventDefault();
			const $btn      = $(this);
			const host      = $('#vk7k-edit-target-host').val();
			const port      = $('#vk7k-edit-target-port').val();
			const user      = $('#vk7k-edit-target-user').val();
			const pass      = $('#vk7k-edit-target-pass').val();
			const path      = $('#vk7k-edit-target-path').val();
			const remoteUrl = $('#vk7k-edit-target-url').val();

			if (!host || !user) {
				alert('Por favor ingresa Host y Usuario SSH antes de escanear.');
				return;
			}

			const origHtml = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update vk7k-spin"></span>');

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_scan_remote_paths',
				nonce: vk7kSyncData.nonce,
				ssh_host: host,
				ssh_port: port,
				ssh_user: user,
				ssh_pass: pass,
				ssh_path: path,
				remote_url: remoteUrl
			}, function(res) {
				$btn.prop('disabled', false).html(origHtml);
				if (res.success && res.data && res.data.discovered_paths) {
					const paths = res.data.discovered_paths;
					if (paths.length === 0) {
						alert('No se encontraron instalaciones de WordPress en las rutas habituales del servidor.');
						return;
					}
					openPathSelectionModal({
						alertMessage: `Se detectaron ${paths.length} instalaciones de WordPress en el servidor. Selecciona cuál asignar a este perfil:`,
						paths: paths,
						configuredPath: path,
						onConfirm: function(selectedPath) {
							$('#vk7k-edit-target-path').val(selectedPath);
						}
					});
				} else {
					alert('Error al escanear rutas: ' + getErrorMessage(res));
				}
			}).fail(function(xhr) {
				$btn.prop('disabled', false).html(origHtml);
				alert('Error de red al escanear rutas.');
			});
		});
	}

	function initTestConnection() {
		async function runTestConnectionFlow(forceConfirm) {
			const $btn = $('#vk7k-test-connection-btn');
			const isSsh = $('#vk7k-use-ssh').val() === '1';

			$btn.prop('disabled', true);

			const remoteUrl = $('#vk7k-remote-url').val();
			const remoteKey = $('#vk7k-remote-key').val();
			const remoteIp  = $('#vk7k-remote-ip').val();

			if (!remoteUrl) {
				$btn.prop('disabled', false);
				showConnectionAlert('error', 'Por favor ingresa la URL del WordPress remoto.');
				return;
			}

			if (isSsh && vk7kSyncData.hasPin && !vk7kSyncData.isVaultUnlocked && !$('#vk7k-ssh-pass').val()) {
				$btn.prop('disabled', false);
				$('#vk7k-pin-unlock-modal').fadeIn(150).css('display', 'flex');
				setTimeout(function() { $('#vk7k-input-unlock-pin').focus(); }, 200);
				showConnectionAlert('error', '⚠️ Bóveda protegida: Desbloquea la bóveda con tu PIN Maestro para utilizar tus credenciales SSH guardadas.');
				return;
			}

			try {
				if (isSsh) {
					$btn.html('<span class="dashicons dashicons-update vk7k-spin"></span> Auto-Emparejando por SSH...');
					const sshRes = await $.ajax({
						url: vk7kSyncData.ajaxUrl,
						type: 'POST',
						data: {
							action: 'vk7k_sync_test_ssh',
							nonce: vk7kSyncData.nonce,
							ssh_host: $('#vk7k-ssh-host').val(),
							ssh_port: $('#vk7k-ssh-port').val(),
							ssh_user: $('#vk7k-ssh-user').val(),
							ssh_pass: $('#vk7k-ssh-pass').val(),
							ssh_path: $('#vk7k-ssh-path').val(),
							remote_url: remoteUrl,
							force_confirm: forceConfirm ? 1 : 0
						}
					});

					if (!sshRes.success) {
						if (sshRes.data && sshRes.data.needs_path_selection) {
							openPathSelectionModal({
								alertMessage: sshRes.data.message,
								paths: sshRes.data.discovered_paths || [],
								configuredPath: sshRes.data.configured_path || '',
								onConfirm: function(selectedPath) {
									$('#vk7k-ssh-path').val(selectedPath);
									saveAndReRunTestWithForce(selectedPath);
								}
							});
							return;
						}
						throw new Error(getErrorMessage(sshRes));
					}

					if (sshRes.data && sshRes.data.detected_path) {
						$('#vk7k-ssh-path').val(sshRes.data.detected_path);
					}
				}

				$btn.html('<span class="dashicons dashicons-update vk7k-spin"></span> Verificando Handshake REST...');
				const testRes = await $.ajax({
					url: vk7kSyncData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'vk7k_sync_test_connection',
						nonce: vk7kSyncData.nonce,
						remote_url: remoteUrl,
						secret_key: remoteKey,
						remote_ip: remoteIp
					}
				});

				if (!testRes.success) {
					throw new Error(getErrorMessage(testRes));
				}

				isConnectionVerified = true;
				const siteName  = testRes.data.site_name || remoteUrl;
				const wpVer     = testRes.data.wp_version ? ` (WP v${testRes.data.wp_version})` : '';
				const pluginVer = testRes.data.plugin_version ? ` • Plugin v${testRes.data.plugin_version}` : '';

				if (testRes.data.version_mismatch) {
					const rVer = testRes.data.remote_version || testRes.data.plugin_version;
					const lVer = testRes.data.local_version || '1.3.0';
					const deployBtn = `<button type="button" class="button button-small vk7k-manual-deploy-btn" style="margin-left:8px;"><span class="dashicons dashicons-upload" style="vertical-align:middle;font-size:15px;width:15px;height:15px;"></span> Desplegar v${lVer} local al remoto</button>`;
					showConnectionAlert('warning', `<strong>⚠️ Conexión establecida con discrepancia de versión:</strong> ${siteName}${wpVer} tiene Plugin v${rVer} (Local: v${lVer}). ${deployBtn}`);
				} else {
					showConnectionAlert('success', `<strong>✓ Conexión y Auto-Emparejamiento Exitoso:</strong> Conectado con ${siteName}${wpVer}${pluginVer}. Entorno listo.`);
				}
			} catch (err) {
				isConnectionVerified = false;
				showConnectionAlert('error', `<strong>⚠️ Error de conexión:</strong> ${getErrorMessage(err)}`);
			} finally {
				$btn.prop('disabled', false).html(`<span class="dashicons dashicons-yes-alt"></span> <span id="vk7k-test-btn-text">${isSsh ? 'Probar y Auto-Emparejar' : 'Probar Conexión REST'}</span>`);
			}
		}

		async function saveAndReRunTestWithForce(selectedPath) {
			showConnectionAlert('info', `<span class="dashicons dashicons-update vk7k-spin"></span> Guardando ruta <code>${escapeHtml(selectedPath)}</code> y auto-emparejando...`);
			try {
				await $.ajax({
					url: vk7kSyncData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'vk7k_sync_save_settings',
						nonce: vk7kSyncData.nonce,
						role: $('#vk7k-role-select').val(),
						remote_url: $('#vk7k-remote-url').val(),
						remote_secret_key: $('#vk7k-remote-key').val(),
						remote_ip: $('#vk7k-remote-ip').val(),
						use_ssh: 1,
						ssh_host: $('#vk7k-ssh-host').val(),
						ssh_port: $('#vk7k-ssh-port').val(),
						ssh_user: $('#vk7k-ssh-user').val(),
						ssh_pass: $('#vk7k-ssh-pass').val(),
						ssh_path: selectedPath,
						sync_db: $('#vk7k-opt-db').is(':checked') ? 1 : 0,
						sync_uploads: $('#vk7k-opt-uploads').is(':checked') ? 1 : 0,
						sync_plugins: $('#vk7k-opt-plugins').is(':checked') ? 1 : 0,
						sync_themes: $('#vk7k-opt-themes').is(':checked') ? 1 : 0
					}
				});

				runTestConnectionFlow(true);
			} catch(e) {
				showConnectionAlert('error', 'Error al guardar la ruta seleccionada: ' + getErrorMessage(e));
			}
		}

		$('#vk7k-test-connection-btn').on('click', function(e) {
			e.preventDefault();
			if (initialCheckTimer) {
				clearTimeout(initialCheckTimer);
				initialCheckTimer = null;
			}
			runTestConnectionFlow(false);
		});

		$(document).on('click', '.vk7k-manual-deploy-btn', async function(e) {
			e.preventDefault();
			const $dBtn = $(this);
			$dBtn.prop('disabled', true).html('<span class="dashicons dashicons-update vk7k-spin"></span> Desplegando...');
			try {
				const depRes = await $.ajax({
					url: vk7kSyncData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'vk7k_sync_deploy_plugin_to_remote',
						nonce: vk7kSyncData.nonce,
						remote_url: $('#vk7k-remote-url').val().trim(),
						secret_key: $('#vk7k-remote-key').val().trim(),
						remote_ip: $('#vk7k-remote-ip').val().trim()
					}
				});
				if (depRes.success) {
					showConnectionAlert('success', `<strong>✓ Despliegue Exitoso:</strong> ${depRes.data.message}`);
				} else {
					showConnectionAlert('error', `<strong>⚠️ Error al desplegar:</strong> ${getErrorMessage(depRes)}`);
					$dBtn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Reintentar despliegue');
				}
			} catch (dErr) {
				showConnectionAlert('error', `<strong>⚠️ Error en petición:</strong> ${getErrorMessage(dErr)}`);
				$dBtn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Reintentar despliegue');
			}
		});
	}

	function initSyncTypeSelector() {
		$('.vk7k-sync-type-card').on('click', function() {
			const type = $(this).data('type');
			$('.vk7k-sync-type-card').removeClass('is-selected');
			$(this).addClass('is-selected');
			selectedSyncType = type;

			if (type === 'woocommerce_catalog') {
				// DB setup: only content (posts/meta for products) and taxonomies, no options, comments, orders, users, logs
				$('#vk7k-opt-db').prop('checked', true).trigger('change');
				$('#vk7k-opt-sync-content').prop('checked', true);
				$('#vk7k-opt-sync-options').prop('checked', false);
				$('#vk7k-opt-sync-taxonomies').prop('checked', true);
				$('#vk7k-opt-sync-comments').prop('checked', false);
				$('#vk7k-opt-sync-wc-orders').prop('checked', false);
				$('#vk7k-opt-sync-users').prop('checked', false);
				$('#vk7k-opt-sync-logs').prop('checked', false);

				// Uploads: checked (all)
				$('#vk7k-opt-uploads').prop('checked', true).trigger('change');
				$('input[name="media_filter"][value="all"]').prop('checked', true);

				// Plugins & Themes: unchecked
				$('#vk7k-opt-plugins').prop('checked', false).trigger('change');
				$('#vk7k-opt-themes').prop('checked', false).trigger('change');

				$('#vk7k-preset-summary-title').text('🛍️ Catálogo de WooCommerce Seleccionado');
				$('#vk7k-preset-summary-details').html('Incluye: <strong>Productos, variaciones, atributos, categorías (`product_cat`, `product_tag`) y galería de imágenes (Uploads)</strong>.<br/>🔒 <em>Pedidos de WooCommerce y Cuentas de Usuarios quedan 100% protegidos y no serán alterados.</em>');
				$('#vk7k-advanced-panel').slideUp(200);
				$('#vk7k-selected-preset-summary').slideDown(200);
			} else if (type === 'design_theme') {
				// DB setup: content (for templates), options (for customizer / FSE), no orders, users, comments, logs
				$('#vk7k-opt-db').prop('checked', true).trigger('change');
				$('#vk7k-opt-sync-content').prop('checked', true);
				$('#vk7k-opt-sync-options').prop('checked', true);
				$('#vk7k-opt-sync-taxonomies').prop('checked', false);
				$('#vk7k-opt-sync-comments').prop('checked', false);
				$('#vk7k-opt-sync-wc-orders').prop('checked', false);
				$('#vk7k-opt-sync-users').prop('checked', false);
				$('#vk7k-opt-sync-logs').prop('checked', false);

				// Uploads: unchecked
				$('#vk7k-opt-uploads').prop('checked', false).trigger('change');

				// Plugins: unchecked
				$('#vk7k-opt-plugins').prop('checked', false).trigger('change');

				// Themes: checked (active theme)
				$('#vk7k-opt-themes').prop('checked', true).trigger('change');

				$('#vk7k-preset-summary-title').text('🎨 Diseño, Plantillas y Tema Seleccionado');
				$('#vk7k-preset-summary-details').html('Incluye: <strong>Plantillas FSE (`wp_template`, `wp_template_part`), estilos globales, opciones de diseño y carpeta del tema activo</strong>.<br/>🔒 <em>Sin impacto en usuarios ni pedidos.</em>');
				$('#vk7k-advanced-panel').slideUp(200);
				$('#vk7k-selected-preset-summary').slideDown(200);
			} else if (type === 'full_content_safe') {
				// DB setup: content, options, taxonomies, comments. Users & Orders UNCHECKED.
				$('#vk7k-opt-db').prop('checked', true).trigger('change');
				$('#vk7k-opt-sync-content').prop('checked', true);
				$('#vk7k-opt-sync-options').prop('checked', true);
				$('#vk7k-opt-sync-taxonomies').prop('checked', true);
				$('#vk7k-opt-sync-comments').prop('checked', true);
				$('#vk7k-opt-sync-wc-orders').prop('checked', false); // Preserves orders
				$('#vk7k-opt-sync-users').prop('checked', false); // Preserves users
				$('#vk7k-opt-sync-logs').prop('checked', false);

				// Uploads: checked
				$('#vk7k-opt-uploads').prop('checked', true).trigger('change');
				$('input[name="media_filter"][value="all"]').prop('checked', true);

				// Plugins & Themes: unchecked by default
				$('#vk7k-opt-plugins').prop('checked', false).trigger('change');
				$('#vk7k-opt-themes').prop('checked', false).trigger('change');

				$('#vk7k-preset-summary-title').text('📦 Todo el Contenido Seguro Seleccionado');
				$('#vk7k-preset-summary-details').html('Incluye: <strong>Entradas, páginas, productos WooCommerce, taxonomías, opciones y biblioteca multimedia</strong>.<br/>🔒 <em>Pedidos y usuarios quedan intactos y protegidos.</em>');
				$('#vk7k-advanced-panel').slideUp(200);
				$('#vk7k-selected-preset-summary').slideDown(200);
			} else if (type === 'custom_advanced') {
				$('#vk7k-selected-preset-summary').slideUp(200);
				$('#vk7k-advanced-panel').slideDown(250);
			}
		});

		$('#vk7k-btn-view-advanced').on('click', function(e) {
			e.preventDefault();
			$('.vk7k-sync-type-card[data-type="custom_advanced"]').trigger('click');
		});

		$('#vk7k-opt-sync-users').on('change', function() {
			if ($(this).is(':checked')) {
				showConnectionAlert('info', 'ℹ️ Has seleccionado sincronizar usuarios y clientes. Los administradores locales y sus sesiones activas serán resguardados automáticamente.');
			}
		});

		// Trigger safe default preset on load if none selected
		if (!$('.vk7k-sync-type-card.is-selected').length) {
			$('.vk7k-sync-type-card[data-type="woocommerce_catalog"]').trigger('click');
		}
	}

	function initAccordionAndSelectors() {
		// Toggle accordion on header click
		$('.vk7k-accordion-header').on('click', function(e) {
			if ($(e.target).is('input[type="checkbox"]')) {
				return; // Handled by checkbox change
			}
			const $item = $(this).closest('.vk7k-accordion-item');
			$item.toggleClass('is-open');
		});

		// Master Checkboxes toggle accordion body enabled state
		$('.vk7k-master-check input[type="checkbox"]').on('change', function() {
			const $item = $(this).closest('.vk7k-accordion-item');
			const isChecked = $(this).is(':checked');
			if (isChecked) {
				$item.addClass('is-open');
				$item.find('.vk7k-accordion-body input').prop('disabled', false);
			} else {
				$item.find('.vk7k-accordion-body input').prop('disabled', true);
			}
		});

		// Search plugins
		$('#vk7k-plugin-search').on('input', function() {
			const q = $(this).val().toLowerCase().trim();
			$('.vk7k-plugin-item').each(function() {
				const name = $(this).find('.vk7k-plugin-name').text().toLowerCase();
				if (!q || name.includes(q)) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		// Plugin selection buttons
		$('#vk7k-plugins-select-all').on('click', function() {
			$('.vk7k-plugin-cb').prop('checked', true);
		});

		$('#vk7k-plugins-select-active').on('click', function() {
			$('.vk7k-plugin-cb').each(function() {
				const isActive = $(this).data('active') === 1 || $(this).data('active') === '1';
				$(this).prop('checked', isActive);
			});
		});

		$('#vk7k-plugins-deselect').on('click', function() {
			$('.vk7k-plugin-cb').prop('checked', false);
		});
	}

	function initSyncActions() {
		$('#vk7k-btn-push').on('click', function(e) {
			e.preventDefault();
			if ($(this).prop('disabled') || $(this).hasClass('is-disabled')) return;
			if (!selectedSyncType) {
				alert('⚠️ Por favor selecciona primero qué contenido deseas sincronizar en la sección superior.');
				return;
			}
			prepareConfirmationModal('push');
		});

		$('#vk7k-btn-pull').on('click', function(e) {
			e.preventDefault();
			if ($(this).prop('disabled') || $(this).hasClass('is-disabled')) return;
			if (!selectedSyncType) {
				alert('⚠️ Por favor selecciona primero qué contenido deseas sincronizar en la sección superior.');
				return;
			}
			prepareConfirmationModal('pull');
		});

		$('#vk7k-btn-confirm-proceed').on('click', function() {
			$('#vk7k-confirm-modal').fadeOut(100);
			if (pendingSyncMode) {
				startSyncOrchestrator(pendingSyncMode);
			}
		});
	}

	function prepareConfirmationModal(mode) {
		const remoteUrl = $('#vk7k-remote-url').val();
		if (!remoteUrl) {
			alert('Por favor configura la URL del WordPress remoto.');
			return;
		}

		if (vk7kSyncData.hasPin && !vk7kSyncData.isVaultUnlocked) {
			$('#vk7k-pin-unlock-modal').fadeIn(150).css('display', 'flex');
			setTimeout(function() { $('#vk7k-input-unlock-pin').focus(); }, 200);
			alert('⚠️ Bóveda de seguridad protegida: Por favor ingresa tu PIN Maestro para autorizar la sincronización.');
			return;
		}

		if (!selectedSyncType) {
			alert('⚠️ Por favor selecciona primero qué contenido deseas sincronizar.');
			return;
		}

		const syncDb          = $('#vk7k-opt-db').is(':checked');
		const syncContent     = $('#vk7k-opt-sync-content').is(':checked');
		const syncOptions     = $('#vk7k-opt-sync-options').is(':checked');
		const syncTaxonomies  = $('#vk7k-opt-sync-taxonomies').is(':checked');
		const syncComments    = $('#vk7k-opt-sync-comments').is(':checked');
		const syncWcOrders    = $('#vk7k-opt-sync-wc-orders').is(':checked');
		const syncUsers       = $('#vk7k-opt-sync-users').is(':checked');
		const syncLogs        = $('#vk7k-opt-sync-logs').is(':checked');

		const syncUploads     = $('#vk7k-opt-uploads').is(':checked');
		const mediaFilter     = $('input[name="media_filter"]:checked').val() || 'all';

		const syncPlugins     = $('#vk7k-opt-plugins').is(':checked');
		const selectedPlugins = [];
		if (syncPlugins) {
			$('.vk7k-plugin-cb:checked').each(function() {
				selectedPlugins.push($(this).val());
			});
		}

		const syncThemes     = $('#vk7k-opt-themes').is(':checked');
		const selectedThemes = [];
		if (syncThemes) {
			$('.vk7k-theme-cb:checked').each(function() {
				selectedThemes.push($(this).val());
			});
		}

		if (!syncDb && !syncUploads && (!syncPlugins || selectedPlugins.length === 0) && (!syncThemes || selectedThemes.length === 0)) {
			alert('Debes seleccionar al menos un componente para sincronizar.');
			return;
		}

		pendingSyncMode = mode;
		const localUrl = window.location.origin;

		if (mode === 'push') {
			$('#vk7k-summary-origin-url').text(`${localUrl} (Local)`);
			$('#vk7k-summary-dest-url').text(`${remoteUrl} (Remoto)`);
			$('#vk7k-confirm-title').html('<span class="dashicons dashicons-upload" style="color:#4f46e5;"></span> Confirmar Sincronización PUSH (Subir)');
			$('#vk7k-confirm-warning-text').text('⚠️ Se sobrescribirá la base de datos y los archivos en el Servidor Remoto con la información de este sitio local.');
		} else {
			$('#vk7k-summary-origin-url').text(`${remoteUrl} (Remoto)`);
			$('#vk7k-summary-dest-url').text(`${localUrl} (Local)`);
			$('#vk7k-confirm-title').html('<span class="dashicons dashicons-download" style="color:#0d9488;"></span> Confirmar Sincronización PULL (Traer)');
			$('#vk7k-confirm-warning-text').text('ℹ️ Se descargarán los datos del Servidor Remoto y se actualizará este sitio local. El servidor remoto solo será leído.');
		}

		// Build Summary List
		const $list = $('#vk7k-confirm-summary-list').empty();

		// Header for selected type
		let typeName = 'Personalizado (Avanzado)';
		let typeIcon = '⚙️';
		if (selectedSyncType === 'woocommerce_catalog') {
			typeName = 'Catálogo de WooCommerce (Productos, Fotos y Categorías)';
			typeIcon = '🛍️';
		} else if (selectedSyncType === 'design_theme') {
			typeName = 'Diseño, Plantillas FSE y Temas';
			typeIcon = '🎨';
		} else if (selectedSyncType === 'full_content_safe') {
			typeName = 'Todo el Contenido Seguro (Sin Usuarios ni Pedidos)';
			typeIcon = '📦';
		}

		$list.append(`
			<div class="vk7k-summary-item" style="background:#f8fafc; border-left:4px solid #4f46e5; margin-bottom:12px;">
				<div class="vk7k-summary-head"><span>${typeIcon}</span> <strong>Tipo de Sincronización:</strong> ${typeName}</div>
			</div>
		`);

		// DB item
		if (syncDb) {
			const dbDetails = [];
			if (syncContent) dbDetails.push('Posts/Páginas');
			if (syncOptions) dbDetails.push('Opciones');
			if (syncTaxonomies) dbDetails.push('Taxonomías');
			if (syncWcOrders) dbDetails.push('<strong>Pedidos WooCommerce ✓</strong>');
			else dbDetails.push('<em>Pedidos WC Omitidos</em>');
			if (syncUsers) dbDetails.push('<strong>Cuentas/Usuarios ✓</strong>');
			else dbDetails.push('<em>Usuarios Omitidos</em>');
			if (syncLogs) dbDetails.push('Logs Temporales');

			$list.append(`
				<div class="vk7k-summary-item">
					<div class="vk7k-summary-head"><span class="dashicons dashicons-database"></span> <strong>Base de Datos Completa</strong></div>
					<div class="vk7k-summary-desc">${dbDetails.join(' • ')}</div>
				</div>
			`);
		}

		// Uploads item
		if (syncUploads) {
			const filterLabel = (mediaFilter === 'current_year') ? 'Solo archivos del año actual (2026)' : 'Toda la biblioteca de medios';
			$list.append(`
				<div class="vk7k-summary-item">
					<div class="vk7k-summary-head"><span class="dashicons dashicons-format-image"></span> <strong>Archivos Multimedia (Uploads)</strong></div>
					<div class="vk7k-summary-desc">${filterLabel}</div>
				</div>
			`);
		}

		// Plugins item
		if (syncPlugins && selectedPlugins.length > 0) {
			$list.append(`
				<div class="vk7k-summary-item">
					<div class="vk7k-summary-head"><span class="dashicons dashicons-admin-plugins"></span> <strong>Plugins (${selectedPlugins.length} seleccionados)</strong></div>
					<div class="vk7k-summary-desc">${selectedPlugins.join(', ')}</div>
				</div>
			`);
		}

		// Themes item
		if (syncThemes && selectedThemes.length > 0) {
			$list.append(`
				<div class="vk7k-summary-item">
					<div class="vk7k-summary-head"><span class="dashicons dashicons-admin-appearance"></span> <strong>Temas (${selectedThemes.length} seleccionados)</strong></div>
					<div class="vk7k-summary-desc">${selectedThemes.join(', ')}</div>
				</div>
			`);
		}

		$('#vk7k-confirm-modal').fadeIn(150).css('display', 'flex');
	}

	function log(msg, type) {
		type = type || 'info';
		const now = new Date().toLocaleTimeString();
		const $line = $('<div>').addClass('vk7k-log-line is-' + type).text(`[${now}] ${msg}`);
		const $console = $('#vk7k-log-console');
		$console.append($line);
		$console.scrollTop($console[0].scrollHeight);
	}

	function setProgress(percentage, label) {
		percentage = Math.min(100, Math.max(0, Math.round(percentage)));
		$('#vk7k-progress-bar-fill').css('width', percentage + '%');
		$('#vk7k-progress-percentage').text(percentage + '%');
		if (label) {
			$('#vk7k-current-step-label').text(label);
		}
	}

	function setStepActive(stepName) {
		$('.vk7k-step-node').removeClass('is-active is-completed');
		const steps = ['init', 'db', 'files', 'finalize'];
		const activeIdx = steps.indexOf(stepName);

		steps.forEach((s, idx) => {
			const $node = $(`.vk7k-step-node[data-step="${s}"]`);
			if (idx < activeIdx) {
				$node.addClass('is-completed');
			} else if (idx === activeIdx) {
				$node.addClass('is-active');
			}
		});
	}

	/**
	 * Main Synchronization Orchestrator
	 */
	async function startSyncOrchestrator(mode) {
		const remoteUrl = $('#vk7k-remote-url').val();
		const remoteKey = $('#vk7k-remote-key').val();
		const remoteIp  = $('#vk7k-remote-ip').val();

		const useSsh  = $('#vk7k-use-ssh').val() === '1';
		const sshHost = $('#vk7k-ssh-host').val();
		const sshPort = $('#vk7k-ssh-port').val();
		const sshUser = $('#vk7k-ssh-user').val();
		const sshPass = $('#vk7k-ssh-pass').val();
		const sshPath = $('#vk7k-ssh-path').val();

		const syncDb          = $('#vk7k-opt-db').is(':checked');
		const syncContent     = $('#vk7k-opt-sync-content').is(':checked');
		const syncOptions     = $('#vk7k-opt-sync-options').is(':checked');
		const syncTaxonomies  = $('#vk7k-opt-sync-taxonomies').is(':checked');
		const syncComments    = $('#vk7k-opt-sync-comments').is(':checked');
		const syncWcOrders    = $('#vk7k-opt-sync-wc-orders').is(':checked');
		const syncUsers       = $('#vk7k-opt-sync-users').is(':checked');
		const syncLogs        = $('#vk7k-opt-sync-logs').is(':checked');

		const syncUploads     = $('#vk7k-opt-uploads').is(':checked');
		const mediaFilter     = $('input[name="media_filter"]:checked').val() || 'all';

		const syncPlugins     = $('#vk7k-opt-plugins').is(':checked');
		const selectedPlugins = [];
		if (syncPlugins) {
			$('.vk7k-plugin-cb:checked').each(function() {
				selectedPlugins.push($(this).val());
			});
		}

		const syncThemes     = $('#vk7k-opt-themes').is(':checked');
		const selectedThemes = [];
		if (syncThemes) {
			$('.vk7k-theme-cb:checked').each(function() {
				selectedThemes.push($(this).val());
			});
		}

		isSyncRunning = true;
		shouldCancel  = false;
		$('#vk7k-modal-title').text(`Sincronización ${mode.toUpperCase()} en Progreso...`);
		$('#vk7k-modal-cancel-btn').show().prop('disabled', false).text('Detener Sincronización');
		$('#vk7k-modal-done-btn').hide();
		$('#vk7k-log-console').empty();
		setProgress(0, 'Iniciando...');
		setStepActive('init');
		$('#vk7k-progress-modal').fadeIn();

		log(`Iniciando sincronización en modo ${mode.toUpperCase()}...`, 'info');
		if (mode === 'push') {
			log(`Dirección del flujo: [Local] ${window.location.origin} ➔ [Destino Remoto] ${remoteUrl}`, 'info');
		} else {
			log(`Dirección del flujo: [Origen Remoto] ${remoteUrl} ➔ [Destino Local] ${window.location.origin}`, 'info');
		}

		try {
			// STEP 1: Handshake
			log('Paso 1: Verificando handshake con el servidor remoto...', 'info');
			const testRes = await $.ajax({
				url: vk7kSyncData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_test_connection',
					nonce: vk7kSyncData.nonce,
					remote_url: remoteUrl,
					secret_key: remoteKey,
					remote_ip: remoteIp
				}
			});

			if (!testRes.success) {
				throw new Error(getErrorMessage(testRes));
			}
			const opt = testRes.data.optimal_settings || { zip_chunk_mb: 4, db_batch: 1000 };
			log(`Handshake OK. Remoto: ${testRes.data.site_name} (WP v${testRes.data.wp_version})`, 'success');
			log(`⚡ Evaluación del Servidor: Memoria: ${opt.min_mem_mb || 256}M. Lote ZIP: ${opt.zip_chunk_mb}MB, DB: ${opt.db_batch} filas.`, 'info');
			setProgress(10, 'Handshake verificado');

			if (shouldCancel) return;

			// STEP 2: Database Sync
			if (syncDb) {
				setStepActive('db');
				log('Paso 2: Obteniendo listado de tablas con filtros seleccionados...', 'info');
				log(`Opciones DB: Contenido=${syncContent?'Sí':'No'}, Pedidos=${syncWcOrders?'Sí':'No'}, Usuarios=${syncUsers?'Sí':'No'}, Logs=${syncLogs?'Sí':'No'}`, 'info');

				const tablesRes = await $.ajax({
					url: vk7kSyncData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'vk7k_sync_get_tables',
						nonce: vk7kSyncData.nonce,
						mode: mode,
						sync_content: syncContent ? 1 : 0,
						sync_options: syncOptions ? 1 : 0,
						sync_taxonomies: syncTaxonomies ? 1 : 0,
						sync_comments: syncComments ? 1 : 0,
						sync_wc_orders: syncWcOrders ? 1 : 0,
						sync_users: syncUsers ? 1 : 0,
						sync_logs: syncLogs ? 1 : 0,
						remote_url: remoteUrl,
						secret_key: remoteKey,
						remote_ip: remoteIp
					}
				});

				if (!tablesRes.success || !tablesRes.data.tables) {
					throw new Error(getErrorMessage(tablesRes) || 'No se pudieron listar las tablas.');
				}

				const tables = tablesRes.data.tables;
				log(`Se sincronizarán ${tables.length} tablas de base de datos...`, 'info');

				let currentTableIdx = 0;
				let currentOffset   = 0;
				const totalTables   = tables.length;
				const dbBatchLimit  = opt.db_batch || 1000;

				while (currentTableIdx < totalTables) {
					if (shouldCancel) return;

					const tableObj = tables[currentTableIdx];
					const currentTable = tableObj.name || tableObj;

					const chunkRes = await $.ajax({
						url: vk7kSyncData.ajaxUrl,
						type: 'POST',
						data: {
							action: 'vk7k_sync_db_step',
							nonce: vk7kSyncData.nonce,
							mode: mode,
							tables: tables,
							table_idx: currentTableIdx,
							offset: currentOffset,
							limit: dbBatchLimit,
							remote_url: remoteUrl,
							secret_key: remoteKey,
							remote_ip: remoteIp
						}
					});

					if (!chunkRes.success) {
						throw new Error(getErrorMessage(chunkRes) || `Error sincronizando tabla ${currentTable}`);
					}

					const d = chunkRes.data;
					const percent = 10 + Math.round((currentTableIdx / totalTables) * 40);
					setProgress(percent, `Sincronizando DB: ${d.table} (${d.imported} registros)`);
					log(`DB: ${d.table} | Registros procesados: ${d.imported}/${d.total_rows || d.imported}`, 'info');

					currentTableIdx = d.table_idx;
					currentOffset   = d.offset;

					if (d.is_done) {
						break;
					}
				}

				log('Base de datos sincronizada y URLs reemplazadas con éxito.', 'success');
				setProgress(50, 'Base de datos completada');
			} else {
				setProgress(50, 'Omitiendo Base de Datos');
			}

			if (shouldCancel) return;

			// STEP 3: Files Sync
			const fileComponents = [];
			if (syncUploads) fileComponents.push('uploads');
			if (syncPlugins && selectedPlugins.length > 0) fileComponents.push('plugins');
			if (syncThemes && selectedThemes.length > 0)  fileComponents.push('themes');

			if (fileComponents.length > 0) {
				setStepActive('files');
				log(`Paso 3: Calculando diferencial de archivos para: ${fileComponents.join(', ')}...`, 'info');
				if (syncPlugins) {
					log(`Plugins seleccionados (${selectedPlugins.length}): ${selectedPlugins.join(', ')}`, 'info');
				}

				const prepRes = await $.ajax({
					url: vk7kSyncData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'vk7k_sync_prepare_files',
						nonce: vk7kSyncData.nonce,
						mode: mode,
						components: fileComponents,
						selected_plugins: selectedPlugins,
						selected_themes: selectedThemes,
						media_filter: mediaFilter,
						remote_url: remoteUrl,
						secret_key: remoteKey,
						remote_ip: remoteIp
					}
				});

				if (!prepRes.success) {
					throw new Error(getErrorMessage(prepRes) || 'Error preparando lista de archivos.');
				}

				const totalFiles = prepRes.data.total_files || 0;
				log(`Archivos a transferir: ${totalFiles}`, 'info');

				if (totalFiles > 0) {
					let sftpSuccess = false;

					if (mode === 'push' && useSsh) {
						log(`⚡ Modo Turbo SSH/SFTP activado. Transfiriendo ${totalFiles} archivos por lotes SFTP de alta velocidad...`, 'info');
						let sftpOffset = 0;

						try {
							while (sftpOffset < totalFiles) {
								if (shouldCancel) return;

								const sftpRes = await $.ajax({
									url: vk7kSyncData.ajaxUrl,
									type: 'POST',
									data: {
										action: 'vk7k_sync_files_sftp',
										nonce: vk7kSyncData.nonce,
										remote_url: remoteUrl,
										secret_key: remoteKey,
										remote_ip: remoteIp,
										ssh_host: sshHost,
										ssh_port: sshPort,
										ssh_user: sshUser,
										ssh_pass: sshPass,
										ssh_path: sshPath,
										offset: sftpOffset,
										chunk_mb: 25,
										max_files: 2000
									}
								});

								if (!sftpRes.success) {
									throw new Error(getErrorMessage(sftpRes));
								}

								const sd = sftpRes.data;
								sftpOffset = sd.offset;
								const percent = 50 + Math.round((sftpOffset / totalFiles) * 40);
								setProgress(percent, `Turbo SFTP: ${sftpOffset}/${totalFiles} archivos`);
								log(`✓ ${sd.message || `Lote SFTP: ${sftpOffset}/${totalFiles}`}`, 'info');

								if (sd.is_done) {
									sftpSuccess = true;
									break;
								}
							}
						} catch (sftpErr) {
							log(`Aviso Turbo SFTP: ${getErrorMessage(sftpErr)}. Pasando a transporte HTTP de respaldo...`, 'warning');
							sftpSuccess = false;
						}
					}

					// Fallback to HTTP REST Zip Chunking
					if (!sftpSuccess) {
						log(`Transfiriendo ${totalFiles} archivos vía HTTP REST por paquetes ZIP...`, 'info');
						let currentFileIdx = 0;

						while (currentFileIdx < totalFiles) {
							if (shouldCancel) return;

							const fileRes = await $.ajax({
								url: vk7kSyncData.ajaxUrl,
								type: 'POST',
								data: {
									action: 'vk7k_sync_files_step',
									nonce: vk7kSyncData.nonce,
									mode: mode,
									offset: currentFileIdx,
									file_idx: currentFileIdx,
									chunk_mb: opt.zip_chunk_mb || 4,
									max_files: opt.zip_files || 50,
									remote_url: remoteUrl,
									secret_key: remoteKey,
									remote_ip: remoteIp
								}
							});

							if (!fileRes.success) {
								throw new Error(getErrorMessage(fileRes) || 'Error transfiriendo paquete de archivos.');
							}

							const fd = fileRes.data;
							const nextOffset = (fd.offset !== undefined) ? fd.offset : (fd.file_idx !== undefined ? fd.file_idx : (currentFileIdx + (fd.transferred || 1)));
							const transferredCount = fd.transferred !== undefined ? fd.transferred : (fd.files_count || 0);

							if (nextOffset <= currentFileIdx) {
								currentFileIdx = currentFileIdx + (transferredCount > 0 ? transferredCount : 1);
							} else {
								currentFileIdx = nextOffset;
							}

							const percent = 50 + Math.round((currentFileIdx / totalFiles) * 40);
							setProgress(percent, `Archivos: ${currentFileIdx}/${totalFiles} (${transferredCount} en paquete)`);
							log(`Paquete transferido: ${currentFileIdx}/${totalFiles} archivos procesados.`, 'info');

							if (fd.is_done || currentFileIdx >= totalFiles) {
								break;
							}
						}
					}

					log('Transferencia de archivos completada exitosamente.', 'success');
					setProgress(90, 'Archivos completados');
				} else {
					log('Todos los archivos están actualizados (0 diferenciales).', 'success');
					setProgress(90, 'Sin cambios en archivos');
				}
			} else {
				setProgress(90, 'Omitiendo Archivos');
			}

			if (shouldCancel) return;

			// STEP 4: Finalize & Flush Caches
			setStepActive('finalize');
			log('Paso 4: Finalizando sincronización y regenerando permalinks/caché...', 'info');

			const finRes = await $.ajax({
				url: vk7kSyncData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_finalize',
					nonce: vk7kSyncData.nonce,
					mode: mode,
					remote_url: remoteUrl,
					secret_key: remoteKey,
					remote_ip: remoteIp
				}
			});

			if (!finRes.success) {
				log(`Aviso en finalización: ${getErrorMessage(finRes)}`, 'warning');
			} else {
				log('Reglas de reescritura, caché de WordPress y modo seguro finalizados.', 'success');
			}

			setProgress(100, '¡Sincronización Completada!');
			log(`🎉 ¡Sincronización ${mode.toUpperCase()} finalizada con éxito absoluto!`, 'success');

			$('#vk7k-modal-title').text(`¡Sincronización ${mode.toUpperCase()} Completada!`);
			$('#vk7k-modal-cancel-btn').hide();
			$('#vk7k-modal-done-btn').show().on('click', function() {
				window.location.reload();
			});

		} catch(err) {
			log(`❌ ERROR CRÍTICO: ${getErrorMessage(err)}`, 'error');
			setProgress(100, 'Error en la sincronización');
			$('#vk7k-modal-title').text('Error en la Sincronización');
			$('#vk7k-modal-cancel-btn').text('Cerrar').prop('disabled', false);
			alert(`Ocurrió un error durante la sincronización:\n\n${getErrorMessage(err)}`);
		} finally {
			isSyncRunning = false;
		}
	}

	function initModalControls() {
		// Close modals on X or Cancel
		$('.vk7k-modal-close, .vk7k-modal-close-btn, .vk7k-modal-close-action').on('click', function() {
			$(this).closest('.vk7k-modal').fadeOut(150);
		});

		// Cancel running sync
		$('#vk7k-modal-cancel-btn').on('click', function() {
			if (isSyncRunning) {
				if (confirm('¿Estás seguro de cancelar la sincronización en curso?')) {
					shouldCancel = true;
					log('Cancelación solicitada por el usuario...', 'warning');
					$(this).prop('disabled', true).text('Deteniendo...');
				}
			} else {
				$('#vk7k-progress-modal').fadeOut(150);
			}
		});

		$('#vk7k-clear-log-btn').on('click', function() {
			$('#vk7k-log-console').empty();
		});
	}

	function initPinVaultControls() {
		$('#vk7k-btn-open-pin-setup').on('click', function() {
			$('#vk7k-pin-setup-modal').fadeIn(150).css('display', 'flex');
			setTimeout(function() { $('#vk7k-input-new-pin').focus(); }, 200);
		});

		$('#vk7k-btn-open-pin-unlock').on('click', function() {
			$('#vk7k-pin-unlock-modal').fadeIn(150).css('display', 'flex');
			setTimeout(function() { $('#vk7k-input-unlock-pin').focus(); }, 200);
		});

		$('#vk7k-btn-open-pin-manage').on('click', function() {
			$('#vk7k-pin-manage-modal').fadeIn(150).css('display', 'flex');
			setTimeout(function() { $('#vk7k-input-old-pin').focus(); }, 200);
		});

		$('#vk7k-btn-lock-vault').on('click', function() {
			if (!confirm('¿Bloquear la bóveda de seguridad?')) return;
			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_lock_vault',
				nonce: vk7kSyncData.nonce
			}, function(res) {
				if (res.success) {
					window.location.reload();
				}
			});
		});

		$('#vk7k-form-setup-pin').on('submit', function(e) {
			e.preventDefault();
			const pin1 = $('#vk7k-input-new-pin').val();
			const pin2 = $('#vk7k-input-confirm-pin').val();

			if (!pin1 || pin1.length < 4) {
				showPinAlert('#vk7k-setup-pin-alert', 'error', 'El PIN debe tener al menos 4 caracteres.');
				return;
			}
			if (pin1 !== pin2) {
				showPinAlert('#vk7k-setup-pin-alert', 'error', 'Los PINs ingresados no coinciden.');
				return;
			}

			const $btn = $('#vk7k-btn-submit-setup-pin').prop('disabled', true).text('Cifrando y activando...');
			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_setup_pin',
				nonce: vk7kSyncData.nonce,
				pin: pin1
			}, function(res) {
				$btn.prop('disabled', false).text('Activar Bóveda Cifrada');
				if (res.success) {
					alert('✓ Bóveda cifrada activada con éxito.');
					window.location.reload();
				} else {
					showPinAlert('#vk7k-setup-pin-alert', 'error', getErrorMessage(res));
				}
			});
		});

		$('#vk7k-form-unlock-pin').on('submit', function(e) {
			e.preventDefault();
			const pin = $('#vk7k-input-unlock-pin').val();
			if (!pin) return;

			const $btn = $('#vk7k-btn-submit-unlock-pin').prop('disabled', true).text('Verificando...');
			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_unlock_vault',
				nonce: vk7kSyncData.nonce,
				pin: pin
			}, function(res) {
				$btn.prop('disabled', false).text('Desbloquear');
				if (res.success) {
					vk7kSyncData.isVaultUnlocked = true;
					$('#vk7k-pin-unlock-modal').fadeOut(100);
					window.location.reload();
				} else {
					showPinAlert('#vk7k-unlock-pin-alert', 'error', getErrorMessage(res));
				}
			});
		});

		$('#vk7k-form-change-pin').on('submit', function(e) {
			e.preventDefault();
			const oldPin = $('#vk7k-input-old-pin').val();
			const newPin = $('#vk7k-input-change-new-pin').val();

			if (!newPin || newPin.length < 4) {
				alert('El nuevo PIN debe tener al menos 4 caracteres.');
				return;
			}

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_change_pin',
				nonce: vk7kSyncData.nonce,
				old_pin: oldPin,
				new_pin: newPin
			}, function(res) {
				if (res.success) {
					alert('PIN actualizado correctamente.');
					$('#vk7k-pin-manage-modal').fadeOut(100);
				} else {
					alert('Error: ' + getErrorMessage(res));
				}
			});
		});

		$('#vk7k-btn-action-reset-vault').on('click', function() {
			if (!confirm('⚠️ ¿Estás COMPLETAMENTE SEGURO de restablecer la bóveda?\n\nEsto eliminará todas las contraseñas SSH cifradas guardadas.')) {
				return;
			}
			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_reset_vault',
				nonce: vk7kSyncData.nonce
			}, function(res) {
				if (res.success) {
					alert('Bóveda restablecida.');
					window.location.reload();
				} else {
					alert('Error: ' + getErrorMessage(res));
				}
			});
		});
	}

	function showPinAlert(selector, type, msg) {
		$(selector).removeClass('is-success is-error').addClass('is-' + type).text(msg).slideDown(150);
	}

	function initTargetControls() {
		$('#vk7k-btn-manage-targets').on('click', function() {
			loadTargetsList();
			$('#vk7k-targets-modal').fadeIn(150).css('display', 'flex');
		});

		$('#vk7k-btn-add-new-target').on('click', function() {
			resetTargetForm();
			$('#vk7k-target-form-title').text('Agregar Nuevo Servidor Remoto');
			$('#vk7k-btn-delete-target').hide();
		});

		function getNormalizedTargetsState() {
			const raw = vk7kSyncData.targetsState;
			if (!raw) {
				return { targets: {}, active_target_id: 'staging' };
			}
			if (raw.targets && typeof raw.targets === 'object') {
				return raw;
			}
			// If raw is a direct targets map
			return {
				active_target_id: vk7kSyncData.activeTarget?.id || 'staging',
				targets: raw
			};
		}

		function loadTargetsList() {
			const state = getNormalizedTargetsState();
			const targetsMap = state.targets || {};
			const activeId   = state.active_target_id || vk7kSyncData.activeTarget?.id || 'staging';
			const $list      = $('#vk7k-targets-list').empty();

			const tids = Object.keys(targetsMap);
			if (tids.length === 0) {
				$list.html('<p style="color:#64748b;font-size:0.85rem;padding:8px;">No hay destinos registrados.</p>');
				return;
			}

			tids.forEach(function(tid) {
				const t = targetsMap[tid];
				if (!t) return;
				const isActive = (tid === activeId);
				const envIcon  = (t.environment === 'production') ? '🚀' : '🌐';

				const $item = $(`
					<div class="vk7k-target-item ${isActive ? 'is-active' : ''}" data-target-id="${tid}">
						<div class="vk7k-target-item-info">
							<strong>${envIcon} ${t.name || tid}</strong>
							<span>${t.remote_url || 'Sin URL'}</span>
						</div>
						<div class="vk7k-target-item-actions">
							${!isActive ? `<button type="button" class="button button-small vk7k-btn-activate-target" title="Activar">Activar</button>` : '<span class="vk7k-badge-active-target">Activo</span>'}
						</div>
					</div>
				`);

				$item.on('click', function(e) {
					if (!$(e.target).hasClass('vk7k-btn-activate-target')) {
						populateTargetForm(tid, t);
					}
				});

				$item.find('.vk7k-btn-activate-target').on('click', function(e) {
					e.stopPropagation();
					switchActiveTarget(tid);
				});

				$list.append($item);
			});

			const activeT = targetsMap[activeId] || targetsMap[tids[0]];
			if (activeT) {
				populateTargetForm(activeId, activeT);
			}
		}

		function populateTargetForm(tid, t) {
			$('#vk7k-edit-target-id').val(tid);
			$('#vk7k-target-form-title').text('Editar Servidor: ' + (t.name || tid));
			$('#vk7k-edit-target-name').val(t.name || '');
			$('#vk7k-edit-target-env').val(t.environment || 'staging');
			$('#vk7k-edit-target-url').val(t.remote_url || '');
			$('#vk7k-edit-target-ip').val(t.remote_ip || '');
			$('#vk7k-edit-target-host').val(t.ssh_host || '');
			$('#vk7k-edit-target-port').val(t.ssh_port || 22);
			$('#vk7k-edit-target-user').val(t.ssh_user || '');
			$('#vk7k-edit-target-pass').val('').attr('placeholder', t.ssh_pass ? '•••••••••••• (Guardada)' : '');
			$('#vk7k-edit-target-path').val(t.ssh_path || '');
			$('#vk7k-btn-delete-target').show();
		}

		function resetTargetForm() {
			$('#vk7k-edit-target-id').val('');
			$('#vk7k-target-form-title').text('Agregar Nuevo Servidor Remoto');
			$('#vk7k-edit-target-name').val('');
			$('#vk7k-edit-target-env').val('staging');
			$('#vk7k-edit-target-url').val('');
			$('#vk7k-edit-target-ip').val('');
			$('#vk7k-edit-target-host').val('');
			$('#vk7k-edit-target-port').val('22');
			$('#vk7k-edit-target-user').val('');
			$('#vk7k-edit-target-pass').val('').attr('placeholder', '');
			$('#vk7k-edit-target-path').val('');
			$('#vk7k-btn-delete-target').hide();
		}

		$('#vk7k-form-edit-target').on('submit', function(e) {
			e.preventDefault();
			const targetId = $('#vk7k-edit-target-id').val() || ('target_' + Date.now());
			const targetData = {
				name: $('#vk7k-edit-target-name').val(),
				environment: $('#vk7k-edit-target-env').val(),
				remote_url: $('#vk7k-edit-target-url').val(),
				remote_ip: $('#vk7k-edit-target-ip').val(),
				ssh_host: $('#vk7k-edit-target-host').val(),
				ssh_port: $('#vk7k-edit-target-port').val(),
				ssh_user: $('#vk7k-edit-target-user').val(),
				ssh_pass: $('#vk7k-edit-target-pass').val(),
				ssh_path: $('#vk7k-edit-target-path').val()
			};

			const $btn = $('#vk7k-btn-save-target-submit').prop('disabled', true).text('Guardando...');

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_save_target',
				nonce: vk7kSyncData.nonce,
				target_id: targetId,
				target_data: targetData
			}, function(res) {
				$btn.prop('disabled', false).text('Guardar Perfil');
				if (res.success) {
					vk7kSyncData.targetsState = res.data.targets_state;
					if (res.data.active_target) {
						vk7kSyncData.activeTarget = res.data.active_target;
						if (res.data.active_target.id === targetId) {
							populateMainFormFromTarget(res.data.active_target);
						}
					}
					loadTargetsList();
					updateTargetSwitcherOptions(res.data.targets_state);
					alert('Perfil de destino guardado con éxito.');
				} else {
					alert('Error guardando perfil: ' + getErrorMessage(res));
				}
			}).fail(function(xhr) {
				$btn.prop('disabled', false).text('Guardar Perfil');
				alert('Error de conexión AJAX: ' + getErrorMessage(xhr));
			});
		});

		$('#vk7k-btn-delete-target').on('click', function() {
			const targetId = $('#vk7k-edit-target-id').val();
			if (!targetId) return;
			if (!confirm('¿Estás seguro de eliminar este servidor remoto?')) return;

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_delete_target',
				nonce: vk7kSyncData.nonce,
				target_id: targetId
			}, function(res) {
				if (res.success) {
					vk7kSyncData.targetsState = res.data.targets_state;
					if (res.data.active_target) {
						vk7kSyncData.activeTarget = res.data.active_target;
						populateMainFormFromTarget(res.data.active_target);
					}
					loadTargetsList();
					updateTargetSwitcherOptions(res.data.targets_state);
					resetTargetForm();
				} else {
					alert('Error eliminando perfil: ' + getErrorMessage(res));
				}
			});
		});

		$('#vk7k-target-switcher').on('change', function() {
			const selectedId = $(this).val();
			switchActiveTarget(selectedId);
		});

		function switchActiveTarget(targetId) {
			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_switch_target',
				nonce: vk7kSyncData.nonce,
				target_id: targetId
			}, function(res) {
				if (res.success) {
					const target = res.data.target || res.data.active_target;
					vk7kSyncData.activeTarget = target;
					if (res.data.targets_state) {
						vk7kSyncData.targetsState = res.data.targets_state;
					}
					populateMainFormFromTarget(target);
					$('#vk7k-target-switcher').val(targetId);
					$('#vk7k-badge-remote-target').text(target ? (target.name || targetId) : 'Remoto');
					updateButtonUrls();
					loadTargetsList();
					runInitialConnectionCheck();
				} else {
					showConnectionAlert('error', 'Error al cambiar de servidor: ' + getErrorMessage(res));
				}
			});
		}

		function updateTargetSwitcherOptions(state) {
			const $sw = $('#vk7k-target-switcher').empty();
			const normState  = (state && state.targets) ? state : { targets: state || {}, active_target_id: 'staging' };
			const targetsMap = normState.targets || {};
			const activeId   = normState.active_target_id || vk7kSyncData.activeTarget?.id || 'staging';

			Object.keys(targetsMap).forEach(function(tid) {
				const t = targetsMap[tid];
				if (!t) return;
				const icon = (t.environment === 'production') ? '🚀 ' : '🌐 ';
				const $opt = $('<option>').val(tid).text(icon + (t.name || tid));
				if (tid === activeId) {
					$opt.prop('selected', true);
				}
				$sw.append($opt);
			});
		}

		function populateMainFormFromTarget(target) {
			if (!target) return;
			$('#vk7k-remote-url').val(target.remote_url || '');
			$('#vk7k-remote-ip').val(target.remote_ip || '');
			$('#vk7k-ssh-host').val(target.ssh_host || '');
			$('#vk7k-ssh-port').val(target.ssh_port || 22);
			$('#vk7k-ssh-user').val(target.ssh_user || '');
			$('#vk7k-ssh-pass').val('').attr('placeholder', target.ssh_pass ? '•••••••••••• (Guardada de forma segura)' : 'Ingresa contraseña SSH');
			$('#vk7k-ssh-path').val(target.ssh_path || '');
			if (target.remote_secret_key) {
				$('#vk7k-remote-key').val(target.remote_secret_key);
			}

			// Mode (SSH or HTTP)
			const useSsh = (target.use_ssh === 1 || target.use_ssh === '1' || target.use_ssh === true);
			$('input[name="conn_mode"][value="' + (useSsh ? 'ssh' : 'http') + '"]').prop('checked', true).trigger('change');
		}
	}

	function initLinkTools() {
		$('#vk7k-btn-audit-links').on('click', function(e) {
			e.preventDefault();
			const $btn = $(this);
			const origHtml = $btn.html();
			const remoteUrl = $('#vk7k-remote-url').val() || (vk7kSyncData.activeTarget && vk7kSyncData.activeTarget.remote_url) || '';

			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update vk7k-spin"></span> Auditando...');
			$('#vk7k-audit-result-box').hide();

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_audit_links',
				nonce: vk7kSyncData.nonce,
				target_url: remoteUrl
			}, function(res) {
				$btn.prop('disabled', false).html(origHtml);
				if (res.success && res.data && res.data.audit) {
					const audit = res.data.audit;
					renderAuditResults(audit, res.data.target_url);
				} else {
					$('#vk7k-audit-result-box')
						.html('<div style="color:#d63638;"><span class="dashicons dashicons-warning"></span> Error al auditar: ' + getErrorMessage(res) + '</div>')
						.show();
				}
			}).fail(function(err) {
				$btn.prop('disabled', false).html(origHtml);
				$('#vk7k-audit-result-box')
					.html('<div style="color:#d63638;"><span class="dashicons dashicons-warning"></span> Error de comunicación: ' + getErrorMessage(err) + '</div>')
					.show();
			});
		});

		$('#vk7k-btn-fix-links').on('click', function(e) {
			e.preventDefault();
			const remoteUrl = $('#vk7k-remote-url').val() || (vk7kSyncData.activeTarget && vk7kSyncData.activeTarget.remote_url) || '';
			if (!remoteUrl) {
				alert('Por favor especifica la URL remota de origen antes de reparar enlaces.');
				return;
			}

			if (!confirm('¿Deseas buscar y reemplazar todas las referencias a "' + remoteUrl + '" en la base de datos local y convertirlas a "' + vk7kSyncData.siteUrl + '"? Se preservará la serialización PHP y JSON FSE.')) {
				return;
			}

			const $btn = $(this);
			const origHtml = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update vk7k-spin"></span> Reparando...');
			$('#vk7k-audit-result-box').hide();

			$.post(vk7kSyncData.ajaxUrl, {
				action: 'vk7k_sync_fix_links',
				nonce: vk7kSyncData.nonce,
				source_url: remoteUrl,
				target_url: vk7kSyncData.siteUrl
			}, function(res) {
				$btn.prop('disabled', false).html(origHtml);
				if (res.success && res.data) {
					const stats = res.data.stats || {};
					const audit = res.data.audit || {};
					let html = '<div style="background:#f0f6fc; border-left:4px solid #00a32a; padding:10px 14px; margin-bottom:8px;">';
					html += '<strong style="color:#00a32a;"><span class="dashicons dashicons-yes-alt"></span> ' + (res.data.message || 'Reparación completada.') + '</strong><br>';
					html += '<small style="color:#50575e;">Filas escaneadas: ' + (stats.scanned || 0) + ' • Registros modificados: ' + (stats.updated || 0) + '</small>';
					html += '</div>';

					if (audit) {
						if (audit.clean) {
							html += '<div style="color:#00a32a; font-weight:600;"><span class="dashicons dashicons-shield"></span> Auditoría post-reparación: 0 enlaces residuales (100% Limpio).</div>';
							$('#vk7k-link-status-badge').css({ background: '#d4edda', color: '#155724' }).text('✅ 100% Limpio y Verificado');
						} else {
							html += '<div style="color:#d63638;"><span class="dashicons dashicons-warning"></span> Quedan ' + (audit.total_residual || 0) + ' enlaces residuales.</div>';
							$('#vk7k-link-status-badge').css({ background: '#fff3cd', color: '#856404' }).text('⚠️ ' + audit.total_residual + ' residuales');
						}
					}

					$('#vk7k-audit-result-box').html(html).slideDown(200);
				} else {
					$('#vk7k-audit-result-box')
						.html('<div style="color:#d63638;"><span class="dashicons dashicons-warning"></span> Error al reparar: ' + getErrorMessage(res) + '</div>')
						.show();
				}
			}).fail(function(err) {
				$btn.prop('disabled', false).html(origHtml);
				$('#vk7k-audit-result-box')
					.html('<div style="color:#d63638;"><span class="dashicons dashicons-warning"></span> Error de comunicación: ' + getErrorMessage(err) + '</div>')
					.show();
			});
		});
	}

	function renderAuditResults(audit, targetUrl) {
		let html = '';
		if (audit.clean || audit.total_residual === 0) {
			html = '<div style="background:#eaf8ee; border-left:4px solid #46b450; padding:10px 14px; color:#155724;">';
			html += '<strong><span class="dashicons dashicons-yes-alt"></span> Base de datos limpia:</strong> No se detectaron enlaces residuales al dominio remoto (' + (targetUrl || '') + ').';
			html += '</div>';
			$('#vk7k-link-status-badge').css({ background: '#d4edda', color: '#155724' }).text('✅ 100% Limpio');
		} else {
			html = '<div style="background:#fff8e5; border-left:4px solid #dba617; padding:10px 14px; color:#856404;">';
			html += '<strong><span class="dashicons dashicons-warning"></span> Se detectaron ' + audit.total_residual + ' enlaces residuales a ' + (targetUrl || 'dominio remoto') + ':</strong><br>';
			if (audit.breakdown) {
				html += '<ul style="margin:6px 0 0 18px; list-style:disc;">';
				Object.keys(audit.breakdown).forEach(function(tbl) {
					if (audit.breakdown[tbl] > 0) {
						html += '<li><code>' + tbl + '</code>: ' + audit.breakdown[tbl] + ' coincidencias</li>';
					}
				});
				html += '</ul>';
			}
			html += '<div style="margin-top:8px;"><small>Haz clic en "Reparar y Normalizar URLs Ahora" para corregirlos automáticamente.</small></div>';
			html += '</div>';
			$('#vk7k-link-status-badge').css({ background: '#fff3cd', color: '#856404' }).text('⚠️ ' + audit.total_residual + ' enlaces residuales');
		}
		$('#vk7k-audit-result-box').html(html).slideDown(200);
	}

})(jQuery);
