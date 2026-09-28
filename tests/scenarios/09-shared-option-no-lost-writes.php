<?php
// Every product shares one option. A save must not erase what another product
// (or a concurrent request) stored, and a new device_id must survive: losing it
// made the license server record a new installation on every update check.
$option = 'storeengine_sdk_software_data';
$backup = get_option( $option, null );

$fresh = function ( $client ) {
	( function () {
		$this->software_data = null;
		$this->is_dirty      = false;
		$this->dirty_keys    = [];
	} )->call( $client );
};
$stored = function () use ( $option ) {
	wp_cache_flush();

	return (array) get_option( $option, [] );
};
$hash = function ( $client ) {
	return ( function () {
		return $this->package_file_hash;
	} )->call( $client );
};

try {
	// Seed: pro has a device_id, free has none yet.
	update_option( $option, [ $hash( se_t_pro() ) => [ 'device_id' => 'PRO-ID' ] ] );
	$fresh( se_t_pro() );
	$fresh( se_t_free() );

	// Both clients load the same snapshot early in the request.
	se_t_pro()->get_option( 'device_id' );
	se_t_free()->get_option( 'device_id' );

	$free_id = se_t_free()->get_device_id();
	se_t_assert( '' !== $free_id, 'free product generated a device_id' );
	se_t_assert( ( $stored()[ $hash( se_t_free() ) ]['device_id'] ?? '' ) === $free_id, 'new device_id is stored immediately, not at shutdown' );

	// Pro saves from its stale snapshot (it never saw free's id).
	se_t_pro()->set_option( 'tracking_last_send', 123 );
	se_t_pro()->save_software_data();
	$data = $stored();
	se_t_assert( ( $data[ $hash( se_t_free() ) ]['device_id'] ?? '' ) === $free_id, "another product's save keeps the free device_id" );
	se_t_assert( 123 === ( $data[ $hash( se_t_pro() ) ]['tracking_last_send'] ?? null ), "pro's own change is saved" );
	se_t_assert( 'PRO-ID' === ( $data[ $hash( se_t_pro() ) ]['device_id'] ?? '' ), "pro's untouched keys are kept" );

	// A concurrent request changed pro's license data after this one loaded;
	// this request's unrelated change must not roll it back.
	$fresh( se_t_pro() );
	se_t_pro()->get_option( 'device_id' );
	$other = $stored();
	$other[ $hash( se_t_pro() ) ]['license_data'] = [ 'status' => 'active' ];
	update_option( $option, $other );
	se_t_pro()->set_option( 'allow_tracking', 'yes' );
	se_t_pro()->save_software_data();
	$data = $stored();
	se_t_assert( 'active' === ( $data[ $hash( se_t_pro() ) ]['license_data']['status'] ?? '' ), 'a concurrent write to another key survives' );
	se_t_assert( 'yes' === ( $data[ $hash( se_t_pro() ) ]['allow_tracking'] ?? '' ), '…and this request\'s key is saved too' );

	// A client whose snapshot predates another request's new id reuses that id.
	$fresh( se_t_free() );
	se_t_free()->get_option( 'x' );
	$other = $stored();
	$other[ $hash( se_t_free() ) ]['device_id'] = 'FROM-OTHER-REQUEST';
	update_option( $option, $other );
	( function () {
		unset( $this->software_data[ $this->package_file_hash ]['device_id'] );
	} )->call( se_t_free() );
	se_t_assert( 'FROM-OTHER-REQUEST' === se_t_free()->get_device_id(), 'a device_id stored by another request is reused, not replaced' );
} finally {
	null === $backup ? delete_option( $option ) : update_option( $option, $backup );
}
