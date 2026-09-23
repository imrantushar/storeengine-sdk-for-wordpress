<?php
// Explicit user actions get 30 s even through REST; background stays short.
wp_set_current_user( 1 );
$req = new WP_REST_Request( 'POST', '/storeengine-sdk/v1/se-sdk-fixture-pro/license/activate' );
$req->set_param( 'license', 'TEST-KEY-123' );
se_t_reset_hits();
rest_do_request( $req );
$act = se_t_hits( 'activate-license' );
se_t_assert( 1 === count( $act ) && 30 === (int) $act[0]['timeout'], 'REST activation timeout is 30s (got ' . ( $act[0]['timeout'] ?? 'no request' ) . ')' );
se_t_reset_hits();
se_t_cron_batch();
$bg = se_t_hits();
se_t_assert( $bg && (int) $bg[0]['timeout'] <= 15, 'background batch timeout ≤ 15s (got ' . ( $bg[0]['timeout'] ?? '-' ) . ')' );
// Opening the panel (plain GET) must not bypass an open breaker.
set_site_transient( 'se_sdk_srv_' . se_t_pro()->get_server_key(), [ 'until' => time() + 600, 'fails' => 1 ], 3600 );
delete_site_transient( se_t_pro()->getHookName( 'versions_list' ) );
se_t_reset_hits();
rest_do_request( new WP_REST_Request( 'GET', '/storeengine-sdk/v1/se-sdk-fixture-pro/updates/versions' ) );
se_t_assert( 0 === count( se_t_hits() ), 'panel GET while breaker open: 0 requests' );
$f = new WP_REST_Request( 'GET', '/storeengine-sdk/v1/se-sdk-fixture-pro/updates/versions' );
$f->set_param( 'force', 'true' );
rest_do_request( $f );
se_t_assert( 1 === count( se_t_hits( 'versions' ) ), 'Refresh (force) while breaker open: goes out' );
