<?php
/**
 * Plugin Name: SE SDK Test Fixture (free)
 * Description: Test-only consumer of the StoreEngine SDK. Used by tests/run.sh; never shipped.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// The fixture is symlinked into wp-content/plugins; __FILE__ resolves to the repo.
require_once dirname( __DIR__, 3 ) . '/init.php';

add_action( 'plugins_loaded', function () {
	$GLOBALS['se_sdk_fixture_free'] = se_license_init( [
		'package_file'   => WP_PLUGIN_DIR . '/se-sdk-fixture-free/se-sdk-fixture-free.php', // Symlinked: __FILE__ is the repo path.
		'package_name'   => 'SE SDK Fixture free',
		'slug'           => 'se-sdk-fixture-free',
		'product_id'     => 9002,
		'is_free'        => true,
		'use_update'     => true,
		'license_server' => 'https://license.example.test/',
		'init_insights'  => false,
		'init_promotions'=> true,
		'allow_local'    => true,
		'menu'           => 'admin.php?page=se-sdk-fixture-free',
		
	] );
}, 5 );
