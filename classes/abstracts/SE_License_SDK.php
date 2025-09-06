<?php

/**
 * Class SE_License_SDK
 *
 * @codeCoverageIgnore
 */
abstract class SE_License_SDK {

	/**
	 * SDK file path.
	 *
	 * @var string
	 */
	private static $sdk_init_file = '';

	private static $sdk_version = '1.0.0';

	/**
	 * Data store is initialized.
	 *
	 * @var bool
	 */
	private static $sdk_initialized = false;

	private static $items = [];

	/**
	 * Get the absolute system path to the sdk directory, or a file therein
	 *
	 * @static
	 *
	 * @param ?string $path Path relative to sdk directory.
	 *
	 * @return string
	 */
	public static function sdk_path( ?string $path ): string {
		$base = dirname( self::$sdk_init_file );
		if ( $path ) {
			return trailingslashit( $base ) . $path;
		} else {
			return untrailingslashit( $base );
		}
	}

	/**
	 * Get the absolute URL to the sdk directory, or a file therein
	 *
	 * @static
	 *
	 * @param string $path Path relative to sdk directory.
	 *
	 * @return string
	 */
	public static function sdk_url( string $path ): string {
		if ( str_starts_with( self::$sdk_init_file, wp_normalize_path( WPMU_PLUGIN_DIR ) ) || str_starts_with( self::$sdk_init_file, wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
			return plugins_url( $path, self::$sdk_init_file );
		}

		return get_theme_file_uri( $path );
	}

	/**
	 * Autoload.
	 *
	 * @param string $class Class name.
	 */
	public static function autoload( string $class ) {
		$ds          = DIRECTORY_SEPARATOR;
		$classes_dir = self::sdk_path( 'classes' . $ds );
		$separator   = strrpos( $class, '\\' );
		if ( false !== $separator ) {
			if ( 0 !== strpos( $class, 'SE_License_SDK' ) ) {
				return;
			}
			$class = substr( $class, $separator + 1 );
		}

		if ( self::is_class_abstract( $class ) ) {
			$dir = $classes_dir . 'abstracts' . $ds;
		} elseif ( strpos( $class, 'SE_License_SDK' ) === 0 ) {
			$segments = explode( '_', $class );
			$type     = $segments[1] ?? '';

			switch ( $type ) {
				case 'WPCLI':
					$dir = $classes_dir . 'WP_CLI' . $ds;
					break;
				default:
					$dir = $classes_dir;
					break;
			}
		} elseif ( self::is_class_cli( $class ) ) {
			$dir = $classes_dir . 'WP_CLI' . $ds;
		} elseif ( strpos( $class, 'WP_Async_Request' ) === 0 ) {
			$dir = self::sdk_path( 'lib' . $ds );
		} else {
			return;
		}

		if ( file_exists( $dir . "{$class}.php" ) ) {
			include $dir . $class . '.php';
		}
	}

	/**
	 * Initialize the plugin
	 *
	 * @static
	 *
	 * @param string $sdk_init_file Plugin file path.
	 */
	public static function init( string $sdk_init_file ) {
		self::$sdk_init_file = $sdk_init_file;

		spl_autoload_register( [ __CLASS__, 'autoload' ] );

		/**
		 * Fires in the early stages of Action Scheduler init hook.
		 */
		do_action( 'se_license_sdk_pre_init' );

		require_once self::sdk_path( 'functions.php' );
		
		// Ensure initialization on plugin activation.
		if ( ! did_action( 'init' ) ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode

			add_action(
				'init',
				/**
				 * Runs after the active store's init() method has been called.
				 *
				 * It would probably be preferable to have $store->init() (or it's parent method) set this itself,
				 * once it has initialized, however that would cause problems in cases where a custom data store is in
				 * use, and it has not yet been updated to follow that same logic.
				 */
				function () {
					self::$sdk_initialized = true;

					/**
					 * Fires when Action Scheduler is ready: it is safe to use the procedural API after this point.
					 */
					do_action( 'se_license_sdk_init' );
				},
				1
			);
		} else {
			
			self::$sdk_initialized = true;

			/**
			 * Fires when Action Scheduler is ready: it is safe to use the procedural API after this point.
			 */
			do_action( 'se_license_sdk_init' );
		}
	}

	public static function register( string $file, string $name, array $args ) {
		if ( empty( self::$items[ $file ] ) ) {
			$client = SE_License_SDK_Client::get_instance( $file, $name, $args );
			$client->set_sdk_version( self::$sdk_version );

			self::$items[ $file ] = $client;
		}

		return self::$items[ $file ];
	}

	/**
	 * Check whether the AS data store has been initialized.
	 *
	 * @param ?string $function_name The name of the function being called. Optional. Default `null`.
	 *
	 * @return bool
	 */
	public static function is_initialized( ?string $function_name = null ): bool {
		if ( ! self::$sdk_initialized && ! empty( $function_name ) ) {
			$message = sprintf(
			/* translators: %s function name. */
				__( '%s() was called before the StoreEngine License Manager Client SDK was initialized', 'se-license' ),
				esc_attr( $function_name )
			);
			_doing_it_wrong( esc_html( $function_name ), esc_html( $message ), '1.0.0' );
		}

		return self::$sdk_initialized;
	}

	/**
	 * Determine if the class is one of our abstract classes.
	 *
	 * @param string $class The class name.
	 *
	 * @return bool
	 */
	protected static function is_class_abstract( string $class ): bool {
		static $abstracts = [
			'SE_License_SDK'                            => true,
			'SE_License_SDK_WPCLI_Command'              => true,
		];

		return isset( $abstracts[ $class ] ) && $abstracts[ $class ] || false !== strpos( $class, 'SE_License_SDK_Abstract_' );
	}

	/**
	 * Determine if the class is one of our WP CLI classes.
	 *
	 * @param string $class The class name.
	 *
	 * @return bool
	 */
	protected static function is_class_cli( $class ) {
		static $cli_segments = array(
			'QueueRunner'                             => true,
			'Command'                                 => true,
			'ProgressBar'                             => true,
			'\Action_Scheduler\WP_CLI\Action_Command' => true,
			'\Action_Scheduler\WP_CLI\System_Command' => true,
		);

		$segments = explode( '_', $class );
		$segment  = isset( $segments[1] ) ? $segments[1] : $class;

		return isset( $cli_segments[ $segment ] ) && $cli_segments[ $segment ];
	}

	/**
	 * Clone.
	 */
	final public function __clone() {
		trigger_error( 'Singleton. No cloning allowed!', E_USER_ERROR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
	}

	/**
	 * Wakeup.
	 */
	final public function __wakeup() {
		trigger_error( 'Singleton. No serialization allowed!', E_USER_ERROR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
	}

	/**
	 * Construct.
	 */
	final private function __construct() {
	}
}

// End of file SE_License_SDK.php.
