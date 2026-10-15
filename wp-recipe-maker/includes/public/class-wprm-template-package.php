<?php
/** Version 1 portable modern-template contract. Fonts are bundled WPRM font slugs; external assets are unsupported. */
class WPRM_Template_Package {
	const ALGORITHM = 'wprm-template-v1';
	const MIN_VERSION = '10.9.0';
	const MAX_FONTS = 8;

	/** Active Premium version, or null without Premium. */
	public static function premium_version() {
		return WPRM_Addons::is_active( 'premium' ) && defined( 'WPRMP_VERSION' ) ? WPRMP_VERSION : null;
	}

	public static function rewrite_slug( $template, $slug ) {
		$old = preg_quote( 'wprm-recipe-template-' . $template['slug'], '~' );
		$new = 'wprm-recipe-template-' . $slug;
		$template['html'] = preg_replace_callback( '~\bclass\s*=\s*([\'\"])(.*?)\1~is', function ( $match ) use ( $old, $new ) {
			return 'class=' . $match[1] . preg_replace( '~(?<![^\s])' . $old . '(?![^\s])~i', $new, $match[2] ) . $match[1];
		}, $template['html'] );
		// Only complete selector class tokens; prefixes and custom classes stay intact.
		$template['css'] = self::rewrite_css_selectors( $template['css'], $old, $new );
		$template['slug'] = $slug;
		return $template;
	}


