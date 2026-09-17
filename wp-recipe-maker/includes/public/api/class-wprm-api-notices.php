<?php
/**
 * API for the admin notices.
 *
 * @link       https://bootstrapped.ventures
 * @since      5.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * API for the admin notices.
 *
 * @since      5.0.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_Notices {

	/**
	 * Register actions and filters.
	 *
	 * @since    5.0.0
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'api_register_data' ) );
	}

	/**
	 * Register data for the REST API.
	 *
	 * @since    5.0.0
	 */
	public static function api_register_data() {
		if ( function_exists( 'register_rest_field' ) ) { // Prevent issue with Jetpack.
			register_rest_route( 'wp-recipe-maker/v1', '/notice', array(
				'callback' => array( __CLASS__, 'api_dismiss_notice' ),
				'methods' => 'DELETE',
				'permission_callback' => array( __CLASS__, 'api_permissions' ),
				'args' => array(
					'id' => array(
						'required' => true,
						'type' => 'string',
						'minLength' => 1,
						'maxLength' => 100,
						'validate_callback' => array( 'WPRM_Notices', 'is_valid_notice_id' ),
					),
					'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
			));
		}
	}

	/**
	 * Only allow authenticated users to dismiss their own notices.
	 * Cookie authentication is protected by WordPress' REST nonce check.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	public static function api_permissions( $request ) {
		return get_current_user_id() && current_user_can( 'read' )
			&& ( ! isset( $request['user_id'] ) || get_current_user_id() === (int) $request['user_id'] );
	}

	/**
	 * Handle dismiss notice call to the REST API.
	 *
	 * @since 5.0.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_dismiss_notice( $request ) {
		$result = WPRM_Notices::dismiss( $request['id'] );

		return rest_ensure_response( $result );
	}
}

WPRM_Api_Notices::init();
