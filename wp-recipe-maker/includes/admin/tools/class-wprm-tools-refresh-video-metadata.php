<?php
/**
 * Responsible for handling the refresh video metadata tool.
 *
 * @link       https://bootstrapped.ventures
 * @since      6.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/admin
 */

/**
 * Responsible for handling the refresh video metadata tool.
 *
 * @since      6.0.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/admin
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Tools_Refresh_Video_Metadata {

	/**
	 * Register actions and filters.
	 *
	 * @since	6.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu_page' ), 20 );
		add_action( 'wp_ajax_wprm_refresh_video_metadata', array( __CLASS__, 'ajax_refresh_video_metadata' ) );
	}

	/**
	 * Add the tools submenu to the WPRM menu.
	 *
	 * @since	6.0.0
	 */
	public static function add_submenu_page() {
		add_submenu_page( '', __( 'Refresh Video Metadata', 'wp-recipe-maker' ), __( 'Refresh Video Metadata', 'wp-recipe-maker' ), WPRM_Settings::get( 'features_tools_access' ), 'wprm_refresh_video_metadata', array( __CLASS__, 'refresh_video_metadata' ) );
	}

	/**
	 * Get the template for the refresh video metadata page.
	 *
	 * @since    6.0.0
	 */
	public static function refresh_video_metadata() {
		$args = array(
			'post_type' => WPRM_POST_TYPE,
			'post_status' => 'all',
			'posts_per_page' => -1,
			'fields' => 'ids',
		);

		$posts = get_posts( $args );

		// Only when debugging.
		if ( WPRM_Tools_Manager::$debugging ) {
			$result = self::refreshing_video_metadata( $posts ); // Input var okay.
			WPRM_Debug::log( $result );
			die();
		}

		// Handle via AJAX.
		wp_localize_script( 'wprm-admin', 'wprm_tools', array(
			'action' => 'refresh_video_metadata',
			'posts' => $posts,
			'args' => array(),
		));

		require_once( WPRM_DIR . 'templates/admin/menu/tools/refresh-video-metadata.php' );
	}

	/**
	 * Refresh video metadata through AJAX.
	 *
	 * @since    6.0.0
	 */
	public static function ajax_refresh_video_metadata() {
		if ( ! check_ajax_referer( 'wprm', 'security', false ) || ! current_user_can( WPRM_Settings::get( 'features_tools_access' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to use this tool.', 'wp-recipe-maker' ) ), 403 );
		}

		$posts = isset( $_POST['posts'] ) && is_string( $_POST['posts'] ) ? json_decode( wp_unslash( $_POST['posts'] ), true ) : null;
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'queue';
		if ( ! is_array( $posts ) || array_filter( $posts, function( $id ) { return ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || intval( $id ) < 1; } ) || ! in_array( $mode, array( 'queue', 'status', 'now' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Provide valid recipe IDs and a refresh action.', 'wp-recipe-maker' ) ), 400 );
		}
		if ( 'now' === $mode && ( 1 !== count( $posts ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', intval( reset( $posts ) ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators who can edit this recipe can refresh it now.', 'wp-recipe-maker' ) ), 403 );
		}

		$posts_processed = array_map( 'intval', array_splice( $posts, 0, 10 ) );
		$entries = 'queue' === $mode ? self::refreshing_video_metadata( $posts_processed ) : array();
		if ( 'queue' !== $mode ) {
			foreach ( $posts_processed as $recipe_id ) {
				$entries[] = 'now' === $mode ? self::refresh_recipe_now( $recipe_id ) : self::recipe_report( $recipe_id );
			}
		}

		wp_send_json_success( array(
			'posts_processed' => $posts_processed,
			'posts_left' => array_values( $posts ),
			'report' => array(
				'title' => __( 'Video metadata refresh status', 'wp-recipe-maker' ),
				'summary' => __( 'Queued work runs in the background. Check status for saved metadata and the latest failure details.', 'wp-recipe-maker' ),
				'columns' => array(
					array( 'key' => 'recipe_id', 'label' => __( 'Recipe ID', 'wp-recipe-maker' ) ),
					array( 'key' => 'status', 'label' => __( 'Status', 'wp-recipe-maker' ) ),
					array( 'key' => 'message', 'label' => __( 'Details', 'wp-recipe-maker' ) ),
					array( 'key' => 'saved_at', 'label' => __( 'Last saved (UTC)', 'wp-recipe-maker' ) ),
					array( 'key' => 'next_attempt', 'label' => __( 'Retry planned (UTC)', 'wp-recipe-maker' ) ),
					array( 'key' => 'diagnostics', 'label' => __( 'Last failure', 'wp-recipe-maker' ) ),
				),
				'entries' => $entries,
			),
		) );
	}

	/** Queue each recipe and return the actual scheduling outcome. */
	public static function refreshing_video_metadata( $posts ) {
		$entries = array();
		foreach ( $posts as $post_id ) {
			$recipe = WPRM_Recipe_Manager::get_recipe( $post_id );
			if ( $recipe ) {
				// Keep visitor-driven recovery available when cron or loopback is interrupted.
				delete_post_meta( $post_id, 'wprm_video_metadata_updated' );
			}
			$result = WPRM_MetadataVideo::schedule_refresh_result( $recipe, true );
			$entries[] = self::recipe_report( $post_id, $result );
		}
		return $entries;
	}

	/** Explicit admin-only recovery; use the same queue state, worker and stale checks. */
	public static function refresh_recipe_now( $recipe_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $recipe_id ) ) {
			return self::recipe_report( $recipe_id, array( 'outcome' => 'skipped', 'reason' => 'not_authorized' ) );
		}
		$recipe = WPRM_Recipe_Manager::get_recipe( $recipe_id );
		if ( $recipe ) {
			delete_post_meta( $recipe_id, 'wprm_video_metadata_updated' );
		}
		$result = WPRM_MetadataVideo::schedule_refresh_result( $recipe, true, false );
		$state = WPRM_MetadataVideo::get_refresh_state( $recipe_id );
		if ( in_array( $result['outcome'], array( 'queued', 'already_pending' ), true ) && ! in_array( $result['reason'], array( 'locked', 'processing' ), true ) && $state && 'queued' === $state['status'] ) {
			$status = WPRM_MetadataVideo::process_refresh( $recipe_id, $state['signature'] );
			$result = array( 'outcome' => $status, 'reason' => $status );
		}
		return self::recipe_report( $recipe_id, $result );
	}

	/** Project only useful admin fields. Never expose signatures or initiating user IDs. */
	public static function recipe_report( $recipe_id, $result = null ) {
		$recipe = WPRM_Recipe_Manager::get_recipe( $recipe_id );
		if ( ! $recipe ) {
			return array( 'recipe_id' => intval( $recipe_id ), 'status' => 'skipped', 'message' => __( 'Recipe is missing or inaccessible.', 'wp-recipe-maker' ) );
		}
		$state = WPRM_MetadataVideo::get_refresh_state( $recipe_id );
		$metadata = get_post_meta( $recipe_id, 'wprm_video_metadata', true );
		$saved_at = intval( get_post_meta( $recipe_id, 'wprm_video_metadata_updated', true ) );
		$status = $result ? $result['outcome'] : ( $state ? $state['status'] : ( $saved_at && is_array( $metadata ) ? 'saved' : 'idle' ) );
		$reason = $result ? $result['reason'] : $status;
		if ( ! $result && ! $state && ! WPRM_MetadataVideo::has_video_sources( $recipe ) ) {
			$status = 'skipped';
			$reason = 'no_video';
		}
		if ( ! $result && $state && ! empty( $state['dispatch_diagnostic'] ) ) {
			$status = 'failed';
			$reason = 'dispatch_failed';
		}
		$messages = array(
			'queued' => __( 'Queued; metadata has not yet been saved by this refresh.', 'wp-recipe-maker' ),
			'dispatch_accepted' => __( 'Queued; the nonblocking dispatch does not confirm worker completion.', 'wp-recipe-maker' ),
			'dispatch_deferred' => __( 'Queued; waiting for the background worker or its next dispatch.', 'wp-recipe-maker' ),
			'dispatch_failed' => __( 'Dispatch failed. Work remains queued for recovery; see failure details.', 'wp-recipe-maker' ),
			'worker_unavailable' => __( 'Background worker is unavailable. Check the planned retry and cron details.', 'wp-recipe-maker' ),
			'processing' => __( 'A refresh is already running.', 'wp-recipe-maker' ),
			'locked' => __( 'Recipe is busy. Check status again shortly.', 'wp-recipe-maker' ),
			'retry_scheduled' => __( 'Waiting for retry backoff to expire.', 'wp-recipe-maker' ),
			'no_video' => __( 'Skipped: no usable video source.', 'wp-recipe-maker' ),
			'invalid_recipe' => __( 'Recipe is missing or inaccessible.', 'wp-recipe-maker' ),
			'completed' => __( 'Refresh completed and metadata was verified in storage.', 'wp-recipe-maker' ),
			'saved' => __( 'Saved metadata is available; no refresh is pending.', 'wp-recipe-maker' ),
			'failed' => __( 'Refresh failed. See failure details and the next retry time.', 'wp-recipe-maker' ),
			'storage_failed' => __( 'Refresh data could not be saved.', 'wp-recipe-maker' ),
			'stale' => __( 'Recipe changed during refresh. Check status or refresh again.', 'wp-recipe-maker' ),
			'not_authorized' => __( 'You do not have permission to refresh this recipe now.', 'wp-recipe-maker' ),
			'idle' => __( 'No saved metadata or pending refresh was found.', 'wp-recipe-maker' ),
		);
		$diagnostics = $state && isset( $state['diagnostics'] ) ? array_slice( $state['diagnostics'], 0, 5 ) : array();
		if ( $state && ! empty( $state['dispatch_diagnostic'] ) ) {
			array_unshift( $diagnostics, $state['dispatch_diagnostic'] );
		}
		$retry_event = $state && isset( $state['signature'] ) ? wp_next_scheduled( WPRM_MetadataVideo::REFRESH_RETRY_HOOK, array( intval( $recipe_id ), $state['signature'] ) ) : false;
		$healthcheck_event = wp_next_scheduled( 'wprm_video_metadata_refresh_cron' );
		return array(
			'recipe_id' => intval( $recipe_id ),
			'status' => $status,
			'message' => isset( $messages[ $reason ] ) ? $messages[ $reason ] : $messages['idle'],
			'saved_at' => $saved_at ? gmdate( 'Y-m-d H:i:s', $saved_at ) : '',
			'next_attempt' => $state && ! empty( $state['next_attempt'] ) ? gmdate( 'Y-m-d H:i:s', $state['next_attempt'] ) : '',
			'retry_event_at' => $retry_event ? gmdate( 'Y-m-d H:i:s', $retry_event ) : '',
			'healthcheck_event_at' => $healthcheck_event ? gmdate( 'Y-m-d H:i:s', $healthcheck_event ) : '',
			'diagnostics' => $diagnostics,
		);
	}
}

WPRM_Tools_Refresh_Video_Metadata::init();
