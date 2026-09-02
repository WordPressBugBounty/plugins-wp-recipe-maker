<?php
/**
 * Handle the Google Preferred Sources shortcode.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.9.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/general
 */

/**
 * Handle the Google Preferred Sources shortcode.
 *
 * @since      10.9.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/shortcodes/general
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_SC_Google_Preferred_Sources extends WPRM_Template_Shortcode {
	public static $shortcode = 'wprm-google-preferred-sources';

	/**
	 * Whether the custom button integration script was added.
	 *
	 * @since 10.9.0
	 * @var bool
	 */
	private static $custom_script_added = false;

	/**
	 * Whether the custom script needs the WordPress 4.4 footer fallback.
	 *
	 * @since 10.9.0
	 * @var bool
	 */
	private static $custom_script_fallback = false;

	/**
	 * Initialize the shortcode and Google script integration.
	 *
	 * @since 10.9.0
	 */
	public static function init() {
		$custom_dependency = array(
			'id' => 'display_mode',
			'value' => 'custom',
		);

		self::$attributes = array(
			'display_header' => array(
				'type' => 'header',
				'default' => __( 'Display', 'wp-recipe-maker' ),
			),
			'display_mode' => array(
				'default' => 'official',
				'type' => 'dropdown',
				'options' => array(
					'official' => __( 'Official Google Button', 'wp-recipe-maker' ),
					'custom' => __( 'Custom WPRM Button', 'wp-recipe-maker' ),
				),
				'help' => __( 'The button loads the Google Preferred Sources library on pages where it appears.', 'wp-recipe-maker' ),
			),
			'theme' => array(
				'default' => 'light',
				'type' => 'dropdown',
				'options' => array(
					'light' => __( 'Light', 'wp-recipe-maker' ),
					'dark' => __( 'Dark', 'wp-recipe-maker' ),
				),
				'dependency' => array(
					'id' => 'display_mode',
					'value' => 'official',
				),
			),
			'language' => array(
				'default' => '',
				'type' => 'text',
				'help' => __( 'Optional language code for the official Google button. Leave blank to use the visitor\'s browser language.', 'wp-recipe-maker' ),
				'dependency' => array(
					'id' => 'display_mode',
					'value' => 'official',
				),
			),
			'button_header' => array(
				'type' => 'header',
				'default' => __( 'Custom Button', 'wp-recipe-maker' ),
				'dependency' => $custom_dependency,
			),
			'style' => array(
				'default' => 'inline-button',
				'type' => 'dropdown',
				'options' => array(
					'text' => __( 'Text', 'wp-recipe-maker' ),
					'button' => __( 'Button', 'wp-recipe-maker' ),
					'inline-button' => __( 'Inline Button', 'wp-recipe-maker' ),
					'wide-button' => __( 'Full Width Button', 'wp-recipe-maker' ),
				),
				'dependency' => $custom_dependency,
			),
			'icon' => array(
				'default' => 'google-color',
				'type' => 'icon',
				'dependency' => $custom_dependency,
			),
			'text' => array(
				'default' => __( 'Add to Preferred Sources', 'wp-recipe-maker' ),
				'type' => 'text',
				'dependency' => $custom_dependency,
			),
			'text_style' => array(
				'default' => 'normal',
				'type' => 'dropdown',
				'options' => 'text_styles',
				'dependency' => $custom_dependency,
			),
			'icon_color' => array(
				'default' => '#333333',
				'type' => 'color',
				'dependency' => array(
					$custom_dependency,
					array(
						'id' => 'icon',
						'value' => '',
						'type' => 'inverse',
					),
				),
			),
			'text_color' => array(
				'default' => '#333333',
				'type' => 'color',
				'dependency' => $custom_dependency,
			),
			'horizontal_padding' => array(
				'default' => '10px',
				'type' => 'size',
				'dependency' => array(
					$custom_dependency,
					array(
						'id' => 'style',
						'value' => 'text',
						'type' => 'inverse',
					),
				),
			),
			'vertical_padding' => array(
				'default' => '5px',
				'type' => 'size',
				'dependency' => array(
					$custom_dependency,
					array(
						'id' => 'style',
						'value' => 'text',
						'type' => 'inverse',
					),
				),
			),
			'button_color' => array(
				'default' => '#ffffff',
				'type' => 'color',
				'dependency' => array(
					$custom_dependency,
					array(
						'id' => 'style',
						'value' => 'text',
						'type' => 'inverse',
					),
				),
			),
			'border_color' => array(
				'default' => '#333333',
				'type' => 'color',
				'dependency' => array(
					$custom_dependency,
					array(
						'id' => 'style',
						'value' => 'text',
						'type' => 'inverse',
					),
				),
			),
			'border_radius' => array(
				'default' => '0px',
				'type' => 'size',
				'dependency' => array(
					$custom_dependency,
					array(
						'id' => 'style',
						'value' => 'text',
						'type' => 'inverse',
					),
				),
			),
		);

		parent::init();

		add_filter( 'script_loader_tag', array( __CLASS__, 'add_async_attribute' ), 10, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'output_custom_button_script_fallback' ), 19 );
	}

	/**
	 * Output for the shortcode.
	 *
	 * @since 10.9.0
	 * @param array $atts Options passed along with the shortcode.
	 */
	public static function shortcode( $atts ) {
		$atts = parent::get_attributes( $atts );

		if ( 'print' === WPRM_Context::get() || ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) ) {
			return apply_filters( parent::get_hook(), '', $atts );
		}

		$is_preview = $atts['is_template_editor_preview'] || WPRM_Context::is_gutenberg_preview();

		if ( 'custom' === $atts['display_mode'] ) {
			$output = self::get_custom_button( $atts, $is_preview );
		} else {
			$output = self::get_official_button( $atts, $is_preview );
		}

		if ( ! $is_preview ) {
			self::enqueue_google_script( 'custom' === $atts['display_mode'] );
		}

		return apply_filters( parent::get_hook(), $output, $atts );
	}

	/**
	 * Get the official Google button markup.
	 *
	 * @since 10.9.0
	 * @param array $atts       Shortcode attributes.
	 * @param bool  $is_preview Whether this is an editor preview.
	 */
	private static function get_official_button( $atts, $is_preview ) {
		$classes = array(
			'wprm-google-preferred-sources',
			'wprm-google-preferred-sources-official',
		);

		if ( $is_preview ) {
			$classes[] = 'wprm-google-preferred-sources-official-preview';
		}

		if ( $atts['class'] ) {
			$classes[] = $atts['class'];
		}

		$theme = 'dark' === $atts['theme'] ? 'dark' : 'light';
		$language = trim( $atts['language'] );
		if ( $language && ! preg_match( '/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/', $language ) ) {
			$language = '';
		}

		$attributes = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		$attributes .= ' data-theme="' . esc_attr( $theme ) . '"';

		if ( $language ) {
			$attributes .= ' data-lang="' . esc_attr( $language ) . '"';
		}

		if ( $is_preview ) {
			$icon = WPRM_Icon::get( 'google-color' );
			$button = '<div' . $attributes . '><span class="wprm-recipe-icon wprm-google-preferred-sources-icon">' . $icon . '</span> ' . esc_html__( 'Add to Preferred Sources', 'wp-recipe-maker' ) . '</div>';
		} else {
			$button = '<div google-add-preferred-source-btn' . $attributes . '></div>';
		}

		return '<div class="wprm-google-preferred-sources-official-wrapper">' . $button . '</div>';
	}

	/**
	 * Get the custom WPRM button markup.
	 *
	 * @since 10.9.0
	 * @param array $atts       Shortcode attributes.
	 * @param bool  $is_preview Whether this is an editor preview.
	 */
	private static function get_custom_button( $atts, $is_preview ) {
		$icon = '';
		if ( $atts['icon'] ) {
			$icon = WPRM_Icon::get( $atts['icon'], $atts['icon_color'] );

			if ( $icon ) {
				$icon = '<span class="wprm-recipe-icon wprm-google-preferred-sources-icon">' . $icon . '</span> ';
			}
		}

		$classes = array(
			'wprm-google-preferred-sources',
			'wprm-google-preferred-sources-custom',
			'wprm-recipe-link',
			'wprm-block-text-' . $atts['text_style'],
		);

		if ( $atts['class'] ) {
			$classes[] = $atts['class'];
		}

		$style = 'color: ' . $atts['text_color'] . ';';
		if ( 'text' !== $atts['style'] ) {
			$classes[] = 'wprm-recipe-link-' . $atts['style'];
			$classes[] = 'wprm-color-accent';

			$style .= 'background-color: ' . $atts['button_color'] . ';';
			$style .= 'border-color: ' . $atts['border_color'] . ';';
			$style .= 'border-radius: ' . $atts['border_radius'] . ';';
			$style .= 'padding: ' . $atts['vertical_padding'] . ' ' . $atts['horizontal_padding'] . ';';
		}

		$text = WPRM_i18n::maybe_translate( $atts['text'] );
		$aria_label = $text ? '' : ' aria-label="' . esc_attr__( 'Add this site to your Google Preferred Sources', 'wp-recipe-maker' ) . '"';
		$preview_attribute = $is_preview ? '' : ' data-wprm-google-preferred-sources';

		return '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( $style ) . '"' . $aria_label . $preview_attribute . '>' . $icon . WPRM_Shortcode_Helper::sanitize_html( $text ) . '</button>';
	}

	/**
	 * Load Google's library and add the custom-button callback when needed.
	 *
	 * The script is deliberately loaded without preferred-sources-control="manual"
	 * so official declarative buttons and custom buttons can coexist on a page.
	 *
	 * @since 10.9.0
	 * @param bool $custom Whether a custom button is present.
	 */
	private static function enqueue_google_script( $custom ) {
		if ( ! apply_filters( 'wprm_load_google_preferred_sources', true ) ) {
			return;
		}

		wp_enqueue_script(
			'wprm-google-preferred-sources',
			'https://news.google.com/swg/js/v1/publisher.js',
			array(),
			null,
			true
		);

		if ( $custom && ! self::$custom_script_added ) {
			self::$custom_script_added = true;

			if ( function_exists( 'wp_add_inline_script' ) ) {
				wp_add_inline_script(
					'wprm-google-preferred-sources',
					self::get_custom_button_script(),
					'before'
				);
			} else {
				self::$custom_script_fallback = true;
			}
		}
	}

	/**
	 * Output the custom integration script on WordPress 4.4.
	 *
	 * wp_add_inline_script() was added in WordPress 4.5, while WPRM supports
	 * WordPress 4.4. This runs before footer scripts are printed.
	 *
	 * @since 10.9.0
	 */
	public static function output_custom_button_script_fallback() {
		if ( self::$custom_script_fallback ) {
			echo '<script>' . self::get_custom_button_script() . '</script>';
		}
	}

	/**
	 * Add async to Google's library.
	 *
	 * @since 10.9.0
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 */
	public static function add_async_attribute( $tag, $handle ) {
		if ( 'wprm-google-preferred-sources' === $handle && false === strpos( $tag, ' async' ) ) {
			$tag = str_replace( ' src=', ' async src=', $tag );
		}

		return $tag;
	}

	/**
	 * JavaScript that connects custom WPRM buttons to Google's flow.
	 *
	 * Uses one delegated click listener so buttons added after page load work too.
	 *
	 * @since 10.9.0
	 */
	private static function get_custom_button_script() {
		return <<<'JS'
(function () {
	(self.PREFERRED_SOURCE = self.PREFERRED_SOURCE || []).push(function (preferredSource) {
		preferredSource.init({ theme: 'light' });

		document.addEventListener('click', function (event) {
			var target = event.target;
			var button = target && typeof target.closest === 'function'
				? target.closest('[data-wprm-google-preferred-sources]')
				: null;

			if (!button) {
				return;
			}

			event.preventDefault();
			preferredSource.addPreferredSource();
		});
	});
}());
JS;
	}
}

WPRM_SC_Google_Preferred_Sources::init();
