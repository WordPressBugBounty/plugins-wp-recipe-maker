<?php
/**
 * Handle the Hubbub Save This shortcode.
 *
 * @link       https://bootstrapped.ventures
 * @since      9.6.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/general
 */

/**
 * Handle the Hubbub Save This shortcode.
 *
 * @since      9.6.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/general
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_SC_Hubbub_Save_This extends WPRM_Template_Shortcode {
	public static $shortcode = 'wprm-hubbub-save-this';

	public static function init() {
		self::$attributes = array(
			'compact' => array(
				'default' => '0',
				'type' => 'toggle',
			),
			'heading' => array(
				'default' => '',
				'type' => 'text',
			),
			'message' => array(
				'default' => '',
				'type' => 'text',
			),
			'consent' => array(
				'default' => 'default',
				'type' => 'dropdown',
				'options' => array(
					'default' => 'Use Hubbub Setting',
					'no' => 'No Consent Checkbox',
					'yes' => 'Consent Required to Submit',
					'mailing-list' => 'Consent for Mailing List Only',
				),
				'value_aliases' => array(
					'0' => 'no',
					'1' => 'yes',
				),
			),
			'consent_text' => array(
				'default' => '',
				'type' => 'text',
				'dependency' => array(
					'id' => 'consent',
					'value' => array( 'yes', 'mailing-list' ),
				),
			),
			'button_text' => array(
				'default' => '',
				'type' => 'text',
			),
			'custom_button_colors' => array(
				'default' => '0',
				'type' => 'toggle',
			),
			'button_background_color' => array(
				'default' => '#000000',
				'type' => 'color',
				'dependency' => array(
					'id' => 'custom_button_colors',
					'value' => '1',
				),
			),
			'button_text_color' => array(
				'default' => '#ffffff',
				'type' => 'color',
				'dependency' => array(
					'id' => 'custom_button_colors',
					'value' => '1',
				),
			),
			'after_form' => array(
				'default' => '',
				'type' => 'text',
			),
		);
		parent::init();
	}

	/**
	 * Output for the shortcode.
	 *
	 * @since	4.0.0
	 * @param	array $atts Options passed along with the shortcode.
	 */
	public static function shortcode( $atts ) {
		$atts = parent::get_attributes( $atts );

		// Shortcode attributes.
		$hubbub_atts = array();

		if ( $atts['heading'] ) { $hubbub_atts['heading'] = $atts['heading']; }
		if ( $atts['message'] ) { $hubbub_atts['message'] = $atts['message']; }

		$consent = self::normalize_consent( $atts['consent'] );
		if ( 'default' !== $consent ) {
			$hubbub_atts['consent'] = $consent;
		}
		if ( in_array( $consent, array( 'yes', 'mailing-list' ), true ) ) {
			if ( $atts['consent_text'] ) { $hubbub_atts['consent_text'] = $atts['consent_text']; }
		}

		if ( $atts['button_text'] ) { $hubbub_atts['button_text'] = $atts['button_text']; }
		if ( $atts['after_form'] ) { $hubbub_atts['after_form'] = $atts['after_form']; }
		if ( (bool) $atts['compact'] ) { $hubbub_atts['compact'] = 'yes'; }

		if ( (bool) $atts['custom_button_colors'] ) {
			if ( $atts['button_background_color'] ) { $hubbub_atts['custom_button_color'] = $atts['button_background_color']; }
			if ( $atts['button_text_color'] ) { $hubbub_atts['custom_button_text_color'] = $atts['button_text_color']; }
		}

		// Construct shortcode.
		$hubbub_shortcode = '[hubbub_save_this';
		if ( $hubbub_atts ) {
			foreach ( $hubbub_atts as $key => $value ) {
				$hubbub_shortcode .= ' ' . $key . '="' . esc_attr( $value ) . '"';
			}
		}
		$hubbub_shortcode .= ']';

		// Try to output.
		$output = do_shortcode( $hubbub_shortcode );

		// Maybe show message in template editor.
		if ( $atts['is_template_editor_preview'] ) {
			if ( $output === $hubbub_shortcode ) {
				$output = '<div class="wprm-template-editor-premium-only">' . __( 'Make sure the Hubbub Pro plugin is installed', 'wp-recipe-maker' ) . '</div>';
			} elseif ( '' === $output ) {
				$output = '<div class="wprm-template-editor-premium-only">' . __( 'Make sure the "Save This" tool is enabled in Hubbub Pro', 'wp-recipe-maker' ) . '</div>';
			}
		} else {
			// Do not output the shortcode itself.
			if ( $output === $hubbub_shortcode ) {
				$output = '';
			}
		}
		
		return apply_filters( parent::get_hook(), $output, $atts );
	}

	/**
	 * Normalize WPRM and Hubbub consent values.
	 *
	 * @since	10.5.0
	 * @param	mixed $consent Consent attribute.
	 */
	private static function normalize_consent( $consent ) {
		if ( in_array( $consent, array( '1', 1, true, 'yes' ), true ) ) {
			return 'yes';
		}
		if ( in_array( $consent, array( '0', 0, false, 'no' ), true ) ) {
			return 'no';
		}
		if ( 'mailing-list' === $consent ) {
			return 'mailing-list';
		}

		return 'default';
	}
}

WPRM_SC_Hubbub_Save_This::init();
