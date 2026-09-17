<?php
/**
 * Show a FAQ in the backend menu.
 *
 * @link       https://bootstrapped.ventures
 * @since      1.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/admin
 */

/**
 * Show a FAQ in the backend menu.
 *
 * @since      1.0.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/admin
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Admin_Menu_Faq {

	/**
	 * Register actions and filters.
	 *
	 * @since    1.0.0
	 */
	public static function init() {
		add_action( 'admin_head-wp-recipe-maker_page_wprm_faq', array( __CLASS__, 'add_support_widget' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu_page' ), 22 );

		add_action( 'current_screen', array( __CLASS__, 'redirect_to_onboarding' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_wprm_finished_onboarding', array( __CLASS__, 'ajax_finished_onboarding' ) );
	}

	/**
	 * Add our support widget to the page.
	 *
	 * @since    1.0.0
	 */
	public static function add_support_widget() {
		require_once( WPRM_DIR . 'templates/admin/menu/support-widget.php' );
	}

	/**
	 * Add the FAQ & Support submenu to the WPRM menu.
	 *
	 * @since    1.0.0
	 */
	public static function add_submenu_page() {
		add_submenu_page( 'wprecipemaker', __( 'FAQ & Support', 'wp-recipe-maker' ), __( 'FAQ & Support', 'wp-recipe-maker' ), WPRM_Settings::get( 'features_faq_access' ), 'wprm_faq', array( __CLASS__, 'page_template' ) );
	}

	/**
	 * Get the template for this submenu.
	 *
	 * @since    1.0.0
	 */
	public static function page_template() {
		echo '<div class="wrap wprm-wrap"><div id="wprm-admin-faq">Loading...</div></div>';
	}

	/**
	 * Check if we should redirect to onboarding.
	 *
	 * @since    5.8.0
	 */
	public static function redirect_to_onboarding() {
		$screen = get_current_screen();

		// Redirect from Manage page if not onboarded yet.
		if ( 'toplevel_page_wprecipemaker' === $screen->id || 'wp-recipe-maker_page_wprm_manage' === $screen->id ) {
			if ( ! self::is_onboarded() ) {
				if ( isset( $_GET['skip_onboarding'] ) && '1' === $_GET['skip_onboarding'] ) {
					update_option( 'wprm_onboarded', time(), 'no' );
				} else {
					wp_redirect( admin_url( 'admin.php?page=wprm_faq' ) );
				}
			}
		}
	}

	/**
	 * Check if someone is already onboarded.
	 *
	 * @since    5.8.0
	 */
	public static function is_onboarded() {
		$count = wp_count_posts( WPRM_POST_TYPE )->publish;
		$already_onboarded = get_option( 'wprm_onboarded' );

		// Already gone through the steps or has more than 3 recipes.
		return $already_onboarded || 3 < intval( $count );
	}

	/**
	 * Detect which editor integrations are likely relevant to the current user.
	 *
	 * This is guidance only. It does not select or save an editor preference.
	 *
	 * @since    10.8.1
	 * @return   array Detected editors and a single likely editor when unambiguous.
	 */
	private static function get_editor_detection() {
		$active_plugins = get_option( 'active_plugins', array() );
		$active_plugins = is_array( $active_plugins ) ? $active_plugins : array();

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		$is_active = static function( $plugin ) use ( $active_plugins ) {
			return in_array( $plugin, $active_plugins, true );
		};

		$detected_editors = array();

		if ( $is_active( 'elementor/elementor.php' ) ) {
			$detected_editors[] = 'elementor';
		}

		$theme = wp_get_theme();
		$divi_theme = 'divi' === strtolower( $theme->get_template() ) || 'divi' === strtolower( $theme->get_stylesheet() );
		if ( $divi_theme || $is_active( 'divi-builder/divi-builder.php' ) ) {
			$detected_editors[] = 'divi';
		}

		if ( $is_active( 'classic-editor/classic-editor.php' ) ) {
			$user_preference = get_user_meta( get_current_user_id(), 'editor', true );
			$site_preference = get_option( 'classic-editor-replace', 'classic' );

			if ( 'classic' === $user_preference || ( ! $user_preference && 'classic' === $site_preference ) ) {
				$detected_editors[] = 'classic';
			}
		}

		$other_builders = array(
			'bb-plugin/fl-builder.php',
			'beaver-builder-lite-version/fl-builder.php',
			'breakdance/plugin.php',
			'brizy/brizy.php',
			'js_composer/js_composer.php',
			'oxygen/functions.php',
			'siteorigin-panels/siteorigin-panels.php',
		);
		foreach ( $other_builders as $plugin ) {
			if ( $is_active( $plugin ) ) {
				$detected_editors[] = 'other';
				break;
			}
		}

		$detected_editors = array_values( array_unique( $detected_editors ) );
		$likely_editor = 1 === count( $detected_editors ) ? $detected_editors[0] : '';

		return array(
			'detected_editors' => $detected_editors,
			'likely_editor' => $likely_editor,
		);
	}

	/**
	 * Enqueue stylesheets and scripts.
	 *
	 * @since    5.8.0
	 */
	public static function enqueue() {
		$screen = get_current_screen();

		// Only load on onboarding page.
		if ( 'wp-recipe-maker_page_wprm_faq' === $screen->id ) {
			wp_enqueue_style( 'wprm-admin-faq', WPRM_URL . 'dist/admin-faq.css', array(), WPRM_VERSION, 'all' );
			wp_enqueue_script( 'wprm-admin-faq', WPRM_URL . 'dist/admin-faq.js', array( 'wprm-admin', 'wprm-admin-modal', 'wprm-admin-template' ), WPRM_VERSION, true );

			$current_user = wp_get_current_user();
			$stored_settings = get_option( 'wprm_settings', array() );
			$stored_settings = is_array( $stored_settings ) ? $stored_settings : array();
			$recommended_settings = array(
				'recipe_template_mode' => WPRM_Settings::get_default( 'recipe_template_mode' ),
				'default_recipe_template_modern' => WPRM_Settings::get_default( 'default_recipe_template_modern' ),
				'recipe_snippets_automatically_add_modern' => true,
				'recipe_snippets_template' => WPRM_Settings::get_default( 'recipe_snippets_template' ),
			);

			// Applying recommendations should never overwrite an existing choice.
			foreach ( array_keys( $recommended_settings ) as $setting_id ) {
				if ( array_key_exists( $setting_id, $stored_settings ) ) {
					unset( $recommended_settings[ $setting_id ] );
				}
			}

			// Allow admins to force onboarding steps for testing via ?test_onboarding=1.
			$test_onboarding = isset( $_GET['test_onboarding'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['test_onboarding'] ) ) && current_user_can( 'manage_options' );
			wp_localize_script( 'wprm-admin-faq', 'wprm_faq', array(
					'onboarded' => $test_onboarding ? false : self::is_onboarded(),
					'can_access_template_editor' => current_user_can( 'manage_options' ),
					'template_editor_feature_explorer_url' => admin_url( 'admin.php?page=wprm_template_editor#explorer' ),
					'editor_detection' => self::get_editor_detection(),
					'recommended_settings' => $recommended_settings,
					'setup_settings' => array(
						'default_recipe_template_modern' => WPRM_Settings::get( 'default_recipe_template_modern' ),
						'recipe_snippets_template' => WPRM_Settings::get( 'recipe_snippets_template' ),
					),
					'user' => array(
						'email' => $current_user->user_email,
						'website' => get_site_url(),
					),
				) );

			WPRM_Template_Editor::localize_admin_template();
		}
	}

	/**
	 * Finish the onboarding through AJAX.
	 *
	 * @since	5.8.0
	 */
	public static function ajax_finished_onboarding() {
		if ( check_ajax_referer( 'wprm', 'security', false ) ) {
			update_option( 'wprm_onboarded', time(), 'no' );
			wp_send_json_success();
		}
		wp_die();
	}
}

WPRM_Admin_Menu_Faq::init();
