<?php
/**
 * API for managing the ratings.
 *
 * @link       https://bootstrapped.ventures
 * @since      5.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 */

/**
 * API for managing the ratings.
 *
 * @since      5.0.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_Manage_Ratings {

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
		if ( function_exists( 'register_rest_field' ) ) {
			register_rest_route( 'wp-recipe-maker/v1', '/manage/rating', array(
				'callback' => array( __CLASS__, 'api_manage_ratings' ),
				'methods' => 'POST',
				'permission_callback' => array( __CLASS__, 'api_required_permissions' ),
			) );
			register_rest_route( 'wp-recipe-maker/v1', '/manage/rating/bulk', array(
				'callback' => array( __CLASS__, 'api_manage_ratings_bulk_edit' ),
				'methods' => 'POST',
				'permission_callback' => array( __CLASS__, 'api_required_permissions' ),
			) );
		}
	}

	/**
	 * Required permissions for the API.
	 *
	 * @since    5.0.0
	 */
	public static function api_required_permissions() {
		return current_user_can( WPRM_Settings::get( 'features_manage_access' ) );
	}

	/**
	 * Handle manage taxonomies call to the REST API.
	 *
	 * @since    5.0.0
	 * @param    WP_REST_Request $request Current request.
	 */
	public static function api_manage_ratings( $request ) {
		global $wpdb;

		// Parameters.
		$params = $request->get_params();

		$page = isset( $params['page'] ) ? intval( $params['page'] ) : 0;
		$page_size = isset( $params['pageSize'] ) ? intval( $params['pageSize'] ) : 25;
		$sorted = isset( $params['sorted'] ) ? $params['sorted'] : array( array( 'id' => 'id', 'desc' => true ) );
		$filtered = isset( $params['filtered'] ) ? $params['filtered'] : array();

		// Starting query args.
		$args = array(
			'limit' => $page_size,
			'offset' => $page * $page_size,
			'filter' => array(),
		);

		// Order.
		$args['order'] = $sorted[0]['desc'] ? 'DESC' : 'ASC';
		$args['orderby'] = $sorted[0]['id'];

		// Filter.
		if ( $filtered ) {
			foreach ( $filtered as $filter ) {
				$value = $filter['value'];
				switch( $filter['id'] ) {
					case 'date':
						$args['filter'][] = 'date LIKE "%' . esc_sql( $wpdb->esc_like( esc_attr( $value ) ) ) . '%"';
						break;
					case 'rating':
						if ( 'all' !== $value ) {
							$args['filter'][] = 'rating = "' . esc_sql( intval( $value ) ) . '"';
						}
						break;
					case 'type':
						if ( 'user' === $value ) {
							$args['filter'][] = 'recipe_id > 0';
						} elseif ( 'comment' === $value ) {
							$args['filter'][] = 'comment_id > 0';
						}
						break;
					case 'approved':
						if ( 'yes' === $value ) {
							$args['filter'][] = 'approved = 1';
						} elseif ( 'no' === $value ) {
							$args['filter'][] = 'approved = 0';
						}
						break;
					case 'has_comment':
						if ( 'yes' === $value ) {
							$args['filter'][] = 'has_comment = 1';
						} elseif ( 'no' === $value ) {
							$args['filter'][] = 'has_comment = 0';
						}
						break;
					case 'user_id':
						$args['filter'][] = 'user_id LIKE "%' . esc_sql( $wpdb->esc_like( intval( $value ) ) ) . '%"';
						break;
					case 'ip':
						$args['filter'][] = 'ip LIKE "%' . esc_sql( $wpdb->esc_like( $value ) ) . '%"';
						break;
					case 'comment_id':
						$args['filter'][] = 'comment_id LIKE "%' . esc_sql( $wpdb->esc_like( intval( $value ) ) ) . '%"';
						break;
					case 'recipe_id':
						$args['filter'][] = 'recipe_id LIKE "%' . esc_sql( $wpdb->esc_like( intval( $value ) ) ). '%"';
						break;
					case 'post_id':
						$args['filter'][] = 'post_id LIKE "%' . esc_sql( $wpdb->esc_like( intval( $value ) ) ). '%"';
						break;
				}
			}

			if ( $args['filter'] ) {
				$args['where'] = implode( ' AND ', $args['filter'] );
			}
		}
		
		$query = WPRM_Rating_Database::get_ratings( $args );

		$total = $query['total'] ? $query['total'] : 0;
		$rows = $query['ratings'] ? array_values( $query['ratings'] ) : array();

		self::prime_rating_manage_caches( $rows );

		// Add extra infromation for the manage page.
		foreach ( $rows as $row ) {
			$row->type = 0 < $row->recipe_id ? 'user' : 'comment';

			if ( 0 < $row->user_id ) {
				$user = get_userdata( $row->user_id );

				if ( $user ) {
					$row->user = $user->display_name;
					$row->user_link = get_edit_user_link( $row->user_id );
				}
			}

			if ( 'user' === $row->type ) {
				$recipe = WPRM_Recipe_Manager::get_recipe( $row->recipe_id );

				if ( $recipe ) {
					$row->recipe = $recipe->name();

					$parent_post_id = $recipe->parent_post_id();

					if ( $parent_post_id ) {
						$row->post_id = $parent_post_id;
					}
				}
			} else {
				$comment = get_comment( $row->comment_id );

				if ( $comment ) {
					$content = trim( substr( $comment->comment_content, 0, 50 ) );

					if ( strlen( $comment->comment_content ) > 50 ) {
						$content .= '...';
					}

					$row->comment = $content;
					$row->comment_link = get_edit_comment_link( $row->comment_id );
					$row->comment_author = $comment->comment_author;
				}
			}

			if ( $row->post_id ) {
				$row->post = get_the_title( $row->post_id );
				$row->post_link = get_edit_post_link( $row->post_id );
			}
		}

		$data = array(
			'rows' => $rows,
			'total' => WPRM_Rating_Database::count_ratings(),
			'filtered' => $total,
			'pages' => ceil( $total / $page_size ),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Prime caches for related objects used while hydrating manage rows.
	 *
	 * @since    10.0.0
	 * @param    array $rows Rating rows returned for the current manage page.
	 */
	private static function prime_rating_manage_caches( $rows ) {
		if ( empty( $rows ) ) {
			return;
		}

		$user_ids = array();
		$comment_ids = array();
		$recipe_ids = array();
		$post_ids = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row->user_id ) ) {
				$user_ids[] = intval( $row->user_id );
			}

			if ( ! empty( $row->comment_id ) ) {
				$comment_ids[] = intval( $row->comment_id );
			}

			if ( ! empty( $row->recipe_id ) ) {
				$recipe_ids[] = intval( $row->recipe_id );
			}

			if ( ! empty( $row->post_id ) ) {
				$post_ids[] = intval( $row->post_id );
			}
		}

		$recipe_ids = array_values( array_unique( array_filter( array_map( 'intval', $recipe_ids ) ) ) );
		if ( ! empty( $recipe_ids ) ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $recipe_ids, false, true );
			} else {
				update_meta_cache( 'post', $recipe_ids );
				foreach ( $recipe_ids as $recipe_id ) {
					get_post( $recipe_id );
				}
			}

			foreach ( $recipe_ids as $recipe_id ) {
				$parent_post_id = intval( get_post_meta( $recipe_id, 'wprm_parent_post_id', true ) );
				if ( $parent_post_id ) {
					$post_ids[] = $parent_post_id;
				}
			}
		}

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( ! empty( $post_ids ) ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $post_ids, false, true );
			} else {
				update_meta_cache( 'post', $post_ids );
				foreach ( $post_ids as $post_id ) {
					get_post( $post_id );
				}
			}
		}

		$comment_ids = array_values( array_unique( array_filter( array_map( 'intval', $comment_ids ) ) ) );
		if ( ! empty( $comment_ids ) ) {
			if ( function_exists( '_prime_comment_caches' ) ) {
				_prime_comment_caches( $comment_ids, true );
			} else {
				foreach ( $comment_ids as $comment_id ) {
					get_comment( $comment_id );
				}
			}
		}

		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! empty( $user_ids ) && function_exists( 'cache_users' ) ) {
			cache_users( $user_ids );
		}
	}

	/**
	 * Handle ratings bulk edit call to the REST API.
	 *
	 * @since    5.0.0
	 * @param    WP_REST_Request $request Current request.
	 */
	public static function api_manage_ratings_bulk_edit( $request ) {
		// Parameters.
		$params = $request->get_params();

		$ids = isset( $params['ids'] ) ? array_map( 'intval', $params['ids'] ) : array();
		$action = isset( $params['action'] ) ? $params['action'] : false;

		if ( $ids && $action && $action['type'] ) {
			switch ( $action['type'] ) {
				case 'delete':
					WPRM_Rating_Database::delete_ratings( $ids );
					break;
			}

			return rest_ensure_response( true );
		}

		return rest_ensure_response( false );
	}
}

WPRM_Api_Manage_Ratings::init();
