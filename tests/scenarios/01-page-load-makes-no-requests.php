<?php
// An ordinary admin page load must never contact the license server.
se_t_page_load();
se_t_assert( 0 === count( se_t_hits() ), 'cold cache: 0 license-server requests on an admin page load (got ' . count( se_t_hits() ) . ')' );
se_t_assert( (bool) wp_next_scheduled( SE_License_SDK_Update_Batch::HOOK ), 'background batch was scheduled' );
se_t_assert( (bool) wp_next_scheduled( se_t_pro()->getHookName( 'refresh_promos' ) ), 'promotions refresh was scheduled instead of fetched' );
se_t_as_page( function () { se_t_pro()->get_js_params(); se_t_free()->get_js_params(); } );
se_t_assert( 0 === count( se_t_hits() ), 'get_js_params() is cache-only' );
