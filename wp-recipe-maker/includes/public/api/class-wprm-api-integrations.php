<?php
/**
 * Open up integrations in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      9.6.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Open up integrations in the WordPress REST API.
 *
 * @since      9.6.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_Integrations {
	/**
	 * Option used to store Jupiter access token metadata.
	 *
	 * @since 10.7.0
	 * @access private
	 * @var string
	 */
	private static $jupiter_access_option = 'wprm_jupiter_access';

	/**
	 * Allowed rel/nofollow values for ingredient links.
	 *
	 * @since 10.7.0
	 * @access private
	 * @var array
	 */
	private static $jupiter_allowed_nofollow = array( 'default', 'follow', 'nofollow', 'sponsored' );

	/**
	 * Register actions and filters.
	 *
	 * @since    9.6.0
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'api_register_data' ) );
	}

	/**
	 * Register data for the REST API.
	 *
	 * @since    9.6.0
	 */
	public static function api_register_data() {
		if ( function_exists( 'register_rest_field' ) ) { // Prevent issue with Jetpack.
			register_rest_route( 'wp-recipe-maker/v1', '/integrations/instacart', array(
				'callback' => array( __CLASS__, 'api_instacart' ),
				'methods' => 'POST',
				'permission_callback' => '__return_true',
			));
			register_rest_route( 'wp-recipe-maker/v1', '/integrations/jupiter/token', array(
				'callback' => array( __CLASS__, 'api_get_jupiter_token' ),
				'methods' => 'GET',
				'permission_callback' => array( __CLASS__, 'api_jupiter_token_admin_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/integrations/jupiter/token', array(
				'callback' => array( __CLASS__, 'api_generate_jupiter_token' ),
				'methods' => 'POST',
				'permission_callback' => array( __CLASS__, 'api_jupiter_token_admin_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/integrations/jupiter/token', array(
				'callback' => array( __CLASS__, 'api_revoke_jupiter_token' ),
				'methods' => 'DELETE',
				'permission_callback' => array( __CLASS__, 'api_jupiter_token_admin_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/integrations/jupiter/ingredient-links', array(
				'callback' => array( __CLASS__, 'api_get_jupiter_ingredient_links' ),
				'methods' => 'GET',
				'permission_callback' => array( __CLASS__, 'api_jupiter_token_permissions' ),
			));
			register_rest_route( 'wp-recipe-maker/v1', '/integrations/jupiter/ingredient-links', array(
				'callback' => array( __CLASS__, 'api_update_jupiter_ingredient_links' ),
				'methods' => 'PUT',
				'permission_callback' => array( __CLASS__, 'api_jupiter_token_permissions' ),
			));
		}
	}

	/**
	 * Handle Instacart Integration call to the REST API.
	 *
	 * @since	9.6.0
	 * @param	WP_REST_Request $request Current request.
	 */
	public static function api_instacart( $request ) {
		$params = $request->get_params();

		$data = isset( $params['data'] ) ? $params['data'] : false;

		if ( $data ) {
			$link = WPRM_Instacart::get_link_for_recipe( $data );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			return rest_ensure_response( $link );
		}

		return rest_ensure_response( false );
	}

	/**
	 * Required permissions for managing the Jupiter token.
	 *
	 * @since 10.7.0
	 */
	public static function api_jupiter_token_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Required permissions for Jupiter ingredient link API calls.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_jupiter_token_permissions( $request ) {
		if ( ! WPRM_Settings::get( 'integration_jupiter' ) ) {
			return new WP_Error(
				'wprm_jupiter_inactive',
				__( 'The Jupiter integration is not active on this site.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::jupiter_ingredient_links_available() ) {
			return new WP_Error(
				'wprm_jupiter_premium_required',
				__( 'Ingredient links require WP Recipe Maker Premium.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		$token = self::get_jupiter_token_from_request( $request );

		if ( ! $token || ! self::is_valid_jupiter_token( $token ) ) {
			return new WP_Error(
				'wprm_jupiter_invalid_token',
				__( 'Invalid Jupiter access token.', 'wp-recipe-maker' ),
				array( 'status' => 401 )
			);
		}

		self::update_jupiter_last_used();

		return true;
	}

	/**
	 * Handle get Jupiter token status call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_get_jupiter_token( $request ) {
		return rest_ensure_response( self::get_jupiter_token_status() );
	}

	/**
	 * Handle generate Jupiter token call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_generate_jupiter_token( $request ) {
		if ( ! WPRM_Settings::get( 'integration_jupiter' ) ) {
			return new WP_Error(
				'wprm_jupiter_inactive',
				__( 'Activate Jupiter before generating an access token.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::jupiter_ingredient_links_available() ) {
			return new WP_Error(
				'wprm_jupiter_premium_required',
				__( 'Ingredient links require WP Recipe Maker Premium.', 'wp-recipe-maker' ),
				array( 'status' => 403 )
			);
		}

		$token = self::generate_jupiter_token_secret();

		$access = array(
			'version' => 1,
			'token_hash' => self::hash_jupiter_token( $token ),
			'created_at' => current_time( 'mysql' ),
			'created_by' => get_current_user_id(),
			'last_used_at' => '',
		);

		update_option( self::$jupiter_access_option, $access, false );

		$status = self::get_jupiter_token_status();
		$status['token'] = $token;

		return rest_ensure_response( $status );
	}

	/**
	 * Handle revoke Jupiter token call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_revoke_jupiter_token( $request ) {
		delete_option( self::$jupiter_access_option );

		return rest_ensure_response( self::get_jupiter_token_status() );
	}

	/**
	 * Handle get Jupiter ingredient links call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_get_jupiter_ingredient_links( $request ) {
		$params = $request->get_params();

		$page = isset( $params['page'] ) ? max( 1, intval( $params['page'] ) ) : 1;
		$per_page = isset( $params['per_page'] ) ? intval( $params['per_page'] ) : 100;
		$per_page = min( 500, max( 1, $per_page ) );
		$search = isset( $params['search'] ) ? sanitize_text_field( wp_unslash( (string) $params['search'] ) ) : '';

		$args = array(
			'taxonomy' => 'wprm_ingredient',
			'hide_empty' => false,
			'orderby' => 'name',
			'order' => 'ASC',
			'number' => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		);

		$count_args = array(
			'taxonomy' => 'wprm_ingredient',
			'hide_empty' => false,
			'fields' => 'count',
		);

		if ( $search ) {
			$args['search'] = $search;
			$count_args['search'] = $search;
		}

		$query = new WP_Term_Query( $args );
		$terms = ! empty( $query->terms ) && ! is_wp_error( $query->terms ) ? array_values( $query->terms ) : array();
		$total = get_terms( $count_args );
		$total = is_wp_error( $total ) ? 0 : intval( $total );

		$links = array();
		foreach ( $terms as $term ) {
			$links[] = self::get_jupiter_ingredient_link_data( $term );
		}

		return rest_ensure_response( array(
			'links' => $links,
			'page' => $page,
			'per_page' => $per_page,
			'total' => $total,
			'total_pages' => $per_page ? intval( ceil( $total / $per_page ) ) : 0,
		) );
	}

	/**
	 * Handle update Jupiter ingredient links call to the REST API.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_update_jupiter_ingredient_links( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$links = isset( $params['links'] ) && is_array( $params['links'] ) ? $params['links'] : array();
		$updated = array();
		$errors = array();

		foreach ( $links as $index => $link ) {
			if ( ! is_array( $link ) ) {
				$errors[] = array(
					'index' => $index,
					'message' => __( 'Invalid ingredient link item.', 'wp-recipe-maker' ),
				);
				continue;
			}

			$term = self::get_jupiter_ingredient_term_for_update( $link );

			if ( ! $term ) {
				$errors[] = array(
					'index' => $index,
					'message' => __( 'Could not find or create the ingredient term.', 'wp-recipe-maker' ),
				);
				continue;
			}

			if ( array_key_exists( 'url', $link ) ) {
				$url = trim( (string) $link['url'] );

				if ( '' === $url ) {
					delete_term_meta( $term->term_id, 'wprmp_ingredient_link' );
				} else {
					update_term_meta( $term->term_id, 'wprmp_ingredient_link', esc_url_raw( $url ) );
				}
			}

			if ( array_key_exists( 'nofollow', $link ) ) {
				$nofollow = in_array( $link['nofollow'], self::$jupiter_allowed_nofollow, true ) ? $link['nofollow'] : 'default';
				update_term_meta( $term->term_id, 'wprmp_ingredient_link_nofollow', $nofollow );
			}

			$updated[] = self::get_jupiter_ingredient_link_data( $term );
		}

		return rest_ensure_response( array(
			'success' => empty( $errors ),
			'updated' => count( $updated ),
			'links' => $updated,
			'errors' => $errors,
		) );
	}

	/**
	 * Check if ingredient links are available.
	 *
	 * @since 10.7.0
	 */
	private static function jupiter_ingredient_links_available() {
		return class_exists( 'WPRMP_Ingredient_Links' );
	}

	/**
	 * Get Jupiter access token status.
	 *
	 * @since 10.7.0
	 */
	private static function get_jupiter_token_status() {
		$access = self::get_jupiter_access();

		return array(
			'has_token' => ! empty( $access['token_hash'] ),
			'created_at' => isset( $access['created_at'] ) ? $access['created_at'] : '',
			'created_by' => isset( $access['created_by'] ) ? intval( $access['created_by'] ) : 0,
			'last_used_at' => isset( $access['last_used_at'] ) ? $access['last_used_at'] : '',
			'active' => (bool) WPRM_Settings::get( 'integration_jupiter' ),
			'ingredient_links_available' => self::jupiter_ingredient_links_available(),
			'ingredient_links_endpoint' => rtrim( get_rest_url( null, 'wp-recipe-maker/v1/integrations/jupiter/ingredient-links' ), '/' ),
		);
	}

	/**
	 * Get stored Jupiter access metadata.
	 *
	 * @since 10.7.0
	 */
	private static function get_jupiter_access() {
		$access = get_option( self::$jupiter_access_option, array() );

		return is_array( $access ) ? $access : array();
	}

	/**
	 * Generate a new Jupiter token secret.
	 *
	 * @since 10.7.0
	 */
	private static function generate_jupiter_token_secret() {
		try {
			$random = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			$random = wp_generate_password( 64, false, false );
		}

		return 'wprm_jupiter_' . $random;
	}

	/**
	 * Get Jupiter bearer token from the REST request.
	 *
	 * @since 10.7.0
	 * @param WP_REST_Request $request Current request.
	 */
	private static function get_jupiter_token_from_request( $request ) {
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

		if ( $authorization && preg_match( '/Bearer\s+(wprm_jupiter_[A-Za-z0-9_]+)/i', $authorization, $matches ) ) {
			return trim( $matches[1] );
		}

		$header_token = $request->get_header( 'x-wprm-jupiter-token' );

		return $header_token ? trim( $header_token ) : '';
	}

	/**
	 * Validate a Jupiter token.
	 *
	 * @since 10.7.0
	 * @param string $token Token to validate.
	 */
	private static function is_valid_jupiter_token( $token ) {
		$access = self::get_jupiter_access();

		if ( empty( $access['token_hash'] ) || ! is_string( $access['token_hash'] ) || ! is_string( $token ) ) {
			return false;
		}

		return hash_equals( $access['token_hash'], self::hash_jupiter_token( $token ) );
	}

	/**
	 * Hash a Jupiter token for storage.
	 *
	 * @since 10.7.0
	 * @param string $token Token to hash.
	 */
	private static function hash_jupiter_token( $token ) {
		return hash( 'sha256', $token );
	}

	/**
	 * Update the last used timestamp for the Jupiter token.
	 *
	 * @since 10.7.0
	 */
	private static function update_jupiter_last_used() {
		$access = self::get_jupiter_access();

		if ( empty( $access['token_hash'] ) ) {
			return;
		}

		$access['last_used_at'] = current_time( 'mysql' );
		update_option( self::$jupiter_access_option, $access, false );
	}

	/**
	 * Get ingredient link data exposed to Jupiter.
	 *
	 * @since 10.7.0
	 * @param WP_Term $term Ingredient term.
	 */
	private static function get_jupiter_ingredient_link_data( $term ) {
		$link_nofollow = get_term_meta( $term->term_id, 'wprmp_ingredient_link_nofollow', true );
		$nofollow = in_array( $link_nofollow, self::$jupiter_allowed_nofollow, true ) ? $link_nofollow : 'default';

		return array(
			'id' => intval( $term->term_id ),
			'name' => $term->name,
			'url' => get_term_meta( $term->term_id, 'wprmp_ingredient_link', true ),
			'nofollow' => $nofollow,
			'count' => intval( $term->count ),
		);
	}

	/**
	 * Get or create an ingredient term for a Jupiter update item.
	 *
	 * @since 10.7.0
	 * @param array $link Link update item.
	 */
	private static function get_jupiter_ingredient_term_for_update( $link ) {
		if ( ! empty( $link['id'] ) ) {
			$term = get_term( intval( $link['id'] ), 'wprm_ingredient' );

			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		if ( empty( $link['name'] ) ) {
			return false;
		}

		$name = WPRM_Recipe_Sanitizer::sanitize_html( (string) $link['name'] );
		$term = term_exists( $name, 'wprm_ingredient' );

		if ( 0 === $term || null === $term ) {
			$term = wp_insert_term( $name, 'wprm_ingredient' );
		}

		if ( is_wp_error( $term ) ) {
			if ( isset( $term->error_data['term_exists'] ) ) {
				$term_id = intval( $term->error_data['term_exists'] );
			} else {
				return false;
			}
		} else {
			$term_id = is_array( $term ) && isset( $term['term_id'] ) ? intval( $term['term_id'] ) : intval( $term );
		}

		if ( ! $term_id ) {
			return false;
		}

		$term_object = get_term( $term_id, 'wprm_ingredient' );

		return $term_object && ! is_wp_error( $term_object ) ? $term_object : false;
	}
}

WPRM_Api_Integrations::init();
