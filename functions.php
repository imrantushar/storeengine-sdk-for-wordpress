<?php
/**
 * Helper Functions.
 */

if ( ! function_exists( 'se_license_init' ) ) {
	/**
	 * Initialize the SDK.
	 *
	 * @param array{
	 * package_file: string,
	 * package_name: string,
	 * product_id: int,
	 * is_free?: bool,
	 * use_update?: bool,
	 * slug: string,
	 * basename: string,
	 * package_type: string,
	 * package_version: string,
	 * allow_local?: bool,
	 * license_server: string,
	 * activation_prompt?: null|string,
	 * purchase_url?: string,
	 * store_dashboard_url?: string,
	 * product_logo?: string,
	 * init_restapi?: bool,
	 * menu: false|string|array{
	 *        type: string,
	 *        parent_slug: string,
	 *        page_title: string,
	 *        menu_title: string,
	 *        capability: string,
	 *        menu_slug: string,
	 *        icon_url: string,
	 *        position: int,
	 * },
	 * init_insights?: bool,
	 * should_show_optin?: bool,
	 * first_install_time?: int,
	 * optin_notice_delay?: int,
	 * data_being_collected?: array,
	 * terms_url?: string,
	 * privacy_policy_url?: string,
	 * support_url?: string,
	 * support_ticket_response?: string,
	 * support_ticket_error_response?: string,
	 * ticket_template?: string,
	 * ticket_recipient?: string,
	 * init_promotions?: bool,
	 * promo_cache_ttl?: int,
	 * promo_source?: string
	 * } $args
	 *
	 * @return SE_License_SDK_Client
	 */
	function se_license_init( array $args ): SE_License_SDK_Client {
		$args = wp_parse_args( $args, [
			'package_file'                  => '',
			'package_name'                  => '',
			'product_id'                    => 0,
			'is_free'                       => null,
			'use_update'                    => null, // is_org_compliant
			'slug'                          => null,
			'basename'                      => null,
			'package_type'                  => null,
			'package_version'               => null,
			'allow_local'                   => true,
			'license_server'                => null,
			'activation_prompt'             => null,
			'purchase_url'                  => null,
			'store_dashboard_url'           => null,
			'product_logo'                  => '',
			'init_restapi'                  => false,
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
			'should_show_optin'             => true,
			'first_install_time'            => null,
			'optin_notice_delay'            => null, // Default 3 * DAY_IN_SECONDS
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
