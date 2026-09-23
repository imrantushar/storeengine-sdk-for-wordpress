<?php
// A server without the batch route gets per-product requests, and is remembered.
$GLOBALS['se_t_mode'] = 'old';
se_t_cron_batch();
se_t_assert( 1 === count( se_t_hits( 'check-updates' ) ) && 2 === count( se_t_hits( 'check-update' ) ), 'batch 404 → falls back to one check-update per product' );
$info = se_t_pro()->updater()->get_cached_update_info();
se_t_assert( $info && '9.9.9' === $info->new_version, 'fallback result cached' );
se_t_reset_hits();
se_t_pro()->updater()->delete_cached_version_info();
se_t_free()->updater()->delete_cached_version_info();
( function () { self::$ran = false; } )->bindTo( null, SE_License_SDK_Update_Batch::class )();
se_t_cron_batch();
se_t_assert( 0 === count( se_t_hits( 'check-updates' ) ) && 2 === count( se_t_hits( 'check-update' ) ), 'next run skips the batch attempt (remembered for a day)' );
