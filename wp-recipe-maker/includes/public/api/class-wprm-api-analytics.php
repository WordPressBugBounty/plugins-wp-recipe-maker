<?php
/**
 * Open up recipe analytics in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      6.5.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Open up recipe analytics in the WordPress REST API.
 *
 * @since      6.5.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_Analytics {

	/**
	 * Register actions and filters.
	 *
	 * @since    6.5.0
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'api_register_data' ) );
	}

	/**
	 * Register data for the REST API.
	 *
	 * @since    6.5.0
	 */
	public static function api_register_data() {
		if ( function_exists( 'register_rest_field' ) ) { // Prevent issue with Jetpack.
			register_rest_route( 'wp-recipe-maker/v1', '/analytics', array(
				'callback' => array( __CLASS__, 'api_create_action' ),
				'methods' => 'POST',
				'permission_callback' => array( __CLASS__, 'api_create_permission' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/analytics/(?P<id>\d+)', array(
				'callback' => array( __CLASS__, 'api_delete_action' ),
				'methods' => 'DELETE',
				'args' => array(
					'id' => array(
						'validate_callback' => array( __CLASS__, 'api_validate_numeric' ),
					),
				),
				'permission_callback' => array( __CLASS__, 'api_required_permissions' ),
			));
		}
	}

	/**
	 * Validate ID in API call.
	 *
	 * @since 6.5.0
	 * @param mixed           $param Parameter to validate.
	 * @param WP_REST_Request $request Current request.
	 * @param mixed           $key Key.
	 */
	public static function api_validate_numeric( $param, $request, $key ) {
		return is_numeric( $param );
	}

	/**
	 * Required permissions for the API.
	 *
	 * @since 6.5.0
	 */
	public static function api_required_permissions() {
		return current_user_can( WPRM_Settings::get( 'features_manage_access' ) );
	}

	/**
	 * Handle delete rating call to the REST API.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_delete_action( $request ) {
		return rest_ensure_response( WPRM_Analytics_Database::delete( $request['id'] ) );
	}

	/**
	 * Required permissions for the create action API.
	 *
	 * @since 6.6.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_create_permission( $request ) {
		$params = $request->get_params();
		$nonce = isset( $params['nonce'] ) ? $params['nonce'] : '';

		if ( $nonce && wp_verify_nonce( $nonce, 'wprm' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Handle delete rating call to the REST API.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_create_action( $request ) {
		$params = $request->get_params();

		$recipe_id = isset( $params['recipeId'] ) ? intval( $params['recipeId'] ) : 0;
		$post_id = isset( $params['postId'] ) ? intval( $params['postId'] ) : 0;
		$type = isset( $params['type'] ) ? sanitize_key( $params['type'] ) : '';
		$meta = isset( $params['meta'] ) ? $params['meta'] : array();
		$uid = isset( $params['uid'] ) ? sanitize_key( $params['uid'] ) : '';

		return rest_ensure_response( WPRM_Analytics::register_action( $recipe_id, $post_id, $type, $meta, $uid ) );
	}
}

WPRM_Api_Analytics::init();
