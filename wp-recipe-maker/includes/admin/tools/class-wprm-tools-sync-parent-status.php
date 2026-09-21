<?php
/**
 * Responsible for handling the sync parent status tool.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.8.4
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/admin
 */

/**
 * Responsible for handling the sync parent status tool.
 *
 * @since      10.8.4
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/admin
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Tools_Sync_Parent_Status {

	/**
	 * Register actions and filters.
	 *
	 * @since	10.8.4
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu_page' ), 20 );
		add_action( 'wp_ajax_wprm_sync_parent_status', array( __CLASS__, 'ajax_sync_parent_status' ) );
	}

	/**
	 * Add the tools submenu to the WPRM menu.
	 *
	 * @since	10.8.4
	 */
	public static function add_submenu_page() {
		add_submenu_page( '', __( 'Sync Parent Status', 'wp-recipe-maker' ), __( 'Sync Parent Status', 'wp-recipe-maker' ), WPRM_Settings::get( 'features_tools_access' ), 'wprm_sync_parent_status', array( __CLASS__, 'sync_parent_status' ) );
	}

	/**
	 * Get the template for the sync parent status page.
	 *
	 * @since	10.8.4
	 */
	public static function sync_parent_status() {
		$posts = self::get_all_recipe_ids();

		// Only when debugging.
		if ( WPRM_Tools_Manager::$debugging ) {
			$result = self::sync_parent_statuses( $posts ); // Input var okay.
			WPRM_Debug::log( $result );
			die();
		}

		// Handle via AJAX.
		wp_localize_script( 'wprm-admin', 'wprm_tools', array(
			'action' => 'sync_parent_status',
			'posts' => $posts,
			'args' => array(),
		));

		require_once( WPRM_DIR . 'templates/admin/menu/tools/sync-parent-status.php' );
	}

	/**
	 * Sync parent status through AJAX.
	 *
	 * @since	10.8.4
	 */
	public static function ajax_sync_parent_status() {
		if ( check_ajax_referer( 'wprm', 'security', false ) ) {
			if ( current_user_can( WPRM_Settings::get( 'features_tools_access' ) ) ) {
				$posts = isset( $_POST['posts'] ) ? json_decode( wp_unslash( $_POST['posts'] ) ) : array(); // Input var okay.

				$posts_left = array();
				$posts_processed = array();
				$result = array();

				if ( count( $posts ) > 0 ) {
					$posts_left = $posts;
					$posts_processed = array_map( 'intval', array_splice( $posts_left, 0, 10 ) );

					$result = self::sync_parent_statuses( $posts_processed );

					if ( is_wp_error( $result ) ) {
						wp_send_json_error( array(
							'redirect' => add_query_arg( array( 'sub' => 'advanced' ), admin_url( 'admin.php?page=wprm_tools' ) ),
						) );
					}
				}

				wp_send_json_success( array(
					'posts_processed' => $posts_processed,
					'posts_left' => $posts_left,
					'report' => isset( $result['report'] ) ? $result['report'] : false,
				) );
			}
		}

		wp_die();
	}

	/**
	 * Sync recipe statuses with their canonical parent posts.
	 *
	 * @since	10.8.4
	 * @param	array $posts Recipe IDs to sync.
	 */
	public static function sync_parent_statuses( $posts ) {
		$entries = array();

		foreach ( $posts as $post_id ) {
			$entries[] = self::sync_parent_status_for_recipe( $post_id );
		}

		return array(
			'report' => array(
				'toggle' => __( 'Detailed Report', 'wp-recipe-maker' ),
				'title' => __( 'Sync Parent Status Report', 'wp-recipe-maker' ),
				'columns' => array(
					array(
						'key' => 'recipe',
						'label' => __( 'Recipe', 'wp-recipe-maker' ),
					),
					array(
						'key' => 'parent_post',
						'label' => __( 'Parent Post', 'wp-recipe-maker' ),
					),
					array(
						'key' => 'recipe_status',
						'label' => __( 'Recipe Status', 'wp-recipe-maker' ),
					),
					array(
						'key' => 'parent_status',
						'label' => __( 'Parent Status', 'wp-recipe-maker' ),
					),
					array(
						'key' => 'status',
						'label' => __( 'Status', 'wp-recipe-maker' ),
					),
					array(
						'key' => 'details',
						'label' => __( 'Details', 'wp-recipe-maker' ),
					),
				),
				'entries' => $entries,
				'json_label' => __( 'Full Report JSON', 'wp-recipe-maker' ),
			),
		);
	}

	/**
	 * Get all recipe IDs across all languages.
	 *
	 * @since	10.8.4
	 */
	private static function get_all_recipe_ids() {
		$request_post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;

		if ( $request_post_id && WPRM_POST_TYPE === get_post_type( $request_post_id ) ) {
			return array( $request_post_id );
		}

		$args = array(
			'post_type' => WPRM_POST_TYPE,
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters' => false,
		);

		global $wpml_query_filter;
		if ( $wpml_query_filter ) {
			remove_filter( 'posts_join', array( $wpml_query_filter, 'posts_join_filter' ), 10, 2 );
			remove_filter( 'posts_where', array( $wpml_query_filter, 'posts_where_filter' ), 10, 2 );
		}

		$query = new WP_Query( $args );

		if ( $wpml_query_filter ) {
			add_filter( 'posts_join', array( $wpml_query_filter, 'posts_join_filter' ), 10, 2 );
			add_filter( 'posts_where', array( $wpml_query_filter, 'posts_where_filter' ), 10, 2 );
		}

		return is_array( $query->posts ) ? $query->posts : array();
	}

	/**
	 * Sync the status for a single recipe and return report data.
	 *
	 * @since	10.8.4
	 * @param	int $post_id Recipe ID.
	 */
	private static function sync_parent_status_for_recipe( $post_id ) {
		$post_id = intval( $post_id );
		$title = get_the_title( $post_id );
		$entry = array(
			'post_id' => $post_id,
			'recipe' => $title ? $title . ' (#' . $post_id . ')' : '#' . $post_id,
			'parent_post_id' => 0,
			'parent_post' => '-',
			'recipe_status' => get_post_status( $post_id ),
			'parent_status' => false,
			'result' => 'skipped_invalid_recipe',
			'status' => __( 'Skipped: invalid recipe', 'wp-recipe-maker' ),
			'details' => __( 'Recipe could not be loaded.', 'wp-recipe-maker' ),
		);

		if ( ! $post_id || WPRM_POST_TYPE !== get_post_type( $post_id ) ) {
			return $entry;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$entry['result'] = 'skipped_forbidden';
			$entry['status'] = __( 'Skipped: insufficient permissions', 'wp-recipe-maker' );
			$entry['details'] = __( 'The current user cannot edit this recipe.', 'wp-recipe-maker' );
			return $entry;
		}

		$parent_post_id = intval( get_post_meta( $post_id, 'wprm_parent_post_id', true ) );
		$entry['parent_post_id'] = $parent_post_id;

		if ( ! $parent_post_id ) {
			$entry['result'] = 'skipped_no_parent';
			$entry['status'] = __( 'Skipped: no parent post', 'wp-recipe-maker' );
			$entry['details'] = __( 'Recipe has no parent post assigned. Run the Find Parent Posts tool first.', 'wp-recipe-maker' );
			return $entry;
		}

		$parent_post = get_post( $parent_post_id );

		if ( ! $parent_post || $parent_post_id === $post_id ) {
			$entry['result'] = 'skipped_invalid_parent';
			$entry['status'] = __( 'Skipped: invalid parent post', 'wp-recipe-maker' );
			$entry['details'] = __( 'The assigned parent post could not be loaded.', 'wp-recipe-maker' );
			return $entry;
		}

		$parent_title = get_the_title( $parent_post_id );
		$entry['parent_post'] = $parent_title ? $parent_title . ' (#' . $parent_post_id . ')' : '#' . $parent_post_id;
		$entry['parent_status'] = $parent_post->post_status;

		$allowed_parent_statuses = array( 'publish', 'future', 'draft', 'private' );
		if ( ! in_array( $parent_post->post_status, $allowed_parent_statuses, true ) ) {
			$entry['result'] = 'skipped_unsupported_parent_status';
			$entry['status'] = __( 'Skipped: unsupported parent status', 'wp-recipe-maker' );
			$entry['details'] = sprintf(
				/* translators: %s: parent post status */
				__( 'Parent post status "%s" is not supported.', 'wp-recipe-maker' ),
				$parent_post->post_status
			);
			return $entry;
		}

		$parent_recipe_ids = WPRM_Recipe_Manager::get_recipe_ids_from_post( $parent_post_id, true );
		$parent_recipe_ids = false === $parent_recipe_ids ? array() : array_map( 'intval', $parent_recipe_ids );

		if ( ! in_array( $post_id, $parent_recipe_ids, true ) ) {
			$entry['result'] = 'skipped_parent_mismatch';
			$entry['status'] = __( 'Skipped: recipe not found in parent', 'wp-recipe-maker' );
			$entry['details'] = __( 'The assigned parent post does not contain this recipe. Run the Find Parent Posts tool first.', 'wp-recipe-maker' );
			return $entry;
		}

		if ( $entry['recipe_status'] === $parent_post->post_status ) {
			$entry['result'] = 'no_changes';
			$entry['status'] = __( 'No changes', 'wp-recipe-maker' );
			$entry['details'] = __( 'Recipe status already matched the parent post.', 'wp-recipe-maker' );
			return $entry;
		}

		$previous_status = $entry['recipe_status'];
		$recipe_post = get_post( $post_id );

		// WordPress couples publish/future statuses to the publication date. Do not
		// change the date as a side effect of this status-only repair.
		if ( in_array( $parent_post->post_status, array( 'publish', 'future' ), true ) ) {
			$recipe_date_gmt = $recipe_post->post_date_gmt;
			if ( ! $recipe_date_gmt || '0000-00-00 00:00:00' === $recipe_date_gmt ) {
				$recipe_date_gmt = get_gmt_from_date( $recipe_post->post_date );
			}

			$recipe_date_timestamp = strtotime( $recipe_date_gmt );
			$recipe_date_is_future = $recipe_date_timestamp && MINUTE_IN_SECONDS <= $recipe_date_timestamp - time();
			$status_requires_date_change = ( 'future' === $parent_post->post_status && ! $recipe_date_is_future )
				|| ( 'publish' === $parent_post->post_status && $recipe_date_is_future );

			if ( $status_requires_date_change ) {
				$entry['result'] = 'skipped_date_conflict';
				$entry['status'] = __( 'Skipped: publication date conflict', 'wp-recipe-maker' );
				$entry['details'] = __( 'Matching the parent status would require changing the recipe publication date, so no changes were made.', 'wp-recipe-maker' );
				return $entry;
			}
		}

		$updated = wp_update_post( array(
			'ID' => $post_id,
			'post_status' => $parent_post->post_status,
			'post_date' => $recipe_post->post_date,
			'post_date_gmt' => $recipe_post->post_date_gmt,
			'edit_date' => true,
		), true );

		if ( is_wp_error( $updated ) ) {
			$entry['result'] = 'update_failed';
			$entry['status'] = __( 'Update failed', 'wp-recipe-maker' );
			$entry['details'] = $updated->get_error_message();
			return $entry;
		}

		$entry['recipe_status'] = get_post_status( $post_id );

		if ( $entry['recipe_status'] !== $parent_post->post_status ) {
			$entry['result'] = 'update_failed';
			$entry['status'] = __( 'Update failed', 'wp-recipe-maker' );
			$entry['details'] = __( 'WordPress did not accept the parent status without changing other recipe fields.', 'wp-recipe-maker' );
			return $entry;
		}

		$entry['result'] = 'updated';
		$entry['status'] = __( 'Updated', 'wp-recipe-maker' );
		$entry['details'] = sprintf(
			/* translators: 1: previous recipe status 2: new recipe status */
			__( 'Recipe status changed from %1$s to %2$s.', 'wp-recipe-maker' ),
			$previous_status,
			$entry['recipe_status']
		);

		return $entry;
	}
}

WPRM_Tools_Sync_Parent_Status::init();
