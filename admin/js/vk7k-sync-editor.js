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
	 * Main Sync Panel UI Component (rendered inside Sidebar and/or Modal).
	 */
	function SyncPanelContent( props ) {
		const config = window.vk7kSyncEditor || {};
		const i18n   = config.i18n || {};

		const [ postInfo, setPostInfo ]       = useState( getCurrentPostInfo() );
		const [ isSyncing, setIsSyncing ]     = useState( false );
		const [ syncStep, setSyncStep ]       = useState( '' );
		const [ statusMessage, setStatus ]    = useState( null );
		const [ syncMedia, setSyncMedia ]     = useState( true );
		const [ reloadReq, setReloadReq ]     = useState( false );
		const [ inspectData, setInspectData ] = useState( null );
		const [ inspecting, setInspecting ]   = useState( false );

		// Inspect dependencies on load
		useEffect( function() {
			const info = getCurrentPostInfo();
			setPostInfo( info );

			if ( ( info.id > 0 || info.slug ) && config.ajaxUrl && config.nonce ) {
				setInspecting( true );
				const fd = new FormData();
				fd.append( 'action', 'vk7k_sync_editor_inspect' );
				fd.append( 'nonce', config.nonce );
				fd.append( 'post_id', info.id );
				fd.append( 'post_name', info.slug );
				fd.append( 'post_type', info.type );

				fetch( config.ajaxUrl, { method: 'POST', body: fd } )
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
						}
					} )
					.catch( () => {} )
					.finally( () => setInspecting( false ) );
			}
		}, [] );

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
		const isBlocked    = isSyncing || ! isConfigured || inspecting || Boolean( remoteStatus && remoteStatus.needs_action );

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

			// Media Option Checkbox
			el(
				'div',
				{ className: 'vk7k-sync-options' },
				el( CheckboxControl, {
					label: i18n.syncMedia || 'Sincronizar medios y adjuntos (imágenes, galerías)',
					checked: syncMedia,
					onChange: setSyncMedia,
					disabled: isBlocked,
				} )
			),

			// Remote Alert / Version Mismatch / Connection Issue Card
			remoteStatus && remoteStatus.needs_action
				? el(
					'div',
					{ className: 'vk7k-sync-remote-alert' },
					el(
						'div',
						{ className: 'vk7k-sync-remote-alert-head' },
						el( 'span', {}, '⚠️' ),
						el(
							'strong',
							{},
							! remoteStatus.connected
								? 'Problema de Conexión Remota'
								: 'Plugins Desincronizados'
						)
					),
					el(
						'p',
						{ className: 'vk7k-sync-remote-alert-msg' },
						remoteStatus.error
					),
					el(
						'a',
						{
							href: config.settingsUrl || 'admin.php?page=uxcribe-sync',
							target: '_blank',
							rel: 'noopener noreferrer',
							className: 'vk7k-sync-btn-settings-link',
						},
						el( 'span', {}, '⚙️ Ir a Configuración del Plugin' ),
						el( 'span', { style: { fontSize: '13px' } }, '↗' )
					)
				)
				: null,

			// Unconfigured Warning with Settings Button
			! isConfigured
				? el(
					'div',
					{ className: 'vk7k-sync-remote-alert' },
					el(
						'div',
						{ className: 'vk7k-sync-remote-alert-head' },
						el( 'span', {}, '⚠️' ),
						el( 'strong', {}, 'Servidor Remoto no Configurado' )
					),
					el(
						'p',
						{ className: 'vk7k-sync-remote-alert-msg' },
						i18n.notConfigured || 'Debes vincular un entorno remoto en los ajustes de uxcribe.cl Sync para habilitar Push y Pull.'
					),
					el(
						'a',
						{
							href: config.settingsUrl || 'admin.php?page=uxcribe-sync',
							target: '_blank',
							rel: 'noopener noreferrer',
							className: 'vk7k-sync-btn-settings-link',
						},
						el( 'span', {}, '⚙️ Configurar Entorno Remoto' ),
						el( 'span', { style: { fontSize: '13px' } }, '↗' )
					)
				)
				: null,

			// Push & Pull Action Buttons
			el(
				'div',
				{ className: 'vk7k-sync-actions' },
				el(
					Button,
					{
						className: 'vk7k-sync-btn-push',
						onClick: handlePush,
						disabled: isBlocked,
						title: ( remoteStatus && remoteStatus.needs_action ) ? remoteStatus.error : '',
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
						disabled: isBlocked,
						title: ( remoteStatus && remoteStatus.needs_action ) ? remoteStatus.error : '',
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
