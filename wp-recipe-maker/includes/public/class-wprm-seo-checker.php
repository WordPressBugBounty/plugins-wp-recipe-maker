<?php
/**
 * Responsible for performing an SEO check on recipes.
 *
 * @link       https://bootstrapped.ventures
 * @since      1.15.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Responsible for performing an SEO check on recipes.
 *
 * @since      1.15.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Seo_Checker {

	/**
	 * Version of the checks. Bump when the checks change so stored results get refreshed.
	 *
	 * @since	10.9.0
	 */
	const VERSION = 3;

	/**
	 * Minimum image width we recommend, as Google uses it for large previews in Search and Discover.
	 *
	 * @since	10.9.0
	 */
	const RECOMMENDED_IMAGE_WIDTH = 1200;

	/**
	 * Keep track of the recipes we're updating the SEO for.
	 *
	 * @since	8.1.0
	 * @access  private
	 * @var     array $updating_seo_for Recipes we're updating the SEO for.
	 */
	private static $updating_seo_for = array();

	/**
	 * Recipes using an attachment that is being deleted.
	 *
	 * @since	10.9.0
	 * @access  private
	 * @var     array $attachment_recipe_ids Recipe IDs, keyed by attachment ID.
	 */
	private static $attachment_recipe_ids = array();

	/**
	 * Register actions and filters.
	 *
	 * @since	10.9.0
	 */
	public static function init() {
		add_action( 'delete_attachment', array( __CLASS__, 'collect_recipes_for_attachment' ), 10, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'update_seo_after_attachment_deleted' ), 10, 1 );
		add_action( 'delete_term', array( __CLASS__, 'update_seo_after_term_deleted' ), 10, 5 );
	}

	/**
	 * Update the SEO data for a recipe.
	 *
	 * @since    8.0.0
	 * @param    mixed $recipe_id Recipe ID to update the SEO for.
	 */
	public static function update_seo_for( $recipe_id ) {
		$result = false;
		$recipe_id = intval( $recipe_id );

		// Prevent infinite loop.
		if ( ! array_key_exists( $recipe_id, self::$updating_seo_for ) ) {
			self::$updating_seo_for[ $recipe_id ] = true;

			// Make sure we get the latest version, with the latest ratings.
			WPRM_Recipe_Manager::invalidate_recipe( $recipe_id );
			$recipe = WPRM_Recipe_Manager::get_recipe( $recipe_id );

			if ( $recipe ) {
				$result = self::check_recipe( $recipe );

				update_post_meta( $recipe_id, 'wprm_seo', $result );
				update_post_meta( $recipe_id, 'wprm_seo_priority', $result['priority'] );

				// The cached recipe loaded its meta before this update.
				WPRM_Recipe_Manager::invalidate_recipe( $recipe_id );
			}
			unset( self::$updating_seo_for[ $recipe_id ] );
		}

		return $result;
	}

	/**
	 * Update the SEO data for multiple recipes.
	 *
	 * @since	10.9.0
	 * @param	array $recipe_ids Recipe IDs to update the SEO for.
	 */
	public static function update_seo_for_recipes( $recipe_ids ) {
		$recipe_ids = array_unique( array_filter( array_map( 'intval', (array) $recipe_ids ) ) );

		foreach ( $recipe_ids as $recipe_id ) {
			if ( WPRM_POST_TYPE === get_post_type( $recipe_id ) ) {
				self::update_seo_for( $recipe_id );
			}
		}
	}

	/**
	 * Signature of everything the stored SEO result depends on, apart from the recipe itself.
	 *
	 * @since	10.9.0
	 */
	public static function signature() {
		return md5( implode( '|', array(
			self::VERSION,
			WPRM_Settings::get( 'post_type_structure' ),
			WPRM_Settings::get( 'metadata_only_show_for_first_recipe' ) ? '1' : '0',
			WPRM_Settings::get( 'recipe_image_use_featured' ) ? '1' : '0',
			self::ratings_enabled() ? '1' : '0',
		) ) );
	}

	/**
	 * Check if stored SEO data needs to be generated again.
	 *
	 * @since	10.9.0
	 * @param	mixed $seo Stored SEO data.
	 */
	public static function is_outdated( $seo ) {
		return ! is_array( $seo ) || ! isset( $seo['signature'] ) || self::signature() !== $seo['signature'];
	}

	/**
	 * Perform an SEO check on a specific recipe.
	 *
	 * @since    1.15.0
	 * @param    mixed $recipe Recipe to check the SEO for.
	 */
	public static function check_recipe( $recipe ) {
		if ( 'food' === $recipe->type() ) {
			$result = self::check_food_recipe( $recipe );
		} else if ( 'howto' === $recipe->type() ) {
			$result = self::check_howto_recipe( $recipe );
		} else {
			$result = array(
				'type' => 'other',
				'message' => 'No metadata will be output as this recipe has been marked as type "Other"',
				'issues' => array(),
				'notes' => array(),
			);
		}

		// Convert type to priority number.
		switch ( $result['type'] ) {
			case 'bad':
				$result['priority'] = 5;
				break;
			case 'warning':
				$result['priority'] = 10;
				break;
			case 'rating':
				$result['priority'] = 15;
				break;
			case 'good':
				$result['priority'] = 20;
				break;
			default:
				$result['priority'] = 25;
		}

		$result['signature'] = self::signature();

		return $result;
	}

	/**
	 * Get SEO data for recipe where it hasn't been generated for.
	 *
	 * @since	8.0.0
	 */
	public static function missing_seo_data() {
		return array(
			'priority' => 0,
			'type' => 'missing',
			'message' => __( 'Run a Health Check through the WP Recipe Maker > Dashboard page to get SEO data', 'wp-recipe-maker' ),
		);
	}

	/**
	 * Perform an SEO check on a food recipe.
	 *
	 * @since    5.2.0
	 * @param    mixed $recipe Recipe to check the SEO for.
	 */
	public static function check_food_recipe( $recipe ) {
		$notes = array();
		$issues = self::check_eligibility( $recipe, $notes );

		// Recipe ratings.
		$rating = $recipe->rating();
		if ( 0 === intval( $rating['count'] ) ) {
			if ( self::ratings_enabled() ) {
				$issues[] = array(
					'id' => 'rating',
					'level' => 'rating',
					'target' => null,
					'message' => 'There are no ratings for your recipe.',
				);
			} else {
				$issues[] = array(
					'id' => 'ratings_disabled',
					'level' => 'rating',
					'target' => 'settings_ratings',
					'message' => __( 'Star ratings are turned off, so this recipe can\'t get any ratings. Turn on comment ratings or user ratings on the settings page.', 'wp-recipe-maker' ),
				);
			}
		}

		// Recommended fields.
		if ( ! $recipe->summary() ) { $issues[] = self::field_issue( 'summary', 'Summary', 'recommended', 'general' ); }
		if ( ! $recipe->servings() ) { $issues[] = self::field_issue( 'servings', 'Servings', 'recommended', 'general' ); }

		if ( ! ( $recipe->total_time() || ( $recipe->prep_time() && $recipe->cook_time() ) ) ) { $issues[] = self::field_issue( 'times', 'Times', 'recommended', 'times' ); }

		if ( 0 === count( $recipe->ingredients_without_groups() ) ) { $issues[] = self::field_issue( 'ingredients', 'Ingredients', 'recommended', 'ingredients' ); }
		if ( 0 === count( $recipe->instructions_without_groups() ) ) { $issues[] = self::field_issue( 'instructions', 'Instructions', 'recommended', 'instructions' ); }
		if ( 0 === count( $recipe->tags( 'course' ) ) ) { $issues[] = self::field_issue( 'course', 'Course', 'recommended', 'categories' ); }
		if ( 0 === count( $recipe->tags( 'cuisine' ) ) ) { $issues[] = self::field_issue( 'cuisine', 'Cuisine', 'recommended', 'categories' ); }
		if ( 0 === count( $recipe->tags( 'keyword' ) ) ) { $issues[] = self::field_issue( 'keyword', 'Keyword', 'recommended', 'categories' ); }

		$nutrition = $recipe->nutrition();
		if ( ! isset( $nutrition['calories'] ) || ! $nutrition['calories'] ) { $issues[] = self::field_issue( 'calories', 'Calories', 'recommended', 'nutrition' ); }

		// Required fields.
		if ( ! $recipe->name() ) { $issues[] = self::field_issue( 'name', 'Name', 'required', 'general' ); }
		if ( ! $recipe->image_id() ) { $issues[] = self::field_issue( 'image', 'Image', 'required', 'media' ); }

		$issues = array_merge( $issues, self::check_image_size( $recipe ) );

		// Recipe video.
		$issues = array_merge( $issues, self::check_video( $recipe, $notes ) );

		return self::build_result( $issues, $notes );
	}

	/**
	 * Perform an SEO check on a How-to recipe.
	 *
	 * @since    5.2.0
	 * @param    mixed $recipe Recipe to check the SEO for.
	 */
	public static function check_howto_recipe( $recipe ) {
		$notes = array();
		$issues = self::check_eligibility( $recipe, $notes );

		// Recommended fields.
		if ( ! $recipe->summary() ) { $issues[] = self::field_issue( 'summary', 'Description', 'recommended', 'general' ); }
		if ( ! $recipe->cost() ) { $issues[] = self::field_issue( 'cost', 'Estimated Cost', 'recommended', 'general' ); }
		if ( ! $recipe->total_time() ) { $issues[] = self::field_issue( 'times', 'Total Time', 'recommended', 'times' ); }
		if ( 0 === count( $recipe->equipment() ) ) { $issues[] = self::field_issue( 'equipment', 'Equipment', 'recommended', 'equipment' ); }
		if ( 0 === count( $recipe->ingredients_without_groups() ) ) { $issues[] = self::field_issue( 'ingredients', 'Materials', 'recommended', 'ingredients' ); }
		if ( ! $recipe->image_id() ) { $issues[] = self::field_issue( 'image', 'Image', 'recommended', 'media' ); }

		$issues = array_merge( $issues, self::check_image_size( $recipe ) );

		// Required fields.
		if ( ! $recipe->name() ) { $issues[] = self::field_issue( 'name', 'Name', 'required', 'general' ); }
		if ( 0 === count( $recipe->instructions_without_groups() ) ) { $issues[] = self::field_issue( 'instructions', 'Instructions', 'required', 'instructions' ); }

		// Recipe video.
		$issues = array_merge( $issues, self::check_video( $recipe, $notes ) );

		return self::build_result( $issues, $notes );
	}

	/**
	 * Check if the recipe metadata actually gets output somewhere on the site.
	 *
	 * @since	10.9.0
	 * @param	mixed $recipe Recipe to check.
	 * @param	array $notes  Informational notes, passed by reference.
	 */
	public static function check_eligibility( $recipe, &$notes ) {
		$issues = array();

		// Recipes with their own public page get their metadata output on that page.
		if ( 'public' === WPRM_Settings::get( 'post_type_structure' ) ) {
			if ( 'publish' !== $recipe->post_status() ) {
				$notes[] = __( 'Metadata will be output once this recipe is published.', 'wp-recipe-maker' );
			}

			return $issues;
		}

		$parent_post_id = intval( $recipe->parent_post_id() );
		$parent_post = $parent_post_id ? get_post( $parent_post_id ) : false;

		if ( ! $parent_post || 'trash' === $parent_post->post_status || WPRM_POST_TYPE === $parent_post->post_type ) {
			$issues[] = array(
				'id' => 'not_in_post',
				'level' => 'required',
				'target' => 'add_to_post',
				'message' => __( 'This recipe isn\'t in any post yet, so Google can\'t see its recipe metadata. Add it to a post to fix this. Already in a post? Use "Find Parent Posts" on the WP Recipe Maker > Tools page.', 'wp-recipe-maker' ),
			);

			return $issues;
		}

		$parent_post_title = self::get_post_title_for_message( $parent_post );

		if ( 'publish' !== $parent_post->post_status ) {
			/* translators: %s: title of the post the recipe is in. */
			$notes[] = sprintf( __( 'Metadata will be output once "%s" is published.', 'wp-recipe-maker' ), $parent_post_title );
		}

		if ( WPRM_Settings::get( 'metadata_only_show_for_first_recipe' ) ) {
			$recipe_ids = WPRM_Recipe_Manager::get_recipe_ids_from_post( $parent_post_id );
			$first_recipe_id = $recipe_ids ? WPRM_Metadata::get_first_recipe_id_for_metadata( $recipe_ids ) : false;

			// Only flag when we can find the recipe in the post content, to prevent false positives.
			if ( $first_recipe_id && intval( $first_recipe_id ) !== intval( $recipe->id() ) && in_array( intval( $recipe->id() ), array_map( 'intval', $recipe_ids ), true ) ) {
				$issues[] = array(
					'id' => 'not_first_recipe',
					'level' => 'required',
					'target' => 'settings_metadata',
					/* translators: %s: title of the post the recipe is in. */
					'message' => sprintf( __( 'No metadata is output for this recipe because it isn\'t the first recipe in "%s" and the "Only show metadata for first recipe" setting is enabled.', 'wp-recipe-maker' ), $parent_post_title ),
				);
			}
		}

		return $issues;
	}

	/**
	 * Check if the recipe image is large enough for search and social platforms.
	 *
	 * @since	10.9.0
	 * @param	mixed $recipe Recipe to check.
	 */
	public static function check_image_size( $recipe ) {
		$issues = array();

		if ( $recipe->image_id() ) {
			$dimensions = $recipe->image_dimensions();
			$width = intval( $dimensions['width'] );
			$height = intval( $dimensions['height'] );

			// Same rule as the image column on the manage page. Only the width matters, as landscape photos are fine for
			// the square and 4:3 crops. Skip when the size is unknown.
			if ( $width && $height && $width < self::RECOMMENDED_IMAGE_WIDTH ) {
				$issues[] = array(
					'id' => 'image_size',
					'level' => 'recommended',
					'target' => 'media',
					/* translators: %1$d: image width, %2$d: recommended minimum width. */
					'message' => sprintf( __( 'Your image is %1$dpx wide. Google recommends images that are at least %2$dpx wide for large previews in Search and Discover.', 'wp-recipe-maker' ), $width, self::RECOMMENDED_IMAGE_WIDTH ),
					'data' => array(
						'width' => $width,
						'height' => $height,
						'recommended' => self::RECOMMENDED_IMAGE_WIDTH,
					),
				);
			}
		}

		return $issues;
	}

	/**
	 * Check if visitors are able to rate recipes.
	 *
	 * @since	10.9.0
	 */
	public static function ratings_enabled() {
		return WPRM_Settings::get( 'features_comment_ratings' ) || ( WPRM_Addons::is_active( 'premium' ) && WPRM_Settings::get( 'features_user_ratings' ) );
	}

	/**
	 * Get the public page that outputs the recipe metadata, to test with external tools.
	 *
	 * @since	10.9.0
	 * @param	mixed $recipe Recipe to get the page for.
	 */
	public static function get_test_url( $recipe ) {
		if ( 'food' !== $recipe->type() && 'howto' !== $recipe->type() ) {
			return false;
		}

		if ( 'public' === WPRM_Settings::get( 'post_type_structure' ) ) {
			return 'publish' === $recipe->post_status() ? get_permalink( $recipe->id() ) : false;
		}

		$parent_post_id = intval( $recipe->parent_post_id() );
		$parent_post = $parent_post_id ? get_post( $parent_post_id ) : false;

		// External tools can only fetch published posts without a password.
		if ( ! $parent_post || 'publish' !== $parent_post->post_status || '' !== $parent_post->post_password || WPRM_POST_TYPE === $parent_post->post_type ) {
			return false;
		}

		return get_permalink( $parent_post );
	}

	/**
	 * Get admin URLs to fix the SEO issues of a recipe, keyed by issue target. Used for links from outside the Manage page.
	 *
	 * @since	10.9.0
	 * @param	mixed $recipe Recipe to get the URLs for.
	 */
	public static function get_fix_urls( $recipe ) {
		$seo = $recipe->seo();
		$issues = isset( $seo['issues'] ) && is_array( $seo['issues'] ) ? $seo['issues'] : array();

		// Should match the settings targets in assets/js/admin-manage/recipes/SeoTarget.js.
		$setting_targets = array(
			'settings_metadata' => 'metadata_only_show_for_first_recipe',
			'settings_ratings' => 'features_comment_ratings',
		);

		$urls = array();
		foreach ( $issues as $issue ) {
			$target = isset( $issue['target'] ) && is_string( $issue['target'] ) ? $issue['target'] : '';

			if ( ! $target || isset( $urls[ $target ] ) ) {
				continue;
			}

			if ( isset( $setting_targets[ $target ] ) ) {
				$urls[ $target ] = admin_url( 'admin.php?page=wprm_settings#' . $setting_targets[ $target ] );
			} elseif ( 'add_to_post' === $target ) {
				$urls[ $target ] = add_query_arg( array(
					'page' => 'wprm_manage',
					'action' => 'add_to_post',
					'recipe_id' => $recipe->id(),
				), admin_url( 'admin.php' ) );
			} else {
				$urls[ $target ] = add_query_arg( array(
					'page' => 'wprm_manage',
					'action' => 'edit',
					'recipe_id' => $recipe->id(),
					'group' => sanitize_key( $target ),
				), admin_url( 'admin.php' ) );
			}
		}

		return $urls;
	}

	/**
	 * Check if the recipe video can be included in the metadata.
	 *
	 * @since	10.9.0
	 * @param	mixed $recipe Recipe to check.
	 * @param	array $notes  Informational notes, passed by reference.
	 */
	public static function check_video( $recipe, &$notes ) {
		$issues = array();

		if ( $recipe->video_id() ) {
			$has_video = (bool) get_post( $recipe->video_id() );
		} else if ( $recipe->video_embed() ) {
			$has_video = true;
		} else {
			// No video, nothing to check.
			return $issues;
		}

		// Use the stored metadata directly, as video_metadata() would schedule a refresh.
		$videos_metadata = $has_video ? get_post_meta( $recipe->id(), 'wprm_video_metadata', true ) : false;
		$metadata = is_array( $videos_metadata ) && isset( $videos_metadata['main'] ) && is_array( $videos_metadata['main'] ) ? $videos_metadata['main'] : false;

		if ( $metadata ) {
			// Google requires a name, thumbnail and upload date for videos and recommends a description.
			if ( ! isset( $metadata['name'] ) || ! $metadata['name'] ) { $issues[] = self::field_issue( 'video_name', 'Name', 'required', 'media', 'video' ); }
			if ( ! isset( $metadata['thumbnailUrl'] ) || ! $metadata['thumbnailUrl'] ) { $issues[] = self::field_issue( 'video_image', 'Image', 'required', 'media', 'video' ); }
			if ( ! isset( $metadata['uploadDate'] ) || ! $metadata['uploadDate'] ) { $issues[] = self::field_issue( 'video_upload_date', 'Upload Date', 'required', 'media', 'video' ); }
			if ( ! isset( $metadata['description'] ) || ! $metadata['description'] ) { $issues[] = self::field_issue( 'video_description', 'Description', 'recommended', 'media', 'video' ); }

			return $issues;
		}

		$state = $has_video && class_exists( 'WPRM_MetadataVideo' ) ? WPRM_MetadataVideo::get_refresh_state( $recipe->id() ) : false;

		if ( $has_video && ( ! $state || ! isset( $state['status'] ) || 'failed' !== $state['status'] ) ) {
			$notes[] = __( 'Your video details are still being fetched. They will be added to the metadata once they are ready.', 'wp-recipe-maker' );
		} else {
			$issues[] = array(
				'id' => 'video_unavailable',
				'level' => 'recommended',
				'target' => 'media',
				'message' => __( 'Your video is left out of the metadata because its details (name, thumbnail or upload date) could not be retrieved. Check the video or use "Refresh Video Metadata" on the WP Recipe Maker > Tools page.', 'wp-recipe-maker' ),
			);
		}

		return $issues;
	}

	/**
	 * Get an issue for a missing field.
	 *
	 * @since	10.9.0
	 * @param	string $id     Issue ID.
	 * @param	string $label  Field label to show in the message.
	 * @param	string $level  Either recommended or required.
	 * @param	string $target Recipe modal group to fix the issue in.
	 * @param	string $type   Either recipe or video field.
	 */
	private static function field_issue( $id, $label, $level, $target, $type = 'recipe' ) {
		return array(
			'id' => $id,
			'level' => $level,
			'target' => $target,
			'field_group' => $type . '_' . $level,
			'label' => $label,
		);
	}

	/**
	 * Combine the issues for a recipe into the stored SEO result.
	 *
	 * @since	10.9.0
	 * @param	array $issues Issues found for the recipe.
	 * @param	array $notes  Informational notes that don't affect the result type.
	 */
	private static function build_result( $issues, $notes ) {
		$severity = array(
			'rating' => 1,
			'recommended' => 2,
			'required' => 3,
		);
		$types = array( 'good', 'rating', 'warning', 'bad' );
		$field_group_labels = array(
			'recipe_recommended' => 'Recommended fields: ',
			'recipe_required' => 'Required fields: ',
			'video_recommended' => 'Recommended video fields: ',
			'video_required' => 'Required video fields: ',
		);

		$level = 0;
		$lines = array();
		$fields = array();
		$stored_issues = array();

		foreach ( $issues as $index => $issue ) {
			$level = max( $level, $severity[ $issue['level'] ] );

			if ( isset( $issue['field_group'] ) ) {
				// Group missing fields on one line, at the position of the first one.
				if ( ! isset( $fields[ $issue['field_group'] ] ) ) {
					$fields[ $issue['field_group'] ] = array();
					$lines[] = array( 'field_group' => $issue['field_group'] );
				}
				$fields[ $issue['field_group'] ][] = $issue['label'];
			} else {
				$lines[] = $issue['message'];
			}

			$stored_issue = array(
				'id' => $issue['id'],
				'level' => $issue['level'],
				'target' => $issue['target'],
				'index' => $index,
			);
			if ( isset( $issue['data'] ) ) {
				$stored_issue['data'] = $issue['data'];
			}
			$stored_issues[] = $stored_issue;
		}

		$message = array();
		foreach ( $lines as $line ) {
			if ( is_array( $line ) ) {
				$message[] = $field_group_labels[ $line['field_group'] ] . implode( ', ', $fields[ $line['field_group'] ] );
			} else {
				$message[] = $line;
			}
		}

		if ( 0 === $level ) {
			$message = array( 'Good job!' );
		}
		$message = array_merge( $message, $notes );

		// Most severe issues first, keeping the original order otherwise.
		usort( $stored_issues, function( $a, $b ) use ( $severity ) {
			$difference = $severity[ $b['level'] ] - $severity[ $a['level'] ];
			return 0 !== $difference ? $difference : $a['index'] - $b['index'];
		} );
		foreach ( $stored_issues as $key => $issue ) {
			unset( $stored_issues[ $key ]['index'] );
		}

		return array(
			'type' => $types[ $level ],
			'message' => implode( '<br/>', $message ),
			'issues' => array_values( $stored_issues ),
			'notes' => $notes,
		);
	}

	/**
	 * Get a post title that's safe to use in an SEO message.
	 *
	 * @since	10.9.0
	 * @param	WP_Post $post Post to get the title for.
	 */
	private static function get_post_title_for_message( $post ) {
		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		return esc_html( '' !== $title ? $title : '#' . $post->ID );
	}

	/**
	 * Remember which recipes use an attachment before it gets deleted.
	 *
	 * @since	10.9.0
	 * @param	int $attachment_id Attachment that is being deleted.
	 */
	public static function collect_recipes_for_attachment( $attachment_id ) {
		global $wpdb;

		$attachment_id = intval( $attachment_id );
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $attachment_id ) );
		$recipe_ids = array();
		$parent_post_ids = array();

		foreach ( $post_ids as $post_id ) {
			if ( WPRM_POST_TYPE === get_post_type( $post_id ) ) {
				$recipe_ids[] = intval( $post_id );
			} else {
				$parent_post_ids[] = intval( $post_id );
			}
		}

		// Recipes can fall back to the featured image of their parent post.
		if ( $parent_post_ids && WPRM_Settings::get( 'recipe_image_use_featured' ) ) {
			$recipe_ids = array_merge( $recipe_ids, get_posts( array(
				'post_type' => WPRM_POST_TYPE,
				'post_status' => 'any',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => 'wprm_parent_post_id',
						'value' => $parent_post_ids,
						'compare' => 'IN',
					),
				),
			) ) );
		}

		if ( $recipe_ids ) {
			self::$attachment_recipe_ids[ $attachment_id ] = $recipe_ids;
		}
	}

	/**
	 * Update the SEO data for recipes that used a deleted attachment.
	 *
	 * @since	10.9.0
	 * @param	int $post_id Post that got deleted.
	 */
	public static function update_seo_after_attachment_deleted( $post_id ) {
		$post_id = intval( $post_id );

		if ( isset( self::$attachment_recipe_ids[ $post_id ] ) ) {
			$recipe_ids = self::$attachment_recipe_ids[ $post_id ];
			unset( self::$attachment_recipe_ids[ $post_id ] );

			self::update_seo_for_recipes( $recipe_ids );
		}
	}

	/**
	 * Update the SEO data for recipes that used a deleted term.
	 *
	 * @since	10.9.0
	 * @param	int    $term_id      Term ID.
	 * @param	int    $tt_id        Term taxonomy ID.
	 * @param	string $taxonomy     Taxonomy slug.
	 * @param	mixed  $deleted_term Copy of the deleted term.
	 * @param	array  $object_ids   Objects the term was assigned to.
	 */
	public static function update_seo_after_term_deleted( $term_id, $tt_id, $taxonomy, $deleted_term = null, $object_ids = array() ) {
		if ( in_array( $taxonomy, array( 'wprm_course', 'wprm_cuisine', 'wprm_keyword' ), true ) && $object_ids ) {
			self::update_seo_for_recipes( $object_ids );
		}
	}
}

WPRM_Seo_Checker::init();
