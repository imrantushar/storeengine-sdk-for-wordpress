<?php
/**
 * Plugin Name: SE SDK Test Fixture (pro)
 * Description: Test-only consumer of the StoreEngine SDK. Used by tests/run.sh; never shipped.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// The fixture is symlinked into wp-content/plugins; __FILE__ resolves to the repo.
require_once dirname( __DIR__, 3 ) . '/init.php';

add_action( 'plugins_loaded', function () {
	$GLOBALS['se_sdk_fixture_pro'] = se_license_init( [
		'package_file'   => WP_PLUGIN_DIR . '/se-sdk-fixture-pro/se-sdk-fixture-pro.php', // Symlinked: __FILE__ is the repo path.
		'package_name'   => 'SE SDK Fixture pro',
		'slug'           => 'se-sdk-fixture-pro',
		'product_id'     => 9001,
		'is_free'        => false,
		'use_update'     => true,
		'license_server' => 'https://license.example.test/',
		'init_insights'  => false,
		'init_promotions'=> true,
		'allow_local'    => true,
		'menu'           => 'admin.php?page=se-sdk-fixture-pro',
		'init_restapi'   => true,
	] );
}, 5 );
