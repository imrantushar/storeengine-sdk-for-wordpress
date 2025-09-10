<?php
/**
 * Class Insights
 */
final class SE_License_SDK_Insights {

	/**
	 * The notice text
	 *
	 * @var string
	 */
	protected $notice;

	/**
	 * Whether to the notice or not
	 *
	 * @var boolean
	 */
	protected $show_notice = true;

	/**
	 * If extra data needs to be sent
	 *
	 * @var ?callable|callable-string
	 */
	protected $usage_log_callback;

	/**
	 * Client
	 *
	 * @var SE_License_SDK_Client
	 */
	protected $client;

	/**
	 * Flag for checking if the init method is already called.
	 * @var bool
	 */
	private $did_init = false;

	/**
	 * Email Message Template File (path) For sending Support Ticket
	 * @var string
	 */
	protected $ticketTemplate = '';

	/**
	 * Ticket Email Recipient
	 * @var string
	 */
	protected $ticketRecipient = '';

	/**
	 * Support Page URL
	 * @var string
	 */
	protected $supportURL = '';

	protected $supportResponse = '';

	protected $supportErrorResponse = '';

	protected $data_collection_list = [];

	/**
	 * Initialize the class
	 *
	 * @param SE_License_SDK_Client|string $client The client.
	 */
	final public function __construct( SE_License_SDK_Client $client ) {
		$this->client         = $client;
		$this->ticketTemplate = __DIR__ . '/../views/insights-support-ticket-email.php';
	}

	/**
	 * Don't show the notice
	 *
	 * @return SE_License_SDK_Insights
	 */
	public function hide_notice() {
		$this->show_notice = false;

		return $this;
	}

	/**
	 * Set custom notice text
	 *
	 * @param string $text Admin Notice Test.
	 *
	 * @return SE_License_SDK_Insights
	 */
	public function notice( string $text ): SE_License_SDK_Insights {
		$this->notice = $text;

		return $this;
	}

	/**
	 * Initialize insights
	 *
	 * @return void
	 */
	public function init() {

		if ( $this->did_init ) {
			return;
		}

		// Initialize.
		$init_method = 'init_' . $this->client->getType();

		if ( method_exists( $this, $init_method ) ) {
			// init_{plugin/theme}
			$this->$init_method();
		}

		$this->did_init = true;
	}

	/**
	 * Initialize theme hooks
	 *
	 * @return void
	 */
	private function init_theme() {
		$this->init_common();

		add_action( 'switch_theme', [ $this, 'deactivation_cleanup' ] );
		add_action( 'switch_theme', [ $this, 'theme_deactivated' ], 12, 3 );
	}

	/**
	 * Initialize plugin hooks
	 *
	 * @return void
	 */
	private function init_plugin() {

		// Plugin deactivate popup.
		if ( ! $this->client->is_local_request() ) {
			add_action( 'admin_footer', [ $this, 'deactivate_scripts' ], PHP_INT_MAX );
			//add_action( 'plugin_action_links_' . $this->client->getBasename(), [ $this, 'plugin_action_links' ] );
		}

		$this->init_common();

		register_activation_hook( $this->client->getPackageFile(), [ $this, 'activate_plugin' ] );
		register_deactivation_hook( $this->client->getPackageFile(), [ $this, 'deactivation_cleanup' ] );
	}

	/**
	 * Initialize common hooks
	 *
	 * @return void
	 */
	protected function init_common() {

		add_action( 'admin_init', [ $this, 'handle_optIn_optOut' ] );
		add_action( 'removable_query_args', [ $this, 'add_removable_query_args' ] );

		// Uninstall reason.
		add_action(
				'wp_ajax_' . $this->client->getHookName( 'submit-uninstall-reason' ),
				[ $this, 'uninstall_reason_submission' ]
		);

		// Ticket submission.
		add_action(
				'wp_ajax_' . $this->client->getHookName( 'submit-support-ticket' ),
			[ $this, 'support_ticket_submission' ]
		);

		// cron events.
		add_filter( 'cron_schedules', [ $this, 'add_weekly_schedule' ] );
		add_action( $this->client->getSlug() . '_tracker_send_event', [ $this, 'send_tracking_data' ] );
	}

	public function set_support_url( string $supportURL ): SE_License_SDK_Insights {
		$this->supportURL = $supportURL;

		return $this;
	}

	public function set_purchase_url( string $purchase_url ): SE_License_SDK_Insights {
		$this->purchase_url = $purchase_url;

		return $this;
	}

	public function set_support_response( ?string $supportResponse ): SE_License_SDK_Insights {
		$this->supportResponse = $supportResponse;

		return $this;
	}

	public function set_support_error_response( ?string $supportErrorResponse ): SE_License_SDK_Insights {
		$this->supportErrorResponse = $supportErrorResponse;

		return $this;
	}

	public function set_ticket_recipient( string $ticketRecipient ): SE_License_SDK_Insights {
		$this->ticketRecipient = $ticketRecipient;

		return $this;
	}

	public function set_ticket_template( string $ticketTemplate ): SE_License_SDK_Insights {
		if ( $ticketTemplate && is_file( $ticketTemplate ) ) {
			$this->ticketTemplate = realpath( $ticketTemplate );
		}

		return $this;
	}

	/**
	 * Send tracking data to absp_service_api server
	 *
	 * @param boolean $override override current settings.
	 *
	 * @return void
	 */
	public function send_tracking_data( bool $override = false ) {

		// Skip on AJAX Requests.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( ! $this->is_tracking_allowed() && ! $override ) {
			return;
		}

		// Send a maximum of once per week.
		$last_send = $this->__get_last_send();

		/**
		 * Tracking interval
		 *
		 * @param string $interval A valid date/time string
		 */
		$trackingInterval = apply_filters( $this->client->getSlug() . '_tracking_interval', '-1 week' );

		try {
			$intervalCheck = strtotime( $trackingInterval );
		} catch ( Exception $e ) {
			// fallback to default 1 week if filter returned unusable data.
			$intervalCheck = strtotime( '-1 week' );
		}

		if ( $last_send && $last_send > $intervalCheck && ! $override ) {
			return;
		}

		$this->client->request( [ 'body' => $this->get_tracking_data(), 'route' => 'log-usage' ] );

