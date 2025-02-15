<?php
/**
 * Responsible for showing admin notices.
 *
 * @link       http://bootstrapped.ventures
 * @since      5.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Responsible for the privacy policy.
 *
 * @since      5.0.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Notices {

	/**
	 * Register actions and filters.
	 *
	 * @since    5.0.0
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'check_for_dismiss' ) );

		add_filter( 'wprm_admin_notices', array( __CLASS__, 'ingredient_units_notice' ) );
	}

	/**
	 * Get all notices to show.
	 *
	 * @since    5.0.0
	 */
	public static function get_notices() {
		$notices_to_display = array();
		$current_user_id = get_current_user_id();

		if ( $current_user_id ) {
			$notices = apply_filters( 'wprm_admin_notices', array() );

			foreach ( $notices as $notice ) {
				// Check capability.
				if ( isset( $notice['capability'] ) && ! current_user_can( $notice['capability'] ) ) {
					continue;
				}

				// Check if user has already dismissed notice.
				if ( isset( $notice['id'] ) && self::is_dismissed( $notice['id'] ) ) {
					continue;
				}

				$notices_to_display[] = $notice;
			}
		}

		return $notices_to_display;
	}

	/**
	 * Check if a notice should be dismissed.
	 *
	 * @since	9.8.0
	 */
	public static function check_for_dismiss() {
		if ( isset( $_GET['wprm_dismiss'] ) ) {
			$notice_id = sanitize_text_field( $_GET['wprm_dismiss'] );
			self::dismiss( $notice_id );
		}
	}

	/**
	 * Dissmiss a specific notice.
	 *
	 * @since	9.8.0
	 * @param	mixed $id Notice to dismiss.
	 */
	public static function dismiss( $id ) {
		$user_id = get_current_user_id();

		if ( $id && $user_id ) {
			add_user_meta( $user_id, 'wprm_dismissed_notices', $id );
			return true;
		}

		return false;
	}

	/**
	 * Check if notice has been dismissed.
	 *
	 * @since    8.0.0
	 * @param	mixed $id Notice to check for dismissal.
	 */
	public static function is_dismissed( $id ) {
		$current_user_id = get_current_user_id();

		if ( $current_user_id ) {
			$dismissed_notices = get_user_meta( $current_user_id, 'wprm_dismissed_notices', false );

			// Notice has been dismissed.
			if ( $id && in_array( $id, $dismissed_notices ) ) {
				return true;
			}

			// Could be dismissing right now.
			if ( isset( $_GET['wprm_dismiss'] ) && $id === $_GET['wprm_dismiss'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Show the ingredient units notice.
	 *
	 * @since	7.6.0
	 * @param	array $notices Existing notices.
	 */
	public static function ingredient_units_notice( $notices ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : false;

		// Only load on manage page.
		if ( $screen && 'wp-recipe-maker_page_wprm_manage' === $screen->id ) {
			if ( WPRM_Version::migration_needed_to( '7.6.0' ) ) {
				$notices[] = array(
					'id' => 'ingredient_units',
					'title' => __( 'Ingredient Units', 'wp-recipe-maker' ),
					'text' => 'Version 7.6.0 introduced a new WP Recipe Maker > Manage > Recipe Fields > Ingredient Units screen. To make sure all units are there, run the <a href="' . admin_url( 'admin.php?page=wprm_find_ingredient_units' ) . '" target="_blank">"Find Ingredient Units" tool</a>.',
				);
			}
		}

		return $notices;
	}
}

WPRM_Notices::init();
