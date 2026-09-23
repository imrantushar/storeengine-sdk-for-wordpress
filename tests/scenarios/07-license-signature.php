<?php
// Old-format signatures migrate; a lost cron event no longer kills the license.
$license = se_t_pro()->license();
$data = [ 'license' => 'TEST-KEY-123', 'status' => 'active', 'activation_id' => 1, 'product_id' => 9001, 'slug' => 'se-sdk-fixture-pro' ];
se_t_call( $license, 'set_license', $data );
wp_schedule_event( time() + 3600, 'daily', se_t_pro()->getHookName( 'license_check_event' ) );
// Rewrite the stored signature the way <= 1.5.9 made it.
$legacy = se_t_call( $license, 'sign', se_t_call( $license, 'legacy_license_signature_payload' ) );
se_t_pro()->set_option( 'license_signature', $legacy );
se_t_pro()->save_software_data();
$fresh = function () use ( $license ) { ( function () { $this->is_valid_license = null; } )->call( $license ); return $license->is_valid(); };
se_t_assert( $fresh(), 'old-format signature still accepted' );
se_t_assert( $legacy !== se_t_pro()->get_option( 'license_signature' ), '…and re-signed in the new format' );
wp_clear_scheduled_hook( se_t_pro()->getHookName( 'license_check_event' ) );
se_t_assert( $fresh(), 'license stays valid after its cron event is removed' );
// Old-format signature AND lost cron event: background re-check gets scheduled.
se_t_pro()->set_option( 'license_signature', $legacy );
delete_site_transient( se_t_pro()->getHookName( 'license_recheck' ) . '_tried' );
se_t_assert( ! $fresh(), 'unverifiable signature → not valid for now' );
se_t_assert( (bool) wp_next_scheduled( se_t_pro()->getHookName( 'license_recheck' ) ), '…and a background re-check is scheduled' );
se_t_reset_hits();
do_action( se_t_pro()->getHookName( 'license_recheck' ) );
se_t_assert( 1 === count( se_t_hits( 'check-license' ) ) && $fresh(), 're-check with the server restores the license' );
se_t_call( $license, 'set_license', [] );