		$this->client->set_option( 'tracking_last_send', time() );
	}

	/**
	 * Get the tracking data points
	 *
	 * @return array
	 */
	protected function get_tracking_data(): array {
		[ 'name' => $name, 'version' => $version ] = $this->get_runtime_info();
		[ 'db_name' => $db_name, 'db_version' => $db_version ] = $this->get_db_info();
		[ 'admin_email' => $admin_emails, 'admin_name' => $admin_name ] = $this->client->get_admin_info();

		$all_plugins = $this->get_plugins_data();
		$theme       = wp_get_theme();
		$theme_data  = [
			'slug'       => $theme->get_stylesheet(),
			'name'       => $theme->get( 'Name' ),
			'version'    => $theme->get( 'Version' ),
			'author'     => $theme->get( 'Author' ),
			'author_url' => $theme->get( 'AuthorURI' ),
			'theme_url'  => $theme->get( 'ThemeURI' ),
			'is_child'   => false,
		];

		if ( $theme->parent() ) {
			$theme_data['is_child']          = true;
			$theme_data['parent_slug']       = $theme->parent()->get_stylesheet();
			$theme_data['parent_name']       = $theme->parent()->get( 'Name' );
			$theme_data['parent_version']    = $theme->parent()->get( 'Version' );
			$theme_data['parent_author']     = $theme->parent()->get( 'Author' );
			$theme_data['parent_author_url'] = $theme->parent()->get( 'AuthorURI' );
			$theme_data['parent_theme_url']  = $theme->parent()->get( 'ThemeURI' );
		}

		$data = [
			'core_name'       => 'WordPress',
			'core_version'    => get_bloginfo( 'version' ),
			'locale'          => get_locale(),
			'server_name'     => $this->get_server_software_name(),
			'server_version'  => $this->get_server_version(),
			'db_name'         => $db_name,
			'db_version'      => $db_version,
			'runtime'         => $name,
			'runtime_version' => $version,
			'os_name'         => php_uname( 's' ),
			'os_arch'         => php_uname( 'm' ),
			'os_version'      => $this->get_os_version(),
			'ip_address'      => $this->client->get_server_ip_address(),
			'usage_log'       => [
				'admin_name'           => $admin_name,
				'admin_email'          => $admin_emails,
				'os_info'              => php_uname(),
				'url'                  => esc_url( home_url() ),
				'site'                 => $this->__get_site_name(),
				'active_plugins'       => $all_plugins['active_plugins'],
				'inactive_plugins'     => $all_plugins['inactive_plugins'],
				'theme'                => $theme_data,
				'wp_memory_limit'      => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'N/A',
				'debug_mode'           => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'multisite'            => is_multisite(),
				'php_execution_time'   => @ini_get( 'max_execution_time' ), // phpcs:ignore
				'php_max_upload_size'  => size_format( wp_max_upload_size() ),
				'php_default_timezone' => date_default_timezone_get(),
				'ext_php_soap'         => class_exists( 'SoapClient' ),
				'ext_php_fsockopen'    => function_exists( 'fsockopen' ),
				'ext_php_curl'         => function_exists( 'curl_init' ),
				'ext_php_ftp'          => function_exists( 'ftp_connect' ),
				'ext_php_sftp'         => function_exists( 'ssh2_connect' ),
			],
		];

		$usage_log = $this->get_usage_log();

		if ( ! empty( $usage_log ) ) {
			$data['usage_log'] = $data['usage_log'] + $usage_log;
		}

		return apply_filters( $this->client->getHookName( 'tracker_data' ), $data );
	}

	protected function set_usage_log_callback( $usage_log_callback): SE_License_SDK_Insights {
		$this->usage_log_callback = $usage_log_callback;

		return $this;
	}

	/**
	 * If a child class wants to send extra data
	 *
	 * @return array
	 */
	protected function get_usage_log(): array {
		if ( is_callable( $this->usage_log_callback ) ) {
			$usage_log = call_user_func( $this->usage_log_callback );

			if ( $usage_log && is_array( $usage_log ) ) {
				return $usage_log;
			}
		}

		return [];
	}

	/**
	 * Explain the user which data we collect
	 *
	 * @return array
	 */
	protected function get_data_collection_list(): array {
		$data = array_merge( [
			'server_env'  => __( 'Server environment details (MySQL version, MySQL version, Server software & version, etc.).', 'storeengine-sdk' ),
			'wp_env'      => __( 'WordPress installation details (version, debug mode, max upload size).', 'storeengine-sdk' ),
			'wp_settings' => __( 'WordPress settings (site language, active and inactive plugins & themes).', 'storeengine-sdk' ),
			'site_meta'   => __( 'Site Name & URL.', 'storeengine-sdk' ),
			'admin_meta'  => __( 'Admin Name & Email.', 'storeengine-sdk' ),
		], $this->data_collection_list );

		return array_unique( array_filter( $data ) );
	}

	public function set_data_being_collected( ?array $items ): SE_License_SDK_Insights {
		$this->data_collection_list = $items ? $items : [];

		return $this;
	}

	/**
	 * Check if the user has opted into tracking
	 *
	 * @return bool
	 */
	public function is_tracking_allowed(): bool {

		// If hide_notice is set (optIn notice is hidden by default), then tracking is also disable.
		// But uninstallation tracking is active.
		if ( ! $this->show_notice ) {
			return false;
		}

		return 'yes' == $this->client->get_option( 'allow_tracking', 'no' );
	}

	/**
	 * Get the last time a tracking was sent
	 *
	 * @return false|int
	 */
	private function __get_last_send() {
		return $this->client->get_option( 'tracking_last_send', false );
	}

	/**
	 * Check if the notice has been dismissed or enabled
	 *
	 * @return boolean
	 */
	private function __notice_dismissed(): bool {
		return 'hide' === $this->client->get_option( 'tracking_notice', 'show' );
	}

	/**
	 * Schedule the event weekly
	 *
	 * @return void
	 */
	private function __schedule_event() {
		$hook_name = $this->client->getSlug() . '_tracker_send_event';
		if ( ! wp_next_scheduled( $hook_name ) ) {
			wp_schedule_event( time(), 'weekly', $hook_name );
		}
	}

	/**
	 * Clear any scheduled hook
	 *
	 * @return void
	 */
	private function __clear_schedule_event() {
		wp_clear_scheduled_hook( $this->client->getSlug() . '_tracker_send_event' );
	}

	/**
	 * Display the admin notice to users that have not opted-in or out
	 *
	 * @return void
	 */
	public function admin_notice() {

		if ( $this->client->is_local_request() ) {
			return;
		}

		if ( $this->__notice_dismissed() ) {
			return;
		}

		if ( $this->is_tracking_allowed() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$what_tracked = $this->get_data_collection_list();

		// Don't show tracking if a local server.

		if ( ! empty( $what_tracked ) ) {
			$trigger           = $this->client->getHookName('insights-collection-list');
			$terms             = '';
			$privacy_policy    = '';
			$what_we_collect   = sprintf(
				'<a class="%s" href="#">%s</a>',
				esc_attr( $trigger ),
				esc_html__( 'What we collect?', 'storeengine-sdk' )
			);

			$terms_policy_text = '';

			if ( $this->privacy_policy_url ) {
				$privacy_policy = sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( $this->privacy_policy_url ),
					esc_html__( 'Privacy Policy', 'storeengine-sdk' )
				);
			}

			if ( $this->terms_url ) {
				$terms = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
					esc_url( $this->terms_url ),
					esc_html__( 'Terms of Services', 'storeengine-sdk' )
				);
			}

			if ( $terms || $privacy_policy ) {
				if ( $terms && $privacy_policy ) {
					$terms_policy_text = sprintf(
						/* translators: 1: Privacy Policy Link, 2: Terms Links */
						__( 'Please read our %1$s and %2$s', 'storeengine-sdk' ),
						$privacy_policy,
						$terms
					);
				} else {
					/* translators: 1: Privacy Policy or Terms Link */
					$terms_policy_text = sprintf( __( 'Please read our %1$s', 'storeengine-sdk' ), $privacy_policy || $terms );
				}
			}

			if ( empty( $this->notice ) || ! str_contains( $this->notice, '%1$s' ) || ! str_contains( $this->notice, '%2$s' ) ) {
				/* translators: 1: plugin name. */
				$this->notice = __( '<span class="se-sdk-insights-notice--title">Help Us Improve & Get Exclusive Perks!</span>', 'storeengine-sdk' );
				/* translators: 1: plugin name, 2: what we collect button. */
				//. What you’ll get if you opt in?
				$this->notice .= __( '<p class="se-sdk-insights-notice--des">We’d love to stay in touch and share useful updates, tips, and special offers to help you get the most from %1$s. Your privacy is our priority. No spam — ever. <small>%2$s</small></p>', 'storeengine-sdk' );
			}

			$this->notice = sprintf(
				$this->notice,
				'<strong class="highlight">' . esc_html( $this->client->getPackageName() ) . '</strong>',
				$what_we_collect
			);

			include __DIR__ . '/../views/insights-opt-in-notice.php';
		}
	}

	/**
	 * Tracking Opt In URL
	 * @return string
	 */
	public function get_opt_in_url(): string {
		return add_query_arg( [
			'optAct'   => $this->client->getHookName( 'tracker_optIn' ),
			'_wpnonce' => wp_create_nonce( $this->client->getHookName( 'insight_action' ) ),
		] );
	}

	/**
	 * Tracking Opt Out URL
	 * @return string
	 */
	public function get_opt_out_url(): string {
		return add_query_arg( [
			'optAct'   => $this->client->getHookName( 'tracker_optOut' ),
			'_wpnonce' => wp_create_nonce( $this->client->getHookName( 'insight_action' ) ),
		] );
	}

	/**
	 * handle the optIn/optOut
	 *
	 * @return void
	 */
	public function handle_optIn_optOut() {
		if ( $this->show_notice ) {
			// Tracking notice.
			add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		}

		if ( isset( $_REQUEST['_wpnonce'], $_REQUEST['optAct'] ) && $_REQUEST['optAct'] ) {
			check_admin_referer( $this->client->getHookName( 'insight_action' ) );
			if ( $this->client->getHookName( 'tracker_optOut' ) === $_REQUEST['optAct'] ) {
				$this->optOut( false );
			} else {
				$this->optIn();
			}


			wp_safe_redirect( remove_query_arg( [ 'optAct', '_wpnonce' ] ) );
			exit;
		}
	}

	/**
	 * Add query vars to removable query args array
	 *
	 * @param array $removable_query_args array of removable args.
	 *
	 * @return array
	 */
	public function add_removable_query_args( array $removable_query_args ): array {
		return array_merge( $removable_query_args, [ 'optAct', '_wpnonce' ] );
	}

	/**
	 * Tracking optIn
	 *
	 * @param bool $override_last_send optional. set send tracking data override setting, ignore last send datetime setting if true.
	 *
	 * @return void
	 * @see Insights::send_tracking_data()
	 */
	public function optIn( $override_last_send = false ) {
		$this->client->set_option( 'allow_tracking', 'yes' );
		$this->client->set_option( 'tracking_notice', 'hide' );
		$this->__clear_schedule_event();
		$this->__schedule_event();
		$this->client->request( [ 'body' => [ 'opt_in' => true ], 'route' => 'opt-in' ] );
		$this->send_tracking_data( $override_last_send );
	}

	/**
	 * optOut from tracking
	 *
	 * @return void
	 */
	public function optOut( $hide_notice = true ) {
		$this->send_tracking_data();
		$this->client->set_option( 'allow_tracking', 'no' );
		$this->client->set_option( 'tracking_notice', $hide_notice ? 'hide' : 'show' );
		$this->client->request( [ 'body' => [ 'opt_in' => false ], 'route' => 'opt-in' ] );
		$this->__clear_schedule_event();
	}

	/**
	 * Get the number of post counts
	 *
	 * @param string $post_type PostType name to get count for.
	 *
	 * @return integer
	 */
	public function get_post_count( $post_type ) {
		global $wpdb;

		// phpcs:disable
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count(ID) FROM $wpdb->posts WHERE post_type = %s and post_status = 'publish'",
				$post_type
			)
		);
		// phpcs:enable
	}

	private function get_server_software_name(): ?string {
		if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ) {
			return null; // No web server in CLI
		}

		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return null;
		}

		// Example: "Apache/2.4.57 (Unix)" → "Apache"
		// Example: "nginx/1.25.2" → "nginx"
		return preg_split( '/[\/\s]+/', $_SERVER['SERVER_SOFTWARE'] )[0] ?? null;
	}

	private function get_server_version(): ?string {
		if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ) {
			return null; // No server software in CLI
		}

		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return null;
		}

		if ( preg_match( '/\d+\.\d+(?:\.\d+)?/', $_SERVER['SERVER_SOFTWARE'], $matches ) ) {
			return $matches[0];
		}

		return null;
	}

	private function get_runtime_info(): array {
		if ( defined( 'HHVM_VERSION' ) ) {
			return [ 'name' => 'HHVM', 'version' => HHVM_VERSION ];
		}
		if ( PHP_SAPI === 'phpdbg' ) {
			return [ 'name' => 'PHPDBG', 'version' => PHP_VERSION ];
		}

		return [ 'name' => 'PHP', 'version' => PHP_VERSION ];
	}

	private function get_db_info(): array {
		global $wpdb;

		$db_name    = null;
		$db_version = null;
		$dbh_class  = get_class( $wpdb->dbh );

		// Try WordPress helper first (MySQL/MariaDB only)
		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			$server_info = $wpdb->db_server_info();
		} elseif ( isset( $wpdb->dbh ) ) {
			if ( $wpdb->dbh instanceof mysqli ) {
				// mysqli
				$server_info = $wpdb->dbh->server_info;
			} elseif ( $wpdb->dbh instanceof \PDO ) {
				// PDO
				$server_info = $wpdb->dbh->getAttribute( \PDO::ATTR_SERVER_VERSION );
				//$driver_name = $wpdb->dbh->getAttribute( \PDO::ATTR_DRIVER_NAME );
			}
		}

		if ( ! empty( $server_info ) ) {
			if ( stripos( $server_info, 'MariaDB' ) !== false ) {
				$db_name    = 'MariaDB';
				$db_version = preg_replace( '/.*MariaDB\s*/i', '', $server_info );
			} elseif ( stripos( $server_info, 'PostgreSQL' ) !== false ) {
				$db_name    = 'PostgreSQL';
				$db_version = preg_replace( '/[^0-9\.].*$/', '', $server_info );
			} elseif ( stripos( $server_info, 'SQLite' ) !== false ) {
				$db_name    = 'SQLite';
				$db_version = preg_replace( '/[^0-9\.].*$/', '', $server_info );
			} else {
				// Default to MySQL
				$db_name    = 'MySQL';
				$db_version = preg_replace( '/[^0-9\.].*$/', '', $server_info );
			}
		}

		return [ 'db_name' => $db_name, 'db_version' => $db_version ];
	}

	private function get_os_version(): ?string {
		if ( php_uname( 's' ) !== 'Darwin' ) {
			return php_uname( 'r' );
		}

		$plistPath = '/System/Library/CoreServices/SystemVersion.plist';
		if ( ! is_readable( $plistPath ) ) {
			return null;
		}

		$plistContent = file_get_contents( $plistPath );
		if ( $plistContent === false ) {
			return null;
		}

		// Match <key>ProductVersion</key> followed by <string>VERSION</string>
		if ( preg_match( '/<key>ProductVersion<\/key>\s*<string>([^<]+)<\/string>/i', $plistContent, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Get the list of active and inactive plugins
	 * @return array
	 */
	private function get_plugins_data(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			include ABSPATH . '/wp-admin/includes/plugin.php';
		}
		$plugins             = get_plugins();
		$active_plugins      = [];
		$active_plugins_keys = get_option( 'active_plugins', [] );
		foreach ( $plugins as $k => $v ) {
			// Take care of formatting the data how we want it.
			$formatted = [
				'name'       => isset( $v['Name'] ) ? wp_strip_all_tags( $v['Name'] ) : '',
				'version'    => isset( $v['Version'] ) ? wp_strip_all_tags( $v['Version'] ) : 'N/A',
				'author'     => isset( $v['Author'] ) ? wp_strip_all_tags( $v['Author'] ) : 'N/A',
				'network'    => isset( $v['Network'] ) ? wp_strip_all_tags( $v['Network'] ) : 'N/A',
				'plugin_uri' => isset( $v['PluginURI'] ) ? wp_strip_all_tags( $v['PluginURI'] ) : 'N/A',
			];
			if ( in_array( $k, $active_plugins_keys ) ) {
				// Remove active plugins from list, so we can show active and inactive separately.
				unset( $plugins[ $k ] );
				$active_plugins[ $k ] = $formatted;
			} else {
				$plugins[ $k ] = $formatted;
			}
		}

		return [
			'active_plugins'   => $active_plugins,
			'inactive_plugins' => $plugins,
		];
	}

	/**
	 * Get user totals based on user role.
	 *
	 * @return array
	 */
	public function get_user_count(): array {
		$user_count          = [];
		$user_count_data     = count_users();
		$user_count['total'] = $user_count_data['total_users'];
		// Get user count based on user role.
		foreach ( $user_count_data['avail_roles'] as $role => $count ) {
			$user_count[ $role ] = $count;
		}

		return $user_count;
	}

	/**
	 * Add weekly cron schedule
	 *
	 * @param array $schedules Cron Schedules.
	 *
	 * @return array
	 */
	public function add_weekly_schedule( $schedules ) {
		$schedules['weekly'] = [
			'interval' => DAY_IN_SECONDS * 7,
			'display'  => __( 'Once Weekly', 'storeengine-sdk' ),
		];

		return $schedules;
	}

	/**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public function activate_plugin() {
		$allowed = $this->client->get_option( 'allow_tracking', 'no' );
		// if it wasn't allowed before, do nothing.
		if ( 'yes' !== $allowed ) {
			return;
		}

		// Re-schedule and delete the last sent time, so we could force send again.
		wp_schedule_event( time(), 'weekly', $this->client->getSlug() . '_tracker_send_event' );
		$this->send_tracking_data( true );
	}

	/**
	 * Clear our options upon deactivation
	 *
	 * @return void
	 */
	public function deactivation_cleanup() {
		$this->__clear_schedule_event();
	}

	protected $action_links = [];
	protected $action_links_exclude = [ 'deactivate' ];
	protected $action_links_html = [
		'b'      => [ 'class', 'style' ],
		'i'      => [ 'class', 'style' ],
		'span'   => [ 'class', 'style' ],
		'strong' => [ 'class', 'style' ],
	];
	protected $action_links_args = [
		'label'  => '',
		'class'  => '',
		'href'   => '',
		'target' => '',
		'rel'    => '',
	];

	protected $terms_url = '';

	protected $privacy_policy_url = '';


	public function add_action_links( string $id, array $args = [] ) {
		$args = wp_parse_args( $args, $this->action_links_args );

		if ( ! in_array( $id, $this->action_links_exclude ) && ! empty( $args['label'] ) && ! empty( $args['href'] ) ) {
			$this->action_links[ $id ] = sprintf(
				'<a class="%s" href="%s" target="%s" rel="%s">%s</a>',
				esc_attr( $args['class'] ),
				esc_url( $args['href'] ),
				esc_attr( $args['target'] ),
				esc_attr( $args['rel'] ),
				wp_kses( $args['label'], $this->action_links_html )
			);
		}

		return $this;
	}

	public function set_terms_url( string $terms_url ): SE_License_SDK_Insights {
		$this->terms_url = $terms_url;

		return $this;
	}

	public function set_privacy_policy_url( string $privacy_policy_url ): SE_License_SDK_Insights {
		$this->privacy_policy_url = $privacy_policy_url;

		return $this;
	}

	/**
	 * Hook into action links and modify the deactivate link
	 *
	 * @param array $links Plugin Action Links.
	 *
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {

		if ( array_key_exists( 'deactivate', $links ) ) {
			$links['deactivate'] = str_replace( '<a', '<a class="' . $this->client->getSlug() . '-deactivate-link"', $links['deactivate'] );
		}

		if ( ! empty( $this->action_links ) ) {
			$links = $links + $this->action_links;
		}

		return $links;
	}

	/**
	 * Deactivation reasons
	 * @return array
	 */
	private function __get_uninstall_reasons(): array {
		$reasons = [
			[
				'id'          => 'how-to-use',
				'text'        => esc_html__( "I couldn't understand how to make it work.", 'storeengine-sdk' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Would you like us to assist you?', 'storeengine-sdk' ),
			],
			[
				'id'          => 'found-better',
				'text'        => esc_html__( 'I found a better product.', 'storeengine-sdk' ),
				'type'        => 'text',
				'placeholder' => esc_html__( 'Which Plugin!?', 'storeengine-sdk' ),
			],
			[
				'id'          => 'feature-needed',
				'text'        => esc_html__( "The plugin is great, but I need specific feature that you don't support.", 'storeengine-sdk' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Can you tell us more about feature that you need?', 'storeengine-sdk' ),
			],
			[
				'id'          => 'not-working',
				'text'        => esc_html__( 'The plugin is not working.', 'storeengine-sdk' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Could you tell us a bit more whats not working?', 'storeengine-sdk' ),
			],
			[
				'id'          => 'looking-for-other',
				'text'        => esc_html__( "It's not what I was looking for.", 'storeengine-sdk' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Could you please let us know more about the features you are looking for?', 'storeengine-sdk' ),
			],
			[
				'id'          => 'not-working-as-expected',
				'text'        => esc_html__( "The plugin didn't work as expected.", 'storeengine-sdk' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Please let us know your needs.', 'storeengine-sdk' ),
			],
			[
				'id'          => 'debugging',
				'text'        => esc_html__( 'Temporary deactivation for debugging.', 'storeengine-sdk' ),
				'type'        => '',
				'placeholder' => '',
			],
			[
				'id'          => 'other',
				'text'        => esc_html__( 'Other', 'storeengine-sdk' ),
				'type'        => 'textarea',
				'placeholder' => esc_html__( 'Could you tell us a bit more?', 'storeengine-sdk' ),
			],
		];

		$extra = apply_filters( $this->client->getHookName( 'uninstall_reasons' ), [], $reasons );

		if ( is_array( $extra ) && ! empty( $extra ) ) {
			// extract the last (other) reason and add after extras.
			$other   = array_pop( $reasons );
			$reasons = array_merge( $reasons, $extra, [ $other ] );
		}

		return $reasons;
	}

	/**
	 * Plugin deactivation uninstall reason submission
	 *
	 * @return void
	 */
	public function uninstall_reason_submission() {
		check_ajax_referer( $this->client->getHookName( 'insight_action' ) );

		if ( ! isset( $_POST['reason_id'] ) ) {
			wp_send_json_error( esc_html__( 'Invalid Request', 'storeengine-sdk' ) );
		}

		$reason  = sanitize_text_field( $_REQUEST['reason_id'] );
		$details = isset( $_REQUEST['reason_info'] ) ? trim( sanitize_textarea_field( $_REQUEST['reason_info'] ) ) : '';

		$current_user = wp_get_current_user();

		if ( $current_user->first_name ) {
			$name = trim( $current_user->first_name . ' ' . $current_user->last_name );
		} else {
			$name = $current_user->display_name;
		}

		$data = [
			'reason'      => $reason,
			'message'     => $details,
			'site'        => $this->__get_site_name(),
			'url'         => esc_url( home_url() ),
			'admin_name'  => $name,
			'admin_email' => $current_user->user_email,
			'usage_log'   => $this->get_tracking_data(),
			'type'        => 'uninstall',
		];

		$response = $this->client->request( [ 'body' => $data, 'route' => 'deactivate-license' ] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response );
		} else {
			wp_send_json_success();
		}
	}

	protected function get_se_url(): string {
		/** @noinspection HttpUrlsUsage */
		return 'http://storeengine.pro/' .
		       '?utm_source=sdk-support-ticket' .
		       '&utm_medium=email-footer' .
		       '&utm_campaign=' . $this->client->getSlug() .
		       '&utm_content=' . $this->client->getLicenseServer();
	}

	protected function getSupportTicketEmailTemplate( $replacements ) {
		ob_start();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Support Ticket Submission', 'storeengine-sdk' ); ?></title>
		</head>
		<body>
			<div class="se-sdk-support-ticket-email-template">
				<?php include $this->ticketTemplate; ?>
				<div style="margin:10px auto">
					<hr style="border-top-color:#008DFF"/>
				</div>
				<div style="margin:50px auto 10px auto">
					<?php // Trick (h3 with &#8203 zero-width-space chars) for dashes before auto generated text version by wp_mail ?>
					<h3 style="display:block;height:0;margin:0;opacity:0;visibility:hidden">&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;</h3>
					<p style="font-size: 12px;">
						<?php
						printf(
						// translators: %1$s. StoreEngine Site Link. %2$s. SDK version.
							esc_html__( 'Message Processed via %1$s WordPress License SDK (v.%2$s)', 'storeengine-sdk' ),
							'<a href="' . esc_url( $this->get_se_url() ) . '" target="_blank" style="color:#008DFF">' . esc_html__( 'StoreEngine', 'storeengine-sdk' ) . '</a>',
							esc_html( $this->client->getVersion() )
						);
						?>
					</p>
				</div>
			</div>
		</body>
		</html>
		<?php
		$content = ob_get_clean();

		return str_replace( $replacements['s'], $replacements['r'], $content );
	}

	/**
	 * Handle Support Ticket Submission
	 * @return void
	 */
	public function support_ticket_submission() {
		check_ajax_referer( $this->client->getHookName( 'insight_action' ) );
		if ( empty( $this->ticketTemplate ) || empty( $this->ticketRecipient ) ) {
			wp_send_json_error( __( 'Something Went Wrong.<br>Please try again after sometime.', 'storeengine-sdk' ) );
		}

		if ( ! is_file( $this->ticketTemplate ) ) {
			wp_send_json_error( __( 'Unable to locate support ticket email template file', 'storeengine-sdk') );
		}

		if ( ! empty( $_REQUEST['name'] ) && ! empty( $_REQUEST['email'] ) && sanitize_email( $_REQUEST['email'] ) && is_email( $_REQUEST['email'] ) && ! empty( $_REQUEST['subject'] ) && ! empty( $_REQUEST['message'] ) ) {
			$name    = ucwords( sanitize_text_field( $_REQUEST['name'] ) );
			$email   = sanitize_email( $_REQUEST['email'] );
			$subject = sprintf( 'Support Request For: %s', $this->client->getPackageName() );

			$headers = [
				'Content-Type: text/html; charset=UTF-8',
				sprintf( 'From: %s <%s>', $name, $email ),
				sprintf( 'Reply-To: %s <%s>', $name, $email ),
			];

			$sanitizers = [
				'email'   => 'sanitize_email',
				'website' => 'esc_url_raw',
				'message' => 'sanitize_textarea_field',
			];

			$data = [ 's' => [], 'r' => [] ];

			foreach ( $_REQUEST as $k => $v ) {
				$sanitizer = $sanitizers[ $k ] ?? 'sanitize_text_field';
				if ( ! function_exists( $sanitizer ) ) {
					continue;
				}

				$v = call_user_func_array( $sanitizer, [ $v ] );

				if ( 'message' === $k ) {
					$v = wp_kses_post( wpautop( ucfirst( $v ) ) );
				} elseif ( 'website' === $k ) {
					$v = esc_url( $v );
				} elseif ( 'subject' === $k ) {
					$v = esc_html( ucfirst( $v ) );
				} else {
					$v = esc_html( $v );
				}

				$data['s'][] = '__' . strtoupper( $k ) . '__';
				$data['r'][] = $v; // phpcs: sanitize ok.
			}



			// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$isSent = wp_mail(
				$this->ticketRecipient,
				$subject,
				$this->getSupportTicketEmailTemplate( $data ),
				$headers
			);
			// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail

			if ( $isSent ) {
				if ( $this->supportResponse ) {
					$message = is_callable( $this->supportResponse ) ? call_user_func_array( $this->supportResponse , [] ) : $this->supportResponse;
				} else {
					$message = '<h3>' . __( 'Thank you -- Support Ticket Submitted.', 'storeengine-sdk' ) . '</h3>';
				}

				wp_send_json_success( wp_kses_post( $message ) );
			} else {
				if ( $this->supportErrorResponse ) {
					$message = is_callable( $this->supportErrorResponse ) ? call_user_func_array( $this->supportErrorResponse , [] ) : $this->supportErrorResponse;
				} else {
					$message = __( 'Something Went Wrong. Please Try Again After Sometime.', 'storeengine-sdk' );
				}

				wp_send_json_error( wp_kses_post( $message ) );
			}
		} else {
			wp_send_json_error( esc_html__( 'Missing Required Fields.', 'storeengine-sdk' ) );
		}
	}

	/**
	 * Handle the plugin deactivation feedback
	 *
	 * @return void
	 */
	public function deactivate_scripts() {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		$reasons           = $this->__get_uninstall_reasons();
		$admin_user        = $this->client->get_admin_data();
		$displayName       = $admin_user->first_name ? trim( $admin_user->first_name . ' ' . $admin_user->last_name ) : $admin_user->display_name;
		$showSupportTicket = $this->ticketTemplate && $this->ticketRecipient;
		?>
		<div class="se-sdk-product-<?php echo esc_attr( $this->client->getSlug() ); ?> se-sdk-deactivation-modal"
			 id="<?php echo esc_attr( $this->client->getSlug() ); ?>-se-sdk-deactivation-modal"
			 aria-label="<?php /* translators: 1: Plugin Name */
		     printf( esc_attr__( '&ldquo;%s&rdquo; Uninstall Confirmation', 'storeengine-sdk' ), esc_attr( $this->client->getPackageName() ) ); ?>"
			 role="dialog" aria-modal="true" style="--se-sdk-primary-color: <?php echo esc_attr( $this->client->getPrimaryColor() ); ?>;">
			<?php
			if ( $showSupportTicket ) {
				include __DIR__ . '/../views/insights-support-ticket-form.php';
			}

			include __DIR__ . '/../views/insights-deactivation-reasons.php';
			?>
		</div>
		<!--suppress CssUnusedSymbol, CssInvalidPseudoSelector, CssFloatPxLength -->
		<style>
            body.se-sdk-deactivation-modal-open {
                overflow: hidden !important;
            }

            .se-sdk-deactivation-modal, .se-sdk-deactivation-modal * {
                box-sizing: border-box;
            }

            .se-sdk-deactivation-modal {
                position: fixed;
                z-index: 9999999;
                top: 0;
                right: 0;
                bottom: 0;
                left: 0;
                background: rgba(0, 0, 0, 0.5);
                display: none;
            }

            .se-sdk-deactivation-modal.modal-active {
                display: block;
            }

            .se-sdk-deactivation-modal strong, .se-sdk-deactivation-modal b {
                font-weight: bold;
            }

            .se-sdk-deactivation-modal--wrap {
                width: 475px;
                margin: auto;
                background: #fff;
                position: absolute;
                display: block;
                top: 0;
                left: 0;
                right: 0;
				bottom: 0;
                z-index: 99;
                border-radius: 3px;
                box-shadow: 0 15px 15px rgba(0, 0, 0, 0.2);
                height: max-content;
            }

            .se-sdk-deactivation-modal--wrap.support {
                z-index: 999;
            }

            .se-sdk-deactivation-modal--wrap .response {
                position: absolute;
                display: none;
                background: rgba(0, 0, 0, 0.8);
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                overflow: hidden;
            }

            .se-sdk-deactivation-modal {
                color: #787878;
                font-size: 13px;
                line-height: 25px;
            }

            .se-sdk-deactivation-modal .response.show {
                display: block;
            }

            .se-sdk-deactivation-modal--wrap .response .wrapper {
                display: flex;
                align-items: center;
                justify-content: center;
                width: calc(100% - 40px);
                height: calc(100% - 40px);
                flex-flow: column;
                padding: 40px 40px;
                margin: 20px 20px;
                text-align: center;
                background: #FFF;
            }

            @media (max-width: 768px) {
                .se-sdk-deactivation-modal--wrap {
                    width: calc( 100% - 30px ) !important;
                }

                .se-sdk-deactivation-modal--body {
                    padding: 16px !important;
                }
            }

            .se-sdk-deactivation-modal .reason .response .wrapper {
                width: calc(100% - 80px);
                height: calc(100% - 80px);
                flex-flow: column;
                padding: 40px 40px;
                margin: 40px;
            }

            .se-sdk-deactivation-modal .button .dashicons {
                margin: 4px 0;
            }

            .se-sdk-deactivation-modal--header {
                border-bottom: 1px solid #eee;
                padding: 12px 24px;
                position: relative;
                display: block;
                width: 100%;
                float: left;
            }

            .se-sdk-deactivation-modal--header h3 {
                margin: 0;
                color: #141A24;
                font-size: 18px;
                font-style: normal;
                font-weight: 600;
                line-height: 28px;
                display: flex;
                align-items: center;
                gap: 8px;
                flex: 1 0 0;
            }

            .se-sdk-deactivation-modal--close {
                position: absolute;
                top: -15px;
                right: -15px;
                line-height: 0;
                padding: 6px;
                width: 30px;
                height: 30px;
                border-radius: 15px;
                background: #fff;
                box-shadow: 0 5px 7px rgba(0, 0, 0, 0.3);
            }

            .se-sdk-deactivation-modal--close svg {
                font-size: 18px;
                display: inline-block;
                width: 1em;
                height: 1em;
                -webkit-user-select: none;
                -moz-user-select: none;
                -ms-user-select: none;
                user-select: none;
            }

            .se-sdk-deactivation-modal--body {
                padding: 32px;
                position: relative;
                display: block;
                width: 100%;
                float: left;
                box-sizing: border-box;
                max-height: 58dvh;
                overflow: auto;
            }

            .feedback-message {
                color: #141A24;
                font-size: 16px;
                font-style: normal;
                font-weight: 500;
                line-height: 24px;
                margin-bottom: 24px;
            }

            .reasons {
                margin: 0;
                display: flex;
                flex-direction: column;
                gap: 10px
            }

            dd, li {
                margin: 0;
            }

            .se-sdk-deactivation-modal .reason-input {
                margin-top: 5px;
                margin-left: 20px;
            }

            .se-sdk-deactivation-modal--footer {
                display: flex;
                justify-content: space-between;
                padding: 12px 20px;
                position: relative;
                width: 100%;
            }

            .se-sdk-deactivation-modal--open-ticket {
                margin-bottom: 24px;
            }

            .se-sdk-deactivation-modal--open-ticket span {
                color: #738496;
                font-size: 16px;
                font-style: normal;
                font-weight: 500;
                line-height: 24px;
            }

            .se-sdk-deactivation-modal--footer a, .se-sdk-deactivation-modal--footer button {
                vertical-align: middle;
            }

            .support .se-sdk-deactivation-modal--footer {
                text-align: left;
            }

            .support .se-sdk-deactivation-modal--footer button {
                float: right;
                margin-left: 10px;
            }

            .se-sdk-deactivation-modal .se-sdk-row {
                position: relative;
                width: 100%;
                display: block;
                box-sizing: border-box;
                float: left;
                margin: 4px auto;
            }

            .mui {
                border: 0;
                margin: 0;
                display: inline-flex;
                padding: 0;
                min-width: 0;
                flex-direction: column;
                vertical-align: top;
            }

            .se-sdk-deactivation-modal .se-sdk-row.col-2 {
                width: calc(50% - 16px);
            }

            .se-sdk-deactivation-modal .se-sdk-row.col-3 {
                width: calc(calc(100% / 3) - 16px);
            }

            .se-sdk-deactivation-modal .se-sdk-row.col-left {
                margin-right: 8px;
            }

            .se-sdk-deactivation-modal .se-sdk-row.col-center {
                margin-left: 8px;
                margin-right: 8px;
            }

            .se-sdk-deactivation-modal .se-sdk-row.col-right {
                margin-left: 8px;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control {
                cursor: text;
                display: inline-flex;
                position: relative;
                font-size: 1rem;
                box-sizing: border-box;
                align-items: center;
                line-height: 1.1875em;
                width: 100%;
            }

            .se-sdk-deactivation-modal .mui label {
                color: rgba(0, 0, 0, 0.54);
                padding: 0;
                font-size: 1rem;
                font-weight: 400;
                line-height: 1;
                letter-spacing: 0.00938em;
                display: block;
                transform-origin: top left;
                top: 0;
                left: 0;
                position: absolute;
                transform: translate(0, 24px) scale(1);
                transition: color 200ms cubic-bezier(0.0, 0, 0.2, 1) 0ms, transform 200ms cubic-bezier(0.0, 0, 0.2, 1) 0ms, padding 200ms cubic-bezier(0.0, 0, 0.2, 1) 0ms;
            }

            .se-sdk-deactivation-modal .mui label.focused {
                color: var( --se-sdk-primary-color );
            }

            p:not(.helper-text).mui-error, div:not(.helper-text).mui-error,
            .se-sdk-deactivation-modal .mui label.mui-error {
                color: #f02e5e;
                padding: 5px 10px;
            }

            .se-sdk-deactivation-modal .mui label.shrink.mui-error {
                padding: 0;
            }

            p:not(.helper-text).mui-error, div:not(.helper-text).mui-error {
                padding: 5px 10px;
                border: 1px solid #f02e5e;
                font-weight: bold;
            }

            .se-sdk-deactivation-modal .mui label.shrink {
                transform: translate(0, 2px) scale(0.75);
                transform-origin: top left;
            }

            .se-sdk-deactivation-modal .mui label + .se-sdk-form-control {
                margin-top: 16px;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control:before {
                left: 0;
                right: 0;
                bottom: 0;
                content: "\00a0";
                position: absolute;
                transition: border-bottom-color 200ms cubic-bezier(0.4, 0, 0.2, 1) 0ms;
                border-bottom: 1px solid rgba(0, 0, 0, 0.42);
                pointer-events: none;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control:hover:not(.disabled):before {
                border-bottom: 2px solid rgba(0, 0, 0, 0.87);
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control:after {
                left: 0;
                right: 0;
                bottom: 0;
                content: "";
                position: absolute;
                transform: scaleX(0);
                transition: transform 200ms cubic-bezier(0.0, 0, 0.2, 1) 0ms;
                border-bottom: 2px solid var( --se-sdk-primary-color );
                pointer-events: none;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control.focused:after {
                transform: scaleX(1);
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control.mui-error:after {
                transform: scaleX(1);
                border-bottom-color: #f02e5e;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea {
                font: inherit;
                color: currentColor;
                width: 100%;
                border: 0;
                height: 1.1875em;
                min-height: auto;
                margin: 0;
                display: block;
                padding: 6px 0 7px;
                min-width: 0;
                background: none;
                box-sizing: content-box;
                -webkit-tap-highlight-color: transparent;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input {
                animation-name: mui-keyframes-auto-fill-cancel;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input:-moz-autofill,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control input:-webkit-autofill {
                animation-name: mui-keyframes-auto-fill;
                animation-duration: 5000s;
            }

            @-webkit-keyframes mui-keyframes-auto-fill {
            }

            @-webkit-keyframes mui-keyframes-auto-fill-cancel {
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea {
                height: auto;
                resize: none;
                padding: 0;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input::-webkit-search-decoration,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea::-webkit-search-decoration {
                -webkit-appearance: none;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input::-webkit-input-placeholder,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea::-webkit-input-placeholder {
                color: currentColor;
                opacity: 0.42;
                transition: opacity 200ms cubic-bezier(0.4, 0, 0.2, 1) 0ms;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input::-moz-placeholder,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea::-moz-placeholder {
                color: currentColor;
                opacity: 0.42;
                transition: opacity 200ms cubic-bezier(0.4, 0, 0.2, 1) 0ms;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input:-ms-input-placeholder,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea:-ms-input-placeholder {
                color: currentColor;
                opacity: 0.42;
                transition: opacity 200ms cubic-bezier(0.4, 0, 0.2, 1) 0ms;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input::-ms-input-placeholder,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea::-ms-input-placeholder {
                color: currentColor;
                opacity: 0.42;
                transition: opacity 200ms cubic-bezier(0.4, 0, 0.2, 1) 0ms;
            }

            .se-sdk-deactivation-modal .mui .se-sdk-form-control input:focus,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control input:invalid,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea:focus,
            .se-sdk-deactivation-modal .mui .se-sdk-form-control textarea:invalid {
                outline: 0;
                box-shadow: none;
            }

            .se-sdk-deactivation-modal .reason-input input[type="text"]:focus,
            .se-sdk-deactivation-modal .reason-input textarea:focus {
                border-color: var( --se-sdk-primary-color );
                box-shadow: 0 0 0 1px var( --se-sdk-primary-color );
            }

            .se-sdk-deactivation-modal .mui .helper-text {
                color: rgba(0, 0, 0, 0.54);
                margin: 8px 0 0 0;
                font-size: 0.75rem;
                min-height: 1em;
                text-align: left;
                font-weight: 400;
                line-height: 1em;
                letter-spacing: 0.03333em;
            }

            .se-sdk-deactivation-modal .mui .helper-text.contained {
                margin: 8px 14px 0;
            }

            .se-sdk-deactivation-modal .mui .helper-text.mui-error {
                color: #f02e5e;
                position: absolute;
                font-size: 11px;
                margin-top: 3px;
                right: 0;
            }

            .se-sdk-deactivation-modal--open-ticket button,
            .se-sdk-deactivation-modal .button {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2px 14px;
                border-radius: 4px;
                background: var( --se-sdk-primary-color );
                color: #FFFFFF;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
				text-decoration: none;
            }

            .se-sdk-deactivation-modal--open-ticket button {
                color: var( --se-sdk-primary-color );
                gap: 8px;
                border-radius: 9999px;
                border: 1px solid var( --se-sdk-primary-color );
                background: #FFFFFF;
                margin-top: 12px;
                padding: 9px 14px;
			}

            .se-sdk-deactivation-modal .button:focus,
            .se-sdk-deactivation-modal .button:hover {
                background: var( --se-sdk-primary-color );
                border-color: var( --se-sdk-primary-color );
                color: #fff;
				box-shadow: inset 1px 1px 12px -1px #00000040;
			}

            .se-sdk-deactivation-modal--open-ticket button:focus,
            .se-sdk-deactivation-modal .button:focus {
                box-shadow: 0 0 0 1px #fff, 0 0 0 3px var( --se-sdk-primary-color );
            }

            .se-sdk-deactivation-modal .button.button-link,
            .se-sdk-deactivation-modal button.button-link {
                color: #454F59;
                background: transparent;
                border-color: transparent;
            }

            .se-sdk-deactivation-modal .button.button-link:hover,
            .se-sdk-deactivation-modal button.button-link:hover {
                background: transparent;
                border-color: transparent;
                box-shadow: none;
			}

            .se-sdk-deactivation-modal .button.button-link:focus,
            .se-sdk-deactivation-modal button.button-link:focus {
                box-shadow: 0 0 0 1px #fff, 0 0 0 3px currentColor;
			}

            .se-sdk-deactivation-modal .open-ticket-form svg path {
				fill: var( --se-sdk-primary-color );
			}

            .se-sdk-deactivation-modal .button.disabled, .se-sdk-deactivation-modal button.disabled {
                cursor: not-allowed !important;
                background: transparent !important;
                border: 1px solid #c8c8c8;
            }

            .se-sdk-deactivation-modal .button.disabled.button-link, .se-sdk-deactivation-modal button.disabled.button-link {
                border-color: transparent !important;
            }

            .se-sdk-deactivation-modal input[type=radio]:checked::before {
                background-color: var( --se-sdk-primary-color );
            }

            .se-sdk-deactivation-modal input[type=checkbox]:checked::before {
                content: url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27%23645ff5%27%2F%3E%3C%2Fsvg%3E");
            }

            .se-sdk-deactivation-modal input[type=checkbox],
            .se-sdk-deactivation-modal input[type=radio] {
                border-color: var( --se-sdk-primary-color );
            }

            /*.se-sdk-deactivation-modal .se-sdk-row input, .se-sdk-deactivation-modal .se-sdk-row textarea { width: calc( 100% - 10px ); margin: 0 5px; display: block; vertical-align: middle; box-sizing: border-box; float: left; }*/

		</style>
		<!--suppress ES6ConvertVarToLetConst, JSUnresolvedVariable -->
		<script type="text/javascript">
			( function( $ ) {
				/**
				 * Ajax Helper For Submitting Deactivation Reasons
				 * @param {Object} data
				 * @param {*|jQuery} buttonElem
				 * @param {String|Function} callback
				 * @returns {*|jQuery}
				 * @private
				 */
				function _ajax( data, buttonElem, callback ) {
					if ( buttonElem.hasClass( 'disabled' ) ) {
						return;
					}
					buttonElem.data( 'label', buttonElem.text() );
					return $.ajax( {
						url: ajaxurl,
						type: 'POST',
						data: $.fn.extend( {}, {
							action: '<?php echo esc_attr( $this->client->getHookName( 'submit-uninstall-reason' ) ); ?>',
							_wpnonce: '<?php echo esc_attr( wp_create_nonce( $this->client->getHookName( 'insight_action' ) ) ); ?>',
						}, data ), // add default action if action is empty.
						beforeSend: function() {
							buttonElem.addClass( 'disabled' );
							buttonElem.text( '<?php esc_html_e( 'Processing...', 'storeengine-sdk' ); ?>' );
						},
						complete: function( event, xhr, options ) {
							buttonElem.removeClass( 'disabled' );
							buttonElem.text( buttonElem.data('label') );
							if ( 'string' === typeof callback ) {
								window.location.href = callback;
							} else if ( 'function' === typeof callback ) {
								callback( {event: event, xhr: xhr, options: options} );
							}
						},
					} );
				}

				// Variables.
				var modal = $( '#<?php echo esc_attr( $this->client->getSlug() ); ?>-se-sdk-deactivation-modal' ),
					deactivateLink = '',
					reason = modal.find( '.reason' ),
					support = modal.find( '.support' ),
					supportResponse = support.find( '.response' ),
					//reasonResponse = reason.find( '.response' ),
					mui = modal.find( '.mui input, .mui textarea, .mui select' ),
					validMessage = [],
					preventDefault = function( e ) {
						e && e.preventDefault();
					},
					responseButtons = modal.find( '.reason .se-sdk-deactivation-modal--footer .button' ),
					supportURL = '<?php echo esc_url( $this->supportURL ); ?>',
					closeModal = function( e ) {
						preventDefault( e );
						$('body').removeClass('se-sdk-deactivation-modal-open');
						var buttons = modal.find( '.button' );
						modal.removeClass( 'modal-active' );
						// modal.find('.se-sdk-deactivation-modal--wrap').show();
						supportResponse.hide().find( '.wrapper' ).html( '' );
						//reasonResponse.show();
						support.hide();
						reason.show( 0 );
						// enable buttons and restore original labels
						buttons.removeClass( 'disabled' );
						//responseButtons.addClass( 'disabled' );
						buttons.each( function() {
							var self = $( this ), label = self.attr( 'data-label' );
							if ( label ) {
								self.text( label );
							}
						} );
						modal.find( 'input[type="radio"]' ).prop( 'checked', false );
						$( '.reason-input', modal ).remove();
					},
					checkMessageValidity = function( e ) {
						// e.target.checkValidity();
						var target = e && e.target ? e.target : this;
						var self = $( this ), currentMui = self.closest( '.mui' ),
							label = currentMui.find( 'label' ),
							control = currentMui.find( '.se-sdk-form-control' );
						if ( target.checkValidity() ) {
							if ( label.hasClass( 'mui-error' ) ) {
								label.removeClass( 'mui-error' );
							}
							if ( control.hasClass( 'mui-error' ) ) {
								control.removeClass( 'mui-error' );
							}
							currentMui.find( 'p.helper-text' ).hide().remove();
							validMessage.push(true)
						} else {
							validMessage.push(false)
						}
					},
					resetTicketForm = function( clearValues, clearAll ) {
						modal.find( 'p.helper-text.mui-error' ).remove();
						modal.find( '.mui-error' ).removeClass( 'mui-error' );
						if ( clearValues ) {
							if ( clearAll ) {
								mui.val( '' );
							} else {
								modal.find( '#se-sdk-support--message,#se-sdk-support--subject' ).val( '' );
							}
						}
					};

				// The MUI
				{
					// any input el except radio, checkbox and select
					mui.not( 'select' ).not( '[type="checkbox"]' ).not( '[type="radio"]' ).on( 'focus', function() {
						var self = $( this ), currentMui = self.closest( '.mui' ),
							label = currentMui.find( 'label' ),
							control = currentMui.find( '.se-sdk-form-control' );
						control.addClass( 'focused' );
						label.addClass( 'focused' );
						label.addClass( 'shrink' );
					} ).on( 'blur', function() {
						var self = $( this ), currentMui = self.closest( '.mui' ),
							label = currentMui.find( 'label' ),
							control = currentMui.find( '.se-sdk-form-control' );
						control.removeClass( 'focused' );
						label.removeClass( 'focused' );
						if ( self.val() === '' ) {
							label.removeClass( 'shrink' );
						}
					} );
					// any input el in mui
					mui.on( 'blur', checkMessageValidity ).on( 'invalid', function( e ) {
						preventDefault( e );
						var self = $( this ), currentMui = self.closest( '.mui' ),
							label = currentMui.find( 'label' ),
							control = currentMui.find( '.se-sdk-form-control' );
						currentMui.find( 'p.helper-text' ).remove();
						if ( !label.hasClass( 'mui-error' ) ) {
							label.addClass( 'mui-error' );
						}
						if ( !control.hasClass( 'mui-error' ) ) {
							control.addClass( 'mui-error' );
						}
						control.after( '<p class="helper-text mui-error">' + e.target.validationMessage + '</p>' );
					} );
				}

				// The clicker
				$( 'tr[data-slug="<?php echo esc_attr( $this->client->getSlug() ); ?>"] .deactivate a' ).off( 'click' ).on( 'click', function( e ) {
					preventDefault( e );
					$( 'body' ).addClass( 'se-sdk-deactivation-modal-open' );
					modal.addClass( 'modal-active' );
					deactivateLink = $( this ).attr( 'href' );
					modal.find( 'a.dont-bother-me' ).attr( 'href', deactivateLink ).css( 'float', 'left' );
				} );

				// The Modal
				modal
				.on( 'click', '.not-interested', function( e ) {
					preventDefault( e );
					$( this ).closest( '.response' ).slideUp();
					// responseButtons.removeClass('disabled');
				} )
				.on( 'click', '.open-ticket-form', function( e ) {
					preventDefault( e );
					support.show( 0 );
					reason.hide( 0 );
					supportResponse.find( '.wrapper' ).html( '' );
					supportResponse.hide(0);
					resetTicketForm( true );
				} )
				.on( 'click', '.close-ticket', function( e ) {
					preventDefault( e );
					support.hide( 0 );
					reason.show( 0 );
				} )
				.on( 'click', '.modal-close, .se-sdk-deactivation-modal--close', closeModal )
				.on( 'click', '.reason-type:not(.selected-reason)', function() {
					//modal.find( '.reason-input' ).remove();
					modal.find( '.reason-input' ).slideUp(function(){
						$(this).remove();
					});
					var parent = $( this ).closest( '.reason-item' ),
						inputType = parent.data( 'type' );
					$(this).closest('.reasons').find('.selected-reason').removeClass('selected-reason');
						$(this).addClass('selected-reason');
					if ( inputType !== '' ) {
						var reasonMessage = $( 'text' === inputType ? '<input type="text" size="40" />' : '<textarea rows="5" cols="45"></textarea>' ).attr( 'placeholder', parent.data( 'placeholder' ) );
						reasonMessage.slideUp(0);
						$( '<div class="reason-input"></div>' ).append( reasonMessage ).appendTo( parent );
						reasonMessage.slideDown('fast');
						reasonMessage.focus();
					}

					if ( responseButtons.hasClass( 'disabled' ) ) {
						responseButtons.removeClass( 'disabled' );
					}
				} )
				.on( 'click', '.dont-bother-me', function( e ) {
					preventDefault( e );
					_ajax( {
						reason_id: 'no-comment',
						reason_info: '<?php esc_html_e( "I rather wouldn't say.", 'storeengine-sdk' ); ?>',
					}, $( this ), deactivateLink );
				} )
				.on( 'click', '.deactivate', function( e ) {
					preventDefault( e );
					var $radio = $( 'input[type="radio"]:checked', modal ),
						$input = $radio.closest('.reason-item').find( 'textarea, input[type="text"]' );
					_ajax( {
						reason_id: ( 0 === $radio.length ) ? 'none' : $radio.val(),
						reason_info: ( 0 !== $input.length ) ? $input.val().trim() : '',
					}, $( this ), deactivateLink );
				} )
				.on( 'click', '.send-ticket', function( e ) {
					preventDefault( e );
					validMessage = [];
					mui.each( checkMessageValidity );
					if ( !validMessage.every( Boolean ) ) {
						return;
					}
					var buttonElem = $( this ),
						__BTN_TEXT__ = buttonElem.text(),
						data = {
							action: '<?php echo esc_attr( $this->client->getHookName( 'submit-support-ticket' ) ); ?>',
						};
					mui.each( function() {
						data[$( this ).attr( 'name' )] = $( this ).val();
					} );
					_ajax( data, $( this ), function( jqXhr ) {
						buttonElem.removeClass('disabled').text( __BTN_TEXT__ );
						if ( 'error' === jqXhr.xhr ) {
							supportResponse.find( '.wrapper' ).html( '<p class="mui-error"><?php esc_html_e( 'Something went wrong. Please refresh or try again.' ); ?></p>' );
							supportResponse.show();
						} else {
							var response = jqXhr.event.responseJSON;
							if ( response.hasOwnProperty( 'data' ) ) {
								var message = response.success ? '<p>' + response.data + '</p>' : '<p class="mui-error">' + response.data + '</p>';
								supportResponse.find( '.wrapper' ).html( message );
								supportResponse.show();

								if ( response.success ) {
									modal.find( '#se-sdk-support--message,#se-sdk-support--subject' ).val( '' );
								}
								return;
							}

							if ( ! supportURL ) {
								return;
							}
							setTimeout( function() {
								window.open( supportURL, '_blank' );
								// supportResponse.slideUp();
								buttonElem.hasClass( 'disabled' ) && buttonElem.removeClass( 'disabled' );
							}, 5000 );
						}
					} );
				} );
			}( jQuery ) );
			</script>
		<?php
	}

	/**
	 * Run after theme deactivated
	 *
	 * @param string $new_name New Theme Name.
	 * @param WP_Theme $new_theme New Theme WP_Theme Object.
	 * @param WP_Theme $old_theme Old Theme WP_Theme Object.
	 *
	 * @return void
	 */
	public function theme_deactivated( $new_name, $new_theme, $old_theme ) {
		// Make sure this is correct theme to track.
		if ( $old_theme->get_template() == $this->client->getSlug() ) {
			$current_user = wp_get_current_user();

			if ( $current_user->first_name ) {
				$name = trim( $current_user->first_name . ' ' . $current_user->last_name );
			} else {
				$name = $current_user->display_name;
			}

			$data = [
				'reason'      => 'none',
				'message'     => wp_json_encode(
					[
						'new_theme' => [
							'name'          => $new_name,
							'version'       => $new_theme->get( 'Version' ),
							'author'        => $new_theme->get( 'Author' ),
							'parent_theme'  => $new_theme->parent() ? $new_theme->parent()->get( 'Name' ) : '',
							'parent_author' => $new_theme->parent() ? $new_theme->parent()->get( 'Author' ) : '',
						],
					]
				),
				'site'        => $this->__get_site_name(),
				'url'         => esc_url( home_url() ),
				'admin_name'  => $name,
				'admin_email' => $current_user->user_email,
				'usage_log'   => $this->get_tracking_data(),
				'type'        => 'uninstall',
			];

			$this->client->request( [ 'body' => $data, 'route' => 'deactivate-license' ] );
		}
	}

	/**
	 * Get site name
	 * @return string
	 */
	private function __get_site_name(): string {
		$site_name = get_bloginfo( 'name' );
		if ( empty( $site_name ) ) {
			$site_name = get_bloginfo( 'description' );
			$site_name = wp_trim_words( $site_name, 3, '' );
		}
		if ( empty( $site_name ) ) {
			$site_name = get_bloginfo( 'url' );
		}

		return $site_name;
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

// End of file SE_License_SDK_Insights.php.
