/**
 * uxcribe.cl Sync - Classic Editor & Meta Box Controller
 *
 * Provides single-content Push and Pull for WooCommerce Products, Posts and Pages
 * from the classic editor sidebar meta box and list table row actions.
 */
( function( $ ) {
	'use strict';

	const config = window.vk7kSyncClassic || {};
	const i18n   = config.i18n || {};

	/**
	 * Helper: Display Toast Notification on screen
	 */
	function showToast( msg, type = 'info', linkUrl = '' ) {
		$( '#vk7k-sync-toast' ).remove();

		let html = `<div id="vk7k-sync-toast" class="is-${type}">
			<span>${msg}</span>`;
		if ( linkUrl ) {
			html += `<a href="${linkUrl}" target="_blank" style="color:#fff; text-decoration:underline; font-weight:bold; margin-left:8px;">${i18n.viewRemote || 'Ver'} &rarr;</a>`;
		}
		html += `</div>`;

		const $toast = $( html ).appendTo( 'body' );
		if ( type !== 'loading' ) {
			setTimeout( function() {
				$toast.fadeOut( 300, function() { $( this ).remove(); } );
			}, 6000 );
		}
	}

	/**
	 * Minimal HTML escaping for values injected into the meta box.
	 */
	function escapeHtml( str ) {
		return String( str === null || str === undefined ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	/**
	 * Initialize Meta Box on post.php / post-new.php
	 */
	function initMetaBox() {
		const $box = $( '.vk7k-metabox-container' );
		if ( ! $box.length || ! config.isConfigured ) {
			return;
		}

		const postId   = parseInt( $box.data( 'post-id' ), 10 ) || config.postId;
		const postType = $box.data( 'post-type' ) || config.postType;
		const postName = $box.data( 'post-name' ) || config.postSlug;

		/**
		 * Apply a remote_status payload (same semantics as the block editor panel):
		 * connected+match -> green badge; mismatch -> warning card with direct update;
		 * disconnected -> danger card without the update button.
		 */
		function applyRemoteStatus( rs ) {
			if ( ! rs ) {
				return;
			}

			const $badge = $( '#vk7k-mb-status-badge' );
			const $alert = $( '#vk7k-mb-version-alert' );
			const $msg   = $( '#vk7k-mb-version-alert-msg' );
			const $upd   = $( '#vk7k-btn-mb-update-plugin' );
			const $push  = $( '#vk7k-btn-mb-push' );
			const $pull  = $( '#vk7k-btn-mb-pull' );

			if ( rs.connected && rs.version_match ) {
				$badge.attr( 'class', 'vk7k-sync-status-badge is-online' );
				$badge.find( '.vk7k-badge-text' ).text( 'Conectado v' + ( rs.remote_version || '' ) );
				$alert.slideUp( 150 );
				if ( config.role !== 'slave' ) {
					$push.prop( 'disabled', false ).removeAttr( 'title' );
				}
				$pull.prop( 'disabled', false ).removeAttr( 'title' );
				return;
			}

			if ( ! rs.connected ) {
				$badge.attr( 'class', 'vk7k-sync-status-badge is-offline' );
				$badge.find( '.vk7k-badge-text' ).text( 'Desconectado' );
				$msg.html( '<strong>Sin conexión con el servidor remoto.</strong> ' + escapeHtml( rs.error || '' ) );
				$alert.removeClass( 'is-warning' ).addClass( 'is-danger' ).slideDown( 150 );
				$upd.hide();
				$push.prop( 'disabled', true ).attr( 'title', rs.error || 'Servidor remoto desconectado.' );
				$pull.prop( 'disabled', true ).attr( 'title', rs.error || 'Servidor remoto desconectado.' );
				return;
			}

			// Connected but running a different plugin version.
			$badge.attr( 'class', 'vk7k-sync-status-badge is-warning' );
			$badge.find( '.vk7k-badge-text' ).text( 'v' + ( rs.remote_version || '?' ) + ' Desfasado' );
			$msg.html(
				'<strong>Plugin desincronizado (remoto v' + escapeHtml( rs.remote_version || '?' ) +
				' · local v' + escapeHtml( rs.local_version || config.pluginVersion || '?' ) + ').</strong> ' +
				escapeHtml( rs.error || '' )
			);
			$alert.removeClass( 'is-danger' ).addClass( 'is-warning' ).slideDown( 150 );
			$push.prop( 'disabled', true ).attr( 'title', 'Push bloqueado: actualiza el plugin en remoto primero.' );
			// Pull does not write to the remote, so it stays available.
			$pull.prop( 'disabled', false ).removeAttr( 'title' );

			// Nothing to deploy when the remote is ahead: the local copy must be updated first.
			if ( rs.remote_is_newer || config.canUpdatePlugin === false ) {
				$upd.hide();
			} else {
				$upd.show();
			}
		}

		// 1. Live inspection of dependencies and remote status
		function runInspect() {
			if ( postId <= 0 ) {
				$( '.vk7k-mb-inspect-loading' ).text( 'Guarda el borrador antes de sincronizar.' );
				return;
			}
			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_editor_inspect',
					nonce: config.nonce,
					post_id: postId,
					post_type: postType,
					post_name: postName,
				},
				dataType: 'json',
				success: function( res ) {
					if ( res && res.success && res.data ) {
						const d = res.data;
						if ( d.is_product ) {
							const typeLabel = ( d.product_type === 'variable' ) ? 'Producto variable' : ( d.product_type ? d.product_type.charAt(0).toUpperCase() + d.product_type.slice(1) : 'Producto' );
							$( '#vk7k-mb-val-type' ).text( typeLabel + ( d.sku ? ' (' + d.sku + ')' : '' ) );
							$( '#vk7k-mb-val-variations' ).text( d.variations_count || 0 );
							$( '#vk7k-mb-row-variations' ).show();
						} else {
							$( '#vk7k-mb-val-type' ).text( d.type || postType );
							$( '#vk7k-mb-row-variations' ).hide();
						}
						$( '#vk7k-mb-val-media' ).text( d.media_count || 0 );
						$( '#vk7k-mb-val-meta' ).text( ( d.meta_count || 0 ) + ' meta / ' + ( d.terms_count || 0 ) + ' términos' );

						$( '.vk7k-mb-inspect-loading' ).hide();
						$( '.vk7k-mb-inspect-content' ).slideDown( 150 );

						// Live remote connection & plugin version status
						applyRemoteStatus( d.remote_status );
					} else {
						$( '.vk7k-mb-inspect-loading' ).text( 'Listo para sincronizar.' );
					}
				},
				error: function() {
					$( '.vk7k-mb-inspect-loading' ).text( 'Listo para sincronizar.' );
				}
			} );
		}

		/**
		 * Re-run the inspection a few times: a fresh deploy can take a moment
		 * before the remote serves the new plugin version.
		 */
		function pollRemoteVersion( tries ) {
			runInspect();
			if ( tries <= 0 ) {
				return;
			}
			window.setTimeout( function() {
				pollRemoteVersion( tries - 1 );
			}, 1500 );
		}

		runInspect();

		// Update Remote Plugin Button Handler
		$( document ).on( 'click', '#vk7k-btn-mb-update-plugin', function( e ) {
			e.preventDefault();
			const $btn = $( this );
			if ( $btn.prop( 'disabled' ) ) return;

			$btn.prop( 'disabled', true );
			const originalHtml = $btn.html();
			$btn.html( '<span class="spinner is-active" style="float:none; margin:0 4px 0 0; vertical-align:middle;"></span> Sincronizando plugin en remoto...' );

			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_update_remote_plugin',
					nonce: config.nonce,
				},
				dataType: 'json',
				success: function( res ) {
					if ( res && res.success ) {
						const rs = res.data && res.data.remote_status ? res.data.remote_status : null;
						if ( rs ) {
							applyRemoteStatus( rs );
						}

						const verified = Boolean( res.data && res.data.verified );
						showToast(
							( verified ? '✅ ' : '⚠️ ' ) + ( res.data && res.data.message ? res.data.message : 'Plugin actualizado en remoto con éxito.' ),
							verified ? 'success' : 'warning'
						);

						$btn.html( originalHtml ).prop( 'disabled', false );

						if ( verified ) {
							runInspect();
						} else {
							// A fresh deploy can take a moment to be served: re-check a few times.
							pollRemoteVersion( 4 );
						}
					} else {
						const err = res && res.data && res.data.message ? res.data.message : 'Error desconocido al actualizar plugin en remoto.';
						alert( '❌ ' + err );
						$btn.html( originalHtml ).prop( 'disabled', false );
					}
				},
				error: function() {
					alert( '❌ Error de conexión al sincronizar el plugin en remoto.' );
					$btn.html( originalHtml ).prop( 'disabled', false );
				}
			} );
		} );

		// 2. Handle PUSH button click
		$( '#vk7k-btn-mb-push' ).on( 'click', function( e ) {
			e.preventDefault();
			const $btn = $( this );

			if ( config.role === 'slave' ) {
				alert( i18n.roleSlavePushWarn || 'Subida (Push) bloqueada en rol Esclavo.' );
				return;
			}

			if ( ! postId || postId <= 0 ) {
				alert( 'Guarda o publica este contenido antes de subirlo a remoto.' );
				return;
			}

			const syncMedia     = $( '#vk7k-mb-chk-media' ).is( ':checked' ) ? 1 : 0;
			const preserveStock = $( '#vk7k-mb-chk-stock' ).length ? ( $( '#vk7k-mb-chk-stock' ).is( ':checked' ) ? 1 : 0 ) : 0;

			const $statusBox = $( '#vk7k-mb-status-box' ).removeClass( 'is-success is-error' ).addClass( 'is-loading' ).show();
			$statusBox.find( '.vk7k-mb-status-step' ).text( i18n.pushing || 'Subiendo a servidor remoto...' );
			$statusBox.find( '.vk7k-mb-status-msg' ).empty();
			$statusBox.find( '.vk7k-mb-status-actions, .vk7k-mb-link-remote, .vk7k-mb-btn-reload' ).hide();

			$( '#vk7k-btn-mb-push, #vk7k-btn-mb-pull' ).prop( 'disabled', true );

			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_editor_push',
					nonce: config.nonce,
					post_id: postId,
					post_type: postType,
					post_name: postName,
					sync_media: syncMedia,
					preserve_stock: preserveStock,
				},
				dataType: 'json',
				success: function( res ) {
					$statusBox.removeClass( 'is-loading' );
					if ( res && res.success ) {
						$statusBox.addClass( 'is-success' );
						$statusBox.find( '.vk7k-mb-status-step' ).text( '✅ ' + ( i18n.success || 'Sincronización completada' ) );
						$statusBox.find( '.vk7k-mb-status-msg' ).text( res.data && res.data.message ? res.data.message : '' );

						if ( res.data && res.data.remote_post_url ) {
							$statusBox.find( '.vk7k-mb-link-remote' ).attr( 'href', res.data.remote_post_url ).show();
							$statusBox.find( '.vk7k-mb-status-actions' ).show();
						}
					} else {
						$statusBox.addClass( 'is-error' );
						$statusBox.find( '.vk7k-mb-status-step' ).text( '❌ Error en Push' );
						$statusBox.find( '.vk7k-mb-status-msg' ).text( res && res.data && res.data.message ? res.data.message : 'Error desconocido al subir.' );
					}
				},
				error: function( xhr ) {
					$statusBox.removeClass( 'is-loading' ).addClass( 'is-error' );
					$statusBox.find( '.vk7k-mb-status-step' ).text( '❌ Error de Conexión' );
					$statusBox.find( '.vk7k-mb-status-msg' ).text( xhr.statusText || 'No se pudo comunicar con el servidor local.' );
				},
				complete: function() {
					$( '#vk7k-btn-mb-push, #vk7k-btn-mb-pull' ).prop( 'disabled', false );
					if ( config.role === 'slave' ) {
						$( '#vk7k-btn-mb-push' ).prop( 'disabled', true );
					}
				}
			} );
		} );

		// 3. Handle PULL button click
		$( '#vk7k-btn-mb-pull' ).on( 'click', function( e ) {
			e.preventDefault();

			const confirmMsg = i18n.confirmPull || '¿Deseas traer la versión remota de este contenido? Se sobrescribirá el contenido, metadatos y medios locales.';
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			const syncMedia     = $( '#vk7k-mb-chk-media' ).is( ':checked' ) ? 1 : 0;
			const preserveStock = $( '#vk7k-mb-chk-stock' ).length ? ( $( '#vk7k-mb-chk-stock' ).is( ':checked' ) ? 1 : 0 ) : 0;

			const $statusBox = $( '#vk7k-mb-status-box' ).removeClass( 'is-success is-error' ).addClass( 'is-loading' ).show();
			$statusBox.find( '.vk7k-mb-status-step' ).text( i18n.pulling || 'Trayendo desde servidor remoto...' );
			$statusBox.find( '.vk7k-mb-status-msg' ).empty();
			$statusBox.find( '.vk7k-mb-status-actions, .vk7k-mb-link-remote, .vk7k-mb-btn-reload' ).hide();

			$( '#vk7k-btn-mb-push, #vk7k-btn-mb-pull' ).prop( 'disabled', true );

			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_editor_pull',
					nonce: config.nonce,
					post_id: postId,
					post_type: postType,
					post_name: postName,
					sync_media: syncMedia,
					preserve_stock: preserveStock,
				},
				dataType: 'json',
				success: function( res ) {
					$statusBox.removeClass( 'is-loading' );
					if ( res && res.success ) {
						$statusBox.addClass( 'is-success' );
						$statusBox.find( '.vk7k-mb-status-step' ).text( '✅ ' + ( i18n.success || 'Sincronización completada' ) );
						$statusBox.find( '.vk7k-mb-status-msg' ).text( res.data && res.data.message ? res.data.message : '' );
						$statusBox.find( '.vk7k-mb-btn-reload' ).show();
						$statusBox.find( '.vk7k-mb-status-actions' ).show();
					} else {
						$statusBox.addClass( 'is-error' );
						$statusBox.find( '.vk7k-mb-status-step' ).text( '❌ Error en Pull' );
						$statusBox.find( '.vk7k-mb-status-msg' ).text( res && res.data && res.data.message ? res.data.message : 'Error desconocido al descargar.' );
					}
				},
				error: function( xhr ) {
					$statusBox.removeClass( 'is-loading' ).addClass( 'is-error' );
					$statusBox.find( '.vk7k-mb-status-step' ).text( '❌ Error de Conexión' );
					$statusBox.find( '.vk7k-mb-status-msg' ).text( xhr.statusText || 'No se pudo comunicar con el servidor local.' );
				},
				complete: function() {
					$( '#vk7k-btn-mb-push, #vk7k-btn-mb-pull' ).prop( 'disabled', false );
					if ( config.role === 'slave' ) {
						$( '#vk7k-btn-mb-push' ).prop( 'disabled', true );
					}
				}
			} );
		} );
	}

	/**
	 * Initialize Post List Row Actions (edit.php?post_type=product, etc.)
	 */
	function initRowActions() {
		$( document ).on( 'click', '.vk7k-sync-row-push', function( e ) {
			e.preventDefault();
			const $link    = $( this );
			const id       = $link.data( 'id' );
			const title    = $link.data( 'title' ) || '#' + id;
			const postType = $link.data( 'type' ) || 'post';

			if ( config.role === 'slave' ) {
				alert( i18n.roleSlavePushWarn || 'Subida (Push) bloqueada en rol Esclavo.' );
				return;
			}

			showToast( `⏳ Subiendo "${title}" a remoto...`, 'loading' );

			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_editor_push',
					nonce: config.nonce,
					post_id: id,
					post_type: postType,
					sync_media: 1,
					preserve_stock: 1,
				},
				dataType: 'json',
				success: function( res ) {
					if ( res && res.success ) {
						const url = res.data && res.data.remote_post_url ? res.data.remote_post_url : '';
						showToast( `✅ "${title}" subido exitosamente a remoto.`, 'success', url );
					} else {
						showToast( `❌ Error: ${res && res.data && res.data.message ? res.data.message : 'Fallo en Push'}`, 'error' );
					}
				},
				error: function() {
					showToast( `❌ Error de conexión al sincronizar "${title}".`, 'error' );
				}
			} );
		} );

		$( document ).on( 'click', '.vk7k-sync-row-pull', function( e ) {
			e.preventDefault();
			const $link    = $( this );
			const id       = $link.data( 'id' );
			const title    = $link.data( 'title' ) || '#' + id;
			const postType = $link.data( 'type' ) || 'post';

			const confirmMsg = i18n.confirmPull || '¿Deseas traer este contenido desde el remoto? Se sobrescribirá la versión local.';
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			showToast( `⏳ Trayendo "${title}" desde remoto...`, 'loading' );

			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vk7k_sync_editor_pull',
					nonce: config.nonce,
					post_id: id,
					post_type: postType,
					sync_media: 1,
					preserve_stock: 1,
				},
				dataType: 'json',
				success: function( res ) {
					if ( res && res.success ) {
						showToast( `✅ "${title}" actualizado desde remoto. Recarga la página para ver cambios.`, 'success' );
					} else {
						showToast( `❌ Error: ${res && res.data && res.data.message ? res.data.message : 'Fallo en Pull'}`, 'error' );
					}
				},
				error: function() {
					showToast( `❌ Error de conexión al traer "${title}".`, 'error' );
				}
			} );
		} );
	}

	// Document Ready
	$( function() {
		initMetaBox();
		initRowActions();
	} );

} )( jQuery );
