<?php
// Loaded with `wp --require=`: turns the fixture plugins on for this process
// only, without activating them on the site.
WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) {
	$plugins   = is_array( $plugins ) ? $plugins : [];
	$plugins[] = 'se-sdk-fixture-pro/se-sdk-fixture-pro.php';
	$plugins[] = 'se-sdk-fixture-free/se-sdk-fixture-free.php';

	return array_values( array_unique( $plugins ) );
} );
