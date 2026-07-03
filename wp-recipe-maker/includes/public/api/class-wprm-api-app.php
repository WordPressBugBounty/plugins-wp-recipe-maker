<?php
/**
 * Open up the companion app endpoints in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.7.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Open up the companion app endpoints in the WordPress REST API.
 *
 * @since      10.7.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_App {
	/**
	 * Option used to store companion app access tokens, keyed by token hash.
	 *
	 * @since 10.7.0
	 * @access private
	 * @var string
	 */
	private static $app_access_option = 'wprm_app_access';

	/**
	 * Maximum number of companion app tokens that can exist at the same time.
	 *
	 * @since 10.7.0
	 * @access private
	 * @var int
	 */
	private static $max_tokens = 20;

	/**
	 * Version of the companion app API, only bumped on breaking changes.
	 *
	 * @since 10.7.0
	 * @access private
	 * @var int
	 */
	private static $api_version = 1;

	/**
	 * Register actions and filters.
	 *
	 * @since    10.7.0
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'api_register_data' ) );
	}

	/**
	 * Register data for the REST API.
	 *
	 * @since    10.7.0
	 */
	public static function api_register_data() {
		if ( function_exists( 'register_rest_field' ) ) { // Prevent issue with Jetpack.
			register_rest_route( 'wp-recipe-maker/v1', '/app/tokens', array(
				'callback' => array( __CLASS__, 'api_get_app_tokens' ),
				'methods' => 'GET',
				'permission_callback' => array( __CLASS__, 'api_app_token_admin_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/tokens', array(
				'callback' => array( __CLASS__, 'api_generate_app_token' ),
				'methods' => 'POST',
				'permission_callback' => array( __CLASS__, 'api_app_token_admin_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/tokens/(?P<id>[a-f0-9]+)', array(
				'callback' => array( __CLASS__, 'api_revoke_app_token' ),
				'methods' => 'DELETE',
				'permission_callback' => array( __CLASS__, 'api_app_token_admin_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/info', array(
				'callback' => array( __CLASS__, 'api_get_app_info' ),
				'methods' => 'GET',
				'permission_callback' => array( __CLASS__, 'api_app_token_only_permissions' ),
			));
		}
	}

	/**
	 * Required permissions for managing companion app tokens.
	 *
	 * @since 10.7.0
	 */
	public static function api_app_token_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Required permissions for companion app data API calls.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_app_permissions( $request ) {
		if ( ! WPRM_Addons::is_active( 'premium' ) ) {
			return new WP_Error(
				'wprm_app_premium_required',
				__( 'The companion app requires WP Recipe Maker Premium.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		return self::api_app_token_only_permissions( $request );
	}

	/**
	 * Token-only permissions, without the premium check. Used for the info
	 * endpoint so the app can report a missing premium plugin gracefully.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_app_token_only_permissions( $request ) {
		$token = self::get_app_token_from_request( $request );
		$token_hash = $token ? self::find_valid_app_token_hash( $token ) : false;

		if ( ! $token_hash ) {
			return new WP_Error(
				'wprm_app_invalid_token',
				__( 'Invalid companion app access token.', 'wp-recipe-maker' ),
				array( 'status' => 401 )
			);
		}

		self::update_app_token_last_used( $token_hash );

		return true;
	}

	/**
	 * Handle get companion app tokens call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_get_app_tokens( $request ) {
		return rest_ensure_response( self::get_app_tokens_status() );
	}

	/**
	 * Handle generate companion app token call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_generate_app_token( $request ) {
		if ( ! WPRM_Addons::is_active( 'premium' ) ) {
			return new WP_Error(
				'wprm_app_premium_required',
				__( 'The companion app requires WP Recipe Maker Premium.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		$access = self::get_app_access();

		if ( self::$max_tokens <= count( $access ) ) {
			return new WP_Error(
				'wprm_app_too_many_tokens',
				__( 'The maximum number of companion app tokens has been reached. Revoke an existing token first.', 'wp-recipe-maker' ),
				array( 'status' => 400 )
			);
		}

		$params = $request->get_params();
		$name = isset( $params['name'] ) ? sanitize_text_field( wp_unslash( (string) $params['name'] ) ) : '';

		if ( '' === $name ) {
			$name = __( 'Unnamed device', 'wp-recipe-maker' );
		}

		$token = self::generate_app_token_secret();

		$access[ self::hash_app_token( $token ) ] = array(
			'id' => self::generate_app_token_id( $access ),
			'name' => $name,
			'created_at' => current_time( 'mysql' ),
			'created_by' => get_current_user_id(),
			'last_used_at' => '',
		);

		update_option( self::$app_access_option, $access, false );

		$status = self::get_app_tokens_status();
		$status['token'] = $token;

		return rest_ensure_response( $status );
	}

	/**
	 * Handle revoke companion app token call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_revoke_app_token( $request ) {
		$id = sanitize_key( $request['id'] );
		$access = self::get_app_access();

		foreach ( $access as $token_hash => $token_data ) {
			if ( isset( $token_data['id'] ) && $id === $token_data['id'] ) {
				unset( $access[ $token_hash ] );
			}
		}

		if ( $access ) {
			update_option( self::$app_access_option, $access, false );
		} else {
			delete_option( self::$app_access_option );
		}

		return rest_ensure_response( self::get_app_tokens_status() );
	}

	/**
	 * Handle get companion app info call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_get_app_info( $request ) {
		$token = self::get_app_token_from_request( $request );
		$token_hash = self::find_valid_app_token_hash( $token );
		$access = self::get_app_access();
		$token_name = $token_hash && isset( $access[ $token_hash ]['name'] ) ? $access[ $token_hash ]['name'] : '';

		$recipe_count = 0;
		$counts = wp_count_posts( WPRM_POST_TYPE );
		foreach ( array( 'publish', 'future', 'draft', 'pending', 'private' ) as $post_status ) {
			$recipe_count += isset( $counts->{ $post_status } ) ? intval( $counts->{ $post_status } ) : 0;
		}

		return rest_ensure_response( array(
			'api_version' => self::$api_version,
			'wprm_version' => WPRM_VERSION,
			'premium_active' => WPRM_Addons::is_active( 'premium' ),
			'site_name' => get_bloginfo( 'name' ),
			'site_url' => home_url(),
			'token_name' => $token_name,
			'analytics_enabled' => (bool) WPRM_Settings::get( 'analytics_enabled' ),
			'recipe_count' => $recipe_count,
		) );
	}

	/**
	 * Get the status of all companion app tokens.
	 *
	 * @since 10.7.0
	 */
	private static function get_app_tokens_status() {
		$access = self::get_app_access();

		$tokens = array();
		foreach ( $access as $token_data ) {
			$tokens[] = array(
				'id' => isset( $token_data['id'] ) ? $token_data['id'] : '',
				'name' => isset( $token_data['name'] ) ? $token_data['name'] : '',
				'created_at' => isset( $token_data['created_at'] ) ? $token_data['created_at'] : '',
				'created_by' => isset( $token_data['created_by'] ) ? intval( $token_data['created_by'] ) : 0,
				'last_used_at' => isset( $token_data['last_used_at'] ) ? $token_data['last_used_at'] : '',
			);
		}

		usort( $tokens, function( $a, $b ) {
			return strcmp( $b['created_at'], $a['created_at'] );
		} );

		return array(
			'tokens' => $tokens,
			'premium_active' => WPRM_Addons::is_active( 'premium' ),
			'api_url' => rtrim( get_rest_url( null, 'wp-recipe-maker/v1/app' ), '/' ),
			'site_name' => get_bloginfo( 'name' ),
			'site_url' => home_url(),
		);
	}

	/**
	 * Get stored companion app access tokens.
	 *
	 * @since 10.7.0
	 */
	private static function get_app_access() {
		$access = get_option( self::$app_access_option, array() );

		return is_array( $access ) ? $access : array();
	}

	/**
	 * Generate a new companion app token secret.
	 *
	 * @since 10.7.0
	 */
	private static function generate_app_token_secret() {
		try {
			$random = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			$random = wp_generate_password( 64, false, false );
		}

		return 'wprm_app_' . $random;
	}

	/**
	 * Generate a unique short id used to identify a token in the UI.
	 *
	 * @since 10.7.0
	 * @param array $access Existing access tokens.
	 */
	private static function generate_app_token_id( $access ) {
		$existing_ids = wp_list_pluck( $access, 'id' );

		do {
			try {
				$id = bin2hex( random_bytes( 4 ) );
			} catch ( Exception $e ) {
				$id = strtolower( wp_generate_password( 8, false, false ) );
			}
		} while ( in_array( $id, $existing_ids, true ) );

		return $id;
	}

	/**
	 * Get companion app bearer token from the REST request.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	private static function get_app_token_from_request( $request ) {
		$authorization = $request->get_header( 'authorization' );

		if ( ! $authorization && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$authorization = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( ! $authorization && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$authorization = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( ! $authorization && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();

			if ( is_array( $headers ) ) {
				foreach ( $headers as $header => $value ) {
					if ( 'authorization' === strtolower( $header ) ) {
						$authorization = sanitize_text_field( wp_unslash( $value ) );
						break;
					}
				}
			}
		}

		if ( $authorization && preg_match( '/Bearer\s+(wprm_app_[A-Za-z0-9_]+)/i', $authorization, $matches ) ) {
			return trim( $matches[1] );
		}

		$header_token = $request->get_header( 'x-wprm-app-token' );

		return $header_token ? trim( $header_token ) : '';
	}

	/**
	 * Validate a companion app token and return its hash when valid.
	 *
	 * @since 10.7.0
	 * @param string $token Token to validate.
	 */
	private static function find_valid_app_token_hash( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		$access = self::get_app_access();
		$token_hash = self::hash_app_token( $token );

		foreach ( array_keys( $access ) as $stored_hash ) {
			if ( is_string( $stored_hash ) && hash_equals( $stored_hash, $token_hash ) ) {
				return $stored_hash;
			}
		}

		return false;
	}

	/**
	 * Hash a companion app token for storage.
	 *
	 * @since 10.7.0
	 * @param string $token Token to hash.
	 */
	private static function hash_app_token( $token ) {
		return hash( 'sha256', $token );
	}

	/**
	 * Update the last used timestamp for a companion app token.
	 *
	 * @since 10.7.0
	 * @param string $token_hash Hash of the token that was used.
	 */
	private static function update_app_token_last_used( $token_hash ) {
		$access = self::get_app_access();

		if ( ! isset( $access[ $token_hash ] ) ) {
			return;
		}

		$access[ $token_hash ]['last_used_at'] = current_time( 'mysql' );
		update_option( self::$app_access_option, $access, false );
	}
}

WPRM_Api_App::init();
