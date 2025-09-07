<?php

/**
 * Class License
 */
final class SE_License_SDK_License {

	/**
	 * Client.
	 *
	 * @var SE_License_SDK_Client
	 */
	protected $client;

	/**
	 * Unique string for handling post data array for a instance.
	 * @var string
	 */
	protected $data_key;

	/**
	 * Flag for checking if the init method is already called.
	 *
	 * @var bool
	 */
	private $did_init = false;

	/**
	 * Arguments of create menu.
	 *
	 * @var array
	 */
	protected $menu_args;

	/**
	 * `option_name` of `wp_options` table.
	 *
	 * @var string
	 */
	protected $option_key;

	/**
	 * Error message of HTTP request.
	 *
	 * @var string
	 */
	protected $error;

	/**
	 * Success message on form submit.
	 *
	 * @var string
	 */
	protected $success;

	/**
	 * Corn schedule hook name.
	 *
	 * @var string
	 */
	protected $schedule_hook;

	/**
	 * Set value for valid license.
	 *
	 * @var boolean
	 */
	private $is_valid_license = null;

	/**
	 * The license data.
	 *
	 * @var ?array
	 */
	protected $license = null;

	/**
	 * Current User Permission for managing License.
	 *
	 * @var bool
	 */
	protected $userCapability = false;

	/**
	 * Is Current Page is the license manage page.
	 *
	 * @var bool
	 */
	protected $is_license_page = false;

	protected $header_icon_url = false;

	protected $manage_license_url = null;

	/**
	 * Product Purchase Page URL
	 * @var string
	 */
	protected $purchase_url = '';

	private $updating_license = false;

	protected $page_url = null;

	protected $remove_header = false;

	protected $use_custom_style = false;

	/**
	 * @var string|null
	 */
	protected $header_message = null;

	/**
	 * @var string|null
	 */
	protected $header_content = null;

	/**
	 * Initialize the class.
	 *
	 * @param SE_License_SDK_Client $client The Client.
	 */
	public function __construct( SE_License_SDK_Client $client ) {
		$this->client        = $client;
		$this->option_key    = $this->client->getHookName( 'manage_license' );
		$this->data_key      = $this->client->getHookName( 'license' );
		$this->schedule_hook = $this->client->getHookName( 'license_check_event' );

		// Load the license.
		$this->get_license();
	}

	public function set_page_url( $url ): SE_License_SDK_License {
		$this->page_url = esc_url_raw( $url );

		return $this;
	}

	public function get_page_url(): string {
		if ( null !== $this->page_url ) {
			return $this->page_url;
		}

		return admin_url( 'admin.php?page=' . $this->menu_args['menu_slug'] );
	}

	public function set_header_message( string $message = null ): SE_License_SDK_License {
		$this->header_message = $message;

		return $this;
	}

	public function set_header_content( string $message = null ): SE_License_SDK_License {
		$this->header_content = $message;

		return $this;
	}

	public function set_manage_license_url( string $url = null ): SE_License_SDK_License {
		$this->manage_license_url = $url;

		return $this;
	}

	public function set_purchase_url( string $url = null ): SE_License_SDK_License {
		$this->purchase_url = $url;

		return $this;
	}

	public function use_custom_style(): SE_License_SDK_License {
		$this->use_custom_style = true;

		return $this;
	}

	private function updating_license( bool $status ): void {

		// Set initial flag.
		$this->updating_license = $status;

		if ( $status ) {
			set_transient( $this->option_key . '_is_updating_license', 'yes', 20 );
		} else {
			delete_transient( $this->option_key . '_is_updating_license' );
		}

		// Method Chain.
	}

	private function is_updating_license(): bool {
		return $this->updating_license || 'yes' === get_transient( $this->option_key . '_is_updating_license' );
	}

	/**
	 * Initialize License.
	 *
	 * @return void
	 */
	public function init() {

		add_action( 'init', [ $this, 'handle_license_page_form' ] );

		if ( null === $this->menu_args ) {
			$this->set_menu_args();
		}

		// Run hook to check license status daily.
		add_action( $this->schedule_hook, [ $this, 'check_license_status' ] );
		$this->userCapability  = $this->menu_args['capability'];
		$this->is_license_page = isset( $_GET['page'] ) && $_GET['page'] === $this->menu_args['menu_slug']; // phpcs:ignore

		if ( $this->client->isPlugin() ) {
			add_action( 'plugin_action_links_' . $this->client->getBasename(), [ $this, 'plugin_action_links' ] );
		}

		add_action( 'admin_notices', [ $this, '__admin_notices' ] );

		// Activation/Deactivation hooks.
		$this->activation_deactivation();

		// Check the validity and save the state. (check after cron scheduled)
		$this->is_valid();

		// Set Did Init Flag
		$this->did_init = true;
	}

	/**
	 * Expose the License Key.
	 *
	 * @return string
	 */
	public function get_key(): string {
		$this->get_license();

		return $this->license['license'] ?? '';
	}

