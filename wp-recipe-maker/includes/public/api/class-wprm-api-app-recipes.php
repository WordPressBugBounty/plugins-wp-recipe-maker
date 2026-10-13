<?php
/**
 * Open up recipes for WPRM Kitchen in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.7.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 */

/**
 * Open up recipes for WPRM Kitchen in the WordPress REST API.
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
	 * Handle the WPRM Kitchen recipes call to the REST API. Uses the same
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
			$recipe = self::maybe_update_seo( $recipe );
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
	 * Handle the WPRM Kitchen get recipe call to the REST API.
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

		$recipe = self::maybe_update_seo( $recipe );
		$data = $recipe->get_data( 'api' );

		$data['images'] = self::get_recipe_images( $recipe );
		$data['permalink'] = $recipe->permalink( true );
		$data['parent_post_url'] = $recipe->parent_url();

		$parent_post = $recipe->parent_post();
		$data['parent_post_title'] = $parent_post ? get_the_title( $parent_post ) : '';

		$data['seo'] = $recipe->seo();
		$data['seo_test_url'] = (string) WPRM_Seo_Checker::get_test_url( $recipe );
		$data['seo_fix_urls'] = (object) WPRM_Seo_Checker::get_fix_urls( $recipe );

		return rest_ensure_response( $data );
	}

	/**
	 * Generate SEO data that's missing or outdated, the same way the Manage page does.
	 *
	 * @since    10.9.0
	 * @param    WPRM_Recipe $recipe Recipe to check.
	 */
	private static function maybe_update_seo( $recipe ) {
		if ( WPRM_Seo_Checker::is_outdated( $recipe->seo() ) && WPRM_Seo_Checker::update_seo_for( $recipe->id() ) ) {
			$updated_recipe = WPRM_Recipe_Manager::get_recipe( $recipe->id() );
			$recipe = $updated_recipe ? $updated_recipe : $recipe;
		}

		return $recipe;
	}

	/**
	 * Handle the WPRM Kitchen taxonomies call to the REST API. Returns the
	 * data needed to construct recipe filters.
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
	 * Get the slim recipe data used for WPRM Kitchen list rows.
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
			'images' => self::get_recipe_images( $recipe ),
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
			'servings_nutrition' => $recipe->servings_nutrition(),
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
	 * Get the full recipe image and the thumbnails used in the recipe metadata,
	 * following the same rules as WPRM_Metadata::get_metadata_details().
	 *
	 * @since    10.9.0
	 * @param    WPRM_Recipe $recipe Recipe to get the images for.
	 */
	private static function get_recipe_images( $recipe ) {
		if ( ! $recipe->image_id() ) {
			return array();
		}

		// Prevent Jetpack Photon from replacing image URLs, like the metadata does.
		$photon_removed = false;
		if ( class_exists( 'Jetpack' ) && class_exists( '\Automattic\Jetpack\Image_CDN\Image_CDN' ) && Jetpack::is_module_active( 'photon' ) ) {
			$photon_removed = remove_filter( 'image_downsize', array( Automattic\Jetpack\Image_CDN\Image_CDN::instance(), 'filter_image_downsize' ) );
		}

		$sizes = array( 'full', 'wprm-metadata-1_1', 'wprm-metadata-4_3', 'wprm-metadata-16_9' );
		$full_url = '';
		$metadata_urls = array();
		$images = array();

		foreach ( $sizes as $size ) {
			$url = (string) $recipe->image_url( $size );

			if ( 'full' === $size ) {
				$full_url = $url;
			}

			// A missing thumbnail falls back to the full image.
			$generated = '' !== $url && ( 'full' === $size || $url !== $full_url );

			// Food recipes include each unique size, how-to only the full image.
			$in_metadata = false;
			if ( $url && ( 'food' === $recipe->type() || ( 'howto' === $recipe->type() && 'full' === $size ) ) ) {
				$in_metadata = ! in_array( $url, $metadata_urls, true );
				$metadata_urls[] = $url;
			}

			$images[] = array(
				'size' => $size,
				'url' => $url,
				'generated' => $generated,
				'in_metadata' => $in_metadata,
			);
		}

		if ( $photon_removed ) {
			add_filter( 'image_downsize', array( Automattic\Jetpack\Image_CDN\Image_CDN::instance(), 'filter_image_downsize' ), 10, 3 );
		}

		return $images;
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