	private static function rewrite_css_selectors( $css, $old, $new ) {
		// Delimiters inside strings/comments do not begin selector blocks.
		$tokens = preg_split( '~(/\*.*?\*/|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[{};])~s', $css, -1, PREG_SPLIT_DELIM_CAPTURE );
		$output = ''; $pending = '';
		foreach ( $tokens as $token ) {
			if ( '{' === $token ) {
				$pending = preg_replace_callback( '~(/\*.*?\*/|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')|\.' . $old . '(?![a-zA-Z0-9_-])~s', function ( $match ) use ( $new ) {
					return ! empty( $match[1] ) ? $match[0] : '.' . $new;
				}, $pending );
				$output .= $pending . $token; $pending = '';
			} elseif ( ';' === $token || '}' === $token ) {
				$output .= $pending . $token; $pending = '';
			} else { $pending .= $token; }
		}
		return $output . $pending;
	}

	/**
	 * Canonical inline styles, as safecss_filter_attr() writes them: line breaks and tabs
	 * removed, declarations trimmed and joined by ';' without empties, and attribute quotes
	 * normalized to double quotes (as in WordPress 7's wp_kses_hair()). Comparing this form
	 * accepts kses rewrites that keep meaning (such as a trailing ';') while a dropped
	 * declaration, attribute or tag still differs. The stored HTML is never changed.
	 */
	private static function normalize_styles( $html ) {
		return preg_replace_callback( '~\sstyle\s*=\s*(["\'])(.*?)\1~is', function ( $match ) {
			$declarations = array_filter( array_map( 'trim', explode( ';', str_replace( array( "\n", "\r", "\t" ), '', $match[2] ) ) ), 'strlen' );
			// kses removes a style attribute left without declarations.
			return $declarations ? ' style="' . implode( ';', $declarations ) . '"' : '';
		}, $html );
	}

	public static function checksum( $template ) {
		$portable = self::rewrite_slug( $template, 'kitchen-placeholder' );
		return hash( 'sha256', wp_json_encode( array(
			'type' => $portable['type'], 'premium' => (bool) $portable['premium'],
			'html' => trim( $portable['html'] ), 'css' => trim( $portable['css'] ),
			'fonts' => array_values( $portable['fonts'] ),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function requirements( $template ) {
		$features = $template['premium'] ? array( 'premium' ) : array();
		preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_-]*)([^\]]*)\]/', $template['html'], $blocks, PREG_SET_ORDER );
		foreach ( $blocks as $block ) {
			$tag = strtolower( $block[1] ); $atts = shortcode_parse_atts( $block[2] ); $atts = is_array( $atts ) ? $atts : array();
			if ( 'wprm-recipe-unit-conversion' === $tag || ( 'wprm-recipe-ingredients' === $tag && ! empty( $atts['unit_conversion'] ) && 'disabled' !== $atts['unit_conversion'] ) ) { $features[] = 'unit-conversion'; }
			if ( 'wprm-recipe-custom-field' === $tag ) { $features[] = 'custom-fields'; }
			if ( in_array( $tag, array( 'wprm-recipe-add-to-collection', 'wprm-recipe-add-to-shopping-list' ), true ) ) { $features[] = 'recipe-collections'; }
			if ( ( 'wprm-recipe-nutrition' === $tag && 'calories' !== ( $atts['field'] ?? '' ) ) || 'wprm-nutrition-label' === $tag || in_array( $atts['style'] ?? '', array( 'checkbox', 'advanced' ), true ) || in_array( $atts['adjustable'] ?? '', array( 'tooltip', 'text', 'text-buttons', '1' ), true ) ) { $features[] = 'premium'; }
		}
		$features = array_values( array_unique( $features ) ); sort( $features );
		return $features;
	}

	public static function export( $template ) {
		$native = array(
			'name' => $template['name'], 'slug' => $template['slug'], 'type' => $template['type'],
			'premium' => (bool) $template['premium'], 'html' => trim( $template['html'] ),
			'css' => trim( WPRM_Template_Manager::get_template_css( $template, false ) ),
			'fonts' => isset( $template['fonts'] ) ? array_values( $template['fonts'] ) : array(),
		);
		return array(
			'format_version' => 1, 'algorithm' => self::ALGORITHM,
			'min_wprm_version' => self::MIN_VERSION, 'min_premium_version' => null,
			'template_id' => isset( $template['kitchen']['template_id'] ) ? $template['kitchen']['template_id'] : null,
			'version_id' => isset( $template['kitchen']['version_id'] ) ? $template['kitchen']['version_id'] : null,
			'template' => $native, 'checksum' => self::checksum( $native ),
			'required_features' => self::requirements( $native ), 'assets' => array(),
		);
	}

	public static function validate( $package, $check_entitlement = true ) {
		$bad = function ( $message ) { return new WP_Error( 'wprm_template_incompatible', $message, array( 'status' => 422 ) ); };
		if ( ! is_array( $package ) || 1 !== ( $package['format_version'] ?? null ) || self::ALGORITHM !== ( $package['algorithm'] ?? null ) || ! is_array( $package['template'] ?? null ) ) { return $bad( 'Unsupported modern-template package format.' ); }
		if ( ! is_string( $package['min_wprm_version'] ?? null ) || ! preg_match( '/^\d+\.\d+\.\d+$/', $package['min_wprm_version'] ) || version_compare( WPRM_VERSION, $package['min_wprm_version'], '<' ) ) { return $bad( 'Update WP Recipe Maker before installing this template.' ); }
		// Optional: packages from before Premium minimums omit it.
		$min_premium = $package['min_premium_version'] ?? null;
		if ( null !== $min_premium && ( ! is_string( $min_premium ) || ! preg_match( '/^\d+\.\d+\.\d+$/', $min_premium ) ) ) { return $bad( 'Invalid minimum WP Recipe Maker Premium version.' ); }
		$t = $package['template'];
		foreach ( array( 'name', 'slug', 'type', 'html', 'css' ) as $field ) { if ( ! is_string( $t[$field] ?? null ) ) { return $bad( 'Invalid template fields.' ); } }
		if ( ! is_bool( $t['premium'] ?? null ) || ! isset( $package['assets'] ) || array() !== $package['assets'] ) { return $bad( 'Asset mapping is not supported in this release.' ); }
		// Fonts are an ordered list of bundled font slugs, never URLs. Fonts added in a later release are unknown here.
		if ( ! is_array( $t['fonts'] ?? null ) || array_values( $t['fonts'] ) !== $t['fonts'] || count( $t['fonts'] ) > self::MAX_FONTS ) { return $bad( 'Invalid template fonts.' ); }
		$available_fonts = WPRM_Template_Fonts::get_available_fonts();
		foreach ( $t['fonts'] as $font ) {
			if ( ! is_string( $font ) || ! preg_match( '/^[a-z0-9-]{1,60}$/', $font ) ) { return $bad( 'Invalid template fonts.' ); }
			if ( ! isset( $available_fonts[ $font ] ) ) { return $bad( 'This template uses the font "' . $font . '", which this version of WP Recipe Maker doesn\'t include. Update WP Recipe Maker before installing it.' ); }
		}
		if ( count( array_unique( $t['fonts'] ) ) !== count( $t['fonts'] ) ) { return $bad( 'Invalid template fonts.' ); }
		if ( ! $t['name'] || strlen( $t['name'] ) > 160 || ! preg_match( '/^[a-z0-9_-]{1,80}$/', $t['slug'] ) || ! in_array( $t['type'], array( 'recipe', 'snippet', 'roundup', 'favorites', 'import' ), true ) || strlen( $t['html'] ) + strlen( $t['css'] ) > 262144 ) { return $bad( 'Invalid template type, slug or size.' ); }
		foreach ( array( 'template_id', 'version_id' ) as $id ) { if ( ! array_key_exists( $id, $package ) || ( null !== $package[$id] && ( ! is_string( $package[$id] ) || ! preg_match( '/^[a-f0-9-]{36}$/', $package[$id] ) ) ) ) { return $bad( 'Invalid template provenance.' ); } }
		// Reject unresolved media, links and recipe IDs before native rendering. Feature shortcodes are gated by requirements() and render empty when their configuration is missing.
		if ( preg_match( '~\b(?:src|srcset|poster)\s*=|\bhref\s*=\s*[\'\"](?!#)|\bid\s*=\s*[\'\"][1-9]~i', $t['html'] ) ) { return $bad( 'Template contains site-specific media or links that cannot be transferred yet.' ); }
		// Reject assets, executable markup and CSS escapes instead of guessing their meaning.
		$css = preg_replace( '~/\*.*?\*/~s', '', $t['css'] );
		if ( preg_match( '~(?:https?:|//|data:|<\?|<%|\bon\w+\s*=)~i', $t['html'] ) || self::normalize_styles( wp_kses_post( $t['html'] ) ) !== self::normalize_styles( $t['html'] ) || preg_match( '~(?:url\s*\(|@import|expression\s*\(|javascript|behavior\s*:|-moz-binding|[\\\\])~i', $css ) ) { return $bad( 'Template contains unsupported assets or unsafe markup/styles.' ); }
		global $shortcode_tags;
		preg_match_all( '/\[\/?([a-zA-Z][a-zA-Z0-9_-]*)\b/', $t['html'], $matches );
		foreach ( array_unique( $matches[1] ) as $tag ) { if ( 0 !== strpos( $tag, 'wprm-' ) || ! isset( $shortcode_tags[$tag] ) ) { return $bad( 'Template contains unsupported shortcode: ' . sanitize_key( $tag ) ); } }
		if ( ! is_string( $package['checksum'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $package['checksum'] ) || ! hash_equals( self::checksum( $t ), $package['checksum'] ) || self::requirements( $t ) !== ( $package['required_features'] ?? null ) ) { return $bad( 'Template checksum or feature requirements do not match.' ); }
		// Feature shortcodes render nothing without their add-on, so only Premium designs are gated: the template manager never renders them on free sites.
		if ( $check_entitlement && $t['premium'] && ! WPRM_Addons::is_active( 'premium' ) ) { return $bad( 'This is a Premium template design. Install it on a site with WP Recipe Maker Premium.' ); }
		// Premium renders Premium designs entirely, so its minimum is enforced for them. For other designs it is advisory: they work without Premium and Kitchen warns instead.
		if ( $check_entitlement && $t['premium'] && null !== $min_premium && version_compare( (string) self::premium_version(), $min_premium, '<' ) ) { return $bad( 'Update WP Recipe Maker Premium to ' . $min_premium . ' or later before installing this template.' ); }
		return $package;
	}
}
