<?php
/**
 * Open up the WPRM Kitchen data endpoints in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.7.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Open up the WPRM Kitchen data endpoints in the WordPress REST API.
 *
 * @since      10.7.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_App {
	/**
	 * Version of the WPRM Kitchen site API, only bumped on breaking changes.
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
			register_rest_route( 'wp-recipe-maker/v1', '/app/info', array(
				'callback' => array( __CLASS__, 'api_get_app_info' ),
				'methods' => 'GET',
				'permission_callback' => array( __CLASS__, 'api_app_token_only_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/token/current', array(
				'callback' => array( __CLASS__, 'api_revoke_current_kitchen_token' ),
				'methods' => 'DELETE',
				'permission_callback' => array( __CLASS__, 'api_kitchen_token_permissions' ),
			));
		}
	}

	/**
	 * Required permissions for WPRM Kitchen data API calls.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_app_permissions( $request ) {
		if ( ! WPRM_Addons::is_active( 'premium' ) ) {
			return new WP_Error(
				'wprm_app_premium_required',
				__( 'WPRM Kitchen data access requires WP Recipe Maker Premium.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		$result = self::validate_app_token( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$route = $request->get_route();
		$required_scope = false !== strpos( $route, '/analytics/' ) ? 'analytics:read' : 'recipes:read';

		if ( ! WPRM_App_Token_Store::has_scope( $result['data'], $required_scope ) ) {
			return new WP_Error(
				'wprm_app_insufficient_scope',
				__( 'The WPRM Kitchen token does not grant access to this endpoint.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		WPRM_App_Token_Store::touch( $result['hash'] );

		return true;
	}

	/**
	 * Token-only permissions, without the premium check. Used for the info
	 * endpoint so Kitchen can report a missing premium plugin gracefully.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_app_token_only_permissions( $request ) {
		$result = self::validate_app_token( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! WPRM_App_Token_Store::has_scope( $result['data'], 'site:read' ) ) {
			return new WP_Error(
				'wprm_app_insufficient_scope',
				__( 'The WPRM Kitchen token does not grant access to this endpoint.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		WPRM_App_Token_Store::touch( $result['hash'] );

		return true;
	}

	/**
	 * Validate the bearer for Kitchen-initiated self-revocation.
	 *
	 * @since 10.9.0
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public static function api_kitchen_token_permissions( $request ) {
		$result = self::validate_app_token( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['data']['kind'] ) || 'kitchen' !== $result['data']['kind'] ) {
			return new WP_Error(
				'wprm_app_kitchen_token_required',
				__( 'This endpoint only accepts the active WPRM Kitchen token.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Let Kitchen revoke exactly its current bearer and linked local metadata.
	 *
	 * @since 10.9.0
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_revoke_current_kitchen_token( $request ) {
		$result = self::validate_app_token( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['data']['kind'] ) || 'kitchen' !== $result['data']['kind'] ) {
			return new WP_Error(
				'wprm_app_kitchen_token_required',
				__( 'This endpoint only accepts the active WPRM Kitchen token.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		if ( ! WPRM_App_Token_Store::revoke_by_hash( $result['hash'] ) ) {
			return new WP_Error(
				'wprm_app_invalid_token',
				__( 'Invalid WPRM Kitchen access token.', 'wp-recipe-maker' ),
				array( 'status' => 401 )
			);
		}

		WPRM_Api_App_Kitchen::handle_token_self_revocation( $result['data'] );

		return rest_ensure_response( array( 'revoked' => true ) );
	}

	/**
	 * Handle get WPRM Kitchen site info call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_get_app_info( $request ) {
		$token = self::get_app_token_from_request( $request );
		$found = WPRM_App_Token_Store::find( $token );
		$token_name = $found && isset( $found['data']['name'] ) ? $found['data']['name'] : '';

		$recipe_count = 0;
		$counts = wp_count_posts( WPRM_POST_TYPE );
		foreach ( array( 'publish', 'future', 'draft', 'pending', 'private' ) as $post_status ) {
			$recipe_count += isset( $counts->{ $post_status } ) ? intval( $counts->{ $post_status } ) : 0;
		}

		return rest_ensure_response( array(
			'api_version' => self::$api_version,
			'site_id' => WPRM_Api_App_Kitchen::get_site_id(),
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
	 * Get the WPRM Kitchen bearer token from the REST request.
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

		return '';
	}

	/**
	 * Validate a WPRM Kitchen token and return its hash when valid.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 * @return array|WP_Error Validated token record or error.
	 */
	private static function validate_app_token( $request ) {
		$token = self::get_app_token_from_request( $request );
		$found = $token ? WPRM_App_Token_Store::find( $token ) : false;

		if ( ! $found || empty( $found['data']['kind'] ) || 'kitchen' !== $found['data']['kind'] ) {
			return new WP_Error(
				'wprm_app_invalid_token',
				__( 'Invalid WPRM Kitchen access token.', 'wp-recipe-maker' ),
				array( 'status' => 401 )
			);
		}

		return $found;
	}
}

WPRM_Api_App::init();
