<?php
/**
 * Handle the recipe analytics shortcode.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.8.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/recipe
 */

/**
 * Handle the recipe analytics shortcode.
 *
 * @since      10.8.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/recipe
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_SC_Analytics extends WPRM_Template_Shortcode {
	public static $shortcode = 'wprm-recipe-analytics';

	public static function init() {
		self::$attributes = array(
			'id' => array(
				'default' => '0',
			),
			'type' => array(
				'default' => 'print',
				'type' => 'dropdown',
				'options' => WPRM_Analytics::get_types(),
			),
			'metric' => array(
				'default' => 'total',
				'type' => 'dropdown',
				'options' => array(
					'total' => __( 'Total Interactions', 'wp-recipe-maker' ),
					'unique' => __( 'Unique Visitor Interactions', 'wp-recipe-maker' ),
				),
			),
			'days' => array(
				'default' => '0',
				'type' => 'number',
				'help' => __( 'Number of days to count, including today. Use 0 for all data still stored according to the Analytics retention setting.', 'wp-recipe-maker' ),
			),
			'text' => array(
				'default' => '%count%',
				'type' => 'text',
				'help' => __( 'Use %count% where the formatted analytics count should appear.', 'wp-recipe-maker' ),
			),
			'hide_zero' => array(
				'default' => '0',
				'type' => 'toggle',
			),
			'text_style' => array(
				'default' => 'normal',
				'type' => 'dropdown',
				'options' => 'text_styles',
			),
			'tag' => array(
				'default' => 'span',
				'type' => 'dropdown',
				'options' => 'header_tags',
			),
		);
		parent::init();
	}

	/**
	 * Output for the shortcode.
	 *
	 * @since    10.8.0
	 * @param    array $atts Options passed along with the shortcode.
	 */
	public static function shortcode( $atts ) {
		$atts = parent::get_attributes( $atts );

		$recipe = WPRM_Template_Shortcodes::get_recipe( $atts['id'] );
		if ( ! $recipe || ! $atts['text'] ) {
			return apply_filters( parent::get_hook(), '', $atts, $recipe );
		}

		$is_preview = $atts['is_template_editor_preview'];
		$recipe_id = intval( $recipe->id() );

		if ( ! $is_preview && ( ! $recipe_id || ! WPRM_Settings::get( 'analytics_enabled' ) ) ) {
			return apply_filters( parent::get_hook(), '', $atts, $recipe );
		}

		$type = sanitize_key( $atts['type'] );
		$types = WPRM_Analytics::get_types();
		if ( ! array_key_exists( $type, $types ) ) {
			return apply_filters( parent::get_hook(), '', $atts, $recipe );
		}

		$metric = in_array( $atts['metric'], array( 'total', 'unique' ), true ) ? $atts['metric'] : 'total';
		$days = max( 0, intval( $atts['days'] ) );

		if ( $is_preview ) {
			$count = 123;
		} else {
			$count = WPRM_Analytics::get_action_count_for_recipe( $recipe_id, $type, array(
				'start' => $days ? ( $days - 1 ) . ' days ago' : false,
				'unique' => 'unique' === $metric,
			) );
		}

		if ( ! $count && $atts['hide_zero'] ) {
			return apply_filters( parent::get_hook(), '', $atts, $recipe );
		}

		$text = WPRM_i18n::maybe_translate( $atts['text'] );
		$text = str_ireplace( '%count%', number_format_i18n( $count ), $text );

		$classes = array(
			'wprm-recipe-analytics',
			'wprm-recipe-analytics-' . $type,
			'wprm-recipe-analytics-' . $metric,
			'wprm-block-text-' . $atts['text_style'],
		);

		if ( $atts['class'] ) {
			$classes[] = esc_attr( $atts['class'] );
		}

		$tag = WPRM_Shortcode_Helper::sanitize_html_element( $atts['tag'] );
		$output = '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '">' . WPRM_Shortcode_Helper::sanitize_html( $text ) . '</' . $tag . '>';

		return apply_filters( parent::get_hook(), $output, $atts, $recipe );
	}
}

WPRM_SC_Analytics::init();
