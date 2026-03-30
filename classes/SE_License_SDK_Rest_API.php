<?php

/**
 * Class SE_License_SDK_Rest_API
 */
final class SE_License_SDK_Rest_API {

	/**
	 * Namespace for the REST API.
	 */
	const NAMESPACE = 'storeengine-sdk/v1';

	/**
	 * @var SE_License_SDK_Client
	 */
	private $client;

	/**
	 * SE_License_SDK_Rest_API constructor.
	 *
	 * @param SE_License_SDK_Client $client The SDK client.
	 */
	public function __construct( SE_License_SDK_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Initialize the REST API.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the REST API routes for this client.
	 *
	 * @return void
	 */
	public function register_routes() {
		$slug = $this->client->getSlug();

		register_rest_route( self::NAMESPACE, '/' . $slug . '/license/activate', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'activate_license' ],
			'permission_callback' => [ $this, 'permissions_check' ],
			'args'                => [
				'license' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/' . $slug . '/license/deactivate', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'deactivate_license' ],
			'permission_callback' => [ $this, 'permissions_check' ],
		] );

		register_rest_route( self::NAMESPACE, '/' . $slug . '/license/status', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'check_license_status' ],
			'permission_callback' => [ $this, 'permissions_check' ],
			'args'                => [
				'force' => [ 'type' => 'boolean' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/' . $slug . '/insights/optin', [
			'methods'             => WP_REST_Server::ALLMETHODS,
			'callback'            => [ $this, 'handle_insights_optin' ],
			'permission_callback' => [ $this, 'permissions_check' ],
			'args'                => [
				'opt_in' => [ 'type' => 'boolean' ],
			],
		] );
	}

	/**
	 * Check if the user has permission to manage licenses.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to manage licenses.', 'storeengine-sdk' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Callback for activating a license.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_license( WP_REST_Request $request ) {
		$license = $request->get_param( 'license' );

		$this->client->license()->activate_client_license( [ 'license_key' => $license ] );

		if ( $this->client->license()->get_error() ) {
			return new WP_Error( 'error-activating-license', $this->client->license()->get_error(), [ 'status' => 400 ] );
		}


		return rest_ensure_response( [
			'success' => true,
			'message' => $this->client->license()->get_success(),
			'license' => $this->client->license()->get_public_data(),
		] );
	}

	/**
	 * Callback for deactivating a license.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate_license() {
		$this->client->license()->deactivate_client_license();

		if ( $this->client->license()->get_error() ) {
			return new WP_Error( 'error-deactivating-license', $this->client->license()->get_error(), [ 'status' => 400 ] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => __( 'License deactivated successfully.', 'storeengine-sdk' ),
			'license' => $this->client->license()->get_public_data(),
		] );
	}

	/**
	 * Callback for checking license status.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_license_status( WP_REST_Request $request ) {
		if ( $request->get_param( 'force' ) ) {
			$this->client->license()->check_license_status();
			//$response = $this->client->license()->check();

			if ( $this->client->license()->get_error() ) {
				return new WP_Error( 'error-checking-license-status', $this->client->license()->get_error(), [ 'status' => 400 ] );
			}
		}

		return rest_ensure_response( $this->client->license()->get_public_data() );
	}

	/**
	 * Callback for insights opt-in/opt-out.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_insights_optin( WP_REST_Request $request ) {

		$opt_in = $request->get_param( 'opt_in' );

		if ( null !== $opt_in ) {
			if ( $opt_in ) {
				$this->client->insights()->optIn();
			} else {
				$this->client->insights()->optOut();
			}
		}


		return rest_ensure_response( [
			'allowed'   => $this->client->insights()->is_tracking_allowed(),
			'show'      => ! $this->client->insights()->is_notice_dismissed(),
			'last_send' => $this->client->insights()->get_last_send(),
		] );
	}
}
