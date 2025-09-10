<?php
/**
 * Helper Functions.
 */

if ( ! function_exists( 'se_license_init' ) ) {
	function se_license_init( array $args ): SE_License_SDK_Client {
		$args = wp_parse_args( $args, [
			'package_file'                  => '',
			'package_name'                  => '',
			'product_id'                    => 0,
			'is_free'                       => null,
			'slug'                          => null,
			'basename'                      => null,
			'package_type'                  => null,
			'package_version'               => null,
			'allow_local'                   => true,
			'id'                            => null,
			'license_server'                => null,
			'activation_prompt'             => null,
			'purchase_url'                  => null,
			'store_dashboard_url'           => null,
			'product_logo'                  => '',
			'menu'                          => [
				'type'        => '', // Can be: menu, options, submenu. Default to menu.
				'parent_slug' => '', // Parent menu slug if rending as submenu.
				'page_title'  => '', // Default: Manage License
				'menu_title'  => '', // Default: Manage License
				'capability'  => '', // default: manage_options
				'menu_slug'   => '', // Default: [package-slug]-manage-license
				'icon_url'    => '', // Menu icon (icon image/svg url or dashicons).
				'position'    => null, // Menu render priority. Mostly render after the `Settings` menu.
			],
			// Insight settings
			'init_insights'                 => true,
			'data_being_collected'          => [],
			'terms_url'                     => '',
			'privacy_policy_url'            => '',
			'support_url'                   => '',
			'support_ticket_response'       => '',
			'support_ticket_error_response' => '',
			'ticket_template'               => '', // template file path.
			'ticket_recipient'              => '', // ticket recipient email address.
			// Promo settings.
			'init_promotions'               => true,
			'promo_cache_ttl'               => null, // Default 12 * HOUR_IN_SECONDS.
			'promo_source'                  => null, // Default license server `promotions` route/endpoint.
		] );

		return SE_License_SDK::register( $args['package_file'], $args['package_name'], $args );
	}
}

// End of file functions.php.
