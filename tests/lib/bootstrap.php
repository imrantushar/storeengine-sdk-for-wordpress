<?php
/**
 * Scenario runner, executed through `wp eval-file tests/lib/bootstrap.php <scenario.php>`
 * (see tests/run.sh). Each scenario runs in its own WP-CLI process, so the
 * SDK's per-request statics start clean.
 *
 * The license server is faked with `pre_http_request`; nothing leaves the machine.
 */

// ---------------------------------------------------------------------------
// Fake license server.
// ---------------------------------------------------------------------------

/** 'ok' | 'down' | 'old' (no batch route) | 'slow-ok' */
$GLOBALS['se_t_mode']       = 'ok';
$GLOBALS['se_t_hits']       = [];
$GLOBALS['se_t_directives'] = [];

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'license.example.test' ) ) {
		return $pre;
	}

	preg_match( '#/software/([a-z-]+)/#', $url, $m );
	$route = $m[1] ?? '?';

	$GLOBALS['se_t_hits'][] = [
		'route'   => $route,
		'slug'    => $args['body']['slug'] ?? '',
		'items'   => isset( $args['body']['items'] ) ? array_keys( $args['body']['items'] ) : null,
		'timeout' => $args['timeout'] ?? null,
	];

	$http = function ( int $code, $body, array $headers = [] ) {
		return [ 'headers' => $headers, 'body' => wp_json_encode( $body ), 'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
	};

	$mode = $GLOBALS['se_t_mode'];

	if ( 'down' === $mode ) {
		return $http( 503, [ 'message' => 'down' ] );
	}

	$update = function ( string $slug ) {
		return array_merge( [
			'slug'        => $slug,
			'new_version' => '9.9.9',
			'package'     => 'https://license.example.test/download/' . $slug . '.zip',
		], $GLOBALS['se_t_directives'] );
	};

	switch ( $route ) {
		case 'check-updates':
			if ( 'old' === $mode ) {
				return $http( 404, [ 'code' => 'rest_no_route', 'message' => 'No route' ] );
			}
			$results = [];
			foreach ( array_keys( $args['body']['items'] ) as $key ) {
				$results[ $key ] = [ 'success' => true, 'data' => $update( $key ) ];
			}
			return $http( 200, array_merge( [ 'results' => $results ], $GLOBALS['se_t_batch_directives'] ?? [] ) );
		case 'check-update':
			return $http( 200, $update( $args['body']['slug'] ) );
		case 'activate-license':
			return $http( 200, [ 'license' => $args['body']['license'] ?? '', 'status' => 'active', 'activation_id' => 1, 'remaining' => 1, 'activations' => 1, 'limit' => 2, 'expires' => '' ] );
		case 'check-license':
			return $http( 200, [ 'status' => 'active', 'activation_id' => 1, 'expires' => '' ] );
		case 'promotions':
			return $http( 200, [] );
		default:
			return $http( 200, [] );
	}
}, 1, 3 );

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

$GLOBALS['se_t_failed'] = 0;

function se_t_assert( bool $ok, string $what ) {
	echo ( $ok ? '  PASS ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$GLOBALS['se_t_failed']++;
	}
}

function se_t_hits( ?string $route = null ): array {
	return array_values( array_filter( $GLOBALS['se_t_hits'], function ( $h ) use ( $route ) {
		return null === $route || $h['route'] === $route;
	} ) );
}

function se_t_reset_hits() {
	$GLOBALS['se_t_hits'] = [];
}

/** @return SE_License_SDK_Client */
function se_t_pro() {
	return SE_License_SDK::get_registered_by_slug( 'se-sdk-fixture-pro' );
}

/** @return SE_License_SDK_Client */
function se_t_free() {
	return SE_License_SDK::get_registered_by_slug( 'se-sdk-fixture-free' );
}

/** Wipe every piece of SDK state the scenarios touch. */
function se_t_reset_state() {
	global $wpdb;
	foreach ( [ $wpdb->options, $wpdb->sitemeta ?? null ] as $table ) {
		if ( ! $table ) {
			continue;
		}
		$col = $table === $wpdb->options ? 'option_name' : 'meta_key';
		$wpdb->query( "DELETE FROM {$table} WHERE {$col} LIKE '%se_srv_sdk_%' OR {$col} LIKE '%se_sdk_%' OR {$col} LIKE '%se-sdk-fixture%'" ); // phpcs:ignore
	}
	delete_site_transient( 'update_plugins' );
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( $hooks ) as $hook ) {
			if ( false !== strpos( $hook, 'se_license_sdk' ) || false !== strpos( $hook, 'se-sdk-fixture' ) ) {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}
	wp_cache_flush();
	se_t_reset_hits();
}

/** Run $callback as if in an ordinary (non-cron, non-CLI) request. */
function se_t_as_page( callable $callback ) {
	add_filter( 'se_license_sdk_can_fetch_inline', '__return_false' );
	try {
		return $callback();
	} finally {
		remove_filter( 'se_license_sdk_can_fetch_inline', '__return_false' );
	}
}

/** Simulate an ordinary admin page load: WP's update check, promotions, localized params. */
function se_t_page_load() {
	se_t_as_page( function () {
		wp_set_current_user( 1 );
		$GLOBALS['pagenow'] = 'plugins.php';
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		foreach ( [ se_t_pro(), se_t_free() ] as $client ) {
			$client->promotions()->get_promos(); // What admin_init does.
			$client->get_js_params();            // What admin_enqueue_scripts does.
		}
	} );
}

/** Run the background batch the way WP-Cron would. */
function se_t_cron_batch() {
	add_filter( 'wp_doing_cron', '__return_true' );
	do_action( SE_License_SDK_Update_Batch::HOOK );
	remove_filter( 'wp_doing_cron', '__return_true' );
}

/** Call a private/protected method (test-only). */
function se_t_call( $object, string $method, ...$args ) {
	return ( function () use ( $method, $args ) {
		return $this->{$method}( ...$args );
	} )->call( $object );
}

// ---------------------------------------------------------------------------
// Run the scenario.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'SE_License_SDK_Update_Batch' ) || ! se_t_pro() || ! se_t_free() ) {
	echo "  FAIL fixtures not loaded (is the SDK under test the elected copy?)\n";
	exit( 1 );
}

$scenario = $args[0] ?? '';
echo basename( $scenario, '.php' ) . "\n";
se_t_reset_state();
require $scenario;
se_t_reset_state();

exit( $GLOBALS['se_t_failed'] ? 1 : 0 );
