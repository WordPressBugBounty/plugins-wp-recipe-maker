<?php
/**
 * Responsible for showing admin notices.
 *
 * @link       https://bootstrapped.ventures
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
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

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

				// Check if notice is dismissable.
				$notice['dismissable'] = isset( $notice['dismissable'] ) ? $notice['dismissable'] : true;

				// Check if user has already dismissed notice.
				if ( isset( $notice['id'] ) && $notice['dismissable'] && self::is_dismissed( $notice['id'] ) ) {
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
		if ( isset( $_GET['wprm_dismiss'], $_GET['_wpnonce'] ) && is_string( $_GET['wprm_dismiss'] ) && is_string( $_GET['_wpnonce'] ) ) {
			$notice_id = sanitize_text_field( wp_unslash( $_GET['wprm_dismiss'] ) );
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wprm_dismiss_' . $notice_id ) ) {
				self::dismiss( $notice_id );
			}
		}
	}

	/**
	 * Known dismissible IDs, independent of the current admin screen.
	 * Screen-specific integrations can register IDs with this filter as well.
	 *
	 * @return array
	 */
	public static function get_dismissible_notice_ids() {
		// Marketing is normally admin-only; REST also needs its exact campaign IDs.
		if ( ! class_exists( 'WPRM_Marketing' ) ) {
			require_once WPRM_DIR . 'includes/admin/class-wprm-marketing.php';
		}
		$ids = array( 'ingredient_units' );
		if ( defined( 'WPRMP_VERSION' ) ) {
			$ids[] = 'amazon_creators_api';
		}
		foreach ( apply_filters( 'wprm_admin_notices', array() ) as $notice ) {
			if ( isset( $notice['id'] ) && ( ! isset( $notice['dismissable'] ) || $notice['dismissable'] )
				&& ( ! isset( $notice['capability'] ) || current_user_can( $notice['capability'] ) ) ) {
				$ids[] = $notice['id'];
			}
		}
		$ids = apply_filters( 'wprm_dismissible_notice_ids', $ids );
		$ids = array_filter( $ids, function( $id ) {
			return is_string( $id ) && 0 < strlen( $id ) && 100 >= strlen( $id );
		} );
		return array_slice( array_values( array_unique( $ids ) ), 0, 100 );
	}

	/**
	 * Validate before storing any caller-controlled value.
	 *
	 * @param mixed $id Notice ID.
	 * @return bool
	 */
	public static function is_valid_notice_id( $id ) {
		return is_string( $id ) && 0 < strlen( $id ) && 100 >= strlen( $id )
			&& in_array( $id, self::get_dismissible_notice_ids(), true );
	}

	/**
	 * Dismiss a known notice for the current user only.
	 * Keep the historical scalar-row format, bounded to one row per known ID.
	 *
	 * @param mixed $id Notice ID.
	 * @param int   $user_id Optional current user ID for backwards compatibility.
	 * @return bool
	 */
	public static function dismiss( $id, $user_id = 0 ) {
		global $wpdb;
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id || ! current_user_can( 'read' ) || ( $user_id && (int) $user_id !== $current_user_id ) || ! self::is_valid_notice_id( $id ) ) {
			return false;
		}

		if ( ! self::is_dismissed( $id ) && ! add_user_meta( $current_user_id, 'wprm_dismissed_notices', $id ) ) {
			return false;
		}

		// Clean in SQL: loading polluted user meta into PHP can exhaust memory.
		// update_user_meta alone would update every duplicate, not remove rows.
		$ids = self::get_dismissible_notice_ids();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND BINARY meta_value NOT IN ($placeholders)",
			array_merge( array( $current_user_id, 'wprm_dismissed_notices' ), $ids )
		) );
		// Also collapse concurrent inserts, retaining the oldest row for each ID.
		$deduplicated = $wpdb->query( $wpdb->prepare(
			"DELETE duplicate FROM {$wpdb->usermeta} duplicate INNER JOIN {$wpdb->usermeta} original
			ON duplicate.user_id = original.user_id AND duplicate.meta_key = original.meta_key
			AND BINARY duplicate.meta_value = BINARY original.meta_value AND duplicate.umeta_id > original.umeta_id
			WHERE duplicate.user_id = %d AND duplicate.meta_key = %s",
			$current_user_id, 'wprm_dismissed_notices'
		) );
		wp_cache_delete( $current_user_id, 'user_meta' );
		return false !== $deleted && false !== $deduplicated;
	}

	/**
	 * Read a dismissal without hydrating all (possibly polluted) user metadata.
	 *
	 * @param mixed $id Notice ID.
	 * @return bool
	 */
	public static function is_dismissed( $id ) {
		global $wpdb;
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id || ! is_string( $id ) || ! $id || 100 < strlen( $id ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND BINARY meta_value = %s LIMIT 1",
			$current_user_id, 'wprm_dismissed_notices', $id
		) );
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

	/**
	 * Show notices on plugins page.
	 * 
	 * @since	9.8.1
	 */
	public static function admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : false;
		$notices = self::get_notices();
		$current_user_id = get_current_user_id();

		if ( $screen && 'plugins' === $screen->id ) {
			foreach ( $notices as $notice ) {
				// Check if notice should be displayed on this page.
				if ( isset( $notice['location'] ) && is_array( $notice['location'] ) && in_array( 'plugins', $notice['location'], true ) ) {
					$dismissable = isset( $notice['dismissable'] ) ? $notice['dismissable'] : true;
					$notice_id = isset( $notice['id'] ) ? $notice['id'] : '';
					$title = isset( $notice['title'] ) ? $notice['title'] : '';
					$text = isset( $notice['text'] ) ? $notice['text'] : '';
					
					echo '<div class="notice notice-error wprm-notice' . ( $dismissable ? ' is-dismissible' : '' ) . '" data-notice-id="' . esc_attr( $notice_id ) . '" data-user-id="' . esc_attr( $current_user_id ) . '">';
					
					if ( $title ) {
						echo '<p><strong>' . esc_html( $title ) . '</strong></p>';
					}
					
					echo '<p>' . wp_kses_post( $text ) . '</p>';
					echo '</div>';
				}
			}
		}
	}
}

WPRM_Notices::init();