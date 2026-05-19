<?php
/**
 * Shared placeholder utilities.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.5.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Shared placeholder utilities.
 *
 * @since      10.5.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Placeholder {
	/**
	 * Replace recipe placeholders in text.
	 *
	 * @since	10.5.0
	 * @param	mixed $recipe Recipe object to use for replacements.
	 * @param	string $text Text to replace placeholders in.
	 */
	public static function replace_recipe_placeholders( $recipe, $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		$placeholders = self::get_recipe_placeholders( $recipe );
		$text = str_ireplace(
			array_keys( $placeholders ),
			array_values( $placeholders ),
			$text
		);

		return self::replace_recipe_type_text_placeholders( $recipe, $text );
	}

	/**
	 * Get default recipe placeholder replacements.
	 *
	 * @since	10.5.0
	 * @param	mixed $recipe Recipe object to use for replacements.
	 */
	private static function get_recipe_placeholders( $recipe ) {
		$placeholders = array(
			'%recipe_id%' => $recipe->id(),
			'%recipe_url%' => $recipe->permalink(),
			'%recipe_name%' => $recipe->name(),
			'%parent_post_name%' => $recipe->parent_post_name(),
			'%parent_post_or_recipe_name%' => $recipe->parent_post_or_recipe_name(),
			'%recipe_date%' => date( get_option( 'date_format' ), strtotime( $recipe->date() ) ),
			'%recipe_date_modified%' => date( get_option( 'date_format' ), strtotime( $recipe->date_modified() ) ),
			'%recipe_summary%' => $recipe->summary(),
			'%recipe_current_url%' => self::get_current_page_url(),
		);

		return $placeholders;
	}

	/**
	 * Replace recipe type text placeholders.
	 *
	 * @since	10.5.0
	 * @param	mixed $recipe Recipe object to use for replacements.
	 * @param	string $text Text to replace placeholders in.
	 */
	private static function replace_recipe_type_text_placeholders( $recipe, $text ) {
		return preg_replace_callback(
			'/%type\{([^}]*)\}%/i',
			function( $match ) use ( $recipe ) {
				$mappings = self::parse_recipe_type_text_mappings( $match[1] );

				if ( false === $mappings ) {
					return $match[0];
				}

				$recipe_type = self::get_recipe_type( $recipe );
				if ( array_key_exists( $recipe_type, $mappings ) ) {
					return WPRM_i18n::maybe_translate( $mappings[ $recipe_type ] );
				}
				if ( array_key_exists( 'default', $mappings ) ) {
					return WPRM_i18n::maybe_translate( $mappings['default'] );
				}

				return $match[0];
			},
			$text
		);
	}

	/**
	 * Parse recipe type text mappings from placeholder body.
	 *
	 * @since	10.5.0
	 * @param	string $body Placeholder body.
	 */
	private static function parse_recipe_type_text_mappings( $body ) {
		$allowed_keys = array(
			'food',
			'howto',
			'other',
			'default',
		);
		$mappings = array();
		$parts = explode( '|', $body );

		foreach ( $parts as $part ) {
			if ( false === strpos( $part, '=' ) ) {
				return false;
			}

			$key_value = explode( '=', $part, 2 );
			$key = strtolower( trim( $key_value[0] ) );

			if ( ! in_array( $key, $allowed_keys, true ) ) {
				return false;
			}

			$mappings[ $key ] = $key_value[1];
		}

		return $mappings;
	}

	/**
	 * Get the current recipe type in its normalized form.
	 *
	 * @since	10.5.0
	 * @param	mixed $recipe Recipe object to use for replacements.
	 */
	private static function get_recipe_type( $recipe ) {
		$type = $recipe->type();
		$type = is_string( $type ) ? strtolower( $type ) : 'food';

		if ( 'non-food' === $type ) {
			return 'other';
		}

		return $type;
	}

	/**
	 * Get the current page URL.
	 *
	 * @since	10.5.0
	 */
	private static function get_current_page_url() {
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http' ) . '://' . $http_host . $request_uri;
	}
}
