/* global wp */
/**
 * StoreEngine SDK License + Updates panel.
 *
 * Boots once per host plugin from SE_License_SDK_License::render_license_page().
 * Reads its config from a `window.seSdkLicenseAppConfig_{slug}` object that
 * the PHP side injects via wp_localize_script.
 *
 * Uses wp.element (React + ReactDOM that ships with WordPress) — no build
 * step, no JSX, runs as plain JS in every WP admin from 5.3+.
 */
( function ( wp ) {
	if ( ! wp || ! wp.element ) {
		return;
	}

	const { createElement: h, useState, useEffect, useCallback, Fragment, render } = wp.element;
	const { __, sprintf } = ( wp.i18n || { __: ( s ) => s, sprintf: ( s ) => s } );

	function api( config, path, opts ) {
		opts = opts || {};
		const url = config.restUrl + path.replace( /^\//, '' );

		return fetch( url, {
			method: opts.method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
				Accept: 'application/json',
			},
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
		} ).then( async ( res ) => {
			const data = await res.json().catch( () => ( {} ) );
			if ( ! res.ok ) {
				const err = new Error( data.message || res.statusText );
				err.code = data.code;
				err.data = data.data || {};
				err.status = res.status;
				err.log = ( data.data && data.data.log ) || null;
				throw err;
			}
			return data;
		} );
	}

	function timeAgo( unix ) {
		if ( ! unix ) {
			return __( 'never', 'storeengine-sdk' );
		}
		const seconds = Math.max( 0, Math.floor( Date.now() / 1000 - unix ) );
		if ( seconds < 60 ) return __( 'just now', 'storeengine-sdk' );
		if ( seconds < 3600 ) return sprintf( /* translators: %d: minutes */ __( '%d min ago', 'storeengine-sdk' ), Math.floor( seconds / 60 ) );
		if ( seconds < 86400 ) return sprintf( __( '%d hr ago', 'storeengine-sdk' ), Math.floor( seconds / 3600 ) );
		return sprintf( __( '%d days ago', 'storeengine-sdk' ), Math.floor( seconds / 86400 ) );
	}

	function StatusHero( props ) {
		const { state, onCheckNow, checking } = props;
		const updateAvailable = !! state.update_available;

		return h( 'div', { className: 'se-sdk-hero' },
			h( 'div', { className: 'se-sdk-hero-left' },
				h( 'div', { className: 'se-sdk-hero-version' },
					h( 'span', { className: 'se-sdk-hero-version-label' }, __( 'Installed', 'storeengine-sdk' ) ),
					h( 'span', { className: 'se-sdk-hero-version-value' }, state.current_version || '—' )
				),
				updateAvailable && h( 'div', { className: 'se-sdk-hero-arrow' }, '→' ),
				updateAvailable && h( 'div', { className: 'se-sdk-hero-version se-sdk-hero-version-latest' },
					h( 'span', { className: 'se-sdk-hero-version-label' }, __( 'Latest', 'storeengine-sdk' ) ),
					h( 'span', { className: 'se-sdk-hero-version-value' }, state.latest_version || '—' )
				)
			),
			h( 'div', { className: 'se-sdk-hero-right' },
				h( 'span', { className: 'se-sdk-hero-checked' },
					sprintf( __( 'Checked %s', 'storeengine-sdk' ), timeAgo( state.last_checked_at ) )
				),
				h( 'button', {
					type: 'button',
					className: 'button',
					onClick: onCheckNow,
					disabled: checking,
				}, checking ? __( 'Checking…', 'storeengine-sdk' ) : __( 'Check for updates', 'storeengine-sdk' ) )
			)
		);
	}

	function UpdateBanner( props ) {
		const { state, installing, installLog, onInstall, onToggleChangelog, showChangelog } = props;

		if ( ! state.update_available ) {
			return h( 'div', { className: 'se-sdk-banner se-sdk-banner-ok' },
				h( 'span', { className: 'se-sdk-banner-icon' }, '✓' ),
				h( 'span', null, __( 'You\'re running the latest version.', 'storeengine-sdk' ) )
			);
		}

		return h( 'div', { className: 'se-sdk-banner se-sdk-banner-update' },
			h( 'div', { className: 'se-sdk-banner-main' },
				h( 'div', { className: 'se-sdk-banner-message' },
					h( 'strong', null,
						sprintf( __( 'Update available: v%s', 'storeengine-sdk' ), state.latest_version )
					),
					state.changelog && h( 'div', { className: 'se-sdk-banner-sub' }, state.changelog )
				),
				h( 'div', { className: 'se-sdk-banner-actions' },
					h( 'button', {
						type: 'button',
						className: 'button button-primary',
						onClick: () => onInstall( null ),
						disabled: installing,
					}, installing ? __( 'Installing…', 'storeengine-sdk' ) : sprintf( __( 'Update to %s', 'storeengine-sdk' ), state.latest_version ) ),
					state.changelog && h( 'button', {
						type: 'button',
						className: 'button button-link',
						onClick: onToggleChangelog,
					}, showChangelog ? __( 'Hide details', 'storeengine-sdk' ) : __( 'View details', 'storeengine-sdk' ) )
				)
			),
			installing && installLog.length > 0 && h( 'div', { className: 'se-sdk-install-progress' },
				h( 'div', { className: 'se-sdk-progress-bar' },
					h( 'div', { className: 'se-sdk-progress-fill' } )
				),
				h( InstallLog, { messages: installLog } )
			)
		);
	}

	function InstallLog( props ) {
		const { messages } = props;
		const [ open, setOpen ] = useState( false );

		if ( ! messages || messages.length === 0 ) {
			return null;
		}

		return h( 'details', {
			className: 'se-sdk-log-drawer',
			open,
			onToggle: ( e ) => setOpen( e.target.open ),
		},
			h( 'summary', null, sprintf( __( 'Install log (%d entries)', 'storeengine-sdk' ), messages.length ) ),
			h( 'ul', { className: 'se-sdk-log-list' },
				messages.map( ( m, i ) =>
					h( 'li', { key: i, className: 'se-sdk-log-' + m.level },
						h( 'span', { className: 'se-sdk-log-level' }, m.level ),
						h( 'span', { className: 'se-sdk-log-msg' }, m.message )
					)
				)
			)
		);
	}

	function RollbackBanner( props ) {
		const { state, installing, onRollback } = props;
		if ( ! state.previous_version ) {
			return null;
		}

		return h( 'div', { className: 'se-sdk-banner se-sdk-banner-rollback' },
			h( 'span', { className: 'se-sdk-banner-icon' }, '↩' ),
			h( 'div', { className: 'se-sdk-banner-message' },
				sprintf( __( 'Need to roll back? You can return to v%s — the version you had before the last install.', 'storeengine-sdk' ), state.previous_version )
			),
			h( 'button', {
				type: 'button',
				className: 'button',
				onClick: () => onRollback( state.previous_version ),
				disabled: installing,
			}, sprintf( __( 'Roll back to v%s', 'storeengine-sdk' ), state.previous_version ) )
		);
	}

	function LicenseCard( props ) {
		const { config, license, onActivate, onDeactivate, busy, error } = props;
		const [ key, setKey ] = useState( '' );
		const active = license && license.status === 'active';

		const statusPill = h( 'span', {
			className: 'se-sdk-pill ' + ( active ? 'se-sdk-pill-ok' : 'se-sdk-pill-warn' ),
		}, active ? __( 'Active', 'storeengine-sdk' ) : __( 'Inactive', 'storeengine-sdk' ) );

		const usage = license && license.activations !== undefined ? (
			license.unlimited
				? __( 'Unlimited activations', 'storeengine-sdk' )
				: sprintf( __( '%1$d of %2$d activations used', 'storeengine-sdk' ), license.activations, license.limit )
		) : null;

		return h( 'div', { className: 'se-sdk-card' },
			h( 'div', { className: 'se-sdk-card-header' },
				h( 'h2', null, __( 'License', 'storeengine-sdk' ) ),
				statusPill
			),
			active
				? h( 'div', { className: 'se-sdk-license-active' },
					h( 'div', { className: 'se-sdk-key-display' },
						h( 'code', null, license.masked_key || '••••••••' ),
					),
					usage && h( 'div', { className: 'se-sdk-license-meta' }, usage ),
					license.expires && h( 'div', { className: 'se-sdk-license-meta' },
						sprintf( __( 'Expires %s', 'storeengine-sdk' ), license.expires )
					),
					h( 'button', {
						type: 'button',
						className: 'button',
						onClick: onDeactivate,
						disabled: busy,
					}, busy ? __( 'Working…', 'storeengine-sdk' ) : __( 'Deactivate license', 'storeengine-sdk' ) )
				)
				: h( 'div', { className: 'se-sdk-license-inactive' },
					h( 'p', { className: 'se-sdk-help' },
						sprintf( __( 'Activate %s to unlock updates and priority support.', 'storeengine-sdk' ), config.packageName )
					),
					h( 'div', { className: 'se-sdk-input-row' },
						h( 'input', {
							type: 'text',
							className: 'regular-text se-sdk-input',
							placeholder: __( 'Paste your license key', 'storeengine-sdk' ),
							value: key,
							onChange: ( e ) => setKey( e.target.value ),
							disabled: busy,
						} ),
						h( 'button', {
							type: 'button',
							className: 'button button-primary',
							onClick: () => onActivate( key.trim() ),
							disabled: busy || ! key.trim(),
						}, busy ? __( 'Activating…', 'storeengine-sdk' ) : __( 'Activate', 'storeengine-sdk' ) )
					)
				),
			error && h( 'div', { className: 'se-sdk-error' }, error )
		);
	}

	function VersionsTable( props ) {
		const { versions, loading, installing, onInstall, current } = props;
		const [ confirming, setConfirming ] = useState( null );

		if ( loading ) {
			return h( 'div', { className: 'se-sdk-card' },
				h( 'h2', null, __( 'Version history', 'storeengine-sdk' ) ),
				h( 'p', { className: 'se-sdk-help' }, __( 'Loading…', 'storeengine-sdk' ) )
			);
		}

		if ( ! versions || versions.length === 0 ) {
			return h( 'div', { className: 'se-sdk-card' },
				h( 'h2', null, __( 'Version history', 'storeengine-sdk' ) ),
				h( 'p', { className: 'se-sdk-help' }, __( 'No previous versions available.', 'storeengine-sdk' ) )
			);
		}

		return h( 'div', { className: 'se-sdk-card' },
			h( 'h2', null, __( 'Version history', 'storeengine-sdk' ) ),
			h( 'p', { className: 'se-sdk-help' },
				__( 'Install any previous version. Rollback to a version with breaking changes will show a warning.', 'storeengine-sdk' )
			),
			h( 'table', { className: 'wp-list-table widefat striped se-sdk-versions-table' },
				h( 'thead', null,
					h( 'tr', null,
						h( 'th', null, __( 'Version', 'storeengine-sdk' ) ),
						h( 'th', null, __( 'Status', 'storeengine-sdk' ) ),
						h( 'th', null, __( 'Released', 'storeengine-sdk' ) ),
						h( 'th', { className: 'se-sdk-col-actions' }, '' )
					)
				),
				h( 'tbody', null,
					versions.map( ( v ) =>
						h( Fragment, { key: v.id },
							h( 'tr', null,
								h( 'td', null,
									h( 'code', null, v.version ),
									v.is_current && h( 'span', { className: 'se-sdk-pill se-sdk-pill-current' }, __( 'current', 'storeengine-sdk' ) )
								),
								h( 'td', null,
									h( 'span', { className: 'se-sdk-pill se-sdk-pill-' + v.status }, v.status )
								),
								h( 'td', null, v.deployed_at || '—' ),
								h( 'td', { className: 'se-sdk-col-actions' },
									v.is_current
										? h( 'span', { className: 'se-sdk-help' }, __( 'Installed', 'storeengine-sdk' ) )
										: ( ! v.allow_rollback && current && compareVersions( v.version, current ) < 0 )
											? h( 'span', { className: 'se-sdk-help', title: __( 'Vendor disabled rollback to this version.', 'storeengine-sdk' ) }, __( 'Rollback blocked', 'storeengine-sdk' ) )
											: h( 'button', {
												type: 'button',
												className: 'button button-secondary',
												onClick: () => {
													if ( v.breaking_changes ) {
														setConfirming( v );
													} else {
														onInstall( v.version );
													}
												},
												disabled: installing,
											}, ( current && compareVersions( v.version, current ) < 0 )
												? __( 'Roll back', 'storeengine-sdk' )
												: __( 'Install', 'storeengine-sdk' ) )
								)
							),
							confirming && confirming.id === v.id && h( 'tr', { className: 'se-sdk-confirm-row' },
								h( 'td', { colSpan: 4 },
									h( 'div', { className: 'se-sdk-confirm-box' },
										h( 'strong', null, __( 'Breaking changes', 'storeengine-sdk' ) ),
										h( 'p', null, confirming.breaking_changes ),
										h( 'div', { className: 'se-sdk-confirm-actions' },
											h( 'button', {
												type: 'button',
												className: 'button button-primary',
												onClick: () => {
													const version = confirming.version;
													setConfirming( null );
													onInstall( version );
												},
											}, __( 'I understand, continue', 'storeengine-sdk' ) ),
											h( 'button', {
												type: 'button',
												className: 'button',
												onClick: () => setConfirming( null ),
											}, __( 'Cancel', 'storeengine-sdk' ) )
										)
									)
								)
							)
						)
					)
				)
			)
		);
	}

	function compareVersions( a, b ) {
		const pa = String( a ).split( '.' ).map( ( n ) => parseInt( n, 10 ) || 0 );
		const pb = String( b ).split( '.' ).map( ( n ) => parseInt( n, 10 ) || 0 );
		const len = Math.max( pa.length, pb.length );
		for ( let i = 0; i < len; i++ ) {
			const ai = pa[ i ] || 0;
			const bi = pb[ i ] || 0;
			if ( ai !== bi ) return ai < bi ? -1 : 1;
		}
		return 0;
	}

	function SettingsCard( props ) {
		const { state, onBeta, onWindow, savingBeta, savingWindow } = props;
		const [ windowDraft, setWindowDraft ] = useState( state.auto_update_window || '' );

		useEffect( () => {
			setWindowDraft( state.auto_update_window || '' );
		}, [ state.auto_update_window ] );

		return h( 'div', { className: 'se-sdk-card' },
			h( 'h2', null, __( 'Update settings', 'storeengine-sdk' ) ),
			h( 'div', { className: 'se-sdk-setting-row' },
				h( 'label', { className: 'se-sdk-toggle' },
					h( 'input', {
						type: 'checkbox',
						checked: !! state.beta_enabled,
						onChange: ( e ) => onBeta( e.target.checked ),
						disabled: savingBeta,
					} ),
					h( 'span', null, __( 'Receive beta updates', 'storeengine-sdk' ) )
				),
				h( 'p', { className: 'se-sdk-help' }, __( 'Includes pre-release versions tagged as beta on the server.', 'storeengine-sdk' ) )
			),
			h( 'div', { className: 'se-sdk-setting-row' },
				h( 'label', null,
					h( 'span', { className: 'se-sdk-label' }, __( 'Auto-update window', 'storeengine-sdk' ) ),
					h( 'div', { className: 'se-sdk-input-row' },
						h( 'input', {
							type: 'text',
							className: 'regular-text se-sdk-input',
							placeholder: __( 'e.g. sun 03:00 — leave blank to disable', 'storeengine-sdk' ),
							value: windowDraft,
							onChange: ( e ) => setWindowDraft( e.target.value ),
							disabled: savingWindow,
						} ),
						h( 'button', {
							type: 'button',
							className: 'button',
							onClick: () => onWindow( windowDraft.trim() || null ),
							disabled: savingWindow || ( windowDraft.trim() || null ) === ( state.auto_update_window || null ),
						}, savingWindow ? __( 'Saving…', 'storeengine-sdk' ) : __( 'Save', 'storeengine-sdk' ) )
					)
				),
				h( 'p', { className: 'se-sdk-help' }, __( 'Free-form schedule (e.g. "sun 03:00"). Stored on the server for client-side cron interpretation.', 'storeengine-sdk' ) )
			)
		);
	}

	function App( props ) {
		const { config } = props;
		const [ state, setState ] = useState( null );
		const [ versions, setVersions ] = useState( null );
		const [ versionsLoading, setVersionsLoading ] = useState( true );
		const [ checking, setChecking ] = useState( false );
		const [ installing, setInstalling ] = useState( false );
		const [ installLog, setInstallLog ] = useState( [] );
		const [ licenseBusy, setLicenseBusy ] = useState( false );
		const [ licenseError, setLicenseError ] = useState( null );
		const [ savingBeta, setSavingBeta ] = useState( false );
		const [ savingWindow, setSavingWindow ] = useState( false );
		const [ showChangelog, setShowChangelog ] = useState( false );
		const [ toast, setToast ] = useState( null );

		const refreshStatus = useCallback( () => {
			return api( config, 'updates/status' ).then( setState );
		}, [ config ] );

		const refreshVersions = useCallback( () => {
			setVersionsLoading( true );
			return api( config, 'updates/versions' )
				.then( ( res ) => {
					setVersions( res && res.versions ? res.versions : [] );
				} )
				.catch( () => setVersions( [] ) )
				.finally( () => setVersionsLoading( false ) );
		}, [ config ] );

		const refreshLicense = useCallback( () => {
			if ( config.isFree ) return Promise.resolve();
			return api( config, 'license/status' ).then( ( lic ) => {
				setState( ( s ) => Object.assign( {}, s || {}, { license: lic } ) );
			} );
		}, [ config ] );

		useEffect( () => {
			refreshStatus().catch( () => setState( {} ) );
			if ( ! config.isFree ) refreshLicense();
			refreshVersions();
		}, [ refreshStatus, refreshLicense, refreshVersions, config.isFree ] );

		const showToast = useCallback( ( msg, kind ) => {
			setToast( { msg, kind: kind || 'info' } );
			setTimeout( () => setToast( null ), 4000 );
		}, [] );

		const handleCheckNow = useCallback( () => {
			setChecking( true );
			api( config, 'updates/check-now', { method: 'POST' } )
				.then( ( fresh ) => {
					setState( fresh );
					showToast( fresh.update_available
						? sprintf( __( 'Update available: v%s', 'storeengine-sdk' ), fresh.latest_version )
						: __( 'You\'re up to date.', 'storeengine-sdk' ),
					'success' );
				} )
				.catch( ( err ) => showToast( err.message, 'error' ) )
				.finally( () => setChecking( false ) );
		}, [ config, showToast ] );

		const handleInstall = useCallback( ( version ) => {
			setInstalling( true );
			setInstallLog( [ { level: 'info', message: __( 'Starting…', 'storeengine-sdk' ), time: Date.now() / 1000 } ] );
			api( config, 'updates/install', { method: 'POST', body: version ? { version } : {} } )
				.then( ( res ) => {
					setInstallLog( res.log || [] );
					showToast(
						res.is_rollback
							? sprintf( __( 'Rolled back to v%s', 'storeengine-sdk' ), res.target_version )
							: sprintf( __( 'Installed v%s', 'storeengine-sdk' ), res.target_version ),
						'success'
					);
					// The plugin file just got swapped — defer to a reload so
					// WP picks up the new code path.
					setTimeout( () => window.location.reload(), 1500 );
				} )
				.catch( ( err ) => {
					if ( err.log ) setInstallLog( err.log );
					showToast( err.message, 'error' );
					refreshStatus();
				} )
				.finally( () => setInstalling( false ) );
		}, [ config, showToast, refreshStatus ] );

		const handleActivate = useCallback( ( licenseKey ) => {
			setLicenseBusy( true );
			setLicenseError( null );
			api( config, 'license/activate', { method: 'POST', body: { license: licenseKey } } )
				.then( ( res ) => {
					setState( ( s ) => Object.assign( {}, s || {}, { license: res.license } ) );
					showToast( res.message || __( 'License activated.', 'storeengine-sdk' ), 'success' );
					refreshVersions();
				} )
				.catch( ( err ) => setLicenseError( err.message ) )
				.finally( () => setLicenseBusy( false ) );
		}, [ config, showToast, refreshVersions ] );

		const handleDeactivate = useCallback( () => {
			if ( ! window.confirm( __( 'Deactivate this license on this site?', 'storeengine-sdk' ) ) ) return;
			setLicenseBusy( true );
			setLicenseError( null );
			api( config, 'license/deactivate', { method: 'POST' } )
				.then( ( res ) => {
					setState( ( s ) => Object.assign( {}, s || {}, { license: res.license } ) );
					showToast( res.message || __( 'License deactivated.', 'storeengine-sdk' ), 'success' );
				} )
				.catch( ( err ) => setLicenseError( err.message ) )
				.finally( () => setLicenseBusy( false ) );
		}, [ config, showToast ] );

		const handleBeta = useCallback( ( enabled ) => {
			setSavingBeta( true );
			api( config, 'settings/beta-channel', { method: 'POST', body: { enabled } } )
				.then( () => {
					setState( ( s ) => Object.assign( {}, s || {}, { beta_enabled: enabled } ) );
					refreshStatus();
				} )
				.catch( ( err ) => showToast( err.message, 'error' ) )
				.finally( () => setSavingBeta( false ) );
		}, [ config, refreshStatus, showToast ] );

		const handleWindow = useCallback( ( windowStr ) => {
			setSavingWindow( true );
			api( config, 'settings/auto-update-window', { method: 'POST', body: { window: windowStr } } )
				.then( ( res ) => {
					setState( ( s ) => Object.assign( {}, s || {}, { auto_update_window: res.window } ) );
					showToast( __( 'Schedule saved.', 'storeengine-sdk' ), 'success' );
				} )
				.catch( ( err ) => showToast( err.message, 'error' ) )
				.finally( () => setSavingWindow( false ) );
		}, [ config, showToast ] );

		if ( ! state ) {
			return h( 'div', { className: 'se-sdk-loading' }, __( 'Loading…', 'storeengine-sdk' ) );
		}

		return h( 'div', { className: 'se-sdk-app' },
			toast && h( 'div', { className: 'se-sdk-toast se-sdk-toast-' + toast.kind }, toast.msg ),
			h( StatusHero, { state, onCheckNow: handleCheckNow, checking } ),
			h( UpdateBanner, {
				state,
				installing,
				installLog,
				onInstall: handleInstall,
				showChangelog,
				onToggleChangelog: () => setShowChangelog( ! showChangelog ),
			} ),
			h( RollbackBanner, { state, installing, onRollback: handleInstall } ),
			! config.isFree && h( LicenseCard, {
				config,
				license: state.license || config.initialLicense,
				onActivate: handleActivate,
				onDeactivate: handleDeactivate,
				busy: licenseBusy,
				error: licenseError,
			} ),
			h( VersionsTable, {
				versions,
				loading: versionsLoading,
				installing,
				onInstall: handleInstall,
				current: state.current_version,
			} ),
			h( SettingsCard, {
				state,
				onBeta: handleBeta,
				onWindow: handleWindow,
				savingBeta,
				savingWindow,
			} ),
			state.last_install_log && state.last_install_log.messages && h( 'div', { className: 'se-sdk-card' },
				h( 'h2', null, __( 'Last install log', 'storeengine-sdk' ) ),
				h( InstallLog, { messages: state.last_install_log.messages } )
			)
		);
	}

	function boot() {
		const configs = Object.keys( window ).filter( ( k ) => k.startsWith( 'seSdkLicenseAppConfig_' ) );
		configs.forEach( ( key ) => {
			const config = window[ key ];
			const mount = document.getElementById( config.mountId );
			if ( ! mount ) return;
			render( h( App, { config } ), mount );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window.wp ) );
