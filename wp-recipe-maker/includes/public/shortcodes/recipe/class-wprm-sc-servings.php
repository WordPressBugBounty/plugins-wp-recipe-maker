<?php
/**
 * Handle the recipe servings shortcode.
 *
 * @link       https://bootstrapped.ventures
 * @since      3.2.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/recipe
 */

/**
 * Handle the recipe servings shortcode.
 *
 * @since      3.2.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/recipe
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_SC_Servings extends WPRM_Template_Shortcode {
	public static $shortcode = 'wprm-recipe-servings';

	public static function init() {
		$atts = array(
			'id' => array(
				'default' => '0',
			),
			'adjustable' => array (
				'default' => 'tooltip',
				'type' => 'dropdown',
				'options' => 'adjustable_servings',
			),
			'button_background' => array(
				'default' => '#333333',
				'type' => 'color',
				'dependency' => array(
					'id' => 'adjustable',
					'value' => 'text-buttons',
				),
			),
			'button_accent' => array(
				'default' => '#ffffff',
				'type' => 'color',
				'dependency' => array(
					'id' => 'adjustable',
					'value' => 'text-buttons',
				),
			),
			'button_radius' => array(
				'default' => '3px',
				'type' => 'size',
				'dependency' => array(
					'id' => 'adjustable',
					'value' => 'text-buttons',
				),
			),
		);

		$atts = array_merge( $atts, WPRM_Shortcode_Helper::get_label_container_atts() );
		self::$attributes = $atts;

		parent::init();
	}

	/**
	 * Output for the shortcode.
	 *
	 * @since	3.2.0
	 * @param	array $atts Options passed along with the shortcode.
	 */
	public static function shortcode( $atts ) {
		$atts = parent::get_attributes( $atts );

		$recipe = WPRM_Template_Shortcodes::get_recipe( $atts['id'] );
		if ( ! $recipe || ! $recipe->servings() ) {
			return apply_filters( parent::get_hook(), '', $atts, $recipe );
		}

		// Output.
		$classes = array(
			'wprm-recipe-servings',
			'wprm-recipe-details',
			'wprm-block-text-' . $atts['text_style'],
		);

		// Add custom class if set.
		if ( $atts['class'] ) { $classes[] = esc_attr( $atts['class'] ); }

		$output = '<span class="' . esc_attr( implode( ' ', $classes ) ) . '">' . self::format_servings( $recipe->servings() ) . '</span>';

		if ( (bool) $atts['label_container'] ) {
			$unit = WPRM_SC_Servings_Unit::shortcode( $atts );
			if ( $unit ) {
				$output = '<span class="wprm-recipe-servings-with-unit">' . $output . ' ' . $unit . '</span>';
			}

			$output = WPRM_Shortcode_Helper::get_label_container( $atts, 'servings', $output );
		}

		return apply_filters( parent::get_hook(), $output, $atts, $recipe );
	}

	/**
	 * Format servings for display.
	 *
	 * @since	10.5.0
	 * @param	mixed $servings Servings value to format.
	 */
	public static function format_servings( $servings ) {
		if ( WPRM_Settings::get( 'servings_display_fractions' ) ) {
			$formatted_servings = WPRM_Recipe_Parser::format_quantity( $servings, WPRM_Settings::get( 'adjustable_servings_round_to_decimals' ), true, true );

			if ( $formatted_servings ) {
				return $formatted_servings;
			}
		}

		return $servings;
	}
}

WPRM_SC_Servings::init();
