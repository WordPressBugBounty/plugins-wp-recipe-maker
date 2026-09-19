<?php
/**
 * Handle the recipe video metadata.
 *
 * @link       https://bootstrapped.ventures
 * @since      3.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Handle the recipe video metadata.
 *
 * @since      3.0.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_MetadataVideo {

	const REFRESH_STATE_META = 'wprm_video_metadata_refresh_state';
	const REFRESH_LOCK_META = 'wprm_video_metadata_refresh_lock';
	const REFRESH_GENERATION_META = 'wprm_video_metadata_generation';
	const REFRESH_RETRY_HOOK = 'wprm_video_metadata_refresh_retry';
	const DISPATCH_LOCK_OPTION = 'wprm_video_metadata_dispatch_lock';

	/**
	 * Background process used for video metadata refreshes.
	 *
	 * @since 10.9.0
	 * @access private
	 * @var WPRM_Video_Metadata_Background_Process|false $background_process Background process.
	 */
	private static $background_process = false;

	/**
	 * Recipes whose no-source cache was populated in this request.
	 *
	 * @since 10.9.0
	 * @access private
	 * @var array $locally_refreshed_recipes Recipe IDs.
	 */
	private static $locally_refreshed_recipes = array();

	/**
	 * Register actions and initialize the background process.
	 *
	 * @since 10.9.0
	 */
	public static function init() {
		self::$background_process = new WPRM_Video_Metadata_Background_Process();

		add_action( self::REFRESH_RETRY_HOOK, array( __CLASS__, 'retry_refresh' ), 10, 2 );
	}

	/**
	 * Apis for looking up video metadata.
	 *
	 * @since    3.1.0
	 * @access   private
	 * @var      array	$apis	Apis for looking up video metadata.
	 */
	private static $apis = array(
		'youtube' => 'AIzaSyCb5vbLd6f6397ygyBd-yIU0omZagxTfDY',
	);

	/**
	 * Output metadata in the HTML head.
	 *
	 * @since  3.0.0
	 * @param object $recipe        Recipe to get the video metadata for.
	 * @param bool   $update_recipe Whether provider normalization may update the recipe.
	 */
	public static function get_video_metadata_for_recipe( $recipe, $update_recipe = true ) {
		$metadata = array(
			'main' => false,
			'instructions' => array(),
		);

		// Get main video metadata.
		if ( $recipe->video_id() ) {
			$attachment = get_post( $recipe->video_id() );

			if ( $attachment ) {
				$metadata['main'] = self::get_video_metadata_for_upload( $recipe->video_id() );
			}
		} elseif ( $recipe->video_embed() ) {
			$metadata['main'] = self::get_video_metadata_for_embed( $recipe->video_embed(), $recipe, $update_recipe );
		}

		// Get instruction videos metadata.
		$instruction_groups = $recipe->instructions();
		foreach ( $instruction_groups as $group_index => $instruction_group ) {
			$metadata['instructions'][ $group_index ] = array();

			foreach ( $instruction_group['instructions'] as $index => $instruction ) {
				$video_metadata = false;

				if ( isset( $instruction['type'] ) && 'tip' === $instruction['type'] ) {
					$metadata['instructions'][ $group_index ][ $index ] = $video_metadata;
					continue;
				}

				if ( isset( $instruction['video'] ) && isset( $instruction['video']['type'] ) ) {
					if ( 'upload' === $instruction['video']['type'] && $instruction['video']['id'] ) {
						$video_metadata = self::get_video_metadata_for_upload( $instruction['video']['id'] );
					} elseif ( 'embed' === $instruction['video']['type'] && $instruction['video']['embed'] ) {
						$video_metadata = self::get_video_metadata_for_embed( $instruction['video']['embed'] );
					}
				}

				$metadata['instructions'][ $group_index ][ $index ] = $video_metadata;
			}
		}

		return $metadata;
	}

	/**
	 * Queue a video metadata refresh without making provider requests in the current request.
	 *
	 * The per-recipe lock and persisted state deduplicate frontend requests. The background
	 * process uses a non-blocking loopback request and its WP-Cron healthcheck. When WP-Cron
	 * is disabled, later frontend requests retry the loopback dispatch after the cooldown.
	 *
	 * @since 10.9.0
	 * @param object $recipe Recipe to refresh.
	 * @param bool   $force  Whether to ignore a failure backoff (for manual refreshes).
	 * @return bool Whether new work was queued or an existing queue was dispatched.
	 */
	public static function schedule_refresh( $recipe, $force = false ) {
		if ( ! $recipe || ! method_exists( $recipe, 'id' ) ) {
			return false;
		}

		$recipe_id = intval( $recipe->id() );
		if ( ! $recipe_id || ( defined( 'WPRM_POST_TYPE' ) && WPRM_POST_TYPE !== get_post_type( $recipe_id ) ) ) {
			return false;
		}
		if ( isset( self::$locally_refreshed_recipes[ $recipe_id ] ) ) {
			return false;
		}

		$lock = self::acquire_recipe_lock( $recipe_id, 30 );
		if ( ! $lock ) {
			return false;
		}

		// Recipes without usable video sources need no remote work. Cache the empty
		// shape locally so ordinary no-video recipes never enter the background queue.
		if ( ! self::has_video_sources( $recipe ) ) {
			$state = self::get_refresh_state( $recipe_id );
			if ( $state && isset( $state['signature'] ) ) {
				self::clear_retry_event( $recipe_id, $state['signature'] );
			}
			self::store_metadata( $recipe, self::get_video_metadata_for_recipe( $recipe, false ) );
			self::$locally_refreshed_recipes[ $recipe_id ] = true;
			delete_post_meta( $recipe_id, self::REFRESH_STATE_META );
			self::release_recipe_lock( $recipe_id, $lock );
			return false;
		}

		$queued = false;
		$dispatch = false;
		$now = time();
		$refresh_user_id = get_current_user_id();
		$signature = self::get_refresh_signature( $recipe );
		$state = self::get_refresh_state( $recipe_id );

		if ( $state && isset( $state['signature'] ) && hash_equals( (string) $state['signature'], $signature ) ) {
			if ( $refresh_user_id && user_can( $refresh_user_id, 'read_post', $recipe_id ) && $refresh_user_id !== ( isset( $state['user_id'] ) ? intval( $state['user_id'] ) : 0 ) ) {
				$state['user_id'] = $refresh_user_id;
				update_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
			}

			$status = isset( $state['status'] ) ? $state['status'] : '';
			$next_attempt = isset( $state['next_attempt'] ) ? intval( $state['next_attempt'] ) : 0;
			$last_dispatch = isset( $state['last_dispatch'] ) ? intval( $state['last_dispatch'] ) : 0;
			$started_at = isset( $state['started_at'] ) ? intval( $state['started_at'] ) : 0;

			if ( 'processing' === $status && $started_at && $now - $started_at < self::get_processing_stale_interval() ) {
				self::release_recipe_lock( $recipe_id, $lock );
				return false;
			}

			if ( 'failed' === $status && ! $force && $next_attempt > $now ) {
				self::release_recipe_lock( $recipe_id, $lock );
				return false;
			}

			if ( 'processing' === $status ) {
				// The worker or its loopback died without clearing state. Requeue once after
				// the worker lock lifetime so WP-Cron-disabled sites can also recover.
				$status = 'failed';
				$state['status'] = $status;
				$state['next_attempt'] = 0;
			}

			if ( 'queued' === $status ) {
				if ( $now - $last_dispatch >= self::get_dispatch_retry_interval() ) {
					$state['last_dispatch'] = $now;
					if ( self::$background_process && ! self::$background_process->is_queued() ) {
						self::$background_process->push_to_queue(
							array(
								'recipe_id' => $recipe_id,
								'signature' => $signature,
							)
						)->save();
					}
					update_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
					$dispatch = true;
				}

				self::release_recipe_lock( $recipe_id, $lock );

				return $dispatch ? self::dispatch_pending_refreshes() : false;
			}
		}

		$attempts = $state && isset( $state['signature'], $state['attempts'] ) && hash_equals( (string) $state['signature'], $signature ) ? intval( $state['attempts'] ) : 0;
		$new_state = array(
			'signature' => $signature,
			'user_id' => $refresh_user_id && user_can( $refresh_user_id, 'read_post', $recipe_id ) ? $refresh_user_id : 0,
			'status' => 'queued',
			'attempts' => $attempts,
			'queued_at' => $now,
			'last_dispatch' => $now,
			'next_attempt' => 0,
		);

		update_post_meta( $recipe_id, self::REFRESH_STATE_META, $new_state );

		if ( self::$background_process ) {
			self::$background_process->push_to_queue(
				array(
					'recipe_id' => $recipe_id,
					'signature' => $signature,
				)
			)->save();
			$queued = true;
		}

		self::release_recipe_lock( $recipe_id, $lock );

		if ( $queued ) {
			self::dispatch_pending_refreshes();
		}

		return $queued;
	}

	/**
	 * Retry a failed refresh from WP-Cron.
	 *
	 * @since 10.9.0
	 * @param int    $recipe_id Recipe ID.
	 * @param string $signature Signature of the queued video inputs.
	 */
	public static function retry_refresh( $recipe_id, $signature ) {
		$recipe = self::get_fresh_recipe( $recipe_id, $signature );
		if ( ! $recipe || ! hash_equals( (string) $signature, self::get_refresh_signature( $recipe ) ) ) {
			return;
		}

		self::schedule_refresh( $recipe );
	}

	/**
	 * Process one queued metadata refresh.
	 *
	 * Public for the background-process adapter and focused integration tests.
	 *
	 * @since 10.9.0
	 * @param int    $recipe_id Recipe ID.
	 * @param string $signature Signature captured when the work was queued.
	 * @return string completed, failed, stale, locked, or missing.
	 */
	public static function process_refresh( $recipe_id, $signature ) {
		$recipe_id = intval( $recipe_id );
		$signature = (string) $signature;
		$recipe = self::get_fresh_recipe( $recipe_id, $signature );

		if ( ! $recipe ) {
			delete_post_meta( $recipe_id, self::REFRESH_STATE_META );
			return 'missing';
		}

		if ( ! hash_equals( $signature, self::get_refresh_signature( $recipe ) ) ) {
			self::clear_matching_state( $recipe_id, $signature );
			return 'stale';
		}

		$lock = self::acquire_recipe_lock( $recipe_id, 300, 1000 );
		if ( ! $lock ) {
			return 'locked';
		}

		$state = self::get_refresh_state( $recipe_id );
		if ( ! $state || ! isset( $state['signature'] ) || ! hash_equals( (string) $state['signature'], $signature ) ) {
			self::release_recipe_lock( $recipe_id, $lock );
			return 'stale';
		}

		$state['status'] = 'processing';
		$state['started_at'] = time();
		update_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
		self::release_recipe_lock( $recipe_id, $lock );

		try {
			// Do not let provider-specific normalization mutate the recipe before the stale-work check.
			$metadata = self::get_video_metadata_for_recipe( $recipe, false );
		} catch ( Throwable $error ) {
			return self::record_refresh_failure( $recipe_id, $signature );
		}

		$current_recipe = self::get_fresh_recipe( $recipe_id, $signature );
		if ( ! $current_recipe || ! hash_equals( $signature, self::get_refresh_signature( $current_recipe ) ) ) {
			self::clear_matching_state( $recipe_id, $signature );
			return 'stale';
		}

		if ( self::metadata_has_failures( $current_recipe, $metadata ) ) {
			return self::record_refresh_failure( $recipe_id, $signature );
		}

		// Preserve the existing Mediavine duplicate-JSON-LD protection, but only after
		// confirming that the recipe still has the video inputs used by this job.
		if ( self::maybe_disable_mediavine_jsonld( $current_recipe, $metadata ) ) {
			$current_recipe = self::get_fresh_recipe( $recipe_id, $signature );
			if ( ! $current_recipe ) {
				self::clear_matching_state( $recipe_id, $signature );
				return 'stale';
			}
			$signature = self::replace_state_signature( $recipe_id, $signature, self::get_refresh_signature( $current_recipe ) );
			if ( ! $signature ) {
				return 'stale';
			}
		}

		// Serialize the final freshness check and writes with recipe-save invalidation so
		// there is no check-then-write window for a slow, now-stale provider response.
		$lock = self::acquire_recipe_lock( $recipe_id, 300, 1000 );
		if ( ! $lock ) {
			return 'locked';
		}

		$current_recipe = self::get_fresh_recipe( $recipe_id, $signature );
		if ( ! $current_recipe || ! hash_equals( $signature, self::get_refresh_signature( $current_recipe ) ) ) {
			$state = self::get_refresh_state( $recipe_id );
			if ( $state && isset( $state['signature'] ) && hash_equals( (string) $state['signature'], $signature ) ) {
				delete_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
			}
			self::release_recipe_lock( $recipe_id, $lock );
			return 'stale';
		}

		$state = self::get_refresh_state( $recipe_id );
		if ( ! $state || ! isset( $state['signature'] ) || ! hash_equals( (string) $state['signature'], $signature ) ) {
			self::release_recipe_lock( $recipe_id, $lock );
			return 'stale';
		}

		self::store_metadata( $current_recipe, $metadata );

		delete_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
		self::release_recipe_lock( $recipe_id, $lock );
		self::clear_retry_event( $recipe_id, $signature );

		return 'completed';
	}

	/**
	 * Invalidate queued work before a recipe save starts.
	 *
	 * @since 10.9.0
	 * @param int $recipe_id Recipe ID.
	 */
	public static function invalidate_refresh( $recipe_id ) {
		$recipe_id = intval( $recipe_id );
		if ( ! $recipe_id ) {
			return;
		}

		$lock = self::acquire_recipe_lock( $recipe_id, 30, 1000 );
		$state = self::get_refresh_state( $recipe_id );
		if ( $state && isset( $state['signature'] ) ) {
			self::clear_retry_event( $recipe_id, $state['signature'] );
		}

		$generation = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wprm-video-', true );
		update_post_meta( $recipe_id, self::REFRESH_GENERATION_META, $generation );
		delete_post_meta( $recipe_id, self::REFRESH_STATE_META );
		unset( self::$locally_refreshed_recipes[ $recipe_id ] );

		if ( $lock ) {
			self::release_recipe_lock( $recipe_id, $lock );
		}
	}

	/**
	 * Get the persisted refresh state.
	 *
	 * @since 10.9.0
	 * @param int $recipe_id Recipe ID.
	 * @return array|false Refresh state.
	 */
	public static function get_refresh_state( $recipe_id ) {
		$state = get_post_meta( intval( $recipe_id ), self::REFRESH_STATE_META, true );
		return is_array( $state ) ? $state : false;
	}

	/**
	 * Build a signature for everything that can change video metadata lookup results.
	 *
	 * @since 10.9.0
	 * @param object $recipe Recipe object.
	 * @return string Refresh signature.
	 */
	public static function get_refresh_signature( $recipe ) {
		$recipe_id = intval( $recipe->id() );
		$attachments = array();
		$instruction_videos = array();
		$attachment_ids = array();

		if ( $recipe->video_id() ) {
			$attachment_ids[] = intval( $recipe->video_id() );
		}

		foreach ( $recipe->instructions() as $group_index => $group ) {
			$instruction_videos[ $group_index ] = array();
			$instructions = isset( $group['instructions'] ) && is_array( $group['instructions'] ) ? $group['instructions'] : array();

			foreach ( $instructions as $index => $instruction ) {
				$video = isset( $instruction['video'] ) && is_array( $instruction['video'] ) ? $instruction['video'] : false;
				$instruction_videos[ $group_index ][ $index ] = $video;

				if ( $video && isset( $video['type'], $video['id'] ) && 'upload' === $video['type'] && $video['id'] ) {
					$attachment_ids[] = intval( $video['id'] );
				}
			}
		}

		foreach ( array_unique( $attachment_ids ) as $attachment_id ) {
			$attachment = get_post( $attachment_id );
			$attachments[ $attachment_id ] = array(
				'modified' => $attachment ? $attachment->post_modified_gmt : '',
				'metadata' => get_post_meta( $attachment_id, '_wp_attachment_metadata', true ),
				'thumbnail_id' => get_post_thumbnail_id( $attachment_id ),
			);
		}

		$data = array(
			'generation' => get_post_meta( $recipe_id, self::REFRESH_GENERATION_META, true ),
			'video_id' => intval( $recipe->video_id() ),
			'video_embed' => $recipe->video_embed(),
			'instruction_videos' => $instruction_videos,
			'attachments' => $attachments,
			'youtube_api_key' => WPRM_Settings::get( 'metadata_youtube_api_key' ),
			'youtube_agree_terms' => WPRM_Settings::get( 'metadata_youtube_agree_terms' ),
		);

		$data = apply_filters( 'wprm_video_metadata_refresh_signature_data', $data, $recipe );

		return md5( wp_json_encode( $data ) );
	}

	/**
	 * Get the default cache refresh interval.
	 *
	 * @since 10.9.0
	 * @param object $recipe Recipe object.
	 * @return int Refresh interval in seconds.
	 */
	public static function get_refresh_interval( $recipe ) {
		return max( 60, intval( apply_filters( 'wprm_video_metadata_refresh_interval', WEEK_IN_SECONDS, $recipe ) ) );
	}

	/**
	 * Check whether a recipe has any usable local or embedded video source.
	 *
	 * @since 10.9.0
	 * @param object $recipe Recipe object.
	 * @return bool Whether metadata lookup has work to do.
	 */
	private static function has_video_sources( $recipe ) {
		if ( $recipe->video_id() ) {
			if ( get_post( $recipe->video_id() ) ) {
				return true;
			}
		} elseif ( $recipe->video_embed() ) {
			return true;
		}

		foreach ( $recipe->instructions() as $group ) {
			$instructions = isset( $group['instructions'] ) && is_array( $group['instructions'] ) ? $group['instructions'] : array();
			foreach ( $instructions as $instruction ) {
				if ( isset( $instruction['type'] ) && 'tip' === $instruction['type'] ) {
					continue;
				}

				$video = isset( $instruction['video'] ) && is_array( $instruction['video'] ) ? $instruction['video'] : false;
				if ( $video && isset( $video['type'] ) ) {
					if ( 'embed' === $video['type'] && ! empty( $video['embed'] ) ) {
						return true;
					}
					if ( 'upload' === $video['type'] && ! empty( $video['id'] ) && get_post( $video['id'] ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Store refreshed metadata and preserve the existing cache invalidation behavior.
	 *
	 * @since 10.9.0
	 * @param object $recipe   Recipe object.
	 * @param array  $metadata Video metadata.
	 */
	private static function store_metadata( $recipe, $metadata ) {
		global $wp_embed;
		$parent_post = $recipe->parent_post_id();

		if ( $parent_post && isset( $wp_embed ) ) {
			$wp_embed->delete_oembed_caches( $parent_post );
		}

		update_post_meta( $recipe->id(), 'wprm_video_metadata', $metadata );
		update_post_meta( $recipe->id(), 'wprm_video_metadata_updated', time() );

		if ( class_exists( 'WPRM_Metadata' ) ) {
			WPRM_Metadata::invalidate_metadata_for_recipe( $recipe->id() );
		}
	}

	/**
	 * Determine whether any actual video source failed to return metadata.
	 *
	 * @since 10.9.0
	 * @param object $recipe   Recipe object.
	 * @param mixed  $metadata Generated metadata.
	 * @return bool Whether provider metadata was missing.
	 */
	private static function metadata_has_failures( $recipe, $metadata ) {
		if ( ! is_array( $metadata ) || ! isset( $metadata['main'], $metadata['instructions'] ) ) {
			return true;
		}

		$main_upload_exists = $recipe->video_id() && get_post( $recipe->video_id() );
		if ( ( $recipe->video_embed() || $main_upload_exists ) && ! $metadata['main'] ) {
			return true;
		}

		foreach ( $recipe->instructions() as $group_index => $group ) {
			$instructions = isset( $group['instructions'] ) && is_array( $group['instructions'] ) ? $group['instructions'] : array();
			foreach ( $instructions as $index => $instruction ) {
				if ( isset( $instruction['type'] ) && 'tip' === $instruction['type'] ) {
					continue;
				}

				$video = isset( $instruction['video'] ) && is_array( $instruction['video'] ) ? $instruction['video'] : false;
				$has_source = $video && isset( $video['type'] ) && ( ( 'upload' === $video['type'] && ! empty( $video['id'] ) && get_post( $video['id'] ) ) || ( 'embed' === $video['type'] && ! empty( $video['embed'] ) ) );
				$video_metadata = isset( $metadata['instructions'][ $group_index ][ $index ] ) ? $metadata['instructions'][ $group_index ][ $index ] : false;

				if ( $has_source && ! $video_metadata ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Record a failed provider lookup and schedule exponential recovery.
	 *
	 * @since 10.9.0
	 * @param int    $recipe_id Recipe ID.
	 * @param string $signature Refresh signature.
	 * @return string Failed status.
	 */
	private static function record_refresh_failure( $recipe_id, $signature ) {
		$lock = self::acquire_recipe_lock( $recipe_id, 30, 1000 );
		if ( ! $lock ) {
			return 'locked';
		}

		$state = self::get_refresh_state( $recipe_id );
		if ( ! $state || ! isset( $state['signature'] ) || ! hash_equals( (string) $state['signature'], $signature ) ) {
			self::release_recipe_lock( $recipe_id, $lock );
			return 'stale';
		}

		$attempts = isset( $state['attempts'] ) ? intval( $state['attempts'] ) + 1 : 1;
		$delays = apply_filters(
			'wprm_video_metadata_refresh_retry_delays',
			array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS, DAY_IN_SECONDS, WEEK_IN_SECONDS ),
			$recipe_id
		);
		$delays = is_array( $delays ) && $delays ? array_values( array_map( 'intval', $delays ) ) : array( HOUR_IN_SECONDS );
		$delay_index = min( $attempts - 1, count( $delays ) - 1 );
		$delay = max( MINUTE_IN_SECONDS, $delays[ $delay_index ] );
		$next_attempt = time() + $delay;

		$state['status'] = 'failed';
		$state['attempts'] = $attempts;
		$state['failed_at'] = time();
		$state['next_attempt'] = $next_attempt;
		update_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
		self::release_recipe_lock( $recipe_id, $lock );

		if ( ! wp_next_scheduled( self::REFRESH_RETRY_HOOK, array( $recipe_id, $signature ) ) ) {
			wp_schedule_single_event( $next_attempt, self::REFRESH_RETRY_HOOK, array( $recipe_id, $signature ) );
		}

		return 'failed';
	}

	/**
	 * Apply the existing Mediavine JSON-LD opt-out without overwriting a concurrent edit.
	 *
	 * @since 10.9.0
	 * @param object $recipe   Recipe object.
	 * @param array  $metadata Generated metadata.
	 * @return bool Whether the embed was updated.
	 */
	private static function maybe_disable_mediavine_jsonld( $recipe, $metadata ) {
		if ( empty( $metadata['main'] ) ) {
			return false;
		}

		$embed_code = $recipe->video_embed();
		if ( ! $embed_code || false !== strpos( $embed_code, 'data-disable-jsonld' ) ) {
			return false;
		}

		preg_match( '/mv-video-id-(.*?)("|\s)/im', $embed_code, $match );
		if ( ! $match ) {
			preg_match( '/.mediavine.com\/videos\/(.*?)\.js/im', $embed_code, $match );
		}

		if ( ! $match || empty( $match[1] ) ) {
			return false;
		}

		$updated_embed_code = str_ireplace( 'id="' . $match[1] . '"', 'id="' . $match[1] . '" data-disable-jsonld="true"', $embed_code );
		if ( $updated_embed_code === $embed_code ) {
			return false;
		}

		$updated = update_post_meta( $recipe->id(), 'wprm_video_embed', $updated_embed_code, $embed_code );
		if ( $updated && class_exists( 'WPRM_Recipe_Manager' ) ) {
			WPRM_Recipe_Manager::invalidate_recipe( $recipe->id() );
		}

		return (bool) $updated;
	}

	/**
	 * Replace a processing state's signature after a safe internal embed normalization.
	 *
	 * @since 10.9.0
	 * @param int    $recipe_id    Recipe ID.
	 * @param string $old_signature Previous signature.
	 * @param string $new_signature New signature.
	 * @return string|false New signature, or false when state changed concurrently.
	 */
	private static function replace_state_signature( $recipe_id, $old_signature, $new_signature ) {
		$lock = self::acquire_recipe_lock( $recipe_id, 30 );
		if ( ! $lock ) {
			return false;
		}

		$state = self::get_refresh_state( $recipe_id );
		if ( ! $state || ! isset( $state['signature'] ) || ! hash_equals( (string) $state['signature'], $old_signature ) ) {
			self::release_recipe_lock( $recipe_id, $lock );
			return false;
		}

		$state['signature'] = $new_signature;
		update_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
		self::release_recipe_lock( $recipe_id, $lock );

		return $new_signature;
	}

	/**
	 * Dispatch persisted work at most once per cooldown across concurrent requests.
	 *
	 * @since 10.9.0
	 * @return bool Whether dispatch was attempted.
	 */
	private static function dispatch_pending_refreshes() {
		if ( ! self::$background_process || ! apply_filters( 'wprm_video_metadata_background_dispatch', true ) ) {
			return false;
		}

		$now = time();
		$lock = get_site_option( self::DISPATCH_LOCK_OPTION, array() );
		if ( is_array( $lock ) && ! empty( $lock['expires'] ) && intval( $lock['expires'] ) > $now ) {
			return false;
		}

		if ( $lock ) {
			delete_site_option( self::DISPATCH_LOCK_OPTION );
		}

		$dispatch_lock = array(
			'token' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wprm-dispatch-', true ),
			'expires' => $now + self::get_dispatch_retry_interval(),
		);

		if ( ! add_site_option( self::DISPATCH_LOCK_OPTION, $dispatch_lock ) ) {
			return false;
		}

		self::$background_process->dispatch();

		return true;
	}

	/**
	 * Get loopback redispatch cooldown.
	 *
	 * @since 10.9.0
	 * @return int Cooldown in seconds.
	 */
	private static function get_dispatch_retry_interval() {
		return max( MINUTE_IN_SECONDS, intval( apply_filters( 'wprm_video_metadata_dispatch_retry_interval', 5 * MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * Get the age after which an interrupted worker may be safely requeued.
	 *
	 * @since 10.9.0
	 * @return int Stale processing age in seconds.
	 */
	private static function get_processing_stale_interval() {
		return max( 5 * MINUTE_IN_SECONDS, intval( apply_filters( 'wprm_video_metadata_processing_stale_interval', 10 * MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * Release the cross-request dispatch throttle after the worker catches up.
	 *
	 * @since 10.9.0
	 */
	public static function clear_dispatch_lock() {
		delete_site_option( self::DISPATCH_LOCK_OPTION );
	}

	/**
	 * Acquire an atomic per-recipe lock using unique post meta.
	 *
	 * @since 10.9.0
	 * @param int $recipe_id Recipe ID.
	 * @param int $ttl       Lock lifetime in seconds.
	 * @param int $wait_ms   Maximum time to wait for a live lock.
	 * @return array|false Lock value.
	 */
	private static function acquire_recipe_lock( $recipe_id, $ttl, $wait_ms = 0 ) {
		$deadline = microtime( true ) + max( 0, intval( $wait_ms ) ) / 1000;

		do {
			$now = time();
			$current = get_post_meta( $recipe_id, self::REFRESH_LOCK_META, true );

			if ( is_array( $current ) && ! empty( $current['expires'] ) && intval( $current['expires'] ) <= $now ) {
				delete_post_meta( $recipe_id, self::REFRESH_LOCK_META, $current );
			}

			$lock = array(
				'token' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wprm-lock-', true ),
				'expires' => $now + max( 1, intval( $ttl ) ),
			);

			if ( add_post_meta( $recipe_id, self::REFRESH_LOCK_META, $lock, true ) ) {
				return $lock;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}

			usleep( 10000 );
		} while ( true );

		return false;
	}

	/**
	 * Release a per-recipe lock without deleting a newer owner's lock.
	 *
	 * @since 10.9.0
	 * @param int   $recipe_id Recipe ID.
	 * @param array $lock      Exact lock value.
	 */
	private static function release_recipe_lock( $recipe_id, $lock ) {
		delete_post_meta( $recipe_id, self::REFRESH_LOCK_META, $lock );
	}

	/**
	 * Get a recipe after invalidating the request-local recipe cache.
	 *
	 * @since 10.9.0
	 * @param int    $recipe_id Recipe ID.
	 * @param string $signature Refresh signature used to authorize stored user context.
	 * @return object|false Recipe object.
	 */
	private static function get_fresh_recipe( $recipe_id, $signature ) {
		if ( ! class_exists( 'WPRM_Recipe_Manager' ) ) {
			return false;
		}

		$recipe_id = intval( $recipe_id );
		WPRM_Recipe_Manager::invalidate_recipe( $recipe_id );
		$recipe = WPRM_Recipe_Manager::get_recipe( $recipe_id );
		if ( $recipe ) {
			return $recipe;
		}

		$state = self::get_refresh_state( $recipe_id );
		$user_id = $state && isset( $state['user_id'] ) ? intval( $state['user_id'] ) : 0;
		if ( ! $user_id || ! isset( $state['signature'] ) || ! hash_equals( (string) $state['signature'], (string) $signature ) || ! user_can( $user_id, 'read_post', $recipe_id ) ) {
			return false;
		}

		$original_user_id = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			WPRM_Recipe_Manager::invalidate_recipe( $recipe_id );
			return WPRM_Recipe_Manager::get_recipe( $recipe_id );
		} finally {
			wp_set_current_user( $original_user_id );
		}
	}

	/**
	 * Delete state only when it still belongs to this job.
	 *
	 * @since 10.9.0
	 * @param int    $recipe_id Recipe ID.
	 * @param string $signature Refresh signature.
	 */
	private static function clear_matching_state( $recipe_id, $signature ) {
		$state = self::get_refresh_state( $recipe_id );
		if ( $state && isset( $state['signature'] ) && hash_equals( (string) $state['signature'], (string) $signature ) ) {
			delete_post_meta( $recipe_id, self::REFRESH_STATE_META, $state );
		}
	}

	/**
	 * Clear a matching retry event.
	 *
	 * @since 10.9.0
	 * @param int    $recipe_id Recipe ID.
	 * @param string $signature Refresh signature.
	 */
	private static function clear_retry_event( $recipe_id, $signature ) {
		$timestamp = wp_next_scheduled( self::REFRESH_RETRY_HOOK, array( intval( $recipe_id ), (string) $signature ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::REFRESH_RETRY_HOOK, array( intval( $recipe_id ), (string) $signature ) );
		}
	}

	/**
	 * Get video metadata for uploaded video.
	 *
	 * @since   5.11.0
	 * @param	int $id ID of the video to get the metadata for.
	 */
	public static function get_video_metadata_for_upload( $id ) {
		$metadata = false;
		$attachment = get_post( $id );

		if ( $attachment ) {
			$video_data = wp_get_attachment_metadata( $id );
			$video_url = wp_get_attachment_url( $id );

			$image_id = get_post_thumbnail_id( $id );
			$thumb = wp_get_attachment_image_src( $image_id, 'full' );
			$thumbnail_url = $thumb && isset( $thumb[0] ) ? $thumb[0] : '';

			$metadata = array(
				'name' => $attachment->post_title,
				'description' => $attachment->post_content,
				'thumbnailUrl' => $thumbnail_url,
				'contentUrl' => $video_url,
				'uploadDate' => date( 'c', strtotime( $attachment->post_date ) ),
			);

			if ( $video_data && isset( $video_data['length'] ) ) {
				$metadata['duration'] = 'PT' . $video_data['length'] . 'S';
			}
		}

		return $metadata;
	}

	/**
	 * Get video metadata for embedded video.
	 *
	 * @since  5.11.0
	 * @param string $embed_code    Video embed code to get the metadata for.
	 * @param mixed  $recipe        Optional recipe to get the metadata for.
	 * @param bool   $update_recipe Whether provider normalization may update the recipe.
	 */
	public static function get_video_metadata_for_embed( $embed_code, $recipe = false, $update_recipe = true ) {
		$metadata = false;
		$embed_code = trim( $embed_code );

		// Only check if there actually is some embed code.
		if ( $embed_code ) {
			$metadata = $metadata ? $metadata : self::check_for_youtube_embed( $embed_code );
			$metadata = $metadata ? $metadata : self::check_for_vimeo_embed( $embed_code );
			$metadata = $metadata ? $metadata : self::check_for_mediavine_embed( $embed_code, $recipe, $update_recipe );
			$metadata = $metadata ? $metadata : self::check_for_adthrive_embed( $embed_code );
			$metadata = $metadata ? $metadata : self::check_for_wp_youtube_lyte_embed( $embed_code );
			$metadata = $metadata ? $metadata : self::check_for_brid_tv_embed( $embed_code );
	
			$metadata = $metadata ? $metadata : self::check_for_oembed( $embed_code );
			$metadata = $metadata ? $metadata : self::check_for_meta_html( $embed_code );
		}

		return $metadata;
	}

	/**
	 * Check the embed code for a Adthrive video.
	 *
	 * @since   3.0.0
	 * @param	string $embed_code Embed code to check.
	 */
	private static function check_for_adthrive_embed( $embed_code ) {
		$metadata = false;

		$pattern = get_shortcode_regex( array( 'adthrive-in-post-video-player' ) );

		// Prevent issues with - in shortcode.
		$embed_code = str_ireplace( 'video-id', 'video_id', $embed_code );
		$embed_code = str_ireplace( 'upload-date', 'upload_date', $embed_code );

		preg_match( '/' . $pattern . '/s', $embed_code, $matches );
		
		if ( $matches && isset( $matches[3] ) ) {
			$attributes_string = str_replace( '<', '&lt;', $matches[3] ); // Otherwise shortcode_parse_atts doesn't work.
			$attributes = shortcode_parse_atts( stripslashes( $attributes_string ) );

			$video_id = isset( $attributes['video_id'] ) ? $attributes['video_id'] : false;

			if ( $video_id ) {
				$upload_date = isset( $attributes['upload_date'] ) ? $attributes['upload_date'] : '';
				$name = isset( $attributes['name'] ) ? $attributes['name'] : '';
				$description = isset( $attributes['description'] ) ? $attributes['description'] : '';

				$metadata = array(
					'name' => $name,
					'description' => $description,
					'thumbnailUrl' => 'https://content.jwplatform.com/thumbs/' . $video_id . '-720.jpg',
					'contentUrl' => 'https://content.jwplatform.com/videos/' . $video_id . '.mp4',
					'uploadDate' => $upload_date,
				);
			}
		}

		return $metadata;
	}

	/**
	 * Check the embed code for a MediaVine video.
	 *
	 * @since  3.0.0
	 * @param string $embed_code    Embed code to check.
	 * @param object $recipe        Recipe to get the video metadata for.
	 * @param bool   $update_recipe Whether provider normalization may update the recipe.
	 */
	private static function check_for_mediavine_embed( $embed_code, $recipe = false, $update_recipe = true ) {
		$metadata = false;

		// New embed code.
		preg_match( '/mv-video-id-(.*?)(\"|\s)/im', $embed_code, $match );

		// Look for old embed code if new one not found.
		if ( ! $match ) {
			preg_match( '/.mediavine.com\/videos\/(.*?)\.js/im', $embed_code, $match );			
		}

		if ( $match && isset( $match[1] ) ) {
			$mv_video_id = $match[1];
			$metadata = self::check_for_oembed( 'https://embed.mediavine.com/videos/' . $mv_video_id );

			// Make sure MV doesn't output metadata as well if we're already doing that.
			if ( $metadata && false === strpos( $embed_code, 'data-disable-jsonld' ) ) {
				$metadata_disabled_embed_code = str_ireplace( 'id="' . $mv_video_id . '"', 'id="' . $mv_video_id . '" data-disable-jsonld="true"', $embed_code );

				if ( $embed_code !== $metadata_disabled_embed_code ) {
					if ( $recipe && $update_recipe ) {
						update_post_meta( $recipe->id(), 'wprm_video_embed', $metadata_disabled_embed_code );
					}
				}
			}
		}

		return $metadata;
	}

	/**
	 * Check the embed code for a Vimeo video.
	 *
	 * @since   8.X.X
	 * @param	string $embed_code Embed code to check.
	 */
	private static function check_for_vimeo_embed( $embed_code ) {
		$metadata = false;
		$found_vimeo_id = false;

		// Check for Vimeo player URL format: player.vimeo.com/video/ID
		preg_match( '/player\.vimeo\.com\/video\/(\d+)/i', $embed_code, $matches );
		if ( $matches && isset( $matches[1] ) ) {
			$found_vimeo_id = $matches[1];
		}

		// Check for standard Vimeo URL format: vimeo.com/ID
		if ( ! $found_vimeo_id ) {
			preg_match( '/vimeo\.com\/(\d+)/i', $embed_code, $matches );
			if ( $matches && isset( $matches[1] ) ) {
				$found_vimeo_id = $matches[1];
			}
		}

		// Try oEmbed with standard Vimeo URL format.
		if ( $found_vimeo_id ) {
			$vimeo_url = 'https://vimeo.com/' . $found_vimeo_id;
			$metadata = self::check_for_oembed( $vimeo_url );

			// If oEmbed didn't return all required fields, try to extract from embed code.
			if ( $metadata && ( ! $metadata['uploadDate'] || ! $metadata['name'] || ! $metadata['thumbnailUrl'] ) ) {
				// Try to extract title from iframe title attribute.
				if ( ! $metadata['name'] ) {
					preg_match( '/title\s*=\s*"([^"]+)"/i', $embed_code, $title_match );
					if ( $title_match && isset( $title_match[1] ) ) {
						$metadata['name'] = $title_match[1];
					}
				}

				// Try Vimeo oEmbed API directly if WordPress oEmbed didn't work.
				if ( ! $metadata['uploadDate'] || ! $metadata['thumbnailUrl'] ) {
					$vimeo_oembed_url = 'https://vimeo.com/api/oembed.json?url=' . urlencode( $vimeo_url );
					$response = wp_remote_get( $vimeo_oembed_url );
					$body = ! is_wp_error( $response ) && isset( $response['body'] ) ? json_decode( $response['body'] ) : false;

					if ( $body ) {
						if ( ! $metadata['name'] && isset( $body->title ) ) {
							$metadata['name'] = $body->title;
						}
						if ( ! $metadata['thumbnailUrl'] && isset( $body->thumbnail_url ) ) {
							$metadata['thumbnailUrl'] = $body->thumbnail_url;
						}
						if ( ! $metadata['uploadDate'] && isset( $body->upload_date ) ) {
							$metadata['uploadDate'] = date( 'c', strtotime( $body->upload_date ) );
						}
					}
				}

				// If still missing required fields, don't return incomplete metadata.
				// Google requires uploadDate, name, and thumbnailUrl for VideoObject.
				if ( ! $metadata['uploadDate'] || ! $metadata['name'] || ! $metadata['thumbnailUrl'] ) {
					$metadata = false;
				}
			}
		}

		return $metadata;
	}

	/**
	 * Check the embed code for a YouTube video.
	 *
	 * @since   8.1.0
	 * @param	string $embed_code Embed code to check.
	 */
	private static function check_for_youtube_embed( $embed_code ) {
		$metadata = false;

		// oEmbed detects https://www.youtube.com/watch?v=123456789 videos by default, both not YouTube shorts or https://youtu.be URLs.
		$found_youtube_id = false;

		// Check for YT Shorts URL.
		preg_match( '/youtube\.com\/shorts\/(.*?)(?:\?|$)/i', $embed_code, $matches );
		if ( $matches && isset( $matches[1] ) ) {
			$found_youtube_id = $matches[1];
		}

		// Check for shortened URL.
		preg_match( '/youtu\.be\/(.*?)(?:\?|$)/i', $embed_code, $matches );
		if ( $matches && isset( $matches[1] ) ) {
			$found_youtube_id = $matches[1];
		}

		// Try regular YT URL to get the metadata.
		if ( $found_youtube_id ) {
			$metadata = self::check_for_oembed( 'https://www.youtube.com/watch?v=' . $found_youtube_id );
		}

		return $metadata;
	}

	/**
	 * Check the embed code for a WP YouTube Lyte video.
	 *
	 * @since   3.1.0
	 * @param	string $embed_code Embed code to check.
	 */
	private static function check_for_wp_youtube_lyte_embed( $embed_code ) {
		$metadata = false;

		$pattern = get_shortcode_regex( array( 'lyte' ) );
		preg_match( '/' . $pattern . '/s', $embed_code, $matches );
		
		if ( $matches && isset( $matches[3] ) ) {

			$attributes = shortcode_parse_atts( stripslashes( $matches[3] ) );
			$video_id = isset( $attributes['id'] ) ? $attributes['id'] : false;

			if ( $video_id ) {
				$metadata = self::check_for_oembed( 'https://www.youtube.com/watch?v=' . $video_id );
			}
		}

		return $metadata;
	}

	/**
	 * Check the embed code for a Brid TV video.
	 *
	 * @since   5.7.0
	 * @param	string $embed_code Embed code to check.
	 */
	private static function check_for_brid_tv_embed( $embed_code ) {
		$metadata = false;

		$pattern = get_shortcode_regex( array( 'brid' ) );
		preg_match( '/' . $pattern . '/s', $embed_code, $matches );
		
		if ( $matches && isset( $matches[3] ) ) {
			$attributes = shortcode_parse_atts( stripslashes( $matches[3] ) );

			$video_id = isset( $attributes['video'] ) ? $attributes['video'] : false;

			if ( $video_id ) {
				$name = isset( $attributes['title'] ) ? $attributes['title'] : '';
				$description = isset( $attributes['description'] ) ? $attributes['description'] : '';
				$duration = isset( $attributes['duration'] ) ? 'PT' . intval( $attributes['duration'] ) . 'S' : '';
				$thumbnail_url = isset( $attributes['thumbnailurl'] ) ? $attributes['thumbnailurl'] : '';
				$upload_date = isset( $attributes['uploaddate'] ) ? date( 'c', strtotime( $attributes['uploaddate'] ) ) : '';

				$metadata = array(
					'name' => $name,
					'description' => $description,
					'thumbnailUrl' => $thumbnail_url,
					'uploadDate' => $upload_date,
					'duration' => $duration,
				);
			}
		}

		return $metadata;
	}

	/**
	 * Check the embed code for meta fields.
	 *
	 * @since   3.3.0
	 * @param	string $embed_code Embed code to check.
	 */
	private static function check_for_meta_html( $embed_code ) {
		$metadata = false;

		$dom = new DOMDocument;

		// Prevent errors from showing up.
		$internalErrors = libxml_use_internal_errors( true );
		$loaded_html = $dom->loadHTML( $embed_code );
		libxml_use_internal_errors( $internalErrors );

		if ( $loaded_html ) {
			$meta_tags = $dom->getElementsByTagName( 'meta' );

			if ( 0 < $meta_tags->length ) {
				$metadata = array();

				foreach ( $meta_tags as $meta_tag ) {
					if ( in_array( $meta_tag->getAttribute( 'itemprop' ),
							array(
								'uploadDate',
								'name',
								'description',
								'duration',
								'expires',
								'interactionCount',
								'thumbnailUrl',
								'contentUrl',
								'embedUrl',
							)
						) ) {
						$metadata[ $meta_tag->getAttribute( 'itemprop' ) ] = $meta_tag->getAttribute( 'content' );
					}
				}

				if ( ! $metadata ) {
					$metadata = false;
				}
			}
		}

		return $metadata;
	}

	/**
	 * Check the embed code for an oEmbed video.
	 *
	 * @since   3.0.0
	 * @param	string $embed_code Embed code to check.
	 */
	private static function check_for_oembed( $embed_code ) {
		$metadata = false;
		$url = false;

		// Check if it's a regular URL.
		$potential_url = filter_var( $embed_code, FILTER_SANITIZE_URL );

		if ( filter_var( $potential_url, FILTER_VALIDATE_URL ) ) {
			$url = $potential_url;
		}

		// No regular URL? Check embed code.
		if ( ! $url ) {
			$url = self::get_url_from_embed_code( $embed_code );
		}

		// If we've found a URL, try getting the metadata through oEmbed.
		if ( $url ) {
			// Get the WP oEmbed class.
			global $wp_oembed;
			if ( ! $wp_oembed ) {
				if ( file_exists( ABSPATH . WPINC . '/class-wp-oembed.php' ) ) {
					require_once( ABSPATH . WPINC . '/class-wp-oembed.php' );
				} else {
					// Backwards compatibility.
					require_once( ABSPATH . WPINC . '/class-oembed.php' );
				}
				$wp_oembed = new WP_oEmbed();
			}

			// Check if we can find a provider for this URL.
			$provider = $wp_oembed->get_provider( $url );

			if ( $provider ) {
				$oembed_data = $wp_oembed->fetch( $provider, $url );

				if ( $oembed_data ) {
					$name = isset( $oembed_data->title ) ? $oembed_data->title : '';
					$description = isset( $oembed_data->description ) ? $oembed_data->description : '';
					$duration = isset( $oembed_data->duration ) ? 'PT' . intval( $oembed_data->duration ) . 'S' : '';
					$thumbnail_url = isset( $oembed_data->thumbnail_url ) ? $oembed_data->thumbnail_url : '';
					$upload_date = isset( $oembed_data->upload_date ) ? date( 'c', strtotime( $oembed_data->upload_date ) ) : '';

					if ( ! $upload_date && isset( $oembed_data->uploadDate ) ) {
						$upload_date = date( 'c', strtotime( $oembed_data->uploadDate ) );
					}

					// Default to oEmbed URL.
					$content_url = isset( $oembed_data->content_url ) ? $oembed_data->content_url : '';

					if ( ! $content_url && isset( $oembed_data->contentUrl ) ) {
						$content_url = $oembed_data->contentUrl;
					}
					$content_url = $content_url ? $content_url : $url;

					// EmbedUrl.
					$embed_url = isset( $oembed_data->embed_url ) ? $oembed_data->embed_url : '';
					if ( ! $embed_url && isset( $oembed_data->embedUrl ) ) {
						$embed_url = $oembed_data->embedUrl;
					}
					if ( ! $embed_url && isset( $oembed_data->html ) ) {
						preg_match( '/src\s*=\s*"([^"]+)"/im', $oembed_data->html, $match );
						if ( $match && isset( $match[1] ) ) {
							$embed_url = $match[1];
						}
					}

					$metadata = array(
						'name' => $name,
						'description' => $description,
						'thumbnailUrl' => $thumbnail_url,
						'embedUrl' => $embed_url,
						'contentUrl' => $content_url,
						'uploadDate' => $upload_date,
						'duration' => $duration,
					);
				}

				// Extend Youtube metadata via API.
				if ( is_array( $metadata ) && false !== stripos( $provider, 'youtube' ) ) {
					$metadata = self::get_youtube_metadata( $url ) + $metadata;

					// Don't set YouTube metadata if uploadDate is missing.
					if ( ! $metadata['uploadDate'] ) {
						$metadata = false;
					}
				}
			}
		}

		return $metadata;
	}

	/**
	 * Check the embed code for a URL.
	 *
	 * @since   3.0.0
	 * @param	string $embed_code Embed code to get the URL from.
	 */
	private static function get_url_from_embed_code( $embed_code ) {
		// Check for YouTube embed code.
		preg_match("/youtube(-nocookie)?.com\/embed\/(.*?)[\"\?]/im", $embed_code, $match );
		if ( $match && isset( $match[2] ) ) {
			return 'https://www.youtube.com/watch?v=' . $match[2];
		}

		// Check for Vimeo player URL and convert to standard format.
		preg_match( '/player\.vimeo\.com\/video\/(\d+)/i', $embed_code, $match );
		if ( $match && isset( $match[1] ) ) {
			return 'https://vimeo.com/' . $match[1];
		}

		// Check for standard Vimeo URL.
		preg_match( '/vimeo\.com\/(\d+)/i', $embed_code, $match );
		if ( $match && isset( $match[1] ) ) {
			return 'https://vimeo.com/' . $match[1];
		}

		// Check for src="" in the embed code.
		preg_match( '/src\s*=\s*"([^"]+)"/im', $embed_code, $match );
		if ( $match && isset( $match[1] ) ) {
			$url = $match[1];
			
			// Convert Vimeo player URLs to standard format.
			if ( preg_match( '/player\.vimeo\.com\/video\/(\d+)/i', $url, $vimeo_match ) ) {
				return 'https://vimeo.com/' . $vimeo_match[1];
			}
			
			return $url;
		}		

		return false;
	}

	/**
	 * Get the Youtube API key.
	 *
	 * @since   6.0.0
	 */
	private static function get_youtube_api_key() {
		$personal_key = trim( WPRM_Settings::get( 'metadata_youtube_api_key' ) );
		return $personal_key ? $personal_key : self::$apis['youtube'];
	}

	/**
	 * Get Youtube metadata video the API.
	 *
	 * @since   3.1.0
	 * @param	string $url Youtube video URL.
	 */
	private static function get_youtube_metadata( $url ) {
		$metadata = array();
		$video_id = false;

		// Get video ID.
		preg_match( "/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user)\/))([^\?&\"'>]+)/", $url, $video_parts );
		if( isset( $video_parts[1] ) ) {
			$video_id = $video_parts[1];
		}

		if ( $video_id && WPRM_Settings::get( 'metadata_youtube_agree_terms' ) ) {
			$api_key = self::get_youtube_api_key();
			$api_url = 'https://youtube.googleapis.com/youtube/v3/videos?part=snippet&part=contentDetails&id=' . urlencode( $video_id ) . '&key=' . urlencode( $api_key );
			$request_args = apply_filters( 'wprm_youtube_metadata_request_args', array(
				'headers' => array(
					'Referer' => home_url( '/' ),
				),
			), $api_url, $video_id );

			$response = wp_remote_get( $api_url, $request_args );
			$body = ! is_wp_error( $response ) && isset( $response['body'] ) ? json_decode( $response['body'] ) : false;

			if ( $body ) {
				$item = isset( $body->items[0] ) ? $body->items[0] : false;

				if ( $item ) {
					$snippet = $item->snippet;
					$contentDetails = $item->contentDetails;
					$metadata = array();

					// Don't set empty values.
					if ( isset ( $snippet->title ) && $snippet->title ) { $metadata['name'] = $snippet->title; }
					if ( isset ( $snippet->description ) && $snippet->description ) { $metadata['description'] = $snippet->description; }
					if ( isset ( $snippet->publishedAt ) && $snippet->publishedAt ) { $metadata['uploadDate'] = date( 'c', strtotime( $snippet->publishedAt ) ); }
					if ( isset ( $contentDetails->duration ) && $contentDetails->duration ) { $metadata['duration'] = $contentDetails->duration; }
				}
			}
		}

		return $metadata;
	}
}

require_once( WPRM_DIR . 'vendor/wp-background-processing/classes/wp-async-request.php' );
require_once( WPRM_DIR . 'vendor/wp-background-processing/classes/wp-background-process.php' );

/**
 * Background-process adapter for video metadata refreshes.
 *
 * @since 10.9.0
 */
class WPRM_Video_Metadata_Background_Process extends WPRM_WP_Background_Process {

	protected $prefix = 'wprm';
	protected $action = 'video_metadata_refresh';
	protected $cron_interval = 5;
	protected $queue_lock_time = 300;

	/**
	 * Refresh a single recipe and always remove this queue item.
	 * Failed providers are retried through a delayed, deduplicated event instead.
	 *
	 * @param array $item Queue item.
	 * @return array|false Item is retained only while a short-lived recipe lock is busy.
	 */
	protected function task( $item ) {
		if ( is_array( $item ) && isset( $item['recipe_id'], $item['signature'] ) ) {
			$result = WPRM_MetadataVideo::process_refresh( intval( $item['recipe_id'] ), (string) $item['signature'] );
			if ( 'locked' === $result ) {
				return $item;
			}
		}

		return false;
	}

	/**
	 * Clear dispatch throttling and close the enqueue-at-completion race.
	 *
	 * @since 10.9.0
	 */
	protected function complete() {
		parent::complete();
		WPRM_MetadataVideo::clear_dispatch_lock();

		// Work can be queued after handle() observes an empty queue but before
		// complete() runs. Start it instead of waiting for another frontend visit.
		if ( ! $this->is_queue_empty() ) {
			$this->dispatch();
		}
	}
}

WPRM_MetadataVideo::init();
