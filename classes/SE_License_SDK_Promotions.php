<?php
/**
 * Class Promotions
 */
final class SE_License_SDK_Promotions {

	/**
	 * Client
	 *
	 * @var SE_License_SDK_Client
	 */
	protected $client;

	/**
	 * Promotions
	 * @var array[]
	 */
	private $promotions = [];

	/**
	 * List of hidden promotions for current user
	 * @var string[]
	 */
	private $hidden_promotions = [];

	protected $cache_ttl;

	private $promo_source;

	/**
	 * Promotions constructor.
	 *
	 * @param SE_License_SDK_Client $client The Client.
	 *
	 */
	public function __construct( SE_License_SDK_Client $client ) {
		$this->client    = $client;
		$this->cache_ttl = 12 * HOUR_IN_SECONDS;
	}

	public function set_source( ?string $source = null ): SE_License_SDK_Promotions {
		if ( $source ) {
			$this->promo_source = esc_url_raw( $source, [ 'https' ] );
		}

		return $this;
	}

	public function set_cache_ttl( ?int $seconds = null ): SE_License_SDK_Promotions {
		if ( $seconds && $seconds >= 12 * HOUR_IN_SECONDS ) {
			$this->cache_ttl = $seconds;
		}

		return $this;
	}

	/**
	 * Init Promotions
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', [ $this, 'init_internal' ] );
	}

	/**
	 * Set environment variables and init internal hooks
	 * @return void
	 */
	public function init_internal() {
		$this->promotions        = $this->get_promos();
		$this->hidden_promotions = get_transient( $this->client->getHookName( 'hidden_promos' ) );

		if ( ! $this->hidden_promotions ) {
			$this->hidden_promotions = [];
		}
//		$this->hidden_promotions = (array) get_user_option( $this->client->getHookName( 'hidden_promos' ), get_current_user_id() );

		// only run if there is active promotions.
		if ( count( $this->promotions ) ) {
			add_action( 'admin_notices', [ $this, 'render_promo_notices' ] );
			add_action( 'admin_print_styles', [ $this, 'print_promo_notice_styles' ], 99 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_promo_notice_scripts' ] );
			add_action( 'admin_print_footer_scripts', [ $this, 'print_promo_notice_scripts' ] );
			add_action( 'wp_ajax_se_dismiss_promo', [ $this, 'handle_promo_dismiss_request' ] );
		}
	}

	/**
	 * Render Promotions
	 * @return void
	 */
	public function render_promo_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( $this->promotions as $promotion ) {
			$wrapperStyles = '';
			$buttonStyles  = '';
			$button        = $promotion['button'] ?? null;
			$logo          = $promotion['logo'] ?? null;
			$has_columns   = $button && $logo;

			if ( ! empty( $promotion['wrapperStyle'] ) ) {
				if ( ! empty( $promotion['wrapperStyle']['color'] ) ) {
					$wrapperStyles .= 'color:' . $promotion['wrapperStyle']['color'] . ';';
				}
				if ( ! empty( $promotion['wrapperStyle']['padding'] ) ) {
					$wrapperStyles .= 'padding:' . $promotion['wrapperStyle']['padding'] . ';';
				}
				if ( ! empty( $promotion['wrapperStyle']['backgroundColor'] ) ) {
					$wrapperStyles .= 'background-color:' . $promotion['wrapperStyle']['backgroundColor'] . ';';
				}
				if ( ! empty( $promotion['wrapperStyle']['backgroundImage'] ) ) {
					$wrapperStyles .= 'background-image: url("' . $promotion['wrapperStyle']['backgroundImage'] . '");';
				}
				if ( ! empty( $promotion['wrapperStyle']['backgroundRepeat'] ) ) {
					$wrapperStyles .= 'background-repeat:' . $promotion['wrapperStyle']['backgroundRepeat'] . ';';
				}
				if ( ! empty( $promotion['wrapperStyle']['backgroundSize'] ) ) {
					$wrapperStyles .= 'background-size:' . $promotion['wrapperStyle']['backgroundSize'] . ';';
				}
			}

			if ( $button ) {
				if ( ! empty( $button['backgroundColor'] ) ) {
					$buttonStyles .= 'background-color:' . $button['backgroundColor'] . ';';
					$buttonStyles .= 'border-color:' . $button['backgroundColor'] . ';';
				}
				if ( ! empty( $button['color'] ) ) {
					$buttonStyles .= 'color:' . $button['color'] . ';';
				}
			}
			?>
			<div class="notice notice-success se-sdk-promo is-dismissible"
				 id="se-sdk-promo-<?php echo esc_attr( $promotion['hash'] ); ?>"
				 data-hash="<?php echo esc_attr( $promotion['hash'] ); ?>"
				 data-nonce="<?php echo esc_attr( wp_create_nonce( 'se-sdk-dismiss-promo' ) ); ?>"
				 style="<?php echo esc_attr( $wrapperStyles ); ?>">
				<div class="se-sdk-promo--wrap<?php echo ! $has_columns ? ' no-column' : ''; ?>">
					<?php if ( $logo && ! empty( $logo['src'] ) ) { ?>
						<div class="se-sdk-promo--logo se-sdk-promo--column">
							<img src="<?php echo esc_url( $logo['src'] ); ?>"
								 alt="<?php echo esc_attr( $logo['alt'] ?? __( 'Campaign Logo', 'storeengine-sdk' ) ); ?>">
						</div>
					<?php } ?>
					<div class="se-sdk-promo--details<?php echo $has_columns ? ' se-sdk-promo--column' : ''; ?>">
						<?php echo wp_kses_post( wpautop( $promotion['content'] ) ); ?>
					</div>
					<?php if ( $button ) { ?>
						<div class="se-sdk-promo--btn-container se-sdk-promo--column">
							<?php if ( ! empty( $button['url'] ) ) { ?>
								<a href="<?php echo esc_url( $button['url'] ); ?>"
								   class="button se-sdk-promo--btn" style="<?php echo esc_attr( $buttonStyles ); ?>"
								   target="_blank" rel="noopener">
									<?php if ( ! empty( $button['label'] ) ) {
										echo wp_kses_post( $button['label'] );
									} else {
										esc_html_e( 'Learn more', 'storeengine-sdk' );
									} ?>
								</a>
							<?php }
							if ( ! empty( $button['after'] ) ) {
								echo wp_kses_post( wpautop( $button['after'] ) );
							} ?>
						</div>
					<?php } ?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Get Promotion Data
	 * Cache First then fetch source url for json data source.
	 *
	 * @return array[]
	 */
	private function get_promos(): array {
		$promos = get_transient( $this->client->getHookName( 'cached_promos' ) );

		if ( false === $promos ) {
			// Fetch promotions data from json source.
			$args = [
				'timeout'  => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'blocking' => true,
				'method'   => 'GET',
			];

			if ( $this->promo_source ) {
				$args['url'] = $this->promo_source;
			} else {
				$args['route'] = 'promotions';
			}

			$response = $this->client->request( $args );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				// Cache Something, reduce request.
				$promos = '[]';
			} else {
				$promos = wp_remote_retrieve_body( $response );
			}

			// Decode to array.
			$promos = json_decode( $promos, true );

			// Filter promotions by date.
			$promos = array_filter( $promos, [ $this, 'is_promo_active' ] );

			// Cache data.
			set_transient( $this->client->getHookName( 'cached_promos' ), $promos, $this->cache_ttl );
		}

		return array_filter( $promos, [ $this, 'is_promo_visible' ] );
	}

	/**
	 * Check if promotion is active by date.
	 * must have start and end property
	 *
	 * @param array $promo {   the promo item.
	 *      Single Promo item
	 *
	 * @type string $content string. required
	 * @type string $start valid timestamp. required
	 * @type string $end valid timestamp. required
	 * }
	 *
	 * @return bool
	 */
	protected function is_promo_active( array $promo ): bool {
		$now = current_time( 'timestamp', 1 ); // phpcs:ignore

		// Valid promo item must have hash, content, start & end date-time.

		return
			! empty( $promo['hash'] ) &&
			! empty( $promo['content'] ) &&
			! empty( $promo['start'] ) &&
			! empty( $promo['end'] ) &&
			strtotime( $promo['start'] ) <= $now &&
			$now <= strtotime( $promo['end'] );
	}

	protected function is_promo_visible( array $promo ): bool {
		return ! in_array( $promo['hash'], $this->hidden_promotions, true ) && $this->is_promo_active( $promo );
	}

	/**
	 * Js Dependencies
	 * @return void
	 */
	public function enqueue_promo_notice_scripts() {
		wp_enqueue_script( 'wp-util' );
		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Script for hiding promo on user click
	 * @return void
	 */
	public function print_promo_notice_scripts() {
		?>
		<script>
			(
				function( $ ) {
					$( document ).on( 'click', '.se-sdk-promo .notice-dismiss', function( e ) {
						e.preventDefault();
						const {hash, nonce: _wpnonce} = $( this ).closest( '.se-sdk-promo' ).data();
						wp.ajax.post( 'se_dismiss_promo', {dismissed: true, hash, _wpnonce} );
					} );
				}
			)( jQuery );
		</script>
		<?php
	}

	/**
	 * Global Promo Styles
	 * @return void
	 */
	public function print_promo_notice_styles() {
		?>
		<!--suppress CssUnusedSymbol -->
		<style>
            .se-sdk-promo {
                border: none;
                padding: 15px 0;
            }

            .se-sdk-promo--wrap {
                display: flex;
                justify-content: center;
                align-items: center;
                text-align: center;
                color: inherit;
                max-width: 1820px;
                margin: 0 auto;
            }

            .se-sdk-promo--wrap.no-column {
                display: block;
            }

            .se-sdk-promo--column.se-sdk-promo--logo {
                flex: 0 0 25%;
            }

            .se-sdk-promo--column.se-sdk-promo--logo img {
                height: 48px;
                width: auto;
            }

            .se-sdk-promo--details {
                display: block;
            }

            .se-sdk-promo--details h3 {
                color: inherit;
                font-size: 30px;
                margin: 12px 0;
            }

            .se-sdk-promo--details p {
                color: inherit;
                font-size: 15px;
            }

            .se-sdk-promo--column.se-sdk-promo--details {
                flex: 0 0 50%;
            }

            .se-sdk-promo--column.se-sdk-promo--btn-container {
                flex: 0 0 25%;
            }

            .se-sdk-promo--wrap .se-sdk-promo--btn {
                position: relative;
                padding: 15px;
                border-radius: 30px;
                font-size: 15px;
                font-weight: 700;
                display: block;
                color: inherit;
                text-decoration: none;
                max-width: 200px;
                margin: 0 auto;
                line-height: normal;
                height: auto;
                box-shadow: 1px 2px 0 rgba(0, 0, 0, 0.1);
            }

            .se-sdk-promo--wrap .se-sdk-promo--btn:focus,
            .se-sdk-promo--wrap .se-sdk-promo--btn:hover,
            .se-sdk-promo--wrap .se-sdk-promo--btn:active {
                box-shadow: inset 3px 4px 6px 0 rgba(1, 9, 12, 0.25);
            }

            .se-sdk-promo--wrap .se-sdk-promo--btn:active {
                top: 1px;
            }

            @media screen and (max-width: 1200px) {
                .se-sdk-promo--wrap {
                    display: block;
                    overflow: hidden;
                }

                .se-sdk-promo--column .se-sdk-promo--logo {
                    width: 100%;
                    margin: 0 auto;
                }

                .se-sdk-promo--column .se-sdk-promo--details {
                    width: 68%;
                    float: left;
                    margin-right: 4%;
                    margin-top: 32px;
                }

                .se-sdk-promo--column.se-sdk-promo--btn-container {
                    width: 28%;
                    float: right;
                    margin-top: 42px;
                }
            }

            @media screen and (max-width: 782px) {
                .se-sdk-promo--wrap .se-sdk-promo--details {
                    float: none;
                    width: 100%;
                }

                .se-sdk-promo--btn-container {
                    float: none;
                    width: 100%;
                    margin-top: 32px;
                }

                .se-sdk-promo--column.se-sdk-promo--btn-container {
                    width: 100%;
                    float: right;
                    margin-top: 42px;
                }
            }
		</style>
		<?php
	}

	/**
	 * Ajax Callback handler for hiding promo
	 * @return void
	 */
	public function handle_promo_dismiss_request() {
		check_ajax_referer( 'se-sdk-dismiss-promo', 'nonce' );
		$promo = sanitize_text_field( $_REQUEST['hash'] ?? '' );

		if ( ! $promo ) {
			wp_send_json_error();
		}

		$this->hidden_promotions[] = $promo;

		set_transient(
			$this->client->getHookName( 'hidden_promos' ),
			array_unique( array_filter( $this->hidden_promotions ) ),
			15 * DAY_IN_SECONDS
		);

//		update_user_option( get_current_user_id(), $this->client->getHookName( 'hidden_promos' ), array_unique( array_filter( $this->hidden_promotions ) ) );

		wp_send_json_success();
	}

	/**
	 * Clear Hidden Promotion preference for User
	 * @return bool
	 */
	public function clear_hidden_promos(): bool {
		if ( ! did_action( 'admin_init' ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Method must be invoked inside admin_init action.', 'storeengine-sdk' ), '1.0.0' );
		}

		//return delete_user_option( get_current_user_id(), $this->client->getHookName( 'hidden_promos' ) );
		return delete_transient( $this->client->getHookName( 'hidden_promos' ) );

	}

	/**
	 * Clear Cached Promotion data
	 * @return bool
	 */
	public function clear_promo_cache(): bool {
		return delete_transient( $this->client->getHookName( 'cached_promos' ) );
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

// End of file SE_License_SDK_Promotions.php.
