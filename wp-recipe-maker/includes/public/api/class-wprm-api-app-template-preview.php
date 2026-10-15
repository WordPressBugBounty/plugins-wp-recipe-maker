<?php
/** Read-only demo previews for Kitchen. Never installs templates or saves recipes. */
class WPRM_Api_App_Template_Preview {
	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'wp-recipe-maker/v1', '/app/templates/preview', array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'preview' ),
				'permission_callback' => array( 'WPRM_Api_App', 'api_app_token_only_permissions' ),
			) );
		} );
	}

	public static function preview( $request ) {
		if ( strlen( $request->get_body() ) > 600000 ) {
			return new WP_Error( 'wprm_preview_size', 'Template preview is too large.', array( 'status' => 413 ) );
		}
		$args = $request->get_json_params();
		if ( ! is_array( $args ) || ( isset( $args['local_id'] ) === isset( $args['package'] ) ) ) {
			return new WP_Error( 'wprm_preview_request', 'Choose one template to preview.', array( 'status' => 400 ) );
		}
		if ( isset( $args['local_id'] ) ) {
			if ( ! is_string( $args['local_id'] ) || ! preg_match( '/^[a-f0-9-]{32,36}$/', $args['local_id'] ) ) {
				return new WP_Error( 'wprm_preview_request', 'Invalid template identity.', array( 'status' => 400 ) );
			}
			$template = WPRM_Template_Store::find( $args['local_id'] );
			if ( ! $template || 'modern' !== $template['mode'] ) {
				return new WP_Error( 'wprm_template_missing', 'Template not found.', array( 'status' => 404 ) );
			}
			$package = WPRM_Template_Package::export( $template );
		} else {
			$package = WPRM_Template_Package::validate( $args['package'] );
			if ( is_wp_error( $package ) ) { return $package; }
		}
		$template = $package['template'];
		if ( $template['premium'] && ! WPRM_Addons::is_active( 'premium' ) ) {
			return new WP_Error( 'wprm_template_incompatible', 'This design requires WP Recipe Maker Premium.', array( 'status' => 422 ) );
		}
		if ( strlen( $template['html'] ) + strlen( $template['css'] ) > 262144 ) {
			return new WP_Error( 'wprm_preview_size', 'Template preview is too large.', array( 'status' => 413 ) );
		}

		// Only native template blocks, inline ingredients and template conditions may run.
		// Force every recipe reference to the demo, including nested shortcodes with explicit IDs.
		global $shortcode_tags;
		$original_tags = $shortcode_tags;
		$context = WPRM_Template_Shortcodes::get_recipe_context();
		try {
			$shortcode_tags = array();
			// Match both name and callback so third-party replacements stay blocked.
			$native_helpers = array(
				'wprm-ingredient' => array( 'WPRM_Shortcode_Other', 'ingredient_shortcode' ),
				'wprm-condition' => array( 'WPRM_Shortcode_Other', 'condition_shortcode' ),
			);
			foreach ( $original_tags as $tag => $callback ) {
				$is_template_block = is_array( $callback ) && is_string( $callback[0] ) && is_subclass_of( $callback[0], 'WPRM_Template_Shortcode' );
				$is_native_helper = isset( $native_helpers[ $tag ] ) && $native_helpers[ $tag ] === $callback;
				if ( $is_template_block || $is_native_helper ) {
					$shortcode_tags[ $tag ] = function ( $atts, $content = null, $name = '' ) use ( $callback ) {
						$atts = is_array( $atts ) ? $atts : array();
						$atts['id'] = 'demo';
						return call_user_func( $callback, $atts, $content, $name );
					};
				}
			}
			WPRM_Template_Shortcodes::set_current_recipe_id( 'demo' );
			$html = do_shortcode( '<div class="wprm-recipe-container"><div class="wprm-recipe wprm-recipe-template-' . esc_attr( $template['slug'] ) . '">' . $template['html'] . '</div></div>' );
		} finally {
			$shortcode_tags = $original_tags;
			WPRM_Template_Shortcodes::set_current_recipe_id( ...$context );
		}
		// Embed the bundled demo asset, avoiding cross-site cookies, mixed content
		// and dependence on public access to a protected WordPress installation.
		$image = WPRM_DIR . 'assets/images/demo-recipe.jpg';
		if ( is_readable( $image ) ) {
			$image_data = file_get_contents( $image );
			if ( function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagescale' ) ) {
				$source = imagecreatefromjpeg( $image );
				$scaled = $source ? imagescale( $source, 720 ) : false;
				if ( $scaled ) {
					ob_start(); imagejpeg( $scaled, null, 75 ); $image_data = ob_get_clean();
					imagedestroy( $scaled );
				}
				if ( $source ) { imagedestroy( $source ); }
			}
			$html = str_replace( WPRM_URL . 'assets/images/demo-recipe.jpg', 'data:image/jpeg;base64,' . base64_encode( $image_data ), $html );
		}
		$css = '';
		$files = array( WPRM_DIR . 'dist/public-modern.css' );
		if ( defined( 'WPRMP_DIR' ) && defined( 'WPRMP_BUNDLE' ) ) {
			$files[] = WPRMP_DIR . 'dist/public-' . strtolower( WPRMP_BUNDLE ) . '.css';
		}
		foreach ( $files as $file ) {
			if ( is_readable( $file ) ) { $css .= file_get_contents( $file ) . "\n"; }
		}
		$css .= $template['css'];
		$response = new WP_REST_Response( array(
			'html' => $html,
			'css' => $css,
			'font_css' => self::font_css( $template['fonts'] ),
			'checksum' => $package['checksum'],
			'renderer_version' => WPRM_VERSION . ( defined( 'WPRMP_VERSION' ) ? '/' . WPRMP_VERSION : '' ),
		) );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * The template's bundled fonts as data URIs, since the sandboxed preview loads
	 * nothing remote. Only Latin faces are inlined (the demo recipe needs no other
	 * subsets) and a face that exceeds the budget falls back to the next family.
	 */
	private static function font_css( $fonts ) {
		$base = WPRM_URL . 'assets/fonts/google/';
		$css = '';
		foreach ( $fonts as $font ) {
			preg_match_all( '~@font-face\s*\{[^}]*\}~', WPRM_Template_Fonts::get_css_for_font( $font ), $faces );
			foreach ( $faces[0] as $face ) {
				if ( false !== stripos( $face, 'unicode-range' ) && false === stripos( $face, 'U+0000-00FF' ) ) { continue; }
				$face = preg_replace_callback( '~url\(([\'"]?)' . preg_quote( $base, '~' ) . '([a-z0-9_./-]+\.woff2)\1\)~i', function ( $match ) {
					$file = WPRM_DIR . 'assets/fonts/google/' . $match[2];
					return false === strpos( $match[2], '..' ) && is_readable( $file ) ? 'url(data:font/woff2;base64,' . base64_encode( file_get_contents( $file ) ) . ')' : $match[0];
				}, $face );
				if ( preg_match( '~url\((?![\'"]?data:)~i', $face ) || strlen( $css ) + strlen( $face ) > 1000000 ) { continue; }
				$css .= $face . "\n";
			}
		}
		return $css;
	}
}
WPRM_Api_App_Template_Preview::init();
