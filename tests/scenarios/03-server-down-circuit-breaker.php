<?php
// A down server is tried once, then left alone; explicit actions still go out.
$GLOBALS['se_t_mode'] = 'down';
se_t_cron_batch();
se_t_assert( 1 === count( se_t_hits() ), 'down: 1 attempt (got ' . count( se_t_hits() ) . ')' );
$retry = se_t_pro()->get_server_retry_in();
se_t_assert( $retry >= 0.75 * 15 * MINUTE_IN_SECONDS && $retry <= 1.25 * 15 * MINUTE_IN_SECONDS + 5, "breaker open with jittered 15 min back-off (got {$retry}s)" );
se_t_reset_hits();
se_t_pro()->promotions()->refresh_promos();
se_t_pro()->license()->check_license_status();
se_t_assert( 0 === count( se_t_hits() ), 'background requests while open: 0' );
$GLOBALS['se_t_mode'] = 'ok';
se_t_pro()->updater()->force_check();
se_t_assert( 1 === count( se_t_hits( 'check-update' ) ), '"check now" still reaches the server' );
se_t_assert( 0 === se_t_pro()->get_server_retry_in(), 'a successful answer closes the breaker' );
