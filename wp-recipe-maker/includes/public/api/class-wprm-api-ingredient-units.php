<?php
/**
 * Handle ingredient units in the WordPress REST API.
 *
 * @link       https://bootstrapped.ventures
 * @since      7.6.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Handle ingredient units in the WordPress REST API.
 *
 * @since      7.6.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Api_Ingredient_Units {

	/**
	 * Register actions and filters.
	 *
	 * @since	7.6.0
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'api_register_data' ) );

		add_action( 'rest_insert_wprm_ingredient_unit', array( __CLASS__, 'api_insert_update_ingredient_unit' ), 10, 3 );
	}

	/**
	 * Register data for the REST API.
	 *
	 * @since    7.6.0
	 */
	public static function api_register_data() {
		if ( function_exists( 'register_rest_field' ) ) { // Prevent issue with Jetpack.
			register_rest_field( 'wprm_ingredient_unit', 'ingredient_unit', array(
				'get_callback'    => array( __CLASS__, 'api_get_ingredient_unit_meta' ),
				'update_callback' => array( __CLASS__, 'api_update_ingredient_unit_meta' ),
				'schema'          => null,
			));
		}
	}
	
	/**
	 * Handle ingredient unit calls to the REST API.
	 *
	 * @since 7.6.0
	 * @param array           $object Details of current post.
	 * @param mixed           $field_name Name of field.
	 * @param WP_REST_Request $request Current request.
	 */
	public static function api_get_ingredient_unit_meta( $object, $field_name, $request ) {
		$meta = get_term_meta( $object[ 'id' ] );

		$data = array(
			'abbr' => self::get_meta_value( $meta, 'wprm_ingredient_unit_abbr' ),
			'plural' => self::get_meta_value( $meta, 'wprm_ingredient_unit_plural' ),
			'connector' => self::get_meta_value( $meta, 'wprm_ingredient_unit_connector' ),
			'connector_spacing' => WPRM_Ingredient_Display::sanitize_connector_spacing( self::get_meta_value( $meta, 'wprm_ingredient_unit_connector_spacing' ) ),
			'connector_pluralizes_ingredient' => '1' === self::get_meta_value( $meta, 'wprm_ingredient_unit_connector_pluralizes_ingredient' ),
		);

		return $data;
	}
	
	/**
	 * Handle ingredient unit calls to the REST API.
	 *
	 * @since 7.6.0
	 * @param array		$meta	Array of meta parsed from the request.
	 * @param WP_Term	$term 	Term to update.
	 */
	public static function api_update_ingredient_unit_meta( $meta, $term ) {
		if ( isset( $meta['abbr'] ) ) {
			$abbr = sanitize_text_field( $meta['abbr'] );
			update_term_meta( $term->term_id, 'wprm_ingredient_unit_abbr', $abbr );
		}
		if ( isset( $meta['plural'] ) ) {
			$plural = sanitize_text_field( $meta['plural'] );
			update_term_meta( $term->term_id, 'wprm_ingredient_unit_plural', $plural );
		}
		if ( isset( $meta['connector'] ) ) {
			$connector = sanitize_text_field( $meta['connector'] );
			update_term_meta( $term->term_id, 'wprm_ingredient_unit_connector', $connector );
		}
		if ( isset( $meta['connector_spacing'] ) ) {
			$connector_spacing = WPRM_Ingredient_Display::sanitize_connector_spacing( $meta['connector_spacing'] );
			update_term_meta( $term->term_id, 'wprm_ingredient_unit_connector_spacing', $connector_spacing );
		}
		if ( isset( $meta['connector_pluralizes_ingredient'] ) ) {
			$connector_pluralizes_ingredient = rest_sanitize_boolean( $meta['connector_pluralizes_ingredient'] ) ? '1' : '0';
			update_term_meta( $term->term_id, 'wprm_ingredient_unit_connector_pluralizes_ingredient', $connector_pluralizes_ingredient );
		}
	}

	/**
	 * Get a scalar value from raw term meta.
	 *
	 * @since 10.3.0
	 * @param array  $meta Raw term meta.
	 * @param string $key  Meta key.
	 */
	private static function get_meta_value( $meta, $key ) {
		if ( ! isset( $meta[ $key ] ) ) {
			return '';
		}

		$value = is_array( $meta[ $key ] ) ? reset( $meta[ $key ] ) : $meta[ $key ];

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Handle ingredient unit calls to the REST API.
	 *
	 * @since 7.6.0
	 * @param WP_Term         $term     Inserted or updated term object.
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating True when creating a post, false when updating.
	 */
	public static function api_insert_update_ingredient_unit( $term, $request, $creating ) {
		$params = $request->get_params();

		// Need to update recipes using this unit if the name changes.
		if ( false === $creating && isset( $params['name'] ) ) {
			$args = array(
				'post_type' => WPRM_POST_TYPE,
				'post_status' => 'any',
				'nopaging' => true,
				'tax_query' => array(
					array(
						'taxonomy' => 'wprm_ingredient_unit',
						'field' => 'id',
						'terms' => $term->term_id,
					),
				)
			);
	
			$query = new WP_Query( $args );
			$posts = $query->posts;
			foreach ( $posts as $post ) {
				$recipe = WPRM_Recipe_Manager::get_recipe( $post );
	
				$new_ingredients = array();
				foreach ( $recipe->ingredients() as $ingredient_group ) {
					$new_ingredient_group = $ingredient_group;
					$new_ingredient_group['ingredients'] = array();
	
					foreach ( $ingredient_group['ingredients'] as $ingredient ) {
						if ( isset( $ingredient['unit_id'] ) && intval( $ingredient['unit_id'] ) === $term->term_id ) {
							$new_name = $term->name;

							// Check if we need to use plural.
							if ( 1.0 !== floatval( $ingredient['amount'] ) ) {
								$plural = get_term_meta( $term->term_id, 'wprm_ingredient_unit_plural', true );

								if ( $plural ) {
									$new_name = $plural;
								}
							}
							$ingredient['unit'] = $new_name;
						}

						// Check converted as well.
						if ( isset( $ingredient['converted'] ) ) {
							foreach ( $ingredient['converted'] as $system => $conversion ) {
								if ( isset( $ingredient['converted'][ $system ]['unit_id'] ) && intval( $ingredient['converted'][ $system ]['unit_id'] ) === $term->term_id ) {
									$new_name = $term->name;

									// Check if we need to use plural.
									if ( 1.0 !== floatval( $ingredient['converted'][ $system ]['amount'] ) ) {
										$plural = get_term_meta( $term->term_id, 'wprm_ingredient_unit_plural', true );

										if ( $plural ) {
											$new_name = $plural;
										}
									}
									$ingredient['converted'][ $system ]['unit'] = $new_name;
								}
							}
						}

						$new_ingredient_group['ingredients'][] = $ingredient;
					}
	
					$new_ingredients[] = $new_ingredient_group;
				}

				update_post_meta( $recipe->id(), 'wprm_ingredients', $new_ingredients );
			}
		}
	}
}

WPRM_Api_Ingredient_Units::init();
