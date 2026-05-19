<?php
/**
 * Handle Divi compatibility.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.4.1
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Handle Divi compatibility.
 *
 * @since      10.4.1
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Compatibility_Divi {

	/**
	 * Track whether the Divi 5 integration has been initialized.
	 *
	 * @var bool
	 */
	private static $divi5_initialized = false;

	/**
	 * Track whether we've localized Divi 5 builder data.
	 *
	 * @var bool
	 */
	private static $divi5_builder_data_localized = false;

	/**
	 * Register Divi compatibility hooks.
	 *
	 * @since	10.4.1
	 */
	public static function init() {
		add_action( 'divi_extensions_init', array( __CLASS__, 'divi' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'divi_assets' ) );
		add_action( 'init', array( __CLASS__, 'divi5_init' ), 1 );
		add_filter( 'divi_visual_builder_settings_data_post_content', array( __CLASS__, 'divi5_maybe_migrate_post_content' ), 20 );
		add_filter( 'render_block_data', array( __CLASS__, 'divi5_maybe_migrate_render_block_data' ), 20, 2 );
		add_action( 'divi_visual_builder_assets_before_enqueue_scripts', array( __CLASS__, 'divi5_vb_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'divi5_frontend_assets' ) );
	}

	/**
	 * Divi Builder Compatibility.
	 *
	 * @since	5.1.0
	 */
	public static function divi() {
		require_once( WPRM_DIR . 'templates/divi/includes/extension.php' );
	}

	/**
	 * Divi Builder assets.
	 *
	 * @since	9.7.0
	 */
	public static function divi_assets() {
		if ( isset( $_GET['et_fb'] ) && '1' === $_GET['et_fb'] ) {
			WPRM_Assets::load();
		}
	}

	/**
	 * Determine if Divi 5 is active.
	 *
	 * @return bool
	 */
	private static function is_divi5_enabled() {
		return function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled();
	}

	/**
	 * Determine if Divi 5 is active.
	 *
	 * @return bool
	 */
	public static function is_divi5_active() {
		return self::is_divi5_enabled();
	}

	/**
	 * Bootstrap Divi 5 module registration.
	 */
	public static function divi5_init() {
		if ( self::$divi5_initialized || ! self::is_divi5_enabled() ) {
			return;
		}

		if ( ! defined( 'WPRM_DIVI5_PATH' ) ) {
			define( 'WPRM_DIVI5_PATH', WPRM_DIR . 'templates/divi5/' );
			define( 'WPRM_DIVI5_URL', WPRM_URL . 'templates/divi5/' );
			define( 'WPRM_DIVI5_MODULES_PATH', WPRM_DIVI5_PATH . 'src/components/' );
		}

		$modules_bootstrap = WPRM_DIVI5_PATH . 'modules/Modules.php';

		if ( file_exists( $modules_bootstrap ) ) {
			require_once $modules_bootstrap;

			if ( function_exists( '\WPRM\Divi5\Modules\register_modules' ) ) {
				\WPRM\Divi5\Modules\register_modules();
				add_action( 'init', '\WPRM\Divi5\Modules\register_modules', 20 );
			}
		}

		self::$divi5_initialized = true;
	}

	/**
	 * Enqueue Divi 5 Visual Builder assets.
	 */
	public static function divi5_vb_assets() {
		if ( ! self::is_divi5_enabled() || ! function_exists( 'et_core_is_fb_enabled' ) || ! et_core_is_fb_enabled() ) {
			return;
		}

		if ( ! class_exists( '\\ET\\Builder\\VisualBuilder\\Assets\\PackageBuildManager' ) ) {
			return;
		}

		self::divi5_init();

		// Ensure the recipe selection modal assets are available inside the builder iframe.
		if ( ! class_exists( 'WPRM_Modal' ) ) {
			require_once WPRM_DIR . 'includes/admin/class-wprm-modal.php';
		}

		if ( ! class_exists( 'WPRM_Assets' ) ) {
			require_once WPRM_DIR . 'includes/class-wprm-assets.php';
		}

		// Force admin assets to load for Divi 5.
		add_filter( 'wprm_should_load_admin_assets', '__return_true' );

		$GLOBALS['wprm_divi5_context'] = true;
		WPRM_Modal::add_modal_content();
		unset( $GLOBALS['wprm_divi5_context'] );

		WPRM_Assets::enqueue_admin();
		WPRM_Modal::enqueue();

		if ( ! class_exists( 'WPRMP_Assets' ) && defined( 'WPRMP_DIR' ) && file_exists( WPRMP_DIR . 'includes/class-wprmp-assets.php' ) ) {
			require_once WPRMP_DIR . 'includes/class-wprmp-assets.php';
		}

		if ( class_exists( 'WPRMP_Assets' ) ) {
			WPRMP_Assets::enqueue_admin();
		}

		remove_filter( 'wprm_should_load_admin_assets', '__return_true' );

		$base_url = defined( 'WPRM_DIVI5_URL' ) ? WPRM_DIVI5_URL : WPRM_URL . 'templates/divi5/';

		\ET\Builder\VisualBuilder\Assets\PackageBuildManager::register_package_build(
			array(
				'name'    => 'wprm-divi5-builder-bundle-script',
				'version' => class_exists( 'WPRM_Debug' ) && WPRM_Debug::debugging() ? time() : WPRM_VERSION,
				'script'  => array(
					'src'                => $base_url . 'scripts/bundle.js',
					'deps'               => array(
						'divi-module-library',
						'divi-vendor-wp-hooks',
					),
					'enqueue_top_window' => false,
					'enqueue_app_window' => true,
				),
			)
		);

		\ET\Builder\VisualBuilder\Assets\PackageBuildManager::register_package_build(
			array(
				'name'   => 'wprm-divi5-builder-style',
				'version'=> class_exists( 'WPRM_Debug' ) && WPRM_Debug::debugging() ? time() : WPRM_VERSION,
				'style'  => array(
					'src'                => $base_url . 'styles/vb-bundle.css',
					'deps'               => array(),
					'enqueue_top_window' => false,
					'enqueue_app_window' => true,
				),
			)
		);

		if ( ! self::$divi5_builder_data_localized ) {
			$builder_data = array(
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'endpoints'     => array(
					'preview' => trailingslashit( rest_url( 'wp-recipe-maker/v1/utilities/preview' ) ),
				),
				'latestRecipes' => WPRM_Recipe_Manager::get_latest_recipes( 20, 'id' ),
			);

			$inline_script = 'window.WPRMDivi5Data = ' . wp_json_encode( $builder_data ) . ';';

			wp_add_inline_script(
				'divi-module-library',
				$inline_script,
				'before'
			);

			wp_add_inline_script(
				'divi-module-library',
				"(function() { if (typeof window !== 'undefined' && !window.WPRMDivi5Data) { " . $inline_script . " } })();",
				'after'
			);

			self::$divi5_builder_data_localized = true;
		}
	}

	/**
	 * Load Divi 5 front-end styles.
	 */
	public static function divi5_frontend_assets() {
		if ( ! self::is_divi5_enabled() ) {
			return;
		}

		self::divi5_init();

		$style_url = defined( 'WPRM_DIVI5_URL' ) ? WPRM_DIVI5_URL : WPRM_URL . 'templates/divi5/';

		wp_enqueue_style( 'wprm-divi5-modules', $style_url . 'styles/bundle.css', array(), WPRM_VERSION );
	}

	/**
	 * Repair legacy WPRM Divi modules before Divi 5 loads builder content.
	 *
	 * @since	10.4.1
	 * @param	string $content Post content loaded into the Visual Builder.
	 *
	 * @return string
	 */
	public static function divi5_maybe_migrate_post_content( $content ) {
		if ( ! self::is_divi5_enabled() || ! is_string( $content ) || ! self::divi5_content_may_need_migration( $content ) ) {
			return $content;
		}

		$repair = self::repair_legacy_divi5_wprm_recipe_modules( $content );

		if ( ! empty( $repair['changed'] ) && ! empty( $repair['content'] ) && is_string( $repair['content'] ) ) {
			$content = $repair['content'];
		}

		$fallback_repair = self::repair_divi5_fallback_recipe_text_modules( $content );

		if ( ! empty( $fallback_repair['changed'] ) && ! empty( $fallback_repair['content'] ) && is_string( $fallback_repair['content'] ) ) {
			$content = $fallback_repair['content'];
		}

		$shortcode_repair = self::repair_divi5_text_modules_with_recipe_shortcodes( $content );

		if ( ! empty( $shortcode_repair['changed'] ) && ! empty( $shortcode_repair['content'] ) && is_string( $shortcode_repair['content'] ) ) {
			$content = $shortcode_repair['content'];
		}

		return $content;
	}

	/**
	 * Normalize Divi 5 text blocks before frontend rendering.
	 *
	 * @since	10.4.1
	 * @param	array $parsed_block Parsed block data.
	 * @param	array $source_block Original block data.
	 *
	 * @return array
	 */
	public static function divi5_maybe_migrate_render_block_data( $parsed_block, $source_block ) {
		if ( ! self::is_divi5_enabled() || ! is_array( $parsed_block ) || empty( $parsed_block['blockName'] ) || 'divi/text' !== $parsed_block['blockName'] ) {
			return $parsed_block;
		}

		$repair = self::replace_divi5_text_block_fallback_recipes_with_shortcodes( $parsed_block );

		if ( ! empty( $repair['changed'] ) && ! empty( $repair['block'] ) && is_array( $repair['block'] ) ) {
			return $repair['block'];
		}

		return $parsed_block;
	}

	/**
	 * Check if content might contain migratable Divi 5 WPRM markup.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to inspect.
	 *
	 * @return bool
	 */
	private static function divi5_content_may_need_migration( $content ) {
		if ( ! is_string( $content ) ) {
			return false;
		}

		return false !== strpos( $content, 'divi_wprm_recipe' )
			|| false !== strpos( $content, '<!--WPRM Recipe' )
			|| false !== strpos( $content, 'WPRM Recipe' )
			|| false !== strpos( $content, '\\u003c!\\u002d\\u002dWPRM Recipe' );
	}

	/**
	 * Check if content contains legacy Divi 4 WPRM recipe modules.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to inspect.
	 *
	 * @return bool
	 */
	public static function has_legacy_divi5_wprm_recipe_modules( $content ) {
		return 0 < self::count_legacy_divi5_wprm_recipe_modules( $content );
	}

	/**
	 * Count legacy Divi 4 WPRM recipe modules in content.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to inspect.
	 *
	 * @return int
	 */
	public static function count_legacy_divi5_wprm_recipe_modules( $content ) {
		return count( self::get_legacy_divi5_wprm_recipe_shortcodes( $content ) );
	}

	/**
	 * Repair legacy Divi 4 WPRM recipe modules inside Divi 5 content.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to repair.
	 *
	 * @return array{
	 *     changed: bool,
	 *     content: string,
	 *     repaired: int,
	 *     recipe_ids: int[]
	 * }
	 */
	public static function repair_legacy_divi5_wprm_recipe_modules( $content ) {
		$result = array(
			'changed'    => false,
			'content'    => $content,
			'repaired'   => 0,
			'recipe_ids' => array(),
		);

		if ( ! is_string( $content ) || false === strpos( $content, 'divi_wprm_recipe' ) ) {
			return $result;
		}

		$repaired_recipe_ids = array();

		if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = parse_blocks( $content );

			if ( ! empty( $blocks ) ) {
				$block_repairs = 0;
				$blocks        = self::repair_legacy_divi5_wprm_recipe_blocks( $blocks, $block_repairs, $repaired_recipe_ids );

				if ( 0 < $block_repairs ) {
					$content             = serialize_blocks( $blocks );
					$result['changed']   = true;
					$result['repaired'] += $block_repairs;
				}
			}
		}

		$regex_repairs   = 0;
		$pattern         = get_shortcode_regex( array( 'divi_wprm_recipe' ) );
		$updated_content = preg_replace_callback(
			'/' . $pattern . '/s',
			function( $match ) use ( &$regex_repairs, &$repaired_recipe_ids ) {
				if ( ! isset( $match[2] ) || 'divi_wprm_recipe' !== $match[2] ) {
					return $match[0];
				}

				$recipe_id = self::get_legacy_divi5_wprm_recipe_id_from_shortcode_match( $match );

				if ( ! $recipe_id ) {
					return $match[0];
				}

				$regex_repairs++;
				$repaired_recipe_ids[] = $recipe_id;

				return self::serialize_divi5_wprm_recipe_block( $recipe_id );
			},
			$content
		);

		if ( is_string( $updated_content ) && $updated_content !== $content ) {
			$content = $updated_content;
		}

		if ( 0 < $regex_repairs ) {
			$result['changed']   = true;
			$result['repaired'] += $regex_repairs;
		}

		$result['content']    = $content;
		$result['recipe_ids'] = array_values( array_unique( array_map( 'absint', $repaired_recipe_ids ) ) );

		return $result;
	}

	/**
	 * Repair fallback WPRM recipes stored in Divi 5 text modules.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to repair.
	 *
	 * @return array{
	 *     changed: bool,
	 *     content: string,
	 *     repaired: int,
	 *     recipe_ids: int[]
	 * }
	 */
	public static function repair_divi5_fallback_recipe_text_modules( $content ) {
		$result = array(
			'changed'    => false,
			'content'    => $content,
			'repaired'   => 0,
			'recipe_ids' => array(),
		);

		if ( ! is_string( $content ) || ! self::content_has_divi5_fallback_recipe_markup( $content ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $result;
		}

		$blocks = parse_blocks( $content );

		if ( empty( $blocks ) ) {
			return $result;
		}

		$repaired_recipe_ids = array();
		$block_repairs       = 0;
		$blocks              = self::repair_divi5_fallback_recipe_text_blocks( $blocks, $block_repairs, $repaired_recipe_ids );

		if ( 0 >= $block_repairs ) {
			return $result;
		}

		$result['changed']    = true;
		$result['repaired']   = $block_repairs;
		$result['content']    = serialize_blocks( $blocks );
		$result['recipe_ids'] = array_values( array_unique( array_map( 'absint', $repaired_recipe_ids ) ) );

		return $result;
	}

	/**
	 * Repair fallback recipes inside mixed Divi 5 text modules by replacing them with WPRM shortcodes.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to repair.
	 *
	 * @return array{
	 *     changed: bool,
	 *     content: string,
	 *     repaired: int,
	 *     recipe_ids: int[]
	 * }
	 */
	public static function repair_divi5_text_modules_with_recipe_shortcodes( $content ) {
		$result = array(
			'changed'    => false,
			'content'    => $content,
			'repaired'   => 0,
			'recipe_ids' => array(),
		);

		if ( ! is_string( $content ) || ! self::content_has_divi5_fallback_recipe_markup( $content ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $result;
		}

		$blocks = parse_blocks( $content );

		if ( empty( $blocks ) ) {
			return $result;
		}

		$repaired_recipe_ids = array();
		$block_repairs       = 0;
		$blocks              = self::repair_divi5_text_blocks_with_recipe_shortcodes( $blocks, $block_repairs, $repaired_recipe_ids );

		if ( 0 >= $block_repairs ) {
			return $result;
		}

		$result['changed']    = true;
		$result['repaired']   = $block_repairs;
		$result['content']    = serialize_blocks( $blocks );
		$result['recipe_ids'] = array_values( array_unique( array_map( 'absint', $repaired_recipe_ids ) ) );

		return $result;
	}

	/**
	 * Repair legacy WPRM Divi recipe modules inside parsed blocks.
	 *
	 * @since	10.4.1
	 * @param	array $blocks Parsed blocks.
	 * @param	int   $repaired_count Number of repaired blocks.
	 * @param	array $recipe_ids Repaired recipe IDs.
	 *
	 * @return array
	 */
	private static function repair_legacy_divi5_wprm_recipe_blocks( $blocks, &$repaired_count, &$recipe_ids ) {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['blockName'] ) && 'wprm/recipe' === $block['blockName'] ) {
				continue;
			}

			$legacy_recipe_id = self::get_legacy_divi5_wprm_recipe_id_from_block( $block );

			if ( $legacy_recipe_id ) {
				$blocks[ $index ] = self::get_divi5_wprm_recipe_block( $legacy_recipe_id );
				$repaired_count++;
				$recipe_ids[] = $legacy_recipe_id;
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::repair_legacy_divi5_wprm_recipe_blocks( $block['innerBlocks'], $repaired_count, $recipe_ids );
			}
		}

		return $blocks;
	}

	/**
	 * Repair fallback WPRM recipes in Divi 5 text blocks.
	 *
	 * @since	10.4.1
	 * @param	array $blocks Parsed blocks.
	 * @param	int   $repaired_count Number of repaired blocks.
	 * @param	array $recipe_ids Repaired recipe IDs.
	 *
	 * @return array
	 */
	private static function repair_divi5_fallback_recipe_text_blocks( $blocks, &$repaired_count, &$recipe_ids ) {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['blockName'] ) && 'wprm/recipe' === $block['blockName'] ) {
				continue;
			}

			$fallback_recipe_id = self::get_divi5_fallback_recipe_id_from_block( $block );

			if ( $fallback_recipe_id ) {
				$blocks[ $index ] = self::get_divi5_wprm_recipe_block( $fallback_recipe_id );
				$repaired_count++;
				$recipe_ids[] = $fallback_recipe_id;
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::repair_divi5_fallback_recipe_text_blocks( $block['innerBlocks'], $repaired_count, $recipe_ids );
			}
		}

		return $blocks;
	}

	/**
	 * Replace fallback recipes with WPRM shortcodes inside Divi 5 text blocks.
	 *
	 * @since	10.4.1
	 * @param	array $blocks Parsed blocks.
	 * @param	int   $repaired_count Number of repaired blocks.
	 * @param	array $recipe_ids Repaired recipe IDs.
	 *
	 * @return array
	 */
	private static function repair_divi5_text_blocks_with_recipe_shortcodes( $blocks, &$repaired_count, &$recipe_ids ) {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['blockName'] ) && 'divi/text' === $block['blockName'] ) {
				$repair = self::replace_divi5_text_block_fallback_recipes_with_shortcodes( $block );

				if ( ! empty( $repair['changed'] ) ) {
					$blocks[ $index ] = $repair['block'];
					$repaired_count++;
					$recipe_ids = array_merge( $recipe_ids, $repair['recipe_ids'] );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::repair_divi5_text_blocks_with_recipe_shortcodes( $block['innerBlocks'], $repaired_count, $recipe_ids );
			}
		}

		return $blocks;
	}

	/**
	 * Get the recipe ID from a legacy Divi 5 wrapper block.
	 *
	 * @since	10.4.1
	 * @param	array $block Parsed block.
	 *
	 * @return int|false
	 */
	private static function get_legacy_divi5_wprm_recipe_id_from_block( $block ) {
		if ( ! empty( $block['innerBlocks'] ) ) {
			return false;
		}

		$candidates = array();

		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$candidates[] = $block['innerHTML'];
		}

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$candidates[] = implode(
				'',
				array_filter(
					$block['innerContent'],
					'is_string'
				)
			);
		}

		$candidates = array_unique( array_filter( $candidates ) );

		foreach ( $candidates as $candidate ) {
			$shortcodes = self::get_legacy_divi5_wprm_recipe_shortcodes( $candidate );

			if ( empty( $shortcodes ) ) {
				continue;
			}

			foreach ( $shortcodes as $shortcode ) {
				$remaining_markup = str_replace( $shortcode['shortcode'], '', $candidate );
				$remaining_markup = preg_replace( '/<!--[\s\S]*?-->/', '', $remaining_markup );
				$remaining_markup = trim( wp_strip_all_tags( $remaining_markup ) );

				if ( '' === $remaining_markup ) {
					return $shortcode['recipe_id'];
				}
			}
		}

		return false;
	}

	/**
	 * Check if content might contain fallback WPRM recipe markup inside Divi 5 text modules.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to inspect.
	 *
	 * @return bool
	 */
	private static function content_has_divi5_fallback_recipe_markup( $content ) {
		return is_string( $content )
			&& false !== strpos( $content, 'WPRM Recipe' )
			&& false !== strpos( $content, 'divi/text' );
	}

	/**
	 * Get the recipe ID from a Divi 5 text block containing only fallback recipe markup.
	 *
	 * @since	10.4.1
	 * @param	array $block Parsed block.
	 *
	 * @return int|false
	 */
	private static function get_divi5_fallback_recipe_id_from_block( $block ) {
		if ( ! is_array( $block ) || empty( $block['blockName'] ) || 'divi/text' !== $block['blockName'] || ! empty( $block['innerBlocks'] ) ) {
			return false;
		}

		foreach ( self::get_divi5_text_block_content_candidates( $block ) as $candidate ) {
			$recipe_id = self::get_divi5_fallback_recipe_id_from_text_content( $candidate );

			if ( $recipe_id ) {
				return $recipe_id;
			}
		}

		return false;
	}

	/**
	 * Get text content candidates from a Divi 5 text block.
	 *
	 * @since	10.4.1
	 * @param	array $block Parsed block.
	 *
	 * @return array
	 */
	private static function get_divi5_text_block_content_candidates( $block ) {
		$candidates = array();

		if ( isset( $block['attrs']['content']['innerContent']['desktop']['value'] ) && is_string( $block['attrs']['content']['innerContent']['desktop']['value'] ) ) {
			$candidates[] = $block['attrs']['content']['innerContent']['desktop']['value'];
		}

		if ( isset( $block['attrs']['content']['innerContent']['value'] ) && is_string( $block['attrs']['content']['innerContent']['value'] ) ) {
			$candidates[] = $block['attrs']['content']['innerContent']['value'];
		}

		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$candidates[] = $block['innerHTML'];
		}

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$candidates[] = implode(
				'',
				array_filter(
					$block['innerContent'],
					'is_string'
				)
			);
		}

		return array_values( array_unique( array_filter( $candidates, 'is_string' ) ) );
	}

	/**
	 * Get the recipe ID from Divi 5 text content containing fallback recipe markup.
	 *
	 * @since	10.4.1
	 * @param	string $content Text content to inspect.
	 *
	 * @return int|false
	 */
	private static function get_divi5_fallback_recipe_id_from_text_content( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, 'WPRM Recipe' ) ) {
			return false;
		}

		$candidates = self::get_divi5_fallback_text_variants( $content );

		foreach ( $candidates as $candidate ) {
			preg_match_all( WPRM_Fallback_Recipe::get_fallback_regex(), $candidate, $matches, PREG_SET_ORDER );

			if ( 1 !== count( $matches ) ) {
				continue;
			}

			$match     = $matches[0];
			$recipe_id = isset( $match[1] ) ? absint( $match[1] ) : 0;

			if ( ! $recipe_id || empty( $match[0] ) ) {
				continue;
			}

			$remaining_markup = str_replace( $match[0], '', $candidate );
			$remaining_markup = self::strip_divi5_non_content_markup( $remaining_markup );

			if ( '' === $remaining_markup ) {
				return $recipe_id;
			}
		}

		return false;
	}

	/**
	 * Replace fallback recipes with WPRM shortcodes in a Divi 5 text block.
	 *
	 * @since	10.4.1
	 * @param	array $block Parsed block.
	 *
	 * @return array{
	 *     changed: bool,
	 *     block: array,
	 *     recipe_ids: int[]
	 * }
	 */
	private static function replace_divi5_text_block_fallback_recipes_with_shortcodes( $block ) {
		$result = array(
			'changed'    => false,
			'block'      => $block,
			'recipe_ids' => array(),
		);

		if ( ! is_array( $block ) || empty( $block['blockName'] ) || 'divi/text' !== $block['blockName'] ) {
			return $result;
		}

		$path = array( 'attrs', 'content', 'innerContent', 'desktop', 'value' );

		if ( ! self::array_has_nested_string( $block, $path ) ) {
			return $result;
		}

		$content = $block['attrs']['content']['innerContent']['desktop']['value'];
		$repair  = self::replace_divi5_text_fallback_recipes_with_shortcodes( $content );

		if ( ! empty( $repair['changed'] ) ) {
			$result['block']['attrs']['content']['innerContent']['desktop']['value'] = $repair['content'];
			$result['changed'] = true;
			$result['recipe_ids'] = $repair['recipe_ids'];
		}

		return $result;
	}

	/**
	 * Replace fallback recipes with shortcodes in Divi 5 text content.
	 *
	 * @since	10.4.1
	 * @param	string $content Text content to repair.
	 *
	 * @return array{
	 *     changed: bool,
	 *     content: string,
	 *     recipe_ids: int[]
	 * }
	 */
	private static function replace_divi5_text_fallback_recipes_with_shortcodes( $content ) {
		$result = array(
			'changed'    => false,
			'content'    => $content,
			'recipe_ids' => array(),
		);

		if ( ! is_string( $content ) || false === strpos( $content, 'WPRM Recipe' ) ) {
			return $result;
		}

		$replaced_recipe_ids = array();
		$updated_content     = preg_replace_callback(
			'/<!--\s+wp:wp-recipe-maker\/recipe\b([^>]*)-->\s*(<!--WPRM Recipe \d+-->.+?<!--End WPRM Recipe-->)\s*<!--\s+\/wp:wp-recipe-maker\/recipe\s+-->/ms',
			function( $match ) use ( &$replaced_recipe_ids ) {
				$recipe_id = self::get_recipe_id_from_gutenberg_recipe_wrapper( $match );

				if ( ! $recipe_id ) {
					return $match[0];
				}

				$replaced_recipe_ids[] = $recipe_id;

				return '[wprm-recipe id="' . $recipe_id . '"]';
			},
			$content
		);

		if ( is_string( $updated_content ) ) {
			$content = $updated_content;
		}

		$before_fallback_replacement = $content;
		$content                     = WPRM_Fallback_Recipe::replace_fallback_with_shortcode( $content );

		if ( $content !== $before_fallback_replacement ) {
			preg_match_all( '/\[wprm-recipe id="(\d+)"/', $content, $shortcode_matches );

			if ( ! empty( $shortcode_matches[1] ) ) {
				$replaced_recipe_ids = array_merge( $replaced_recipe_ids, array_map( 'absint', $shortcode_matches[1] ) );
			}
		}

		$result['changed']    = $content !== $result['content'];
		$result['content']    = $content;
		$result['recipe_ids'] = array_values( array_unique( array_filter( $replaced_recipe_ids ) ) );

		return $result;
	}

	/**
	 * Build normalized text variants to account for Divi text wrappers.
	 *
	 * @since	10.4.1
	 * @param	string $content Text content to inspect.
	 *
	 * @return array
	 */
	private static function get_divi5_fallback_text_variants( $content ) {
		$variants = array();
		$current  = trim( (string) $content );

		while ( '' !== $current ) {
			$variants[] = $current;

			$unwrapped = self::unwrap_divi5_text_paragraph( $current );

			if ( $unwrapped === $current ) {
				break;
			}

			$current = $unwrapped;
		}

		return array_values( array_unique( array_filter( $variants, 'strlen' ) ) );
	}

	/**
	 * Remove a single outer paragraph wrapper from Divi text content.
	 *
	 * @since	10.4.1
	 * @param	string $content Text content to inspect.
	 *
	 * @return string
	 */
	private static function unwrap_divi5_text_paragraph( $content ) {
		$content = trim( (string) $content );

		if ( ! preg_match( '/^\s*<p\b[^>]*>(.*)<\/p>\s*$/is', $content, $matches ) ) {
			return $content;
		}

		return trim( self::strip_divi5_wrapper_noise( $matches[1] ) );
	}

	/**
	 * Remove harmless Divi wrapper noise from text content.
	 *
	 * @since	10.4.1
	 * @param	string $content Text content to clean.
	 *
	 * @return string
	 */
	private static function strip_divi5_wrapper_noise( $content ) {
		$content = (string) $content;
		$content = str_replace( '[et_pb_line_break_holder]', '', $content );
		$content = preg_replace( '/^(?:\s|&nbsp;|&#160;|<br\s*\/?>)+/i', '', $content );
		$content = preg_replace( '/(?:\s|&nbsp;|&#160;|<br\s*\/?>)+$/i', '', $content );

		return trim( $content );
	}

	/**
	 * Strip non-content markup from Divi text content.
	 *
	 * @since	10.4.1
	 * @param	string $content Text content to clean.
	 *
	 * @return string
	 */
	private static function strip_divi5_non_content_markup( $content ) {
		$content = self::strip_divi5_wrapper_noise( $content );

		do {
			$previous = $content;
			$content  = preg_replace( '/<!--[\s\S]*?-->/', '', $content );
			$content  = preg_replace( '/<p\b[^>]*>\s*<\/p>/i', '', $content );
			$content  = self::strip_divi5_wrapper_noise( $content );
		} while ( $content !== $previous );

		return trim( wp_strip_all_tags( html_entity_decode( $content, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Get the recipe ID from a Gutenberg WPRM wrapper comment match.
	 *
	 * @since	10.4.1
	 * @param	array $match Gutenberg wrapper regex match.
	 *
	 * @return int|false
	 */
	private static function get_recipe_id_from_gutenberg_recipe_wrapper( $match ) {
		$comment_attributes = isset( $match[1] ) ? trim( $match[1] ) : '';

		if ( $comment_attributes ) {
			$attributes_json = $comment_attributes;

			if ( '{' !== substr( $attributes_json, 0, 1 ) ) {
				$attributes_json = '{' . $attributes_json . '}';
			}

			$attributes = json_decode( $attributes_json, true );

			if ( is_array( $attributes ) && ! empty( $attributes['id'] ) ) {
				$recipe_id = absint( $attributes['id'] );

				if ( $recipe_id ) {
					return $recipe_id;
				}
			}
		}

		if ( ! empty( $match[2] ) && preg_match( WPRM_Fallback_Recipe::get_fallback_regex(), $match[2], $fallback_match ) ) {
			return ! empty( $fallback_match[1] ) ? absint( $fallback_match[1] ) : false;
		}

		return false;
	}

	/**
	 * Check if an array contains a nested string at the given path.
	 *
	 * @since	10.4.1
	 * @param	array $array Array to inspect.
	 * @param	array $path Nested path.
	 *
	 * @return bool
	 */
	private static function array_has_nested_string( $array, $path ) {
		$current = $array;

		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return false;
			}

			$current = $current[ $segment ];
		}

		return is_string( $current );
	}

	/**
	 * Find legacy Divi 4 WPRM recipe shortcodes in content.
	 *
	 * @since	10.4.1
	 * @param	string $content Content to inspect.
	 *
	 * @return array
	 */
	private static function get_legacy_divi5_wprm_recipe_shortcodes( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, 'divi_wprm_recipe' ) ) {
			return array();
		}

		$pattern = get_shortcode_regex( array( 'divi_wprm_recipe' ) );
		$matches = array();
		$found   = array();

		if ( ! preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			if ( ! isset( $match[2] ) || 'divi_wprm_recipe' !== $match[2] ) {
				continue;
			}

			$recipe_id = self::get_legacy_divi5_wprm_recipe_id_from_shortcode_match( $match );

			if ( ! $recipe_id ) {
				continue;
			}

			$found[] = array(
				'shortcode' => $match[0],
				'recipe_id' => $recipe_id,
			);
		}

		return $found;
	}

	/**
	 * Get the recipe ID from a shortcode regex match.
	 *
	 * @since	10.4.1
	 * @param	array $match Shortcode regex match.
	 *
	 * @return int|false
	 */
	private static function get_legacy_divi5_wprm_recipe_id_from_shortcode_match( $match ) {
		if ( ! isset( $match[3] ) ) {
			return false;
		}

		$atts = shortcode_parse_atts( stripslashes( $match[3] ) );

		if ( ! isset( $atts['recipe_id'] ) ) {
			return false;
		}

		$recipe_id = absint( $atts['recipe_id'] );

		return $recipe_id > 0 ? $recipe_id : false;
	}

	/**
	 * Build a native Divi 5 WPRM recipe block structure.
	 *
	 * @since	10.4.1
	 * @param	int $recipe_id Recipe ID to set.
	 *
	 * @return array
	 */
	private static function get_divi5_wprm_recipe_block( $recipe_id ) {
		return array(
			'blockName'    => 'wprm/recipe',
			'attrs'        => array(
				'recipe' => array(
					'innerContent' => array(
						'desktop' => array(
							'value' => (string) absint( $recipe_id ),
						),
					),
				),
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * Serialize a native Divi 5 WPRM recipe block.
	 *
	 * @since	10.4.1
	 * @param	int $recipe_id Recipe ID to set.
	 *
	 * @return string
	 */
	private static function serialize_divi5_wprm_recipe_block( $recipe_id ) {
		if ( function_exists( 'serialize_blocks' ) ) {
			return serialize_blocks(
				array(
					self::get_divi5_wprm_recipe_block( $recipe_id ),
				)
			);
		}

		return '[wprm-recipe id="' . absint( $recipe_id ) . '"]';
	}
}

WPRM_Compatibility_Divi::init();
