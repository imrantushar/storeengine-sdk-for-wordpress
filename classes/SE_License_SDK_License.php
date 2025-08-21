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

	protected $manage_license_url;

	private $updating_license = false;

	protected $page_url;

	protected $remove_header = false;

	protected $use_custom_style = false;

	protected $activation_required;

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

		$this->set_activation_required_message(
		/* translators: %s: Plugin Name */
			sprintf( esc_html__( 'Active %s license to get professional support and automatic update from your WordPress dashboard.', 'absolute-addons' ), '<strong>' . esc_html( $this->client->getPackageName() ) . '</strong>' )
		);
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

	public function set_activation_required_message( string $message ): SE_License_SDK_License {
		$this->activation_required = $message;

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
		add_action( 'plugin_action_links_' . $this->client->getBasename(), [ $this, 'plugin_action_links' ] );

		remove_all_actions( 'admin_notices' );
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
	 * @return void|string
	 */
	public function get_key() {
		$this->get_license();

		return $this->license['license'];
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
							esc_html__( '%1$s You\'re blocking external requests which means you won\'t be able to get %2$s updates. Please add %3$s to %4$s constant.', 'absolute-addons' ),
							'<b>' . esc_html__( 'Warning!', 'absolute-addons' ) . '</b>',
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
			<div class="notice notice-error">
				<p><?php
					printf(
					/* translators: 1: This plugin name, 2: Activation Page URL, 3: This Plugin Name */
						esc_html__( 'The %1$s API Key has not been activated, so some features are inactive! %2$s to activate %3$s.', 'absolute-addons' ),
						'<strong>' . esc_attr( $this->client->getPackageName() ) . '</strong>',
						esc_attr( $this->client->getType() ),
						'<a href="' . esc_url( $this->get_page_url() ) . '">' . esc_html__( 'Click here', 'woo-feed' ) . '</a>',
						'<strong>' . esc_attr( $this->client->getPackageName() ) . '</strong>'
					);
					?></p>
			</div>
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
			$links[] = '<a href="' . esc_url( $age_url ) . '">' . esc_html( $this->menu_args['menu_title'] ) . '</a>';
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
				'error'   => __( 'Invalid Request Action.', 'absolute-addons' ),
			];
		}

		// parse license data
		$license = wp_parse_args( $license, $this->get_license() );

		// validate license data.
		if ( ! $this->validate_license_data( $license ) ) {
			return [
				'success' => false,
				'error'   => __( 'Invalid/Empty License Data.', 'absolute-addons' ),
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
				'message' => $body['message'] ?? __( 'Operation successful.', 'absolute-addons' ),
				'data'    => []
			];
		}

		if ( $code && $code >= 400 ) {
			return [
				'success' => false,
				'error'   => $body['message'] ?? __( 'Unknown error.', 'absolute-addons' ),
				'code'    => $body['code'] ?? 'UNKNOWN_ERROR',
				'data'    => $body['data'] ?? [],
			];
		}

		$message = $body['message'] ?? __( 'Operation successful.', 'absolute-addons' );

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
				'type'        => 'menu', // Can be: menu, options, submenu.
				'menu_title'  => __( 'Manage License', 'absolute-addons' ),
				'page_title'  => sprintf(
				/* translators: 1. Theme/Plugin Name. */
					esc_html__( '%s License Management', 'absolute-addons' ),
					esc_html( $this->client->getPackageName() )
				),
				'capability'  => 'manage_options',
				'menu_slug'   => 'manage-' . $this->client->getSlug() . '-license',
				'icon_url'    => 'dashicons-admin-network',
				'position'    => null,
				'parent_slug' => '',
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
					__( '%s Should be called before License::init()', 'absolute-addons' ),
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
					esc_html__( '%s License Management', 'absolute-addons' ),
					esc_html( $this->client->getPackageName() )
				); ?></h1>
			<hr class="wp-header-end">
			<?php $this->render_license_page(); ?>
		</div>
		<?php
	}

	public function render_license_page() {
		$this->licenses_style();
		$action = ( isset( $this->license['status'] ) && 'active' == $this->license['status'] ) ? 'deactivate' : 'activate';
		do_action( $this->client->getHookName( 'before_license_section' ) );
		?>
		<div class="se-sdk-license-settings se-sdk-license-section">
			<?php $this->render_page_header(); ?>
			<div class="se-sdk-license-details">
				<?php
				if ( 'activate' == $action && $this->activation_required ) {
					echo wp_kses_post( wpautop( $this->activation_required ) );
				}
				?>
				<?php $this->render_activation_form( $action ); ?>
				<?php $this->render_license_info(); ?>
			</div>
		</div>
		<?php
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

		$this->updating_license( true );
		// Get current license data.
		$license = $this->get_license();

		if ( $license ) {
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
						'expires'     => 0,
					],
					$license
				);
			}

			// Update the license state & and save in db.
			$this->set_license( $license );

		}

		$this->updating_license( false );
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
	public function is_valid( array $license = [] ): bool {
		if ( null !== $this->is_valid_license ) {
			return $this->is_valid_license;
		}

		// Load the license if already not loaded.
		if ( empty( $license ) ) {
			$license = $this->get_license();
		}

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

            .se-sdk-license-settings {
                margin-top: 20px;
                background-color: #fff;
                -webkit-box-shadow: 0 3px 10px rgba(16, 16, 16, .05);
                box-shadow: 0 3px 10px rgba(16, 16, 16, .05)
            }

            .se-sdk-license-section {
                width: 100%;
                min-height: 1px;
                box-sizing: border-box
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
                fill: #008dff;
            }

            .se-sdk-license-title h2 {
                margin: 10px;
                color: #1d2327
            }

            .se-sdk-license-details {
                padding: 20px
            }

            .se-sdk-license-details p {
                font-size: 15px;
                margin: 0 0 20px 0
            }

            .se-sdk-license-fields .input-group {
                position: relative;
                -webkit-box-flex: 0;
                -ms-flex: 1 1 82%;
                flex: 1 1 82%;
                max-width: 82%
            }

            .se-sdk-license-fields .input-group input,
            .se-sdk-license-fields .input-group select {
                background-color: #f9f9f9 !important;
                padding: 10px 15px 10px 50px;
                border: 1px solid #e8e5e5;
                border-radius: 3px;
                height: 45px !important;
                font-size: 14px;
                line-height: 1;
                color: #71777d;
                width: 100%;
                box-shadow: 0 0 0 transparent;
                text-shadow: none;
            }

            .se-sdk-license-fields .input-group input[readonly],
            .se-sdk-license-fields .input-group select[readonly] {
                cursor: default
            }

            .se-sdk-license-fields .input-group input:focus,
            .se-sdk-license-fields .input-group select:focus {
                outline: 0 none;
                border: 1px solid #e8e5e5;
                -webkit-box-shadow: 0 0 0 transparent;
                box-shadow: 0 0 0 transparent
            }

            .se-sdk-license-fields .input-group .license-input {
                position: relative;
                display: block;
                float: left;
                padding: 0 2px
            }

            .se-sdk-license-fields .input-group .icon-wrap {
                width: 39px;
                height: 39px;
                position: absolute;
                left: 6px;
                top: 3px;
                z-index: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 1.93px;
                background: #fff;
                box-shadow: 0 5px 5px rgba(0, 0, 0, 0.1);
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

            .se-sdk-license-fields {
                display: -webkit-box;
                display: -ms-flexbox;
                display: flex;
                -webkit-box-pack: justify;
                -ms-flex-pack: justify;
                justify-content: space-between;
                margin: 20px 0;
                max-width: 850px;
                width: 100%
            }

            .se-sdk-license-fields button {
                margin-left: 20px;
                color: #645FF5;
                font-size: 15px;
                padding: 8px;
                height: 46px;
                background: transparent;
                border-radius: 3px;
                cursor: pointer;
                -webkit-box-flex: 0;
                -ms-flex: 1 1 18%;
                flex: 1 1 18%;
                max-width: 18%;
                border: 1px solid #645FF5
            }

            .se-sdk-license-fields button.deactivate-button {
                border-color: #f02e5e;
                background: transparent;
                color: #f02e5e;
            }

            .se-sdk-license-fields button:focus {
                outline: 0 none
            }

            .button-license-manage {
                margin-left: 20px;
                font-size: 17px;
                line-height: 2.5;
            }

            .active-license-info {
                display: -webkit-box;
                display: -ms-flexbox;
                display: flex;
                align-items: center;
            }

            .single-license-info {
                margin: 0 70px 5px 0;
                max-width: 290px;
                display: flex;
                align-items: center;
                flex-wrap: wrap;
            }

            .single-license-info h3 {
                font-size: 15px;
                margin: 0 5px 0 0;
                display: inline-block
            }

            .single-license-info p {
                margin: 0;
                font-size: 15px;
                font-weight: 500;
                display: inline-block
            }

            .single-license-info p.active {
                color: #645FF5
            }

            .single-license-info.license-status p {
                font-size: 18px
            }

            .single-license-info.license-status p.active {
                color: #6AD39C
            }

            .single-license-info p.inactive {
                color: #e40055
            }
		</style>
		<?php
	}

	private function render_activation_form( $action ) {
		$submit_label = 'activate' == $action ? __( 'Activate License', 'absolute-addons' ) : __( 'Deactivate License', 'absolute-addons' );
		?>
		<form method="post" action="<?php $this->formActionUrl(); ?>" spellcheck="false" autocomplete="off">
			<?php wp_nonce_field( $this->data_key ); ?>
			<input type="hidden" name="<?php echo esc_attr( $this->data_key ); ?>[_action]" value="<?php echo esc_attr( $action ); ?>">
			<div class="se-sdk-license-fields">
				<div class="input-group">
					<div class="license-input code">
						<span class="icon-wrap" aria-hidden="true">
							<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
						</span>
						<label for="license_key" class="screen-reader-text"><?php esc_html_e( 'License Key', 'absolute-addons' ); ?></label>
						<input class="regular-text" id="license_key" type="text" value="<?php echo esc_attr( $this->get_input_license_value( $this->license ) ); ?>" placeholder="<?php esc_attr_e( 'Enter your license key to activate', 'absolute-addons' ); ?>" name="<?php echo esc_attr( $this->data_key ); ?>[license_key]"<?php wp_readonly( ( 'deactivate' == $action ), true, true ); ?>autocomplete="off" required>
					</div>
				</div>
				<!-- /.se-sdk-license-fields .input-group -->
				<button type="submit" name="<?php echo esc_attr( $this->data_key ); ?>[submit]" class="<?php echo esc_attr( $action ); ?>-button"><?php echo esc_html( $submit_label ); ?></button>
				<?php if ( $this->manage_license_url ) { ?>
					<a href="<?php echo esc_url( $this->manage_license_url ); ?>" class="button button-primary button-license-manage" rel="noopener" target="_blank"><?php esc_html_e( 'Manage License', 'absolute-addons' ); ?></a>
				<?php } ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Show active license information.
	 *
	 * @return void
	 */
	private function render_license_info() {
		$status       = 'active' === $this->license['status'] ? 'active' : 'inactive';
		$remaining    = $this->license['remaining'];
		$status_label = 'active' === $status ? __( 'Active', 'absolute-addons' ) : __( 'Inactive', 'absolute-addons' );
		?>
		<div class="active-license-info">
			<div class="single-license-info-group">
				<div class="single-license-info license-status">
					<h3><?php esc_html_e( 'Status:', 'absolute-addons' ); ?></h3>
					<p class="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></p>
				</div>
				<?php if ( 'inactive' !== $status ) { ?>
					<div class="single-license-info license-checked_at">
						<h3><?php esc_html_e( 'Last Checked:', 'absolute-addons' ); ?></h3>
						<p class="<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $this->license['updated_at'] ); ?>
							<a href="<?php echo esc_url( add_query_arg( [ $this->client->getSlug() . '-check-license' => wp_create_nonce( $this->client->getSlug() ) ], $this->get_page_url() ) ); ?>" style="color: currentColor;text-decoration: none;">
								<span class="dashicons dashicons-update" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Check License Status Now', 'absolute-addons' ); ?></span>
							</a>
						</p>
					</div>
				<?php } ?>
			</div>
			<div class="single-license-info license-expires">
				<h3><?php esc_html_e( 'Expires:', 'absolute-addons' ); ?></h3>
				<?php $this->render_license_expire_datetime(); ?>
			</div>
			<div class="single-license-info-group">
				<div class="single-license-info license-activation-count<?php if ( false !== $this->license['unlimited'] ) {
					echo ' license-unlimited';
				} ?>">
					<h3><?php esc_html_e( 'Activation Remaining:', 'absolute-addons' ); ?></h3>
					<?php if ( 'active' === $status ) { ?>
						<?php if ( false !== $this->license['unlimited'] ) { ?>
							<p class="active">
								<?php
								$unlimited = '<i aria-hidden="true">';
								$unlimited .= '<svg style="top:2px;fill:currentColor;position:relative;" width="15px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512">';
								$unlimited .= '<!-- Font Awesome Free 5.15.4 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free (Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT License) -->';
								$unlimited .= '<path d="M471.1 96C405 96 353.3 137.3 320 174.6 286.7 137.3 235 96 168.9 96 75.8 96 0 167.8 0 256s75.8 160 168.9 160c66.1 0 117.8-41.3 151.1-78.6 33.3 37.3 85 78.6 151.1 78.6 93.1 0 168.9-71.8 168.9-160S564.2 96 471.1 96zM168.9 320c-40.2 0-72.9-28.7-72.9-64s32.7-64 72.9-64c38.2 0 73.4 36.1 94 64-20.4 27.6-55.9 64-94 64zm302.2 0c-38.2 0-73.4-36.1-94-64 20.4-27.6 55.9-64 94-64 40.2 0 72.9 28.7 72.9 64s-32.7 64-72.9 64z"/>';
								$unlimited .= '</svg>';
								$unlimited .= '</i>';
								$unlimited .= '<span class="screen-reader-text">' . esc_html__( 'Unlimited', 'absolute-addons' ) . '</span>';
								printf(
								/* translators: 1: Remaining activation, 2: Total activation (limit) */
									esc_html__( '%1$d out of %2$s', 'absolute-addons' ),
									esc_attr( $this->license['activations'] ),
									$unlimited
								);
								?>
							</p>
						<?php } else { ?>
							<p class="<?php echo $remaining ? 'active' : 'inactive'; ?>">
								<?php
								/* translators: 1: Remaining activation, 2: Total activation (limit) */
								printf( esc_html__( '%1$d out of %2$d', 'absolute-addons' ), esc_attr( $remaining ), esc_attr( $this->license['limit'] ) );
								?>
							</p>
						<?php } ?>
					<?php } else { ?>
						<p class="inactive"><?php esc_html_e( 'N/A', 'absolute-addons' ); ?></p>
					<?php } ?>
				</div>
				<div class="single-license-info">
					<h3><?php esc_html_e( 'Automatic Update:', 'absolute-addons' ); ?></h3>
					<p class="<?php echo esc_attr( $status ); ?>"><?php 'active' === $status ? esc_html_e( 'Enabled', 'absolute-addons' ) : esc_html_e( 'Disabled', 'absolute-addons' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	protected function render_license_expire_datetime() {
		if ( 'active' !== $this->license['status'] ) {
			$expires  = __( 'N/A', 'absolute-addons' );
			$expiring = true;
		} elseif ( empty( $this->license['expires'] ) ) {
			$expires  = __( 'N/A', 'absolute-addons' );
			$expiring = false;
		} else {
			$time_diff = $this->license['expires'] - current_time( 'timestamp', true );
			if ( $time_diff > 0 && $time_diff < WEEK_IN_SECONDS ) {
				// translators: placeholder is human time diff (e.g. "3 weeks")
				$expires  = sprintf( __( 'In %s', 'absolute-addons' ), human_time_diff( current_time( 'timestamp', true ), $this->license['expires'] ) );
				$expiring = true;
			} elseif ( $time_diff < 0 && absint( $time_diff ) < WEEK_IN_SECONDS ) {
				// translators: placeholder is human time diff (e.g. "3 weeks")
				$expires  = sprintf( __( '%s ago', 'absolute-addons' ), human_time_diff( current_time( 'timestamp', true ), $this->license['expires'] ) );
				$expiring = true;
			} else {
				$zone     = wp_timezone();
				$expires  = date_create( '@' . $this->license['expires'], $zone );
				$expires  = wp_date( 'F j, Y H:i (T)', $expires->getTimestamp(), $zone );
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
	private function render_page_header() {
		if ( $this->remove_header ) {
			return;
		}
		?>
		<header class="se-sdk-license-title">
			<?php if ( ! $this->header_icon_url ) { ?>
				<svg class="default-icon" viewBox="0 0 300 300" xml:space="preserve" xmlns="http://www.w3.org/2000/svg">
					<path d="m150 161.48c-8.613 0-15.598 6.982-15.598 15.598 0 5.776 3.149 10.807 7.817 13.505v17.341h15.562v-17.341c4.668-2.697 7.817-7.729 7.817-13.505 0-8.616-6.984-15.598-15.598-15.598z"/>
					<path d="m150 85.849c-13.111 0-23.775 10.665-23.775 23.775v25.319h47.548v-25.319c-1e-3 -13.108-10.665-23.775-23.773-23.775z"/>
					<path d="m150 1e-3c-82.839 0-150 67.158-150 150 0 82.837 67.156 150 150 150s150-67.161 150-150c0-82.839-67.161-150-150-150zm46.09 227.12h-92.173c-9.734 0-17.626-7.892-17.626-17.629v-56.919c0-8.491 6.007-15.582 14.003-17.25v-25.697c0-27.409 22.3-49.711 49.711-49.711 27.409 0 49.709 22.3 49.709 49.711v25.697c7.993 1.673 14 8.759 14 17.25v56.919h2e-3c0 9.736-7.892 17.629-17.626 17.629z"/>
				</svg>
			<?php } else { ?>
				<img src="<?php echo esc_url( $this->header_icon_url ); ?>"
					 width="300"
					 height="300"
					 alt="<?php printf(
					 /* translators: 1. Theme/Plugin Name. */
						 esc_attr__( '%s License Management', 'absolute-addons' ),
						 esc_attr( $this->client->getPackageName() )
					 );; ?>">
			<?php } ?>
			<h2>
				<?php
				printf(
				/* translators: 1. Theme/Plugin Name. */
					esc_html__( '%s License Management', 'absolute-addons' ),
					esc_html( $this->client->getPackageName() )
				);
				?>
			</h2>
		</header>
		<?php
	}

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
			$this->error = __( 'The license key field is required.', 'absolute-addons' );

			return;
		}

		$license   = $this->get_license();
		$updateKey = $this->validate_license_data( $license ) && $postData['license_key'] !== $license['license']; // Check if it's a change request.

		if ( $updateKey ) {
			$deactivate = $this->deactivate(); // deactivate first.
			if ( ! $deactivate['success'] ) {
				$check = $this->check(); // Check api status.
				if ( $check['success'] && isset( $check['data']['activated'] ) && $check['data']['activated'] ) {
					if ( $deactivate['error'] ) {
						$this->error = $deactivate['error'];
					} else {
						$this->error = __( 'Unknown error occurred.', 'absolute-addons' );
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
				$this->error = __( 'Unknown error occurred.', 'absolute-addons' );
			}
		} else {
			$this->schedule_cron_event();

			if ( ! $updateKey ) {
				$this->success = __( 'License activated successfully.', 'absolute-addons' );
			} else {
				$this->success = __( 'License updated successfully.', 'absolute-addons' );
			}
		}

		// Don't reset the key.
		// keep it, so if the user renew subscription update the status and reactivate the plugin.

		// Update license status.
		$this->set_license( wp_parse_args( $response['data'], $license ) );

		$this->updating_license( false );
	}

	/**
	 * deactivate client license.
	 *
	 * @return void
	 */
	private function deactivate_client_license() {
		if ( ! isset( $this->license['license'] ) || empty( $this->license['license'] ) ) {
			$this->error = __( 'License key not found.', 'absolute-addons' );
		} else {
			$response = $this->deactivate();
			if ( ! $response['success'] ) {
				// check api status.
				$check = $this->check();
				if ( $check['success'] && isset( $check['data']['activated'] ) && $check['data']['activated'] ) {
					if ( $response['error'] ) {
						$this->error = $response['error'];
					} else {
						$this->error = __( 'Unknown error occurred.', 'absolute-addons' );
					}
				}
			}
		}

		$this->clear_scheduler();
		// Reset license data.
		$this->set_license();

		$this->success = __( 'License deactivated successfully.', 'absolute-addons' );
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
	public function schedule_cron_event() {
		if ( ! wp_next_scheduled( $this->schedule_hook ) ) {
			wp_schedule_event( time(), 'daily', $this->schedule_hook );
			wp_schedule_single_event( time() + 20, $this->schedule_hook );
		}
	}

	/**
	 * Clear any scheduled hook.
	 *
	 * @return void
	 */
	public function clear_scheduler() {
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
				register_activation_hook( $this->client->getBasename(), [ $this, 'schedule_cron_event' ] );
				register_deactivation_hook( $this->client->getBasename(), [ $this, 'project_deactivation' ] );
				add_action( 'activated_plugin', [ $this, 'redirect_to_license_page' ], 999, 2 );
				break;
			case 'theme':
				add_action( 'switch_theme', [ $this, 'project_deactivation' ] );
				add_action( 'after_switch_theme', [ $this, 'schedule_cron_event' ] );
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
	 *
	 * @return string
	 */
	private function get_input_license_value( $license ) {
		// phpcs:disable
		// if ( 'deactivate' != $action ) return '';
		// $key_length = strlen( $license['license'] );
		// return str_pad( substr( $license['license'], 0, $key_length / 2 ), $key_length, '*' );
		// phpcs:enable
		return $license['license'] ?? '';
	}

	private function license_signature_payload() {
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

	private function validate_license_signature() {

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
		$this->license['updated_at'] = current_time( 'mysql', 1 );

		// Update license signature.
		$this->update_license_signature();

		// Update in db.
		$this->client->set_option( 'license_data', $this->license );
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
			'expires'       => 0,          // Expires set this to a unix timestamp [GMT].
			'updated_at'    => 0,          // Expires set this to a unix timestamp [GMT].
		];

		// Parse.
		$data    = wp_parse_args( $data, $defaults );
		$license = [];

		$updated_at = strtotime( $data['updated_at'] );
		if ( $updated_at ) {
			$updated_at = gmdate( 'Y-m-d H:i:s', $updated_at );
		}

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
		$license['expires']       = absint( $data['expires'] );
		$license['updated_at']    = $updated_at;

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
	 * @return string|false The hashed $data, or false on failure.
	 *
	 * @see wp_hash()
	 * @noinspection PhpUndefinedConstantInspection
	 */
	private function hash( string $data ) {
		if ( ! function_exists( 'wp_generate_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		return wp_hash( $data, 'auth', 'sha256' );
//
//		if ( ! defined( 'AUTH_KEY' ) || AUTH_KEY === 'put your unique phrase here' ) {
//			$auth_key = get_site_option( $this->client->getSlug() . '_license_auth_key' );
//
//			if ( ! $auth_key ) {
//				if ( ! function_exists( 'wp_generate_password' ) ) {
//					require_once ABSPATH . WPINC . '/pluggable.php';
//				}
//
//				$auth_key = wp_generate_password( 64, true, true );
//				update_site_option( $this->client->getSlug() . '_license_auth_key', $auth_key );
//			}
//		} else {
//			$auth_key = AUTH_KEY;
//		}
//
//		if ( ! defined( 'AUTH_SALT' ) || AUTH_SALT === 'put your unique phrase here' || AUTH_SALT === $auth_key ) {
//			$auth_salt = get_site_option( $this->client->getSlug() . '_license_auth_salt' );
//
//			if ( ! $auth_salt ) {
//				if ( ! function_exists( 'wp_generate_password' ) ) {
//					require_once ABSPATH . WPINC . '/pluggable.php';
//				}
//
//				$auth_salt = wp_generate_password( 64, true, true );
//				update_site_option( $this->client->getSlug() . '_license_auth_salt', $auth_salt );
//			}
//		} else {
//			$auth_salt = AUTH_SALT;
//		}
//
//		return hash_hmac( 'sha256', $data, $auth_key . $auth_salt );
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
