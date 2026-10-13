<?php
/**
 * Open up analytics for WPRM Kitchen in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.7.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 */

/**
 * Open up analytics for WPRM Kitchen in the WordPress REST API.
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
			register_rest_route( 'wp-recipe-maker/v1', '/app/analytics/metadata/v1', array(
				'callback' => array( __CLASS__, 'api_metadata' ),
				'methods' => 'GET',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
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
			register_rest_route( 'wp-recipe-maker/v1', '/app/analytics/link-targets/v1', array(
				'callback' => array( 'WPRM_Analytics_Export', 'api_link_targets' ),
				'methods' => 'GET',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/app/analytics/export/v1', array(
				'callback' => array( 'WPRM_Analytics_Export', 'api_export' ),
				'methods' => 'GET',
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_permissions' ),
			));
		}
	}

	/**
	 * Bounded, public-only metadata for aggregate analytics identities. No visitor
	 * data or full recipe payloads. The shared Kitchen token and Premium
	 * gate apply, including revocation on every request.
	 */
	public static function api_metadata( $request ) {
		if ( ! WPRM_Settings::get( 'analytics_enabled' ) ) {
			return new WP_Error( 'wprm_analytics_disabled', __( 'Local analytics collection is disabled.', 'wp-recipe-maker' ), array( 'status' => 403 ) );
		}
		$kind = $request->get_param( 'kind' );
		$raw = $request->get_param( 'ids' );
		if ( ! in_array( $kind, array( 'recipe', 'post' ), true ) || ! is_string( $raw ) || strlen( $raw ) > 400 || ! preg_match( '/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $raw ) ) {
			return new WP_Error( 'wprm_metadata_invalid', 'Provide a kind and up to 25 positive IDs.', array( 'status' => 400 ) );
		}
		$ids = explode( ',', $raw );
		if ( count( $ids ) > 25 || count( array_unique( $ids ) ) !== count( $ids ) ) {
			return new WP_Error( 'wprm_metadata_invalid', __( 'Provide up to 25 unique IDs.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}
		foreach ( $ids as $id ) {
			if ( strlen( $id ) > 15 || intval( $id ) < 1 ) {
				return new WP_Error( 'wprm_metadata_invalid', 'ID is outside the supported range.', array( 'status' => 400 ) );
			}
		}
		$ids = array_map( 'intval', $ids );
		// Prime post/meta/term caches for the entire batch, including parent pages.
		_prime_post_caches( $ids, true, true );
		$parents = array();
		$recipes = array();
		if ( 'recipe' === $kind ) {
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post && 'wprm_recipe' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password ) {
					$recipe = WPRM_Recipe_Manager::get_recipe( $id );
					if ( $recipe ) {
						$recipes[ $id ] = $recipe;
						$parents[] = intval( $recipe->parent_post_id() );
					}
				}
			}
			_prime_post_caches( array_values( array_unique( array_filter( $parents ) ) ), true, true );
		}
		$items = array();
		foreach ( $ids as $id ) {
			$item = array( 'kind' => $kind, 'remote_id' => $id, 'state' => 'metadata-missing', 'visibility' => 'unknown', 'title' => null, 'permalink' => null, 'taxonomies' => null );
			$post = get_post( $id );
			if ( 'recipe' === $kind ) {
				$recipe = isset( $recipes[ $id ] ) ? $recipes[ $id ] : false;
				$parent = $recipe ? get_post( $recipe->parent_post_id() ) : false;
				if ( $recipe && self::metadata_post_is_public( $parent ) ) {
					$item['state'] = 'available';
					$item['visibility'] = 'public';
					$item['title'] = self::metadata_text( $recipe->name() );
					$item['permalink'] = get_permalink( $parent );
					$taxonomies = array();
					foreach ( get_object_taxonomies( 'wprm_recipe' ) as $taxonomy ) {
						$terms = get_the_terms( $id, $taxonomy );
						if ( is_array( $terms ) ) {
							$key = preg_replace( '/^wprm_/', '', $taxonomy );
							$taxonomies[ $key ] = array_map( function( $term ) { return array( 'id' => intval( $term->term_id ), 'name' => self::metadata_text( $term->name ) ); }, array_slice( array_values( $terms ), 0, 500 ) );
						}
					}
					$item['taxonomies'] = (object) $taxonomies;
				}
			} elseif ( self::metadata_post_is_public( $post ) ) {
				$item['state'] = 'available';
				$item['visibility'] = 'public';
				$item['title'] = self::metadata_text( $post->post_title );
				$item['permalink'] = get_permalink( $post );
			}
			$items[] = $item;
		}
		$response = rest_ensure_response( array( 'schema_version' => 'wprm.analytics.metadata.v1', 'site_id' => WPRM_Api_App_Kitchen::get_site_id(), 'items' => $items ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** Match unauthenticated REST visibility for posts, pages and public types. */
	private static function metadata_post_is_public( $post ) {
		if ( ! $post || ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->show_in_rest ) {
			return false;
		}
		// Respect the post type's REST visibility checks without inheriting a
		// logged-in browser's permissions. Bearer access does not grant editor access.
		$current = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$namespace = ! empty( $type->rest_namespace ) ? $type->rest_namespace : 'wp/v2';
			$base = ! empty( $type->rest_base ) ? $type->rest_base : $type->name;
			$check = new WP_REST_Request( 'GET', '/' . $namespace . '/' . $base . '/' . $post->ID );
			$check->set_param( 'context', 'view' );
			$check->set_param( '_fields', 'id,status,content.protected' );
			// Dispatch internally so membership/REST filters can deny access too.
			$response = rest_do_request( $check );
			$data = $response->get_data();
			return 200 === $response->get_status() && is_array( $data )
				&& isset( $data['id'], $data['status'] ) && intval( $data['id'] ) === $post->ID
				&& 'publish' === $data['status'] && empty( $data['content']['protected'] );
		} finally {
			wp_set_current_user( $current );
		}
	}

	/** Bound decoded plain-text labels without breaking UTF-8 characters. */
	private static function metadata_text( $value ) {
		$text = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' );
		preg_match( '/^.{0,500}/us', $text, $match );
		return isset( $match[0] ) ? $match[0] : '';
	}

	/**
	 * Handle the WPRM Kitchen analytics summary call to the REST API.
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

		// Add image to the top recipes for display in Kitchen.
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
	 * Handle the WPRM Kitchen recipe analytics call to the REST API.
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