	/**
	 * Display Admin Notices.
	 *
	 * @return void
	 */
	public function __admin_notices() {
		if ( ! current_user_can( $this->userCapability ) ) {
			return;
		}

		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL === true ) {
			$host = wp_parse_url( $this->client->getLicenseserver(), PHP_URL_HOST );
			if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) || ( defined( 'WP_ACCESSIBLE_HOSTS' ) && false === stristr( WP_ACCESSIBLE_HOSTS, $host ) ) ) {
				?>
				<div class="notice notice-error">
					<p><?php
						printf(
						/* translators: 1: Warning in bold tag, 2: This plugin name, 3: API Host Name, 4: WP_ACCESSIBLE_HOSTS constant */
							esc_html__( '%1$s You\'re blocking external requests which means you won\'t be able to get %2$s updates. Please add %3$s to %4$s constant.', 'storeengine-sdk' ),
							'<b>' . esc_html__( 'Warning!', 'storeengine-sdk' ) . '</b>',
							esc_html( $this->client->getPackageName() ),
							'<strong>' . esc_html( $host ) . '</strong>',
							'<code>WP_ACCESSIBLE_HOSTS</code>'
						);
						?></p>
				</div>
				<?php
			}
		}

		$this->inactive_license_notice();

		if ( ! empty( $this->error ) ) {
			?>
			<div class="notice notice-error notice-alt is-dismissible">
				<?php echo wp_kses_post( wpautop( $this->error ) ); ?>
			</div>
			<?php
		}

		if ( ! empty( $this->success ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<?php echo wp_kses_post( wpautop( $this->success ) ); ?>
			</div>
			<?php
		}
	}

	protected function inactive_license_notice() {
		if ( ! $this->is_license_page && ! $this->is_valid() && ! $this->is_updating_license() ) {
			?>
			<div class="se-sdk-license-notice notice updated">
				<span>
					<?php
					printf(
					/* translators: 1: This plugin name, 2: Activation Page URL, 3: This Plugin Name */
						esc_html__( 'The %1$s license key has not been activated, so some features are inactive! %2$s to activate %3$s.', 'storeengine-sdk' ),
						'<b class="highlight">' . esc_attr( $this->client->getPackageName() ) . '</b>',
						'<a href="' . esc_url( $this->get_page_url() ) . '">' . esc_html__( 'Click here', 'woo-feed' ) . '</a>',
						'<strong>' . esc_attr( $this->client->getPackageName() ) . '</strong>'
					);
					?>
				</span>
			</div>
			<style>
                .se-sdk-license-notice {
                    color: #141A24;
                    padding: 24px !important;
                    border-left-color: <?php $this->client->printPrimaryColor(); ?> !important;
                    border-width: 0 !important;
                    border-left-width: 4px !important;
                }

                .se-sdk-license-notice span {
                    font-size: 14px;
                    font-style: normal;
                    font-weight: 600;
                    line-height: 20px;
                }

                .se-sdk-license-notice a,
                .se-sdk-license-notice .highlight {
                    background-color: transparent;
                    color: <?php $this->client->printPrimaryColor(); ?>;
                }

                .se-sdk-license-notice a:focus {
                    box-shadow: 0 0 0 2px <?php $this->client->printPrimaryColor(); ?>;
                }
			</style>
			<?php
		}
	}

	/**
	 * Setup plugin action link to the license page.
	 *
	 * @param array $links plugin action links.
	 *
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {

		$age_url = $this->get_page_url();

		if ( ! empty( $age_url ) ) {
			array_unshift( $links, '<a href="' . esc_url( $age_url ) . '">' . esc_html__( 'Activate License', 'storeengine-sdk' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * Check license.
	 *
	 * @return array
	 */
	public function check(): array {
		return $this->request( 'status', $this->license );
	}

	/**
	 * Check Plugin Update.
	 *
	 * @return array
	 */
	public function check_update(): array {
		return $this->request( 'update', $this->license );
	}

	/**
	 * Get Plugin data.
	 *
	 * @return array {
	 *     Plugin Information
	 * @type bool $success API response status
	 * @type string $api_call_execution_time API Man (Rest Response) Execution Time
	 * @type array $data {
	 *         Plugin Data ( API Man.)
	 * @type array $package {
	 * @type int $product_id API Man Product ID
	 *         }
	 * @type array $info {
	 * @type string $id Plugin Id
	 * @type string $name Plugin Name
	 * @type string $author Author Name
	 * @type string $author_profile Author Profile URL
	 * @type string $slug Plugin Slug
	 * @type string $plugin Plugin main file path
	 * @type string $new_version New Version String
	 * @type string $url Plugin URL
	 * @type string $package Plugin update download URL
	 * @type string $icons Plugin Icons
	 * @type string $banners Plugin Banners
	 * @type string $banner_rtl RTL Version of Plugin Banners
	 * @type string $upgrade_notice Upgrade Notice
	 * @type string $requires Minimum WordPress Version
	 * @type string $requires_php Minimum PHP Version
	 * @type string $tested Tested upto WordPress Version
	 * @type array $compatibility Compatibility information (API Man sends string)
	 * @type array $contributors Plugin Contributors List (if available)
	 * @type array $ratings Plugin Rating (if available)
	 * @type float $num_ratings Plugin Rating (if available)
	 * @type string $last_updated Last updated Date
	 * @type string $homepage Plugin Home Page URL
	 * @type array $sections {
	 *                 Plugin Description Sections
	 * @type string $description Plugin Description
	 * @type string $changelog Change LOG
	 *             }
	 * @type mixed $author_block_count
	 * @type mixed $author_block_rating
	 *         }
	 *     }
	 * }
	 */
	public function get_information(): array {
		return $this->request( 'information', $this->license );
	}

	/**
	 * Active a license.
	 *
	 * @param array $license license data.
	 *
	 * @return array
	 */
	public function activate( array $license ): array {
		return $this->request( 'activate', $license );
	}

	/**
	 * Deactivate current license.
	 *
	 * @return array
	 */
	public function deactivate(): array {
		return $this->request( 'deactivate', $this->license );
	}

	/**
	 * Send common request.
	 *
	 * @param string $action request action.
	 * @param array $license license data.
	 *
	 * @return array
	 */
	protected function request( string $action, array $license = [] ): array {
		$actions = [
			'activate'    => 'activate-license',
			'deactivate'  => 'deactivate-license',
			'status'      => 'check-license',
			'information' => 'package-info',
			'update'      => 'check-update',
		];

		if ( ! in_array( $action, array_keys( $actions ) ) ) {
			return [
				'success' => false,
				'error'   => __( 'Invalid Request Action.', 'storeengine-sdk' ),
			];
		}

		// parse license data
		$license = wp_parse_args( $license, $this->get_license() );

		// validate license data.
		if ( ! $this->validate_license_data( $license ) ) {
			return [
				'success' => false,
				'error'   => __( 'Invalid/Empty License Data.', 'storeengine-sdk' ),
			];
		}

		$response = $this->client->request( [
			'body'     => array_merge( $license, $this->client->get_admin_info() ),
			'route'    => $actions[ $action ],
			'blocking' => true
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
				'code'    => $response->get_error_code(),
				'data'    => $response->get_error_data( $response->get_error_code() ),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$body = json_decode( $body, true );

		if ( 201 === $code && ! $response ) {
			return [
				'success' => true,
				'message' => $body['message'] ?? __( 'Operation successful.', 'storeengine-sdk' ),
				'data'    => []
			];
		}

		if ( $code && $code >= 400 ) {
			return [
				'success' => false,
				'error'   => $body['message'] ?? __( 'Unknown error.', 'storeengine-sdk' ),
				'code'    => $body['code'] ?? 'UNKNOWN_ERROR',
				'data'    => $body['data'] ?? [],
			];
		}

		$message = $body['message'] ?? __( 'Operation successful.', 'storeengine-sdk' );

		unset( $body['message'] );

		return [
			'success' => true,
			'message' => $message,
			'data'    => $body,
		];
	}

	public function set_menu_args( $args = [] ): SE_License_SDK_License {
		$this->menu_args = wp_parse_args(
			$args,
			[
				'type'        => 'submenu', // Can be: menu, options, submenu.
				'menu_title'  => $this->client->getPackageName(),
				'page_title'  => sprintf(
				/* translators: 1. Theme/Plugin Name. */
					esc_html__( '%s License Management', 'storeengine-sdk' ),
					esc_html( $this->client->getPackageName() )
				),
				'capability'  => 'manage_options',
				'menu_slug'   => 'manage-' . $this->client->getSlug() . '-license',
				'icon_url'    => 'dashicons-admin-network',
				'position'    => null,
				'parent_slug' => 'options-general.php',
			]
		);

		return $this;
	}

	/**
	 * Add settings page for license.
	 *
	 * @param array $args settings for rendering the menu.
	 *
	 * @return $this
	 */
	public function add_settings_page(): SE_License_SDK_License {
		if ( $this->did_init ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
				/* translators: 1. Class Method. */
					__( '%s Should be called before License::init()', 'storeengine-sdk' ),
					'<code>' . __METHOD__ . '</code>'
				),
				'1.0.0'
			);

			return $this;
		}

		if ( ! in_array( $this->menu_args['type'], [ 'menu', 'options', 'submenu' ], true ) ) {
			$this->menu_args['type'] = $this->menu_args['parent_slug'] ? 'submenu' : 'menu';
		}

		if ( 'submenu' === $this->menu_args['type'] && ! $this->menu_args['parent_slug'] ) {
			$this->menu_args['type'] = 'options';
		}

		if ( 'menu' === $this->menu_args['type'] && $this->menu_args['parent_slug'] ) {
			$this->menu_args['type'] = 'submenu';
		}

		add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 999 );

		return $this;
	}

	/**
	 * Admin Menu hook.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		switch ( $this->menu_args['type'] ) {
			case 'submenu':
				$this->add_submenu_page();
				break;
			case 'options':
				$this->add_options_page();
				break;
			case 'menu':
			default:
				$this->add_menu_page();
				break;
		}
	}

	/**
	 * License menu output.
	 *
	 * @return void
	 */
	public function render_menu_page() {
		?>
		<div class="wrap se-sdk-license-settings-wrapper">
			<h1 class="wp-heading-inline"><?php printf(
				/* translators: 1. Theme/Plugin Name. */
					esc_html__( '%s License Management', 'storeengine-sdk' ),
					esc_html( $this->client->getPackageName() )
				); ?></h1>
			<hr class="wp-header-end">
			<?php $this->render_license_page(); ?>
		</div>
		<?php
	}

	public function render_license_page() {
		$this->licenses_style();

		$status       = isset( $this->license['status'] ) && 'active' === $this->license['status'] ? 'active' : 'inactive';
		$action       = 'active' === $status ? 'deactivate' : 'activate';
		$submit_label = 'activate' === $action ? __( 'Activate License', 'storeengine-sdk' ) : __( 'Deactivate License', 'storeengine-sdk' );
		$status_label = 'active' === $status ? __( 'Active', 'storeengine-sdk' ) : __( 'Inactive', 'storeengine-sdk' );
		$isUnlimited  = (bool) ( $this->license['unlimited'] ?? false );
		$remaining    = absint( $this->license['remaining'] ?? 0 );

		if ( ! $this->header_message ) {
			$this->header_message = sprintf(
			/* translators: %s: Plugin Name */
				esc_html__( 'Active %s license to get professional support and automatic update from your WordPress dashboard.', 'storeengine-sdk' ),
				'<strong>' . esc_html( $this->client->getPackageName() ) . '</strong>'
			);
		}

		if ( ! $this->header_content ) {
			$this->header_content = sprintf(
			/* translators: %s: Plugin Name */
				esc_html__( 'Activate %s to unlock automatic updates, priority support, and all tools to optimize your WordPress store.', 'storeengine-sdk' ),
				'<strong>' . esc_html( $this->client->getPackageName() ) . '</strong>'
			);
		}

		do_action( $this->client->getHookName( 'before_license_section' ), $action );

		include_once __DIR__ . '/../views/license-form.php';

		do_action( $this->client->getHookName( 'after_license_section' ) );
	}

	/**
	 * License form submit.
	 *
	 * @return void
	 */
	public function handle_license_page_form() {
		$check_key = $this->client->getSlug() . '-check-license';
		if ( isset( $_GET[ $check_key ] ) && wp_verify_nonce( sanitize_text_field( $_GET[ $check_key ] ), $this->client->getSlug() ) ) {
			$this->check_license_status();
			wp_safe_redirect( $this->get_page_url() );
			die();
		}

		if ( isset( $_POST[ $this->data_key ], $_POST[ $this->data_key ]['_action'] ) ) {
			check_admin_referer( $this->data_key );
			switch ( $_POST[ $this->data_key ]['_action'] ) {
				case 'activate':
					$this->activate_client_license( array_map( 'sanitize_text_field', $_POST[ $this->data_key ] ) );
					break;
				case 'deactivate':
					$this->deactivate_client_license();
					break;
				default:
					break;
			}
		}
	}

	/**
	 * Check license status on schedule.
	 * Check and update license status on db.
	 *
	 * @return void
	 */
	public function check_license_status() {
		$license = $this->get_license();

		if ( empty( $license['license'] ) || 'inactive' === $license['status'] ) {
			$this->clear_license_check_schedule();

			return;
		}

		if ( $license ) {
			$this->updating_license( true );
			// Get current license data.

			// check license.
			$response = $this->check();

			if ( isset( $response['success'] ) && $response['success'] ) {
				// Update license status.
				$license = wp_parse_args( $response['data'], $license );
			} else {
				// Don't reset the key.
				// keep it, if the user renew subscription update the status and reactivate the plugin.
				$license = wp_parse_args(
					[
						'license'     => '',
						'status'      => 'inactive',
						'device_id'   => $this->client->get_device_id(),
						'slug'        => $this->client->getSlug(),
						'product_id'  => $this->client->getProductId(),
						'remaining'   => 0,
						'activations' => 0,
						'limit'       => 0,
						'unlimited'   => false,
						'expires'     => '',
					],
					$license
				);
			}

			// Update the license state & and save in db.
			$this->set_license( $license );

			$this->updating_license( false );
		}

	}

	/**
	 * Check this is a valid license.
	 *
	 * @param array $license Optional. A license instance to check.
	 *                       Default blank.
	 *                       If blank it will test against license from database..
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		if ( null !== $this->is_valid_license ) {
			return $this->is_valid_license;
		}

		// Load the license if already not loaded.
		$license = $this->get_license();

		if ( isset( $license['license'], $license['device_id'], $license['product_id'], $license['status'] ) && 'active' === $license['status'] ) {
			$this->is_valid_license = $this->validate_license_signature();
		} else {
			$this->is_valid_license = false;
		}

		return $this->is_valid_license;
	}

	/**
	 * Validate license data for request.
	 *
	 * @param array $license license data.
	 *
	 * @return bool
	 */
	public function validate_license_data( array $license = [] ): bool {
		$license = $this->parse_license_data( $license );

		return (
			! empty( $license['license'] ) &&
			! empty( $license['device_id'] ) &&
			//			! empty( $license['activation_id'] ) &&
			! empty( $license['slug'] ) &&
			! empty( $license['product_id'] )
		);
	}

	/**
	 * Styles for licenses page.
	 *
	 * @return void
	 */
	private function licenses_style() {
		?>
		<!--suppress CssUnusedSymbol -->
		<style>
            .se-sdk-license-settings * {
                box-sizing: border-box
            }

            .se-sdk-license-settings button {
                -moz-user-select: none;
                -ms-user-select: none;
                -webkit-user-select: none;
                user-select: none;
            }

            .se-sdk-license-settings a,
            .se-sdk-license-settings h1,
            .se-sdk-license-settings h2,
            .se-sdk-license-settings h3,
            .se-sdk-license-settings h4,
            .se-sdk-license-settings input,
            .se-sdk-license-settings select,
            .se-sdk-license-settings i,
            .se-sdk-license-settings span:not(.dashicons),
            .se-sdk-license-settings div,
            .se-sdk-license-settings p {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            }

            .se-sdk-license-settings a,
            .se-sdk-license-settings .highlight {
                background-color: transparent;
				text-decoration: none;
                color: <?php $this->client->printPrimaryColor(); ?>;
            }

            .se-sdk-license-settings a:focus {
                box-shadow: 0 0 0 2px <?php $this->client->printPrimaryColor(); ?>;
            }

            .se-sdk-license-settings {
                margin-top: 20px;
                background-color: #fff;
                -webkit-box-shadow: 0 3px 10px rgba(16, 16, 16, .05);
                box-shadow: 0 3px 10px rgba(16, 16, 16, .05)
            }

            .se-sdk-license-section {
                width: 100%;
                min-height: 1px;
                box-sizing: border-box;
                display: flex;
                padding: 32px 48px 48px 48px;
                flex-direction: column;
                align-items: center;
                gap: 24px;
                border-radius: 4px;
                background: #FFF;
                box-shadow: 0 0.5px 2px 0 rgba(16, 24, 40, 0.15);
            }

            .se-sdk-license-title {
                background-color: #f8fafb;
                border-bottom: 2px solid #eaeaea;
                display: -webkit-box;
                display: -ms-flexbox;
                display: flex;
                -webkit-box-align: center;
                -ms-flex-align: center;
                align-items: center;
                padding: 10px 20px
            }

            .se-sdk-license-title img,
            .se-sdk-license-title svg.default-icon {
                width: auto;
                max-width: 160px;
                height: 30px;
                /*fill: #0082bf;*/
                fill: <?php $this->client->printPrimaryColor(); ?>;
            }

            .se-sdk-license-title h2 {
                margin: 10px;
                color: #1d2327
            }


            .se-sdk-license-details {
                display: flex;
                flex-direction: column;
                align-items: center;
            }

			.se-sdk-license-details-contents {
                color: #141A24;
                text-align: center;
			}

            .se-sdk-license-details h3 {
                font-size: 20px;
                font-weight: 500;
                margin: 0;
            }

            .se-sdk-license-details p {
                color: #738496;
                font-size: 14px;
                font-weight: 400;
                line-height: 20px;
                margin-top: 8px
            }

            .se-sdk-license-details--middle-icons {
                display: flex;
                padding: 12px 24px;
                align-items: center;
                gap: 24px;
                border-radius: 9999px;
                border: 1px solid #CBD1D7;
                margin-top: 24px;
            }

            .se-sdk-license-form-wrapper {
                width: 100%;
                max-width: 840px;
			}

            .se-sdk-license-purchase-prompt {
                justify-content: center;
                align-items: center;
                display: flex;
            }
            .se-sdk-license-purchase-prompt p {
                margin: 0;
                font-size: 1em;
			}

            .se-sdk-license-fields {
                display: flex;
                -webkit-box-pack: justify;
                -ms-flex-pack: justify;
                justify-content: space-between;
                margin: 20px 0;
                width: 100%;
				gap: 16px;
            }

            .se-sdk-license-fields .input-group {
                position: relative;
                -webkit-box-flex: 0;
                -ms-flex: 1 1 82%;
                flex: 1 1 82%;
                max-width: 82%
            }

            .se-sdk-license-fields .input-group input {
                background-color: #f9f9f9 !important;
                height: 40px;
                width: 100%;
                padding: 10px 12px;
                border-radius: 4px;
                border: 1px solid #CBD1D7;
            }


            .se-sdk-license-fields .input-group input[readonly],
            .se-sdk-license-fields .input-group select[readonly] {
                cursor: default
            }

            .se-sdk-license-fields .input-group input:focus,
            .se-sdk-license-fields .input-group select:focus {
                outline: 0 none;
                border: 1px solid #e8e5e5;
                box-shadow: 0 0 0 transparent
            }

            .se-sdk-license-fields .input-group .icon-wrap {
                height: 39px;
                position: absolute;
                left: 2px;
                top: -4px;
                z-index: 1;
                margin-right: 8px;
            }

            .se-sdk-license-fields .input-group svg {
                fill: #000000;
                width: 16px;
                height: 16px;
            }

            .se-sdk-license-fields .input-group .license-input.code {
                width: 100%
            }

            .se-sdk-license-fields .input-group .license-input.product-id {
                width: 225px;
                margin-left: 15px
            }

            .se-sdk-license-fields .activate-button,
            .se-sdk-license-fields .dashboard-button,
            .se-sdk-license-fields .deactivate-button {
                padding: 10px 16px;
                border-radius: 4px;
                border: none;
                background: <?php $this->client->printPrimaryColor(); ?>;
                white-space: nowrap;
                color: #FFF;
                font-size: 14px;
                font-weight: 500;
                line-height: 20px;
                cursor: pointer;
                outline: none;
				text-decoration: none;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            .se-sdk-license-fields button.deactivate-button {
                background: #f02e5e;
            }

            .se-sdk-license-fields .dashboard-button:focus,
            .se-sdk-license-fields .activate-button:focus {
                box-shadow: 0 0 0 1px #fff, 0 0 0 3px <?php $this->client->printPrimaryColor(); ?>;
            }

            .se-sdk-license-fields button.deactivate-button:focus {
                box-shadow: 0 0 0 1px #fff, 0 0 0 3px #f02e5e;
            }

            .button-license-manage {
                margin-left: 20px;
                font-size: 17px;
                line-height: 2.5;
            }

            .active-license-info {
                display: flex;
                /*align-items: center;*/
                align-items: flex-start;
                justify-content: space-between;
            }

            .single-license-info {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
            }

            .single-license-info h3 {
                color: #738496;
                font-size: 12px;
                font-weight: 400;
                line-height: 16px;
                margin: 0;
            }

            .single-license-info p {
                color: #0C3140;
                font-size: 12px;
                font-weight: 500;
                line-height: 16px;
                margin: 0;
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 2px;
            }

			.single-license-info.license-checked_at a {
                color: currentColor;
				text-decoration: none;
				display: inline-flex;
				justify-content: center;
				align-items: center;
			}
			.single-license-info.license-checked_at a .dashicons {
                font-size: 13px;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 14px;
                aspect-ratio: 1;
                height: auto;
			}

            @media (max-width: 768px) {
                .se-sdk-license-section {
                    display: block;
                    padding: 16px 24px 24px 24px;
                }

                .se-sdk-license-form-wrapper {
                    max-width: 425px;
                }

                .se-sdk-license-fields {
                    flex-direction: column;
                    align-items: center;
                    gap: 16px;
                }

                .se-sdk-license-fields .input-group {
                    position: relative;
                    -webkit-box-flex: 0;
                    -ms-flex: 1 1 82%;
                    flex: 1 1 82%;
                    max-width: 100%;
                    width: 100%;
                }

                .se-sdk-license-fields .input-group input {
                    width: 100% !important;
                }

                .active-license-info {
                    flex-direction: column;
                    align-items: normal !important;
                }
            }
		</style>
		<?php
	}

	protected function render_last_checked_datetime() {
		if ( ! $this->license['updated_at'] ) {
			return;
		}

		$time_diff = $this->license['updated_at'] - current_time( 'timestamp', true );

		if ( absint( $time_diff ) < WEEK_IN_SECONDS ) {
			// translators: placeholder is human time diff (e.g. "3 weeks")
			printf( __( '%s ago', 'storeengine-sdk' ), human_time_diff( current_time( 'timestamp', true ), $this->license['updated_at'] ) );
			return;
		}

		echo esc_html( get_date_from_gmt( '@'.$this->license['updated_at'], 'Y-m-d h:i A (P)' ) );
	}

	protected function render_license_expire_datetime() {
		if ( 'active' !== $this->license['status'] ) {
			$expires  = __( 'N/A', 'storeengine-sdk' );
			$expiring = true;
		} elseif ( empty( $this->license['expires'] ) ) {
			$expires  = __( 'N/A', 'storeengine-sdk' );
			$expiring = false;
		} else {
			$time_diff = $this->license['expires'] - current_time( 'timestamp', true );
			if ( $time_diff > 0 && $time_diff < WEEK_IN_SECONDS ) {
				// translators: placeholder is human time diff (e.g. "3 weeks")
				$expires  = sprintf( __( 'In %s', 'storeengine-sdk' ), human_time_diff( current_time( 'timestamp', true ), $this->license['expires'] ) );
				$expiring = true;
			} elseif ( $time_diff < 0 && absint( $time_diff ) < WEEK_IN_SECONDS ) {
				// translators: placeholder is human time diff (e.g. "3 weeks")
				$expires  = sprintf( __( '%s ago', 'storeengine-sdk' ), human_time_diff( current_time( 'timestamp', true ), $this->license['expires'] ) );
				$expiring = true;
			} else {
				$expires = get_date_from_gmt( '@'.$this->license['expires'], 'Y-m-d h:i A (P)' );
				$expiring = false;
			}
		}
		?>
		<p class="<?php echo ! $expiring ? 'active' : 'inactive'; ?>"><?php echo esc_html( $expires ); ?></p>
		<?php
	}

	public function set_header_icon( ?string $url ): SE_License_SDK_License {
		$this->header_icon_url = $url ? esc_url_raw( $url ) : null;

		return $this;
	}

	public function remove_header(): SE_License_SDK_License {
		$this->remove_header = true;

		return $this;
	}

	/**
	 * Card header.
	 *
	 * @return void
	 */

	/**
	 * Active client license.
	 *
	 * @param array $postData Sanitized Form $_POST Data.
	 *
	 * @return void
	 */
	private function activate_client_license( array $postData ) {

		$this->updating_license( true );

		if ( empty( $postData['license_key'] ) ) {
			$this->error = __( 'The license key field is required.', 'storeengine-sdk' );

			return;
		}

		$license   = $this->get_license();
		$updateKey = $this->validate_license_data( $license ) && $postData['license_key'] !== $license['license']; // Check if it's a change request.

		if ( $updateKey ) {
			// Deactivate Previous.
			$deactivate = $this->deactivate(); // deactivate first.
			if ( ! $deactivate['success'] ) {
				$check = $this->check(); // Check api status.
				if ( $check['success'] && isset( $check['data']['activated'] ) && $check['data']['activated'] ) {
					if ( $deactivate['error'] ) {
						$this->error = $deactivate['error'];
					} else {
						$this->error = __( 'Unknown error occurred.', 'storeengine-sdk' );
					}

					return;
				}
			}
		}

		// Set new license info.
		$license['license']   = $postData['license_key'];
		$license['device_id'] = $this->client->get_device_id();

		// Activate The License.
		$response = $this->activate( $license );

		if ( ! $response['success'] ) {
			if ( $response['error'] ) {
				$this->error = $response['error'];
			} else {
				$this->error = __( 'Unknown error occurred.', 'storeengine-sdk' );
			}
		} else {
			if ( ! $updateKey ) {
				$this->success = __( 'License activated successfully.', 'storeengine-sdk' );
			} else {
				$this->success = __( 'License updated successfully.', 'storeengine-sdk' );
			}
		}

		// Don't reset the key.
		// keep it, so if the user renew subscription update the status and reactivate the plugin.

		// Update license status.
		$this->set_license( wp_parse_args( $response['data'], $license ) );

		$this->updating_license( false );

		$this->schedule_license_check();
	}

	/**
	 * deactivate client license.
	 *
	 * @return void
	 */
	private function deactivate_client_license() {
		if ( ! isset( $this->license['license'] ) || empty( $this->license['license'] ) ) {
			$this->error = __( 'License key not found.', 'storeengine-sdk' );
		} else {
			$response = $this->deactivate();
			if ( ! $response['success'] ) {
				// check api status.
				$check = $this->check();
				if ( $check['success'] && isset( $check['data']['activated'] ) && $check['data']['activated'] ) {
					if ( $response['error'] ) {
						$this->error = $response['error'];
					} else {
						$this->error = __( 'Unknown error occurred.', 'storeengine-sdk' );
					}
				}
			}
		}

		$this->clear_license_check_schedule();
		// Reset license data.
		$this->set_license();

		$this->success = __( 'License deactivated successfully.', 'storeengine-sdk' );
	}

	/**
	 * Add license menu page.
	 *
	 * @return void
	 */
	private function add_menu_page() {
		add_menu_page(
			$this->menu_args['page_title'],
			$this->menu_args['menu_title'],
			$this->menu_args['capability'],
			$this->menu_args['menu_slug'],
			[ $this, 'render_menu_page' ],
			$this->menu_args['icon_url'],
			$this->menu_args['position']
		);
	}

	/**
	 * Add submenu page.
	 *
	 * @return void
	 */
	private function add_submenu_page() {
		add_submenu_page(
			$this->menu_args['parent_slug'],
			$this->menu_args['page_title'],
			$this->menu_args['menu_title'],
			$this->menu_args['capability'],
			$this->menu_args['menu_slug'],
			[ $this, 'render_menu_page' ]
		);
	}

	/**
	 * Add submenu page.
	 *
	 * @return void
	 */
	private function add_options_page() {
		add_options_page(
			$this->menu_args['page_title'],
			$this->menu_args['menu_title'],
			$this->menu_args['capability'],
			$this->menu_args['menu_slug'],
			[ $this, 'render_menu_page' ]
		);
	}

	/**
	 * Schedule daily license checker event.
	 *
	 * @return void
	 */
	public function schedule_license_check() {
		if ( ! $this->get_key() ) {
			return;
		}

		if ( ! wp_next_scheduled( $this->schedule_hook ) ) {
			wp_schedule_event( time() + 60, 'daily', $this->schedule_hook );
		}
	}

	/**
	 * Clear any scheduled hook.
	 *
	 * @return void
	 */
	public function clear_license_check_schedule() {
		wp_clear_scheduled_hook( $this->schedule_hook );
	}

	/**
	 * Register Activation And Deactivation Hooks.
	 *
	 * @return void
	 */
	private function activation_deactivation() {
		switch ( $this->client->getType() ) {
			case 'plugin':
				register_activation_hook( $this->client->getBasename(), [ $this, 'schedule_license_check' ] );
				register_deactivation_hook( $this->client->getBasename(), [ $this, 'project_deactivation' ] );
				add_action( 'activated_plugin', [ $this, 'redirect_to_license_page' ], 999, 2 );
				break;
			case 'theme':
				add_action( 'switch_theme', [ $this, 'project_deactivation' ] );
				add_action( 'after_switch_theme', [ $this, 'schedule_license_check' ] );
				add_action( 'after_switch_theme', [ $this, 'redirect_to_license_page' ], 999, 2 );
				break;
		}
	}

	/**
	 * Project Deactivation Callback.
	 *
	 * @return void
	 */
	public function project_deactivation() {
		$this->get_license();
		$this->deactivate_client_license();
	}

	/**
	 * Redirect to the license activation page after plugin/theme is activated.
	 *
	 * @TODO make option for the plugin/theme (which is using this lib) can alter this method with their custom function.
	 *
	 * @param string $param1 Plugin: base file|Theme: old theme name.
	 * @param bool|WP_Theme $param2 Plugin: network wide activation status|Theme: WP_Theme instance of the old theme.
	 *
	 * @return void
	 */
	public function redirect_to_license_page( $param1, $param2 = null ) {

		$canRedirect = false;

		if ( 'plugin' == $this->client->getType() ) {
			$canRedirect = ( $param1 == $this->client->getBasename() );
		}

		if ( 'theme' == $this->client->getType() ) {
			$canRedirect = ( ! get_option( 'theme_switched_via_customizer' ) );
		}

		if ( $canRedirect ) {
			wp_safe_redirect( $this->get_page_url( false ) );
			die();
		}
	}

	/**
	 * Form action URL.
	 */
	private function formActionUrl(): void {
		echo esc_url( $this->get_page_url() );
	}

	/**
	 * Get input license key.
	 *
	 * @param array $license license data.
	 * @param string $action
	 *
	 * @return string
	 */
	private function get_input_license_value( array $license, string $action ): string {
		//phpcs:disable
		if ( 'deactivate' !== $action ) {
			if ( ! empty( $_REQUEST[ $this->data_key ]['license_key'] ) ) {
				return sanitize_text_field( $_REQUEST[ $this->data_key ]['license_key'] );
			}

			return '';
		}
		// phpcs:enabled

		if ( empty( $license['license'] ) ) {
			return '';
		}

		$length = strlen( $license['license'] );
		$size   = max( 8, $length / 8 );
		$size   = $length < $size ? $length / 10 : $size;
		$mid    = $size * 2;
		$mid    = $length > $mid ? $length - $mid : $mid;


		$masked = substr( $license['license'], 0, $size );
		$masked .= str_repeat( '•', $mid );
		$masked .= substr( $license['license'], -1 * $size );

		return $masked;
	}

	private function license_signature_payload(): string {
		/**
		 * Once cron runs it updates the next schedule before running
		 * the scheduled hook, so when we call the update_license_signature
		 * method we get the new time stamp here (wp_next_scheduled).
		 */

		$payload = $this->client->getSlug() . '||' . wp_next_scheduled( $this->schedule_hook );

		return $payload . '||' . implode( '||', array_values( $this->license ) );
	}

	private function update_license_signature() {
		if ( $this->license && is_array( $this->license ) ) {

			$payload   = $this->license_signature_payload();
			$signature = hash_hmac( 'sha256', $payload, $this->hash( $payload ) );

			$this->client->set_option( 'license_signature', $signature );
		}
	}

	private function get_license_signature() {
		return $this->client->get_option( 'license_signature', false );
	}

	private function validate_license_signature(): bool {

		$license_signature = $this->get_license_signature();

		if ( ! $license_signature ) {
			return false;
		}

		// Validate hash.
		$payload   = $this->license_signature_payload();
		$signature = hash_hmac( 'sha256', $payload, $this->hash( $payload ) );

		return hash_equals( $signature, $license_signature );
	}

	/**
	 * Update License Data.
	 * Call this method without license data will deactivate the license (set empty data).
	 *
	 * @param array $license {
	 *     Optional. License Data.
	 *
	 * @type string $key The License Key.
	 * @type string $status Activation Status.
	 * @type int $remaining Remaining Activation.
	 * @type int $activation_limit Number of activation allowed for the license key.
	 * @type int $expires Number of day remaining before the license expires.
	 * }
	 */
	private function set_license( array $license = [] ) {

		// Parse & sanitize.
		$this->license               = $this->parse_license_data( $license );
		$this->license['updated_at'] = current_time( 'timestamp', 1 );

		// Update in db.
		$this->client->set_option( 'license_data', $this->license );

		// Update license signature.
		$this->update_license_signature();
	}

	/**
	 * Get Plugin/Theme License.
	 *
	 * @return array {
	 *     Optional. License Data.
	 * @type string $key The License Key.
	 * @type string $status Activation Status.
	 * @type int $remaining Remaining Activation.
	 * @type int $activation_limit Number of activation allowed for the license key.
	 * @type int $expires Number of day remaining before the license expires.
	 * }
	 */
	public function get_license(): array {

		if ( null !== $this->license ) {
			return $this->license;
		}

		$this->license = $this->client->get_option( 'license_data', false );

		// Initialize blank inactive license data.
		if ( false === $this->license || ! is_array( $this->license ) ) {
			$this->set_license();
		}

		$this->license = $this->parse_license_data( $this->license );

		return $this->license;
	}

	/**
	 * Parse License data.
	 *
	 * @param array $data license data.
	 *
	 * @return array
	 */
	private function parse_license_data( array $data = [] ): array {
		$defaults = [
			'license'       => '',         // License key.
			'status'        => 'inactive', // Current status.
			'activation_id' => 0,
			'device_id'     => $this->client->get_device_id(),         // Instance unique id.
			'slug'          => $this->client->getSlug(),
			'product_id'    => $this->client->getProductId(),
			'remaining'     => 0,          // Remaining activation.
			'activations'   => 0,          // Total activation.
			'limit'         => 0,          //Activation limit.
			'unlimited'     => false,      // Is unlimited activation.
			'expires'       => '',          // Expires set this to a unix timestamp [GMT].
			'updated_at'    => current_time( 'timestamp', 1 ),          // Expires set this to a unix timestamp [GMT].
		];

		// Parse.
		$data    = wp_parse_args( $data, $defaults );
		$license = [];

		$data['updated_at'] = $data['updated_at'] && ! is_numeric( $data['updated_at'] ) ? strtotime( $data['updated_at'] ) : absint( $data['updated_at'] );
		// Sanitize data.
		$license['license']       = sanitize_text_field( $data['license'] );
		$license['status']        = strtolower( $data['status'] ) === 'active' ? 'active' : 'inactive';
		$license['activation_id'] = absint( $data['activation_id'] );
		$license['device_id']     = sanitize_text_field( $data['device_id'] );
		$license['slug']          = sanitize_text_field( $data['slug'] );
		$license['product_id']    = absint( $data['product_id'] ); // Product id can be string too.
		$license['remaining']     = absint( $data['remaining'] );
		$license['activations']   = absint( $data['activations'] );
		$license['limit']         = absint( $data['limit'] );
		$license['unlimited']     = (bool) $data['unlimited'];
		$license['expires']       = $data['expires'] && ! is_numeric( $data['expires'] ) ? strtotime( $data['expires'] ) : absint($data['expires']);
		$license['updated_at']    = $data['updated_at'];

		return $license;
	}

	/**
	 * Gets a form of `wp_hash()` specific to the plugin using license service.
	 *
	 * We cannot use `wp_hash()` because it is defined in `pluggable.php` which is not loaded until after plugins are loaded,
	 * which is too late to verify the recovery mode cookie.
	 *
	 * This tries to use the `AUTH` salts first, but if they aren't valid specific salts will be generated and stored.
	 *
	 * @param string $data Data to hash.
	 *
	 * @return string The hashed $data, or false on failure.
	 *
	 * @see          wp_hash()
	 * @noinspection PhpUndefinedConstantInspection
	 */
	private function hash( string $data ): string {
		if ( ! function_exists( 'wp_generate_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		return wp_hash( $data, 'auth', 'sha256' );
	}

	final public function __clone() {
		trigger_error( 'Singleton. No cloning allowed!', E_USER_ERROR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
	}

	/**
	 * Wakeup.
	 */
	final public function __wakeup() {
		trigger_error( 'Singleton. No serialization allowed!', E_USER_ERROR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
	}
}

// End of file SE_License_SDK_License.php.
