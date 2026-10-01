<?php
// An edge bot-challenge (e.g. Cloudflare's "Just a moment…") in front of the
// license server is a transport failure, not a verdict: a valid license is kept
// through the grace period, an explicit action reports it clearly, and the
// failure breaker backs off so the edge isn't hammered.
$GLOBALS['se_t_mode'] = 'challenge';
$license = se_t_pro()->license();

// A valid, active license must NOT be deactivated while the edge challenges the
// background status check — the grace period holds the last-known-good state.
$data = [ 'license' => 'TEST-KEY-123', 'status' => 'active', 'activation_id' => 1, 'product_id' => 9001, 'slug' => 'se-sdk-fixture-pro' ];
se_t_call( $license, 'set_license', $data );
se_t_pro()->save_software_data();
$license->check_license_status();
$after = se_t_call( $license, 'get_license' );
se_t_assert( 'active' === ( $after['status'] ?? '' ), 'active license stays active through an edge challenge (grace)' );

// An explicit activation reads the challenge as transport-level, not "invalid".
se_t_reset_hits();
$resp = $license->activate( $data );
se_t_assert( empty( $resp['success'] ) && ! empty( $resp['transport_error'] ), 'challenge → transport_error, not a verdict' );
se_t_assert( 'edge_challenge' === ( $resp['code'] ?? '' ), '…reported with the edge_challenge code' );

// The challenge opens the failure breaker so background requests back off.
se_t_assert( se_t_pro()->get_server_retry_in() > 0, 'an edge challenge opens the failure breaker' );

se_t_call( $license, 'set_license', [] );
