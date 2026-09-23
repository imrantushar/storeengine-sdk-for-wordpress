<?php
// The server can set the cache lifetime and pause background checks.
$GLOBALS['se_t_directives']       = [ 'next_check_in' => 2 * DAY_IN_SECONDS ];
$GLOBALS['se_t_batch_directives'] = [ 'pause_background' => 3 * HOUR_IN_SECONDS ];
se_t_cron_batch();
$key     = se_t_pro()->getHookName( 'version_info' ) . 'plugin_update';
$timeout = (int) get_site_option( '_site_transient_timeout_' . $key );
$ttl     = $timeout - time();
se_t_assert( $ttl >= 0.9 * 2 * DAY_IN_SECONDS - 5 && $ttl <= 1.1 * 2 * DAY_IN_SECONDS + 5, "next_check_in honoured: cache lives ~2 days (got {$ttl}s)" );
$retry = se_t_pro()->get_server_retry_in();
se_t_assert( $retry >= 3 * HOUR_IN_SECONDS - 5 && $retry <= 1.25 * 3 * HOUR_IN_SECONDS + 5, "pause_background honoured, never shorter than asked (got {$retry}s)" );
se_t_reset_hits();
se_t_free()->promotions()->refresh_promos();
se_t_assert( 0 === count( se_t_hits() ), 'paused: background request skipped' );
$GLOBALS['se_t_directives'] = [];
se_t_pro()->updater()->force_check();
se_t_assert( se_t_pro()->get_server_retry_in() > 0, 'a successful user action does not lift the server pause' );
