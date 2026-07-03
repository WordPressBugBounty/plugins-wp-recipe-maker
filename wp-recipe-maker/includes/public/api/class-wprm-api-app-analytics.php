<?php
/**
 * Open up analytics for the companion app in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.7.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 */

/**
 * Open up analytics for the companion app in the WordPress REST API.
 *
 * @since      10.7.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_App_Analytics {
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
			register_rest_route( 'wp-recipe-maker/v1', '/app/analytics/summary', array(
				'callback' => array( __CLASS__, 'api_app_analytics_summary' ),
				'methods' => 'GET',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/analytics/recipe/(?P<id>\d+)', array(
				'callback' => array( __CLASS__, 'api_app_analytics_recipe' ),
				'methods' => 'GET',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
		}
	}

	/**
	 * Handle companion app analytics summary call to the REST API.
	 *
	 * @since    10.7.0
	 * @param    WP_REST_Request $request Current request.
	 */
	public static function api_app_analytics_summary( $request ) {
		if ( ! WPRM_Settings::get( 'analytics_enabled' ) ) {
			return rest_ensure_response( array(
				'enabled' => false,
			) );
		}

		$days = self::get_days_param( $request );

		$data = WPRM_Analytics::get_chart_data( $days, 10 );

		// Add image to the top recipes for display in the app.
		foreach ( $data['per_recipe'] as $index => $top_recipe ) {
			$recipe = WPRM_Recipe_Manager::get_recipe( $top_recipe['id'] );
			$data['per_recipe'][ $index ]['image_url'] = $recipe ? (string) $recipe->image_url( 'medium' ) : '';
		}

		return rest_ensure_response( array(
			'enabled' => true,
			'days' => $days,
			'total' => $data['total'],
			'per_day' => $data['per_day'],
			'top_types' => $data['per_type'],
			'top_recipes' => $data['per_recipe'],
		) );
	}

	/**
	 * Handle companion app recipe analytics call to the REST API.
	 *
	 * @since    10.7.0
	 * @param    WP_REST_Request $request Current request.
	 */
	public static function api_app_analytics_recipe( $request ) {
		if ( ! WPRM_Settings::get( 'analytics_enabled' ) ) {
			return rest_ensure_response( array(
				'enabled' => false,
			) );
		}

		$recipe_id = intval( $request['id'] );
		$days = self::get_days_param( $request );

		return rest_ensure_response( array(
			'enabled' => true,
			'days' => $days,
			'recipe_id' => $recipe_id,
			'period' => self::get_per_type_data_for_recipe( $recipe_id, ( $days - 1 ) . ' days ago' ),
			'lifetime' => self::get_per_type_data_for_recipe( $recipe_id ),
		) );
	}

	/**
	 * Get the per-type analytics data for a recipe.
	 *
	 * @since    10.7.0
	 * @param    int   $recipe_id Recipe to get the data for.
	 * @param    mixed $start Optional start of the range. Lifetime when false.
	 */
	private static function get_per_type_data_for_recipe( $recipe_id, $start = false ) {
		$actions = WPRM_Analytics_Database::get_aggregated_actions_for_recipe( $recipe_id, $start );
		$actions = is_array( $actions ) ? $actions : array();

		$types = WPRM_Analytics::get_types();

		$total = 0;
		$per_type = array();

		foreach ( $actions as $action ) {
			if ( ! $action->type || ! array_key_exists( $action->type, $types ) ) {
				continue;
			}

			$total += intval( $action->total );
			$per_type[] = array(
				'id' => $action->type,
				'name' => $types[ $action->type ],
				'total' => intval( $action->total ),
				'unique' => intval( $action->total_unique ),
			);
		}

		usort( $per_type, function( $a, $b ) {
			return $b['total'] - $a['total'];
		} );

		return array(
			'total' => $total,
			'per_type' => $per_type,
		);
	}

	/**
	 * Get the sanitized days parameter from the request.
	 *
	 * @since    10.7.0
	 * @param    WP_REST_Request $request Current request.
	 */
	private static function get_days_param( $request ) {
		$days = intval( $request->get_param( 'days' ) );

		if ( ! $days ) {
			$days = 8;
		}

		return min( 365, max( 1, $days ) );
	}
}

WPRM_Api_App_Analytics::init();
