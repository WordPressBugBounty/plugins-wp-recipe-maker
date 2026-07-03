<?php
/**
 * Open up recipes for the companion app in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.7.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 */

/**
 * Open up recipes for the companion app in the WordPress REST API.
 *
 * @since      10.7.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_App_Recipes {
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
			register_rest_route( 'wp-recipe-maker/v1', '/app/recipes', array(
				'callback' => array( __CLASS__, 'api_app_recipes' ),
				'methods' => 'POST',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/recipes/(?P<id>\d+)', array(
				'callback' => array( __CLASS__, 'api_app_get_recipe' ),
				'methods' => 'GET',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/taxonomies', array(
				'callback' => array( __CLASS__, 'api_app_taxonomies' ),
				'methods' => 'GET',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
		}
	}

	/**
	 * Handle companion app recipes call to the REST API. Uses the same
	 * parameters as the manage page (page, pageSize, sorted, filtered).
	 *
	 * @since    10.7.0
	 * @param    WP_REST_Request $request Current request.
	 */
	public static function api_app_recipes( $request ) {
		$params = $request->get_params();

		$page_size = isset( $params['pageSize'] ) ? intval( $params['pageSize'] ) : 25;
		$params['pageSize'] = min( 100, max( 1, $page_size ) );

		$result = WPRM_Api_Manage_Recipes::query_recipes( $params, false );

		$rows = array();
		foreach ( $result['recipes'] as $recipe ) {
			$rows[] = self::get_recipe_list_data( $recipe );
		}

		return rest_ensure_response( array(
			'rows' => $rows,
			'total' => $result['total'],
			'filtered' => $result['filtered'],
			'pages' => intval( $result['pages'] ),
			'page' => $result['page'],
			'pageSize' => $result['page_size'],
		) );
	}

	/**
	 * Handle companion app get recipe call to the REST API.
	 *
	 * @since    10.7.0
	 * @param    WP_REST_Request $request Current request.
	 */
	public static function api_app_get_recipe( $request ) {
		$recipe_id = intval( $request['id'] );
		$recipe = WPRM_Recipe_Manager::get_recipe( $recipe_id );

		if ( ! $recipe ) {
			return new WP_Error(
				'wprm_app_recipe_not_found',
				__( 'Could not find a recipe with this ID.', 'wp-recipe-maker' ),
				array( 'status' => 404 )
			);
		}

		$data = $recipe->get_data( 'api' );

		$data['permalink'] = $recipe->permalink( true );
		$data['parent_post_url'] = $recipe->parent_url();

		$parent_post = $recipe->parent_post();
		$data['parent_post_title'] = $parent_post ? get_the_title( $parent_post ) : '';

		$data['seo'] = $recipe->seo();

		return rest_ensure_response( $data );
	}

	/**
	 * Handle companion app taxonomies call to the REST API. Returns the
	 * data needed to construct the recipe filters in the app.
	 *
	 * @since    10.7.0
	 * @param    WP_REST_Request $request Current request.
	 */
	public static function api_app_taxonomies( $request ) {
		$taxonomies = array();

		foreach ( WPRM_Taxonomies::get_taxonomies() as $taxonomy => $options ) {
			$terms = get_terms( array(
				'taxonomy' => $taxonomy,
				'hide_empty' => false,
				'number' => 500,
				'orderby' => 'name',
				'order' => 'ASC',
			) );

			if ( is_wp_error( $terms ) ) {
				$terms = array();
			}

			$taxonomies[] = array(
				'key' => $options['key'],
				'name' => $options['name'],
				'singular_name' => isset( $options['singular_name'] ) ? $options['singular_name'] : $options['name'],
				'terms' => array_map( function( $term ) {
					return array(
						'id' => intval( $term->term_id ),
						'name' => $term->name,
						'count' => intval( $term->count ),
					);
				}, array_values( $terms ) ),
			);
		}

		$post_statuses = array( 'publish', 'future', 'draft', 'private' );
		if ( ! WPRM_Addons::is_active( 'recipe-submission' ) ) {
			$post_statuses[] = 'pending';
		}

		return rest_ensure_response( array(
			'taxonomies' => $taxonomies,
			'post_statuses' => $post_statuses,
			'types' => array( 'food', 'howto', 'other' ),
		) );
	}

	/**
	 * Get the slim recipe data used for list rows in the companion app.
	 *
	 * @since    10.7.0
	 * @param    WPRM_Recipe $recipe Recipe to get the data for.
	 */
	private static function get_recipe_list_data( $recipe ) {
		$rating = WPRM_Rating::get_ratings_summary_for( $recipe->id() );
		$seo = $recipe->seo();

		return array(
			'id' => intval( $recipe->id() ),
			'name' => $recipe->name(),
			'slug' => $recipe->slug(),
			'post_status' => $recipe->post_status(),
			'date' => $recipe->date(),
			'type' => $recipe->type(),
			'image_url' => (string) $recipe->image_url( 'medium' ),
			'permalink' => (string) $recipe->permalink( true ),
			'rating' => array(
				'average' => floatval( isset( $rating['average'] ) ? $rating['average'] : 0 ),
				'count' => intval( isset( $rating['count'] ) ? $rating['count'] : 0 ),
			),
			'prep_time' => intval( $recipe->prep_time() ),
			'cook_time' => intval( $recipe->cook_time() ),
			'total_time' => intval( $recipe->total_time() ),
			'servings' => floatval( $recipe->servings() ),
			'servings_unit' => (string) $recipe->servings_unit(),
			'seo' => array(
				'type' => isset( $seo['type'] ) ? (string) $seo['type'] : 'missing',
				'priority' => intval( isset( $seo['priority'] ) ? $seo['priority'] : 0 ),
			),
			'parent_post_id' => intval( $recipe->parent_post_id() ),
			'tags' => array(
				'course' => self::get_term_names( $recipe->tags( 'course' ) ),
				'cuisine' => self::get_term_names( $recipe->tags( 'cuisine' ) ),
			),
		);
	}

	/**
	 * Get a plain array of term names.
	 *
	 * @since    10.7.0
	 * @param    array $terms Terms to get the names for.
	 */
	private static function get_term_names( $terms ) {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values( array_map( function( $term ) {
			return is_object( $term ) ? $term->name : (string) $term;
		}, $terms ) );
	}
}

WPRM_Api_App_Recipes::init();
