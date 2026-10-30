<?php
/**
 * Responsible for saving lists.
 *
 * @link       https://bootstrapped.ventures
 * @since      9.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Responsible for saving lists.
 *
 * @since      9.0.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_List_Saver {

	/**
	 * Register actions and filters.
	 *
	 * @since	9.0.0
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'update_post' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'update_lists_check' ) );
	}

	/**
	 * Sanitize list array.
	 *
	 * @since	9.0.0
	 * @param	array $list Array containing all list input data.
	 */
	public static function sanitize( $list ) {
		$sanitized_list = array();

		// Text fields.
		if ( isset( $list['name'] ) ) { $sanitized_list['name'] = sanitize_text_field( $list['name'] ); }
		if ( isset( $list['note'] ) ) { $sanitized_list['note'] = sanitize_text_field( $list['note'] ); }
		if ( isset( $list['template'] ) ) { $sanitized_list['template'] = sanitize_text_field( $list['template'] ); }
		if ( isset( $list['metadata_name'] ) ) { $sanitized_list['metadata_name'] = sanitize_text_field( $list['metadata_name'] ); }
		if ( isset( $list['metadata_description'] ) ) { $sanitized_list['metadata_description'] = sanitize_text_field( $list['metadata_description'] ); }

		// Boolean fields.
		if ( isset( $list['metadata_output'] ) ) { $sanitized_list['metadata_output'] = $list['metadata_output'] ? true : false; }

		// List Items.
		if ( isset( $list['items'] ) ) {
			$sanitized_items = array();
			$items = is_array( $list['items'] ) ? array_values( $list['items'] ) : array();

			$nbr_items = 0;
			$nbr_items_internal = 0;
			$nbr_items_external = 0;

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['type'] ) || ! isset( $item['data'] ) || ! is_array( $item['data'] ) ) {
					continue;
				}

				$valid_item = false;
				$data = $item['data'];

				if ( 'roundup' === $item['type'] ) {
					if ( ( 'internal' === $data['type'] || 'post' === $data['type'] ) && 0 < intval( $data['id'] ) ) {
						$valid_item = true;
						$nbr_items++;
						$nbr_items_internal++;
					}
					if ( 'external' === $data['type'] && $data['link'] ) {
						$valid_item = true;
						$nbr_items++;
						$nbr_items_external++;
					}

					// Sanitize item fields.
					$data['id'] = isset( $data['id'] ) ? intval( $data['id'] ) : 0;
					$data['link'] = sanitize_text_field( $data['link'] );
					$data['nofollow'] = ! empty( $data['nofollow'] ) ? '1' : '';
					$data['newtab'] = ! empty( $data['newtab'] ) ? '1' : '';
					$data['image'] = intval( $data['image'] );
					$data['image_url'] = sanitize_text_field( $data['image_url'] );
					$data['credit'] = sanitize_text_field( $data['credit'] );
					$data['name'] = sanitize_text_field( $data['name'] );
					$data['button'] = sanitize_text_field( $data['button'] );
					$data['summary'] = WPRM_Recipe_Sanitizer::sanitize_html( $data['summary'] );

					if ( isset( $data['template'] ) ) {
						$data['template'] = sanitize_text_field( $data['template'] );
					}

					// Only keep the fields a roundup item uses. Every field ends up in a shortcode attribute.
					$data = array_intersect_key( $data, array_flip( array( 'type', 'id', 'link', 'nofollow', 'newtab', 'image', 'image_url', 'credit', 'name', 'button', 'summary', 'template' ) ) );
				}
				
				if ( 'text' === $item['type'] ) {
					$text = WPRM_Recipe_Sanitizer::sanitize_html( $data['text'] );
					
					if ( $text ) {
						$data['text'] = $text; // Make sure to store sanitized version.
						$valid_item = true;
					}
				}

				if ( $valid_item ) {
					$sanitized_items[] = array(
						'uid' => isset( $item['uid'] ) ? intval( $item['uid'] ) : 0,
						'type' => sanitize_key( $item['type'] ),
						'data' => $data,
					);
				}
			}

			$sanitized_list['items'] = $sanitized_items;
			$sanitized_list['nbr_items'] = $nbr_items;
			$sanitized_list['nbr_items_internal'] = $nbr_items_internal;
			$sanitized_list['nbr_items_external'] = $nbr_items_external;
		}

		// Import fields.
		if ( isset( $list['imported_from'] ) ) { $sanitized_list['imported_from'] = sanitize_key( $list['imported_from'] ); }

		return apply_filters( 'wprm_list_sanitize', $sanitized_list, $list );
	}

	/**
	 * Create a new list.
	 *
	 * @since	9.0.0
	 * @param	array $list List fields to save.
	 */
	public static function create_list( $list ) {
		$post = array(
			'post_type' => WPRM_LIST_POST_TYPE,
			'post_status' => 'draft',
		);

		$list_id = wp_insert_post( $post );
		WPRM_List_Saver::update_list( $list_id, $list );

		return $list_id;
	}

	/**
	 * Save list fields.
	 *
	 * @since	9.0.0
	 * @param	int   $id Post ID of the list.
	 * @param	array $list List fields to save.
	 */
	public static function update_list( $id, $list ) {
		$meta = array();

		// Meta fields.
		$allowed_meta = array(
			'template',
			'metadata_output',
			'metadata_name',
			'metadata_description',
			'items',
			'nbr_items',
			'nbr_items_internal',
			'nbr_items_external',
			'imported_from',
		);

		foreach ( $allowed_meta as $meta_key ) {
			if ( isset( $list[ $meta_key ] ) ) {
				$meta[ 'wprm_' . $meta_key ] = $list[ $meta_key ];
			}
		}

		// Post Fields.
		$post = array(
			'ID' => $id,
			'meta_input' => $meta,
		);

		if ( isset( $list['name'] ) ) {
			$post['post_title'] = $list['name'];
		}

		if ( isset( $list['note'] ) ) {
			$post['post_content'] = wp_slash( $list['note'] );
		}

		// Always update post to make sure revision gets made.
		WPRM_List_Manager::invalidate_list( $id );
		wp_update_post( $post );
	}

	/**
	 * Check if post being saved contains lists we need to update.
	 *
	 * @since	9.0.0
	 * @param	int    $id Post ID being saved.
	 * @param	object $post Post being saved.
	 */
	public static function update_post( $id, $post ) {
		// Use parent post if we're currently updating a revision.
		$revision_parent = wp_is_post_revision( $post );
		if ( $revision_parent ) {
			$post = get_post( $revision_parent );
		}

		$list_ids = WPRM_List_Manager::get_list_ids_from_post( $post->ID );

		if ( false === $list_ids ) {
			return;
		}

		// Make sure post itself is not included.
		if ( in_array( $post->ID, $list_ids ) ) {
			$list_ids = array_diff( $list_ids, array( $post->ID ) );
		}

		// Only update lists the user saving this post is allowed to edit.
		foreach ( $list_ids as $index => $list_id ) {
			if ( WPRM_LIST_POST_TYPE !== get_post_type( $list_id ) || ! current_user_can( 'edit_post', $list_id ) ) {
				unset( $list_ids[ $index ] );
			}
		}
		$list_ids = array_values( $list_ids );

		if ( count( $list_ids ) > 0 ) {
			$acting_user_id = get_current_user_id();

			// Immediately update when importing, otherwise do on next load to prevent issues with other plugins.
			if ( isset( $_POST['importer_uid'] ) || ( isset( $_POST['action'] ) && 'wprm_finding_parents' === $_POST['action'] ) ) { // Input var okay.
				self::update_lists_in_post( $post->ID, $list_ids, $acting_user_id );
			} else {
				$post_lists_to_update = get_option( 'wprm_post_lists_to_update', array() );
				$post_lists_to_update[ $post->ID ] = array(
					'user_id'  => $acting_user_id,
					'list_ids' => $list_ids,
				);
				update_option( 'wprm_post_lists_to_update', $post_lists_to_update );
			}
		}

		// Fix lists that have this post as a parent post when they aren't actually inside anymore.
		$args = array(
			'post_type' => WPRM_LIST_POST_TYPE,
			'post_status' => 'any',
			'posts_per_page' => -1,
			'meta_query' => array(
				array(
					'key'     => 'wprm_parent_post_id',
					'compare' => '=',
					'value' => $id,
				),
			),
			'fields' => 'ids',
		);

		$query = new WP_Query( $args );
		
		if ( $query->have_posts() ) {
			$ids = $query->posts;

			foreach ( $ids as $id ) {
				if ( ! in_array( $id, $list_ids ) && current_user_can( 'edit_post', $id ) ) {
					delete_post_meta( $id, 'wprm_parent_post_id' );
				}
			}
		}
	}

	/**
	 * Check if post being saved contains lists we need to update.
	 *
	 * @since	9.0.0
	 */
	public static function update_lists_check() {
		if ( ! isset( $_POST['action'] ) ) {
			$post_lists_to_update = get_option( 'wprm_post_lists_to_update', array() );

			if ( ! empty( $post_lists_to_update ) ) {
				$i = 0;
				while ( $i < 10 && ! empty( $post_lists_to_update ) ) {
					// Get first post to update the lists for.
					$update = reset( $post_lists_to_update );
					$post_id = key( $post_lists_to_update );

					// Entries without an acting user predate the authorization check and cannot be processed safely.
					if ( is_array( $update ) && isset( $update['user_id'], $update['list_ids'] ) && is_array( $update['list_ids'] ) ) {
						self::update_lists_in_post( $post_id, $update['list_ids'], intval( $update['user_id'] ) );
					}

					// Update remaing post/lists to update.
					unset( $post_lists_to_update[ $post_id ] );
					$i++;
				}

				update_option( 'wprm_post_lists_to_update', $post_lists_to_update );
			}
		}
	}

	/**
	 * Update lists with post data.
	 *
	 * @since	9.0.0
	 * @param	mixed    $post_id        Post to use the data from.
	 * @param	array    $list_ids       Lists to update.
	 * @param	int|null $acting_user_id User that requested the update. Defaults to the current user for direct calls.
	 */
	public static function update_lists_in_post( $post_id, $list_ids, $acting_user_id = null ) {
		$post = get_post( $post_id );
		$acting_user_id = is_null( $acting_user_id ) ? get_current_user_id() : intval( $acting_user_id );

		// Can happen when revision was scheduled and already removed.
		if ( ! $post ) {
			return;
		}

		// Skip Revisionize revisions.
		$revisionize = get_post_meta( $post_id, '_post_revision_of', true );
		if ( $revisionize && is_plugin_active( 'revisionize/revisionize.php' ) && get_post_status( $revisionize ) ) {
			return;
		}

		// Skip Revision Manager TMC revisions.
		$rm_tmc = get_post_meta( $post_id, 'linked_post_id', true );
		if ( $rm_tmc && is_plugin_active( 'revision-manager-tmc/revision-manager-tmc.php' ) && get_post_status( $rm_tmc ) ) {
			return;
		}

		// Skip Revisionary / PublishPress revisions.
		$revisionary = get_post_meta( $post_id, '_rvy_base_post_id', true );
		if ( $revisionary && is_plugin_active( 'revisionary/revisionary.php' ) && get_post_status( $revisionary ) ) {
			return;
		}

		// Skip Yoast Duplicate Posts Rewrite.
		$yoast_dp = get_post_meta( $post_id, '_dp_is_rewrite_republish_copy', true );
		if ( $yoast_dp && is_plugin_active( 'duplicate-post/duplicate-post.php' ) ) {
			return;
		}

		if ( 'trash' !== $post->post_status ) {
			$list_post_status = $post->post_status;

			// Prevent lists from taking over custom post statusses (and being excluded from the manage page).
			$allowed_post_statusses = array( 'publish', 'future', 'draft', 'private' );
			
			if ( ! in_array( $list_post_status, $allowed_post_statusses ) ) {
				$list_post_status = 'draft';
			}

			// Update lists.
			foreach ( $list_ids as $list_id ) {
				// Prevent infinite loop.
				if ( $list_id === $post_id ) {
					continue;
				}

				// Check the permissions of the user that originally requested this update.
				if ( ! self::user_can_edit_list( $acting_user_id, $list_id ) ) {
					continue;
				}

				$list = array(
					'ID'          	=> $list_id,
					'post_status' 	=> $list_post_status,
					'post_date' 	=> $post->post_date,
					'post_date_gmt' => $post->post_date_gmt,
					'post_modified' => $post->post_modified,
					'post_author'	=> $post->post_author,
					'edit_date'		=> true, // Required when going from draft to future.
				);

				wp_update_post( $list );
				update_post_meta( $list_id, 'wprm_parent_post_id', $post_id );
			}
		} else {
			// Parent got deleted, set as draft and remove parent post relation.
			foreach ( $list_ids as $list_id ) {
				if ( ! self::user_can_edit_list( $acting_user_id, $list_id ) ) {
					continue;
				}

				$current_parent_id = intval( get_post_meta( $list_id, 'wprm_parent_post_id', true ) );

				if ( $current_parent_id && intval( $post_id ) !== $current_parent_id ) {
					continue;
				}

				$list = array(
					'ID'          => $list_id,
					'post_status' => 'draft',
				);
				wp_update_post( $list );

				delete_post_meta( $list_id, 'wprm_parent_post_id' );
			}
		}
	}

	/**
	 * Check if a user is allowed to edit a list.
	 *
	 * @since	10.9.2
	 * @param	int $user_id User to check.
	 * @param	int $list_id List to check.
	 * @return	bool
	 */
	private static function user_can_edit_list( $user_id, $list_id ) {
		$user_id = intval( $user_id );
		$list_id = intval( $list_id );

		return $user_id && WPRM_LIST_POST_TYPE === get_post_type( $list_id ) && user_can( $user_id, 'edit_post', $list_id );
	}
}

WPRM_List_Saver::init();
