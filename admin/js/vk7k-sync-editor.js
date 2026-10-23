/**
 * uxcribe.cl Sync - Block Editor & FSE Integration
 *
 * Adds Push to Remote / Pull from Remote buttons in the editor menu,
 * top header toolbar, and sidebar panel for posts, pages, and WooCommerce products.
 */
( function() {
	'use strict';

	if ( ! window.wp || ! wp.plugins || ! wp.plugins.registerPlugin || ! wp.element ) {
		return;
	}

	const { createElement: el, useState, useEffect, Fragment } = wp.element;
	const { registerPlugin } = wp.plugins;
	const { Button, CheckboxControl, Spinner, Modal } = wp.components;

	// Cross-version / Cross-editor compatibility (Edit Post vs Edit Site FSE vs Editor)
	const PluginSidebar = ( wp.editor && wp.editor.PluginSidebar )
		|| ( wp.editPost && wp.editPost.PluginSidebar )
		|| ( wp.editSite && wp.editSite.PluginSidebar );

	const PluginSidebarMoreMenuItem = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem )
		|| ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem )
		|| ( wp.editSite && wp.editSite.PluginSidebarMoreMenuItem );

	const PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		|| ( wp.editPost && wp.editPost.PluginMoreMenuItem )
		|| ( wp.editSite && wp.editSite.PluginMoreMenuItem );

	// Bidirectional Sync Icon SVG
	const syncIcon = el(
		'svg',
		{
			width: 20,
			height: 20,
			viewBox: '0 0 24 24',
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: 2,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			className: 'vk7k-sync-toolbar-icon',
		},
		el( 'polyline', { points: '17 1 21 5 17 9' } ),
		el( 'path', { d: 'M3 11V9a4 4 0 0 1 4-4h14' } ),
		el( 'polyline', { points: '7 23 3 19 7 15' } ),
		el( 'path', { d: 'M21 13v2a4 4 0 0 1-4 4H3' } )
	);

	/**
	 * Retrieve current post/page/product info from Gutenberg store or localized fallback.
	 */
	function getCurrentPostInfo() {
		const selectEditor = wp.data.select( 'core/editor' );
		const selectSite   = wp.data.select( 'core/edit-site' );
		const selectCore   = wp.data.select( 'core' );

		let id           = null;
		let type         = 'post';
		let title        = '';
		let slug         = '';
		let status       = 'publish';
		let siteEntityId = null;

		if ( selectEditor && typeof selectEditor.getCurrentPostId === 'function' && selectEditor.getCurrentPostId() ) {
			id     = selectEditor.getCurrentPostId();
			type   = selectEditor.getCurrentPostType() || type;
			title  = selectEditor.getEditedPostAttribute( 'title' ) || '';
			slug   = selectEditor.getEditedPostAttribute( 'slug' ) || '';
			status = selectEditor.getEditedPostAttribute( 'status' ) || '';
		} else if ( selectSite && typeof selectSite.getEditedPostId === 'function' ) {
			siteEntityId = selectSite.getEditedPostId();
			type         = selectSite.getEditedPostType() || type;
		}

		// Handle FSE Site Editor entities (templates / template parts)
		if ( siteEntityId || type === 'wp_template' || type === 'wp_template_part' ) {
			const entityKey = siteEntityId || id;
			if ( entityKey && typeof entityKey === 'string' ) {
				slug = entityKey.indexOf( '//' ) !== -1 ? entityKey.split( '//' )[1] : entityKey;
			}

			if ( selectCore && typeof selectCore.getEditedEntityRecord === 'function' && entityKey ) {
				const record = selectCore.getEditedEntityRecord( 'postType', type, entityKey )
					|| ( typeof selectCore.getEntityRecord === 'function' ? selectCore.getEntityRecord( 'postType', type, entityKey ) : null );
				if ( record ) {
					if ( record.wp_id ) {
						id = record.wp_id;
					}
					if ( record.slug ) {
						slug = record.slug;
					}
					if ( record.title ) {
						title = typeof record.title === 'object' ? ( record.title.rendered || record.title.raw || '' ) : record.title;
					}
					if ( record.status ) {
						status = record.status;
					}
				}
			}

			if ( ! title && slug ) {
				title = slug.charAt( 0 ).toUpperCase() + slug.slice( 1 ).replace( /[-_]/g, ' ' );
			}
		}

		if ( ( ! id || 0 === id ) && window.vk7kSyncEditor && window.vk7kSyncEditor.post ) {
			id    = window.vk7kSyncEditor.post.id;
			type  = window.vk7kSyncEditor.post.type || type;
			title = title || window.vk7kSyncEditor.post.title;
			slug  = slug || window.vk7kSyncEditor.post.slug;
		}

		return {
			id: parseInt( id, 10 ) || 0,
			siteEntityId: siteEntityId || null,
			type: type || 'post',
			title: title || ( slug ? slug : '(sin título)' ),
			slug: slug || '',
			status: status || 'publish',
			isFse: ( type === 'wp_template' || type === 'wp_template_part' || !! siteEntityId ),
		};
	}

	/**
	 * Compare two plugin version strings (numeric, dot separated).
	 * Returns 1 if a > b, -1 if a < b, 0 if equal.
	 */
	function cmpVersions( a, b ) {
		const pa = String( a || '0' ).split( '.' );
		const pb = String( b || '0' ).split( '.' );
		const len = Math.max( pa.length, pb.length );

		for ( let i = 0; i < len; i++ ) {
			const x = parseInt( pa[ i ], 10 ) || 0;
			const y = parseInt( pb[ i ], 10 ) || 0;
			if ( x > y ) return 1;
			if ( x < y ) return -1;
		}
		return 0;
	}

	/**
	 * Main Sync Panel UI Component (rendered inside Sidebar and/or Modal).
	 */
	function SyncPanelContent( props ) {
		const config = window.vk7kSyncEditor || {};
		const i18n   = config.i18n || {};

		const [ postInfo, setPostInfo ]       = useState( getCurrentPostInfo() );
		const [ isSyncing, setIsSyncing ]     = useState( false );
		const [ syncStep, setSyncStep ]           = useState( '' );
		const [ statusMessage, setStatus ]        = useState( null );
		const [ syncMedia, setSyncMedia ]         = useState( true );
		const [ preserveStock, setPreserveStock ] = useState( true );
		const [ reloadReq, setReloadReq ]         = useState( false );
		const [ inspectData, setInspectData ] = useState( null );
		const [ inspecting, setInspecting ]   = useState( false );
		const [ isUpdatingPlugin, setIsUpdatingPlugin ] = useState( false );

		/**
		 * Inspect content dependencies + live remote status.
		 * Always returns a Promise resolving to the remote_status object (or null).
		 */
		function inspectRemote() {
			const info = getCurrentPostInfo();
			setPostInfo( info );

			if ( ! ( ( info.id > 0 || info.slug ) && config.ajaxUrl && config.nonce ) ) {
				return Promise.resolve( null );
			}

			setInspecting( true );
			const fd = new FormData();
			fd.append( 'action', 'vk7k_sync_editor_inspect' );
			fd.append( 'nonce', config.nonce );
			fd.append( 'post_id', info.id );
			fd.append( 'post_name', info.slug );
			fd.append( 'post_type', info.type );

			return fetch( config.ajaxUrl, { method: 'POST', body: fd } )
				.then( ( res ) => res.json() )
				.then( ( data ) => {
					if ( data.success && data.data ) {
						setInspectData( data.data );
						if ( data.data.id && data.data.id > 0 ) {
							setPostInfo( ( prev ) => ( {
								...prev,
								id: data.data.id,
								title: data.data.title || prev.title,
								slug: data.data.slug || prev.slug,
							} ) );
						}
						return data.data.remote_status || null;
					}
					return null;
				} )
				.catch( () => null )
				.finally( () => setInspecting( false ) );
		}

		// Kept for backwards compatibility with the rest of the handlers.
		function runInspect() {
			return inspectRemote();
		}

		// Inspect dependencies on load
		useEffect( function() {
			inspectRemote();
		}, [] );

		/**
		 * Poll the remote status until the reported plugin version matches the local one.
		 * Needed because a freshly deployed plugin can take a moment to be served
		 * by the remote PHP workers (opcache / opcode cache).
		 */
		function verifyRemoteVersion( attempts, delayMs ) {
			return new Promise( function( resolve ) {
				let left = attempts;

				function tick() {
					inspectRemote().then( function( rs ) {
						if ( rs && rs.connected && rs.version_match ) {
							resolve( true );
							return;
						}
						left--;
						if ( left <= 0 ) {
							resolve( false );
							return;
						}
						window.setTimeout( tick, delayMs );
					} );
				}

				tick();
			} );
		}

		// Update remote plugin handler
		async function handleUpdateRemotePlugin() {
			if ( isUpdatingPlugin ) return;
			setIsUpdatingPlugin( true );
			setStatus( { type: 'info', text: 'Enviando y actualizando plugin vk7k-wp-sync en el servidor remoto...' } );

			try {
				const fd = new FormData();
				fd.append( 'action', 'vk7k_sync_update_remote_plugin' );
				fd.append( 'nonce', config.nonce );

				const res  = await fetch( config.ajaxUrl, { method: 'POST', body: fd } );
				const text = await res.text();
				let data;
				try {
					data = JSON.parse( text );
				} catch ( e ) {
					throw new Error( 'Respuesta inválida del servidor: ' + text.substring( 0, 100 ) );
				}

				if ( ! data.success ) {
					throw new Error( data.data && data.data.message ? data.data.message : 'Error al actualizar plugin en remoto.' );
				}

				// Apply the status the server already verified, so the panel is never left stale.
				const serverStatus = data.data && data.data.remote_status ? data.data.remote_status : null;
				if ( serverStatus ) {
					setInspectData( ( prev ) => Object.assign( {}, prev || {}, { remote_status: serverStatus } ) );
				}

				let matched = Boolean( serverStatus && serverStatus.connected && serverStatus.version_match );

				// Verify against the remote itself (retries cover a brief opcache lag).
				if ( ! matched ) {
					setStatus( { type: 'info', text: 'Plugin enviado. Verificando la versión activa en el servidor remoto...' } );
					matched = await verifyRemoteVersion( 5, 1200 );
				}

				if ( matched ) {
					setStatus( {
						type: 'success',
						text: data.data.message || 'Plugin actualizado en remoto con éxito.',
					} );
				} else {
					const current = inspectData && inspectData.remote_status ? inspectData.remote_status : serverStatus;
					const remoteV = current && current.remote_version ? 'v' + current.remote_version : 'una versión distinta';
					setStatus( {
						type: 'warning',
						text: 'Los archivos del plugin se enviaron, pero el servidor remoto todavía reporta ' + remoteV +
							' en lugar de v' + ( config.localVersion || '' ) + '. Puede ser la caché de PHP del servidor: ' +
							'vuelve a intentarlo en unos segundos o recarga el panel.',
					} );
				}
			} catch ( err ) {
				setStatus( {
					type: 'error',
					text: err.message || 'Error de conexión al actualizar plugin en remoto.',
				} );
			} finally {
				setIsUpdatingPlugin( false );
			}
		}

		// PUSH Action handler
		async function handlePush() {
			if ( isSyncing ) return;
			setIsSyncing( true );
			setStatus( null );
			setReloadReq( false );
			setSyncStep( 'Comprobando cambios en el editor...' );

			try {
				// 1. If editor has unsaved changes, save first
				const selectEditor   = wp.data.select( 'core/editor' );
				const dispatchEditor = wp.data.dispatch( 'core/editor' );
				const dispatchCore   = wp.data.dispatch( 'core' );
				let current          = getCurrentPostInfo();

				if ( current.isFse && current.siteEntityId && dispatchCore && typeof dispatchCore.saveEditedEntityRecord === 'function' ) {
					setSyncStep( 'Guardando plantilla FSE antes de sincronizar...' );
					await dispatchCore.saveEditedEntityRecord( 'postType', current.type, current.siteEntityId );
				} else if ( selectEditor && selectEditor.isEditedPostDirty && selectEditor.isEditedPostDirty() && dispatchEditor && dispatchEditor.savePost ) {
					setSyncStep( 'Guardando cambios del editor antes de sincronizar...' );
					await dispatchEditor.savePost();
				}

				current = getCurrentPostInfo();
				if ( ! current.id && ! current.slug ) {
					throw new Error( 'No se pudo identificar la entrada o plantilla actual.' );
				}

				setSyncStep( 'Extrayendo contenido, metadatos y medios locales...' );

				const fd = new FormData();
				fd.append( 'action', 'vk7k_sync_editor_push' );
				fd.append( 'nonce', config.nonce );
				fd.append( 'post_id', current.id );
				fd.append( 'post_type', current.type );
				fd.append( 'post_name', current.slug );
				fd.append( 'sync_media', syncMedia ? '1' : '0' );
				fd.append( 'preserve_stock', preserveStock ? '1' : '0' );

				setSyncStep( 'Enviando contenido y archivos de medios al servidor remoto...' );

				const res  = await fetch( config.ajaxUrl, { method: 'POST', body: fd } );
				const text = await res.text();
				let data;
				try {
					data = JSON.parse( text );
				} catch ( e ) {
					const cleanText = text.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
					throw new Error( cleanText ? cleanText.substring( 0, 200 ) : 'Respuesta no válida del servidor.' );
				}

				if ( ! data.success ) {
					throw new Error( data.data && data.data.message ? data.data.message : 'Error durante la sincronización Push.' );
				}

				setStatus( {
					type: 'success',
					text: data.data.message || 'Contenido y medios subidos exitosamente a remoto.',
					remoteUrl: data.data && data.data.remote_post_url ? data.data.remote_post_url : '',
				} );
				setSyncStep( '' );
			} catch ( err ) {
				setStatus( {
					type: 'error',
					text: err.message || 'Error inesperado durante Push.',
				} );
				setSyncStep( '' );
			} finally {
				setIsSyncing( false );
			}
		}

		// PULL Action handler
		async function handlePull() {
			if ( isSyncing ) return;

			const current = getCurrentPostInfo();
			if ( ! current.id && ! current.slug ) {
				alert( 'No se pudo identificar el contenido actual para sincronizar.' );
				return;
			}

			const confirmText = i18n.confirmPull || '¿Deseas traer la versión remota de este contenido? Se sobrescribirá el contenido, metadatos y medios locales.';
			if ( ! window.confirm( confirmText ) ) {
				return;
			}

			setIsSyncing( true );
			setStatus( null );
			setReloadReq( false );
			setSyncStep( 'Solicitando paquete de datos al servidor remoto...' );

			try {
				const fd = new FormData();
				fd.append( 'action', 'vk7k_sync_editor_pull' );
				fd.append( 'nonce', config.nonce );
				fd.append( 'post_id', current.id );
				fd.append( 'post_type', current.type );
				fd.append( 'post_name', current.slug );
				fd.append( 'sync_media', syncMedia ? '1' : '0' );
				fd.append( 'preserve_stock', preserveStock ? '1' : '0' );

				setSyncStep( 'Descargando e importando contenido, metadatos y medios...' );

				const res  = await fetch( config.ajaxUrl, { method: 'POST', body: fd } );
				const text = await res.text();
				let data;
				try {
					data = JSON.parse( text );
				} catch ( e ) {
					const cleanText = text.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
					throw new Error( cleanText ? cleanText.substring( 0, 200 ) : 'Respuesta no válida del servidor.' );
				}

				if ( ! data.success ) {
					throw new Error( data.data && data.data.message ? data.data.message : 'Error durante la sincronización Pull.' );
				}

				setStatus( {
					type: 'success',
					text: data.data.message || 'Contenido y medios traídos exitosamente desde remoto.',
				} );
				setReloadReq( true );
				setSyncStep( '' );
			} catch ( err ) {
				setStatus( {
					type: 'error',
					text: err.message || 'Error inesperado durante Pull.',
				} );
				setSyncStep( '' );
			} finally {
				setIsSyncing( false );
			}
		}

		const isConfigured = Boolean( config.isConfigured );
		const remoteHost   = config.remoteUrl ? config.remoteUrl.replace( /^https?:\/\//, '' ).replace( /\/$/, '' ) : '';
		const remoteStatus = inspectData && inspectData.remote_status ? inspectData.remote_status : null;

		const localVersion      = config.localVersion || ( remoteStatus && remoteStatus.local_version ) || '';
		const isVersionMismatch = Boolean( remoteStatus && remoteStatus.connected && ! remoteStatus.version_match );
		const isDisconnected    = Boolean( remoteStatus && ! remoteStatus.connected );
		// True when the REMOTE runs a newer version: the fix belongs to the local install, not to a remote deploy.
		const remoteIsNewer     = Boolean( isVersionMismatch && cmpVersions( remoteStatus.remote_version, localVersion ) > 0 );
		const isBusy            = isSyncing || inspecting || isUpdatingPlugin;

		// A version mismatch blocks Push (never write with mismatched code) but keeps Pull available,
		// because pulling does not modify the remote. A lost connection blocks both.
		const isSlave           = 'slave' === config.role;
		// A receptor never pushes (the server blocks it too).
		const isPushBlocked     = isSlave || isBusy || ! isConfigured || isVersionMismatch || isDisconnected;
		const isPullBlocked     = isBusy || ! isConfigured || isDisconnected;
		const areOptionsBlocked = isBusy || ! isConfigured;

		// Determine dynamic badge status
		let badgeClass = 'is-offline';
		let badgeText  = 'Inactivo';
		if ( inspecting ) {
			badgeClass = 'is-checking';
			badgeText  = 'Comprobando...';
		} else if ( remoteStatus ) {
			if ( remoteStatus.connected && remoteStatus.version_match ) {
				badgeClass = 'is-online';
				badgeText  = 'Conectado v' + ( remoteStatus.remote_version || '' );
			} else if ( remoteStatus.connected && ! remoteStatus.version_match ) {
				badgeClass = 'is-warning';
				badgeText  = 'v' + ( remoteStatus.remote_version || '?' ) + ' Desfasado';
			} else {
				badgeClass = 'is-offline';
				badgeText  = 'Desconectado';
			}
		} else if ( isConfigured ) {
			badgeClass = 'is-online';
			badgeText  = 'Conectado';
		}

		/**
		 * Unified remote status card (connection, configuration and version state).
		 * The old layout rendered BOTH a "Plugins Desincronizados" box with a
		 * configuration link and a separate version box, which is now a single card
		 * that offers the remote plugin update directly.
		 */
		function remoteAlertCard() {
			const settingsLink = function( label ) {
				return el(
					'a',
					{
						key: 'settings',
						href: config.settingsUrl || 'admin.php?page=uxcribe-sync',
						target: '_blank',
						rel: 'noopener noreferrer',
						className: 'vk7k-sync-btn-settings-link',
					},
					el( 'span', {}, label ),
					el( 'span', { style: { fontSize: '13px' } }, '↗' )
				);
			};

			const retryButton = el(
				Button,
				{
					key: 'retry',
					variant: 'secondary',
					isSmall: true,
					className: 'vk7k-sync-btn-retry',
					disabled: isBusy,
					onClick: function() {
						inspectRemote();
					},
				},
				'🔄 Volver a comprobar'
			);

			const updateButton = el(
				Button,
				{
					key: 'update',
					variant: 'secondary',
					isSmall: true,
					className: 'vk7k-sync-btn-deploy',
					disabled: isUpdatingPlugin,
					onClick: handleUpdateRemotePlugin,
				},
				isUpdatingPlugin ? el( Spinner, {} ) : el( 'span', { style: { marginRight: '4px' } }, '⚡' ),
				isUpdatingPlugin ? ' Actualizando en remoto...' : ' Actualizar plugin en remoto'
			);

			let tone    = 'warn';
			let title   = '';
			let message = '';
			let actions = [];

			if ( ! isConfigured && isSlave ) {
				// One-way trust: a receptor needs no credentials and must not look "misconfigured".
				return el(
					'div',
					{ className: 'vk7k-sync-remote-alert is-info' },
					el(
						'div',
						{ className: 'vk7k-sync-remote-alert-head' },
						el( 'span', {}, '📥' ),
						el( 'strong', {}, 'Sitio receptor de sincronización' )
					),
					el(
						'p',
						{ className: 'vk7k-sync-remote-alert-msg' },
						'Este sitio recibe contenido desde el maestro. No inicia peticiones ni guarda claves de otros entornos.'
					)
				);
			}

			if ( ! isConfigured ) {
				title   = 'Servidor Remoto no Configurado';
				message = i18n.notConfigured || 'Debes vincular un entorno remoto en los ajustes de uxcribe.cl Sync para habilitar Push y Pull.';
				actions = [ settingsLink( '⚙️ Configurar Entorno Remoto' ) ];
			} else if ( ! remoteStatus || inspecting ) {
				return null;
			} else if ( ! remoteStatus.connected ) {
				tone    = 'danger';
				title   = 'Sin Conexión con el Servidor Remoto';
				message = remoteStatus.error || 'No se pudo autenticar con el servidor remoto.';
				actions = [ retryButton, settingsLink( '⚙️ Revisar Ajustes' ) ];
			} else if ( isVersionMismatch ) {
				title   = 'Plugin Desincronizado (remoto v' + ( remoteStatus.remote_version || '?' ) + ' · local v' + ( localVersion || '?' ) + ')';
				message = remoteStatus.error || 'La versión del plugin en el servidor remoto no coincide con la versión local.';
				const canDeploy = config.canUpdatePlugin !== false;
				if ( remoteIsNewer ) {
					// Nothing to deploy: the LOCAL copy is the one that must be updated.
					actions = [ retryButton ];
				} else if ( ! canDeploy ) {
					message += ' Pide a un administrador que actualice el plugin en el servidor remoto.';
					actions = [ retryButton ];
				} else if ( statusMessage && statusMessage.type === 'warning' ) {
					actions = [ updateButton, retryButton ];
				} else {
					actions = [ updateButton ];
				}
			} else {
				return null;
			}

			return el(
				'div',
				{ className: 'vk7k-sync-remote-alert is-' + tone },
				el(
					'div',
					{ className: 'vk7k-sync-remote-alert-head' },
					el( 'span', {}, tone === 'danger' ? '⛔' : '⚠️' ),
					el( 'strong', {}, title )
				),
				el( 'p', { className: 'vk7k-sync-remote-alert-msg' }, message ),
				actions.length ? el( 'div', { className: 'vk7k-sync-alert-actions' }, actions ) : null
			);
		}

		return el(
			'div',
			{ className: 'vk7k-sync-editor-panel' },

			// Target Environment Banner
			el(
				'div',
				{ className: 'vk7k-sync-target-banner' },
				el(
					'div',
					{ className: 'vk7k-sync-target-info' },
					el( 'span', { className: 'vk7k-sync-target-label' }, 'Servidor Remoto' ),
					el(
						'span',
						{ className: 'vk7k-sync-target-url', title: config.remoteUrl || '' },
						remoteHost || 'No configurado'
					)
				),
				el(
					'span',
					{ className: 'vk7k-sync-status-badge ' + badgeClass },
					el( 'span', { className: 'vk7k-sync-dot' } ),
					badgeText
				)
			),

			// Content Details Card
			el(
				'div',
				{ className: 'vk7k-sync-card' },
				el( 'h4', { className: 'vk7k-sync-card-title' }, postInfo.title || '(sin título)' ),
				el(
					'div',
					{ className: 'vk7k-sync-card-meta' },
					el( 'span', { className: 'vk7k-sync-type-pill' }, postInfo.type ),
					el( 'span', { className: 'vk7k-sync-id-badge' }, postInfo.id > 0 ? '#' + postInfo.id : ( postInfo.isFse ? 'FSE' : '#0' ) ),
					postInfo.slug ? el( 'span', { style: { color: '#94a3b8' } }, '/' + postInfo.slug ) : null
				),

				// Detected Dependencies
				el(
					'div',
					{ className: 'vk7k-sync-deps' },
					el(
						'div',
						{ className: 'vk7k-sync-dep-item' },
						el( 'span', { className: 'vk7k-sync-dep-label' }, '🖼️ Medios y adjuntos:' ),
						el(
							'span',
							{ className: 'vk7k-sync-dep-count' },
							inspecting
								? '...'
								: ( inspectData ? inspectData.media_count + ' archivos' : 'Auto' )
						)
					),
					el(
						'div',
						{ className: 'vk7k-sync-dep-item' },
						el( 'span', { className: 'vk7k-sync-dep-label' }, '🏷️ Categorías / Términos:' ),
						el(
							'span',
							{ className: 'vk7k-sync-dep-count' },
							inspecting
								? '...'
								: ( inspectData ? inspectData.terms_count : 'Auto' )
						)
					),
					el(
						'div',
						{ className: 'vk7k-sync-dep-item' },
						el( 'span', { className: 'vk7k-sync-dep-label' }, '⚙️ Campos y Metadatos:' ),
						el(
							'span',
							{ className: 'vk7k-sync-dep-count' },
							inspecting
								? '...'
								: ( inspectData ? inspectData.meta_count + ' campos' : 'Auto' )
						)
					)
				)
			),

			// Media & Stock Option Checkboxes
			el(
				'div',
				{ className: 'vk7k-sync-options' },
				el( CheckboxControl, {
					label: i18n.syncMedia || 'Sincronizar medios y adjuntos (imágenes, galerías)',
					checked: syncMedia,
					onChange: setSyncMedia,
					disabled: areOptionsBlocked,
				} ),
				postInfo && postInfo.type === 'product' && el( CheckboxControl, {
					label: 'Proteger inventario remoto (no sobrescribir stock en vivo)',
					checked: preserveStock,
					onChange: setPreserveStock,
					disabled: areOptionsBlocked,
				} )
			),

			remoteAlertCard(),

			// Push & Pull Action Buttons
			el(
				'div',
				{ className: 'vk7k-sync-actions' },
				el(
					Button,
					{
						className: 'vk7k-sync-btn-push',
						onClick: handlePush,
						disabled: isPushBlocked,
						title: isVersionMismatch ? ( remoteStatus.error || 'Push bloqueado: actualiza el plugin en remoto primero.' ) : '',
					},
					el( 'span', { style: { fontSize: '15px' } }, '⬆️' ),
					isSyncing && syncStep.indexOf( 'Enviando' ) !== -1
						? 'Enviando a remoto...'
						: ( i18n.pushToRemote || 'Push a Remoto' )
				),
				el(
					Button,
					{
						className: 'vk7k-sync-btn-pull',
						onClick: handlePull,
						disabled: isPullBlocked,
						title: isDisconnected ? ( remoteStatus.error || 'Servidor remoto desconectado.' ) : '',
					},
					el( 'span', { style: { fontSize: '15px' } }, '⬇️' ),
					isSyncing && syncStep.indexOf( 'Descargando' ) !== -1
						? 'Trayendo de remoto...'
						: ( i18n.pullFromRemote || 'Pull desde Remoto' )
				)
			),

			// Loading Step Box
			isSyncing
				? el(
					'div',
					{ className: 'vk7k-sync-status-box is-loading' },
					el( Spinner, {} ),
					el( 'span', {}, syncStep || 'Procesando sincronización...' )
				)
				: null,

			// Status Result Message
			statusMessage
				? el(
					'div',
					{ className: 'vk7k-sync-status-box is-' + statusMessage.type },
					el(
						'div',
						{ style: { display: 'flex', alignItems: 'flex-start', gap: '6px' } },
						el(
							'strong',
							{},
							statusMessage.type === 'success' ? '✓ ' : '⚠️ '
						),
						el( 'span', {}, statusMessage.text )
					),

					// View on Remote Button if remote URL was returned
					statusMessage.type === 'success' && statusMessage.remoteUrl
						? el(
							'a',
							{
								href: statusMessage.remoteUrl,
								target: '_blank',
								rel: 'noopener noreferrer',
								className: 'vk7k-sync-btn-remote-view',
							},
							el( 'span', {}, '🌐 Ver en Servidor Remoto' ),
							el( 'span', { style: { fontSize: '13px' } }, '↗' )
						)
						: null,

					// Reload Editor Button if Pull was executed
					reloadReq
						? el(
							Button,
							{
								className: 'vk7k-sync-btn-reload',
								onClick: function() {
									window.location.reload();
								},
							},
							'🔄 Recargar Editor Ahora'
						)
						: null
				)
				: null
		);
	}

	/**
	 * Top-level Plugin Root Component.
	 */
	function VK7KSyncEditorPlugin() {
		const [ isModalOpen, setIsModalOpen ] = useState( false );
		const config = window.vk7kSyncEditor || {};
		const i18n   = config.i18n || {};

		return el(
			Fragment,
			{},

			// 1. More Menu Item (in the three dots '⋮' menu) to toggle the sidebar
			PluginSidebarMoreMenuItem
				? el(
					PluginSidebarMoreMenuItem,
					{
						target: 'vk7k-sync-editor-sidebar',
						icon: syncIcon,
					},
					i18n.menuLabel || 'uxcribe.cl Sync: Push / Pull'
				)
				: null,

			// 2. Action Menu Item (in the three dots '⋮' menu) to open quick modal
			PluginMoreMenuItem
				? el(
					PluginMoreMenuItem,
					{
						icon: syncIcon,
						onClick: function() {
							setIsModalOpen( true );
						},
					},
					'uxcribe.cl Sync: Push / Pull (Ventana)'
				)
				: null,

			// 3. Pinned Header Toolbar Icon + Sidebar Panel
			PluginSidebar
				? el(
					PluginSidebar,
					{
						name: 'vk7k-sync-editor-sidebar',
						title: i18n.pluginTitle || 'uxcribe.cl Sync',
						icon: syncIcon,
						isPinnable: true,
					},
					el( SyncPanelContent, {} )
				)
				: null,

			// 4. Quick Modal Dialog
			isModalOpen
				? el(
					Modal,
					{
						title: 'uxcribe.cl Sync: Push / Pull',
						onRequestClose: function() {
							setIsModalOpen( false );
						},
						className: 'vk7k-sync-modal',
					},
					el( SyncPanelContent, {} )
				)
				: null
		);
	}

	// Register the plugin with Gutenberg
	registerPlugin( 'vk7k-sync-editor', {
		render: VK7KSyncEditorPlugin,
		icon: syncIcon,
	} );
} )();
