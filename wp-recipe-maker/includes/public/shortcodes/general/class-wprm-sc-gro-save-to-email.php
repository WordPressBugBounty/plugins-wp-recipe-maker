<?php
/**
 * Place GRO's Save to Email form inside a recipe card.
 *
 * @since 10.9.0
 * @package WP_Recipe_Maker
 */
class WPRM_SC_Gro_Save_To_Email extends WPRM_Template_Shortcode {
	public static $shortcode = 'wprm-gro-save-to-email';

	public static function init() {
		self::$attributes = array(
			'form_id' => array(
				'default' => '',
				'type' => 'text',
				'name' => __( 'GRO Form ID', 'wp-recipe-maker' ),
				'help' => __( 'Optional. Leave blank to use the default Save to Email form. Configure the form design and email subscription settings in GRO.', 'wp-recipe-maker' ),
			),
		);
		parent::init();
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_preview_styles' ), 20 );
	}

	public static function shortcode( $atts ) {
		$atts = parent::get_attributes( $atts );
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';

		// Kitchen deliberately excludes third-party shortcodes from its demo renderer.
		if ( '/wp-recipe-maker/v1/app/templates/preview' === $route ) {
			return self::preview_notice( __( 'GRO Save to Email appears on your published recipe pages. Configure the form in GRO.', 'wp-recipe-maker' ) );
		}
		$is_preview = $atts['is_template_editor_preview'];
		if ( is_feed() || 'print' === WPRM_Context::get() ) {
			return '';
		}
		if ( ! shortcode_exists( 'gro_save_to_email' ) ) {
			return $is_preview ? self::preview_notice( __( 'Install and activate the GRO plugin to display its Save to Email form.', 'wp-recipe-maker' ) ) : '';
		}

		$form_id = trim( (string) $atts['form_id'] );
		if ( '' !== $form_id && ! preg_match( '/^[A-Za-z0-9_-]{1,64}\z/', $form_id ) ) {
			return $is_preview ? self::preview_notice( __( 'Enter a valid GRO Form ID, or leave it blank to use the default Save to Email form.', 'wp-recipe-maker' ) ) : '';
		}
		$recipe = WPRM_Template_Shortcodes::get_recipe( 0 );
		if ( ! $recipe || ! is_numeric( $recipe->id() ) || $recipe->id() <= 0 ) {
			return $is_preview ? self::preview_notice( __( 'Select a recipe in a published post to preview the GRO Save to Email form.', 'wp-recipe-maker' ) ) : '';
		}

		$shortcode = '[gro_save_to_email recipe_id="' . absint( $recipe->id() ) . '"';
		if ( '' !== $form_id ) {
			$shortcode .= ' id="' . esc_attr( $form_id ) . '"';
		}
		$shortcode .= ']';

		$output = $is_preview ? self::render_preview( $shortcode, $recipe ) : do_shortcode( $shortcode );
		if ( $output === $shortcode ) {
			$output = '';
		}
		if ( $is_preview && '' === trim( $output ) ) {
			$output = self::preview_notice( __( 'Connect this site to GRO and configure a default Save to Email form, or enter a GRO Form ID.', 'wp-recipe-maker' ) );
		}
		if ( '' !== $output ) {
			$output = '<div class="wprm-gro-save-to-email ' . esc_attr( $atts['class'] ) . '">' . $output . '</div>';
		}
		return apply_filters( parent::get_hook(), $output, $atts );
	}

	/** Render against the recipe's public parent without changing the request's context. */
	private static function render_preview( $shortcode, $recipe ) {
		$post = get_post( $recipe->parent_post_id() );
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_publicly_viewable( $post ) ) {
			return self::preview_notice( __( 'Select a recipe in a published, public post to preview the GRO Save to Email form.', 'wp-recipe-maker' ) );
		}

		$had_post = array_key_exists( 'post', $GLOBALS );
		$had_query = array_key_exists( 'wp_query', $GLOBALS );
		$previous_post = $had_post ? $GLOBALS['post'] : null;
		$previous_query = $had_query ? $GLOBALS['wp_query'] : null;
		try {
			// GRO's shortcode needs a post in the loop; a REST preview has no loop.
			$GLOBALS['post'] = $post;
			$GLOBALS['wp_query'] = $previous_query instanceof WP_Query ? clone $previous_query : new WP_Query();
			$GLOBALS['wp_query']->in_the_loop = true;
			$output = do_shortcode( $shortcode );
		} finally {
			if ( $had_post ) { $GLOBALS['post'] = $previous_post; } else { unset( $GLOBALS['post'] ); }
			if ( $had_query ) { $GLOBALS['wp_query'] = $previous_query; } else { unset( $GLOBALS['wp_query'] ); }
		}
		if ( $output === $shortcode || '' === trim( $output ) ) {
			return '';
		}

		// Preserve GRO's layout classes, but remove the form itself and its submission data.
		$output = preg_replace( '/<form\b[^>]*>/i', '<div class="gl-form__form">', $output );
		$output = preg_replace( '/<\/form\s*>/i', '</div>', $output );
		$html = new WP_HTML_Tag_Processor( $output );
		while ( $html->next_tag() ) {
			foreach ( $html->get_attribute_names_with_prefix( 'data-gl-' ) ?? array() as $attribute ) {
				$html->remove_attribute( $attribute );
			}
			$tag = $html->get_tag();
			if ( in_array( $tag, array( 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA' ), true ) ) {
				$html->set_attribute( 'disabled', true );
				$html->remove_attribute( 'name' );
				$html->remove_attribute( 'value' );
				$html->remove_attribute( 'form' );
				$html->remove_attribute( 'formaction' );
				if ( 'BUTTON' === $tag ) { $html->set_attribute( 'type', 'button' ); }
			}
		}
		return '<div class="wprm-gro-preview" inert="" style="pointer-events:none;">' . $html->get_updated_html() . '</div>';
	}

	private static function preview_notice( $message ) {
		return '<div class="wprm-template-editor-premium-only">' . esc_html( $message ) . '</div>';
	}

	/** Load only GRO's form CSS; its submission script is unnecessary in the editor. */
	public static function enqueue_preview_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : false;
		if ( $screen && 'wp-recipe-maker_page_wprm_template_editor' === $screen->id && shortcode_exists( 'gro_save_to_email' ) && wp_style_is( 'grocerslist-form-style', 'registered' ) ) {
			wp_enqueue_style( 'grocerslist-form-style' );
		}
	}
}

WPRM_SC_Gro_Save_To_Email::init();
