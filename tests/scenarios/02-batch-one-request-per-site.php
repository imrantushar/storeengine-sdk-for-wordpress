<?php
// The background job asks about every product in one request.
se_t_page_load();
se_t_cron_batch();
$batch = se_t_hits( 'check-updates' );
se_t_assert( 1 === count( se_t_hits() ), 'exactly 1 request for both products (got ' . count( se_t_hits() ) . ')' );
se_t_assert( 1 === count( $batch ) && 2 === count( (array) $batch[0]['items'] ), 'it is a check-updates batch with 2 items' );
$t   = get_site_transient( 'update_plugins' );
$pro = $t->response[ se_t_pro()->getBasename() ] ?? null;
$fr  = $t->response[ se_t_free()->getBasename() ] ?? null;
se_t_assert( $pro && '9.9.9' === $pro->new_version, 'pro update row injected' );
se_t_assert( $pro && empty( $pro->package ), 'pro package stripped: no valid license' );
se_t_assert( $fr && ! empty( $fr->package ), 'free update row keeps its package' );
se_t_reset_hits();
se_t_page_load();
se_t_assert( 0 === count( se_t_hits() ), 'next page load: 0 requests (served from cache)' );
