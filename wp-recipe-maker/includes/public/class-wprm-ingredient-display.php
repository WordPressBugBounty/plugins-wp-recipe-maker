<?php
/**
 * Ingredient display helpers.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.3.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Ingredient display helpers.
 *
 * @since      10.3.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Ingredient_Display {
	private static $unit_connector_cache = array();

	/**
	 * Register actions and filters.
	 *
	 * @since	10.3.0
	 */
	public static function init() {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wprm_recipe_data', array( __CLASS__, 'recipe_data' ), 20, 3 );
		}
	}

	/**
	 * Add connector metadata to recipe data used by the editor.
	 *
	 * @since	10.3.0
	 * @param	array       $data    Recipe data.
	 * @param	WPRM_Recipe $recipe  Recipe object.
	 * @param	string      $context Data context.
	 */
	public static function recipe_data( $data, $recipe, $context ) {
		if ( 'api' !== $context ) {
			return $data;
		}

		return self::add_ingredient_unit_connector_data( $data );
	}

	/**
	 * Get valid connector spacing options.
	 *
	 * @since	10.3.0
	 */
	public static function get_connector_spacing_options() {
		return array( 'space-both', 'space-before', 'space-after', 'no-space' );
	}

	/**
	 * Check if the locale commonly needs ingredient unit connectors.
	 *
	 * @since	10.3.0
	 * @param	string|false $locale Locale to check.
	 */
	public static function locale_uses_ingredient_connectors( $locale = false ) {
		if ( false === $locale ) {
			$locale = function_exists( 'get_locale' ) ? get_locale() : '';
		}

		$language = strtolower( substr( str_replace( '-', '_', (string) $locale ), 0, 2 ) );
		$languages = apply_filters( 'wprm_ingredient_unit_connector_locale_languages', array(
			'ca',
			'es',
			'fr',
			'gl',
			'it',
			'pt',
			'ro',
		) );

		return in_array( $language, $languages, true );
	}

	/**
	 * Sanitize connector spacing.
	 *
	 * @since	10.3.0
	 * @param	mixed $spacing Spacing value.
	 */
	public static function sanitize_connector_spacing( $spacing ) {
		$spacing = sanitize_key( $spacing );

		return in_array( $spacing, self::get_connector_spacing_options(), true ) ? $spacing : 'space-both';
	}

	/**
	 * Get connector data for a unit term.
	 *
	 * @since	10.3.0
	 * @param	int $unit_id Ingredient unit term ID.
	 */
	public static function get_unit_connector_data( $unit_id ) {
		$unit_id = intval( $unit_id );

		if ( ! $unit_id ) {
			return self::get_empty_connector_data();
		}

		if ( isset( self::$unit_connector_cache[ $unit_id ] ) ) {
			return self::$unit_connector_cache[ $unit_id ];
		}

		$connector = get_term_meta( $unit_id, 'wprm_ingredient_unit_connector', true );
		$connector = is_scalar( $connector ) ? trim( (string) $connector ) : '';

		$spacing = get_term_meta( $unit_id, 'wprm_ingredient_unit_connector_spacing', true );
		$spacing = self::sanitize_connector_spacing( $spacing );

		$pluralizes_ingredient = get_term_meta( $unit_id, 'wprm_ingredient_unit_connector_pluralizes_ingredient', true );
		$pluralizes_ingredient = '' === $connector ? true : '1' === (string) $pluralizes_ingredient;

		self::$unit_connector_cache[ $unit_id ] = array(
			'connector' => $connector,
			'connector_spacing' => $spacing,
			'connector_pluralizes_ingredient' => $pluralizes_ingredient,
		);

		return self::$unit_connector_cache[ $unit_id ];
	}

	/**
	 * Get connector data for an ingredient in a unit system.
	 *
	 * @since	10.3.0
	 * @param	array $ingredient Ingredient data.
	 * @param	int   $system     Unit system.
	 */
	public static function get_connector_data_for_ingredient( $ingredient, $system = 1 ) {
		$unit_id = self::get_unit_id_for_ingredient( $ingredient, $system );

		return self::get_unit_connector_data( $unit_id );
	}

	/**
	 * Get the ingredient unit ID for a unit system.
	 *
	 * @since	10.3.0
	 * @param	array $ingredient Ingredient data.
	 * @param	int   $system     Unit system.
	 */
	public static function get_unit_id_for_ingredient( $ingredient, $system = 1 ) {
		if ( ! is_array( $ingredient ) ) {
			return 0;
		}

		$system = 2 === intval( $system ) ? 2 : 1;
		$system_key = 'unit-system-' . $system;

		if ( isset( $ingredient['unit_systems'][ $system_key ]['unit_id'] ) ) {
			return intval( $ingredient['unit_systems'][ $system_key ]['unit_id'] );
		}

		if ( isset( $ingredient['converted'][ $system ]['unit_id'] ) ) {
			return intval( $ingredient['converted'][ $system ]['unit_id'] );
		}

		if ( isset( $ingredient['unit_id'] ) ) {
			return intval( $ingredient['unit_id'] );
		}

		return 0;
	}

	/**
	 * Check if the ingredient name should pluralize for the amount.
	 *
	 * @since	10.3.0
	 * @param	array $ingredient Ingredient data.
	 * @param	int   $system     Unit system.
	 */
	public static function connector_allows_ingredient_plural( $ingredient, $system = 1 ) {
		$connector_data = self::get_connector_data_for_ingredient( $ingredient, $system );

		return '' === $connector_data['connector'] || (bool) $connector_data['connector_pluralizes_ingredient'];
	}

	/**
	 * Get the amount to use when selecting ingredient singular/plural.
	 *
	 * @since	10.3.0
	 * @param	array $ingredient Ingredient data.
	 * @param	int   $system     Unit system.
	 * @param	mixed $amount     Ingredient amount.
	 */
	public static function get_amount_for_name_pluralization( $ingredient, $system = 1, $amount = false ) {
		if ( ! self::connector_allows_ingredient_plural( $ingredient, $system ) ) {
			return false;
		}

		return $amount;
	}

	/**
	 * Join amount/unit output and ingredient name output with connector if configured.
	 *
	 * @since	10.3.0
	 * @param	string $amount_unit Amount and unit HTML.
	 * @param	string $name_output Ingredient name HTML.
	 * @param	array  $ingredient  Ingredient data.
	 * @param	int    $system      Unit system.
	 */
	public static function join_amount_unit_and_name_html( $amount_unit, $name_output, $ingredient, $system = 1 ) {
		$amount_unit = self::trim_join_whitespace( $amount_unit );

		if ( '' === $amount_unit || '' === $name_output ) {
			return $amount_unit . $name_output;
		}

		$connector_data = self::get_connector_data_for_ingredient( $ingredient, $system );

		if ( '' === $connector_data['connector'] ) {
			return $amount_unit . '&#32;' . $name_output;
		}

		$spacing = self::get_connector_spacing( $connector_data['connector_spacing'], true );
		$connector = '<span class="wprm-recipe-ingredient-connector" data-spacing="' . esc_attr( $connector_data['connector_spacing'] ) . '">' . $spacing['before'] . esc_html( $connector_data['connector'] ) . $spacing['after'] . '</span>';

		return $amount_unit . $connector . $name_output;
	}

	/**
	 * Join amount/unit text and ingredient name text with connector if configured.
	 *
	 * @since	10.3.0
	 * @param	string $amount_unit Amount and unit text.
	 * @param	string $name        Ingredient name text.
	 * @param	array  $ingredient  Ingredient data.
	 * @param	int    $system      Unit system.
	 */
	public static function join_amount_unit_and_name_text( $amount_unit, $name, $ingredient, $system = 1 ) {
		$amount_unit = trim( (string) $amount_unit );
		$name = trim( (string) $name );

		if ( '' === $amount_unit || '' === $name ) {
			return $amount_unit . $name;
		}

		$connector_data = self::get_connector_data_for_ingredient( $ingredient, $system );

		if ( '' === $connector_data['connector'] ) {
			return $amount_unit . ' ' . $name;
		}

		$spacing = self::get_connector_spacing( $connector_data['connector_spacing'], false );

		return $amount_unit . $spacing['before'] . $connector_data['connector'] . $spacing['after'] . $name;
	}

	/**
	 * Add connector data to ingredients.
	 *
	 * @since	10.3.0
	 * @param	array $data Recipe data.
	 */
	private static function add_ingredient_unit_connector_data( $data ) {
		if ( isset( $data['ingredients'] ) && is_array( $data['ingredients'] ) ) {
			foreach ( $data['ingredients'] as $group_index => $group ) {
				if ( ! isset( $group['ingredients'] ) || ! is_array( $group['ingredients'] ) ) {
					continue;
				}

				foreach ( $group['ingredients'] as $ingredient_index => $ingredient ) {
					$data['ingredients'][ $group_index ]['ingredients'][ $ingredient_index ] = self::maybe_add_ingredient_unit_connector_data( $ingredient );
				}
			}
		}

		if ( isset( $data['ingredients_flat'] ) && is_array( $data['ingredients_flat'] ) ) {
			foreach ( $data['ingredients_flat'] as $index => $ingredient ) {
				if ( isset( $ingredient['type'] ) && 'ingredient' === $ingredient['type'] ) {
					$data['ingredients_flat'][ $index ] = self::maybe_add_ingredient_unit_connector_data( $ingredient );
				}
			}
		}

		return $data;
	}

	/**
	 * Add connector data to a single ingredient.
	 *
	 * @since	10.3.0
	 * @param	array $ingredient Ingredient data.
	 */
	private static function maybe_add_ingredient_unit_connector_data( $ingredient ) {
		if ( ! is_array( $ingredient ) || empty( $ingredient['unit_id'] ) ) {
			return $ingredient;
		}

		$connector_data = self::get_unit_connector_data( $ingredient['unit_id'] );

		if ( '' !== $connector_data['connector'] ) {
			$ingredient['unit_connector'] = $connector_data['connector'];
			$ingredient['unit_connector_spacing'] = $connector_data['connector_spacing'];
			$ingredient['unit_connector_pluralizes_ingredient'] = $connector_data['connector_pluralizes_ingredient'];
		}

		return $ingredient;
	}

	/**
	 * Get empty connector data.
	 *
	 * @since	10.3.0
	 */
	private static function get_empty_connector_data() {
		return array(
			'connector' => '',
			'connector_spacing' => 'space-both',
			'connector_pluralizes_ingredient' => true,
		);
	}

	/**
	 * Get before and after spacing for a connector.
	 *
	 * @since	10.3.0
	 * @param	string $spacing Spacing mode.
	 * @param	bool   $html    Whether to return HTML spaces.
	 */
	private static function get_connector_spacing( $spacing, $html = true ) {
		$space = $html ? '&#32;' : ' ';
		$spacing = self::sanitize_connector_spacing( $spacing );

		switch ( $spacing ) {
			case 'space-before':
				return array(
					'before' => $space,
					'after' => '',
				);
			case 'space-after':
				return array(
					'before' => '',
					'after' => $space,
				);
			case 'no-space':
				return array(
					'before' => '',
					'after' => '',
				);
			default:
				return array(
					'before' => $space,
					'after' => $space,
				);
		}
	}

	/**
	 * Trim regular and HTML entity spaces from a join boundary.
	 *
	 * @since	10.3.0
	 * @param	string $value Value to trim.
	 */
	private static function trim_join_whitespace( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return preg_replace( '/^(?:\s|&nbsp;|&#32;)+|(?:\s|&nbsp;|&#32;)+$/', '', $value );
	}
}

WPRM_Ingredient_Display::init();
