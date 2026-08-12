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

		$language = self::get_language_from_locale( $locale );
		$languages = self::apply_filters( 'wprm_ingredient_unit_connector_locale_languages', array(
			'ca',
			'es',
			'fr',
			'gl',
			'it',
			'pt',
			'ro',
		) );
		$languages = is_array( $languages ) ? $languages : array();

		return in_array( $language, $languages, true );
	}

	/**
	 * Get settings used to resolve connector elision before ingredient names.
	 *
	 * @since	10.3.0
	 * @param	string|false $locale Locale to check.
	 */
	public static function get_connector_elision_settings( $locale = false ) {
		if ( false === $locale ) {
			$locale = function_exists( 'get_locale' ) ? get_locale() : '';
		}

		$language = self::get_language_from_locale( $locale );
		$rules = self::apply_filters( 'wprm_ingredient_unit_connector_elision_rules', array(
			'ca' => array(
				'connectors' => array(
					'de' => "d'",
				),
				'allow_h_elision' => true,
			),
			'fr' => array(
				'connectors' => array(
					'de' => "d'",
				),
				'allow_h_elision' => true,
				'elision_vowels' => 'aeiou',
				'elision_words' => array(
					'yeuse',
					'yeuses',
					'yeux',
					'ypérite',
					'ypérites',
				),
			),
			'it' => array(
				'connectors' => array(
					'di' => "d'",
				),
				'allow_h_elision' => false,
			),
		) );
		$rules = is_array( $rules ) ? $rules : array();
		$languages = self::apply_filters( 'wprm_ingredient_unit_connector_elision_locale_languages', array_keys( $rules ) );
		$languages = is_array( $languages ) ? $languages : array();
		$language = in_array( $language, $languages, true ) ? $language : '';
		$language_rules = $language && isset( $rules[ $language ] ) && is_array( $rules[ $language ] ) ? $rules[ $language ] : array();
		$connectors = isset( $language_rules['connectors'] ) && is_array( $language_rules['connectors'] ) ? $language_rules['connectors'] : array();
		$normalized_connectors = array();
		foreach ( $connectors as $connector => $elided_connector ) {
			if ( is_scalar( $connector ) && is_scalar( $elided_connector ) ) {
				$normalized_connectors[ strtolower( trim( (string) $connector ) ) ] = trim( (string) $elided_connector );
			}
		}
		$connectors = $normalized_connectors;
		$allow_h_elision = isset( $language_rules['allow_h_elision'] ) ? (bool) $language_rules['allow_h_elision'] : false;
		$elision_vowels = isset( $language_rules['elision_vowels'] ) && is_scalar( $language_rules['elision_vowels'] ) ? strtolower( (string) $language_rules['elision_vowels'] ) : 'aeiouy';
		$elision_vowels = preg_replace( '/[^a-z]/', '', $elision_vowels );
		$elision_words = isset( $language_rules['elision_words'] ) && is_array( $language_rules['elision_words'] ) ? $language_rules['elision_words'] : array();
		$elision_words = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_connector_word' ), $elision_words ) ) ) );

		$h_aspire_words = self::apply_filters( 'wprm_ingredient_unit_connector_elision_h_aspire_words', array(
			'hachis',
			'haddock',
			'haddocks',
			'halva',
			'hamburger',
			'hamburgers',
			'hareng',
			'harengs',
			'haricot',
			'haricots',
			'harissa',
			'homard',
			'homards',
		) );
		$h_aspire_words = is_array( $h_aspire_words ) ? array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_connector_word' ), $h_aspire_words ) ) ) ) : array();

		return array(
			'language' => $language,
			'connectors' => $connectors,
			'allow_h_elision' => $allow_h_elision,
			'elision_vowels' => $elision_vowels,
			'elision_words' => $elision_words,
			'h_aspire_words' => $h_aspire_words,
		);
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
	 * @param	array  $options     Display options.
	 */
	public static function join_amount_unit_and_name_html( $amount_unit, $name_output, $ingredient, $system = 1, $options = array() ) {
		$amount_unit = self::trim_join_whitespace( $amount_unit );

		if ( '' === $amount_unit || '' === $name_output ) {
			return $amount_unit . $name_output;
		}

		$connector_data = self::get_connector_data_for_ingredient( $ingredient, $system );

		if ( '' === $connector_data['connector'] ) {
			return $amount_unit . '&#32;' . $name_output;
		}

		$connector_data = self::resolve_connector_data_for_name( $connector_data, $name_output, $ingredient, $system );
		$spacing = self::get_connector_spacing( $connector_data['connector_spacing'], true );
		if ( is_array( $options ) && ! empty( $options['suppress_connector_before_spacing'] ) ) {
			$spacing['before'] = '';
		}

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

		$connector_data = self::resolve_connector_data_for_name( $connector_data, $name, $ingredient, $system );
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
	 * Resolve connector data for the ingredient name that follows it.
	 *
	 * @since	10.3.0
	 * @param	array  $connector_data Connector data.
	 * @param	string $name_output    Ingredient name output.
	 * @param	array  $ingredient     Ingredient data.
	 * @param	int    $system         Unit system.
	 */
	private static function resolve_connector_data_for_name( $connector_data, $name_output, $ingredient, $system ) {
		$original_connector_data = $connector_data;
		$name = self::get_plain_ingredient_name( $name_output );

		if ( self::should_elide_connector( $connector_data, $name ) ) {
			$settings = self::get_connector_elision_settings();
			$connector = strtolower( trim( (string) $connector_data['connector'] ) );
			$connector_data['connector'] = isset( $settings['connectors'][ $connector ] ) ? $settings['connectors'][ $connector ] : "d'";
			$connector_data['connector_spacing'] = 'space-before';
		}

		return self::apply_filters( 'wprm_ingredient_unit_connector_data_for_name', $connector_data, $original_connector_data, $name, $ingredient, $system );
	}

	/**
	 * Check if the connector should be elided before this ingredient name.
	 *
	 * @since	10.3.0
	 * @param	array  $connector_data Connector data.
	 * @param	string $name           Ingredient name text.
	 */
	private static function should_elide_connector( $connector_data, $name ) {
		if ( ! is_array( $connector_data ) || empty( $connector_data['connector'] ) || '' === $name ) {
			return false;
		}

		$settings = self::get_connector_elision_settings();
		if ( empty( $settings['language'] ) ) {
			return false;
		}

		$connector = strtolower( trim( (string) $connector_data['connector'] ) );
		if ( empty( $settings['connectors'][ $connector ] ) ) {
			return false;
		}

		$word = self::get_first_ingredient_word( $name );
		if ( '' === $word ) {
			return false;
		}

		$normalized_word = self::normalize_connector_word( $word );
		if ( 'fr' === $settings['language'] && in_array( $normalized_word, $settings['h_aspire_words'], true ) ) {
			return false;
		}

		if ( in_array( $normalized_word, $settings['elision_words'], true ) ) {
			return true;
		}

		$first_letter = substr( $normalized_word, 0, 1 );
		if ( false !== strpos( $settings['elision_vowels'], $first_letter ) ) {
			return true;
		}

		$second_letter = substr( $normalized_word, 1, 1 );

		return '' !== $second_letter
			&& ! empty( $settings['allow_h_elision'] )
			&& 'h' === $first_letter
			&& false !== strpos( $settings['elision_vowels'], $second_letter );
	}

	/**
	 * Get the first word of an ingredient name for connector grammar checks.
	 *
	 * @since	10.3.0
	 * @param	string $name Ingredient name.
	 */
	private static function get_first_ingredient_word( $name ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return '';
		}

		$name = preg_replace( '/^[^\p{L}]+/u', '', $name );

		if ( preg_match( '/^[\p{L}\']+/u', $name, $matches ) ) {
			return $matches[0];
		}

		return '';
	}

	/**
	 * Strip markup and helper shortcodes from an ingredient name.
	 *
	 * @since	10.3.0
	 * @param	string $name_output Ingredient name output.
	 */
	private static function get_plain_ingredient_name( $name_output ) {
		$name = is_scalar( $name_output ) ? (string) $name_output : '';
		$name = preg_replace( '/\[\/?adjustable\]/i', '', $name );
		$name = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $name ) : strip_tags( $name );
		$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );

		return trim( $name );
	}

	/**
	 * Normalize a word for connector grammar comparisons.
	 *
	 * @since	10.3.0
	 * @param	string $word Word to normalize.
	 */
	private static function normalize_connector_word( $word ) {
		$word = trim( (string) $word );
		$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
		$word = function_exists( 'remove_accents' ) ? remove_accents( $word ) : strtr( $word, array(
			'à' => 'a',
			'á' => 'a',
			'â' => 'a',
			'ä' => 'a',
			'æ' => 'ae',
			'ç' => 'c',
			'è' => 'e',
			'é' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'ì' => 'i',
			'í' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ò' => 'o',
			'ó' => 'o',
			'ô' => 'o',
			'ö' => 'o',
			'œ' => 'oe',
			'ù' => 'u',
			'ú' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ÿ' => 'y',
		) );

		return $word;
	}

	/**
	 * Apply WordPress filters if available.
	 *
	 * @since	10.3.0
	 * @param	string $tag   Filter tag.
	 * @param	mixed  $value Value to filter.
	 * @param	mixed  ...$args Additional filter arguments.
	 */
	private static function apply_filters( $tag, $value, ...$args ) {
		if ( function_exists( 'apply_filters' ) ) {
			return apply_filters( $tag, $value, ...$args );
		}

		return $value;
	}

	/**
	 * Get a two-letter language code from a locale.
	 *
	 * @since	10.3.0
	 * @param	string $locale Locale to parse.
	 */
	private static function get_language_from_locale( $locale ) {
		return strtolower( substr( str_replace( '-', '_', (string) $locale ), 0, 2 ) );
	}

	/**
	 * Get before and after spacing for a connector.
	 *
	 * @since	10.3.0
	 * @param	string $spacing Spacing mode.
	 * @param	bool   $html    Whether to return HTML spaces.
	 */
	private static function get_connector_spacing( $spacing, $html = true ) {
		$space = $html ? '&nbsp;' : ' ';
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
