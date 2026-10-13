<?php
/**
 * Versioned aggregate analytics export for WPRM Kitchen.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.9.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Builds immutable, bounded snapshots without exporting raw visitor data.
 */
class WPRM_Analytics_Export {
	const SCHEMA_VERSION = 'wprm.analytics.export.v1';
	const METRIC_VERSION = 'wprm.analytics.actions.v1';
	const STATE_OPTION = 'wprm_analytics_export_state_v1';
	const SNAPSHOT_PREFIX = 'wprm_analytics_export_v1_';
	const SNAPSHOT_TTL = 900;
	const MAX_RANGE_DAYS = 90;
	const DEFAULT_PAGE_SIZE = 500;
	const MAX_PAGE_SIZE = 1000;
	const MAX_SNAPSHOT_BUCKETS = 25000;
	const SNAPSHOT_CHUNK_SIZE = 500;
	const MAX_SNAPSHOT_STORAGE_BYTES = 524288; // 512 KiB per transient, leaving room for backend overhead.

	/** Normalize a public destination. Query strings, fragments and names never leave WordPress. */
	public static function normalize_link_target( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
			return null;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host ) || filter_var( $host, FILTER_VALIDATE_IP ) || preg_match( '/\.(?:localhost|local|test|internal)$/D', $host ) ) {
			return null;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$decoded = rawurldecode( $path );
		// Redact email addresses, UUIDs, tokens and phone-like path segments.
		if ( preg_match( '/@|[\\\\\x00-\x20\x7f]|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}|(?:^|\/)[a-f0-9]{24,}(?:\/|$)|(?:^|\/)[+0-9]{9,}(?:\/|$)|(?:^|\/)\.{1,2}(?:\/|$)/i', $decoded ) ) {
			return null;
		}
		if ( isset( $parts['port'] ) && intval( $parts['port'] ) !== ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 ) ) {
			return null;
		}
		return strtolower( $parts['scheme'] ) . '://' . $host . ( '' === $path ? '/' : $path );
	}

	/** Supplementary contract: bounded aggregate links, independent of v1 action totals. */
	public static function api_link_targets( $request ) {
		global $wpdb;
		if ( ! WPRM_Settings::get( 'analytics_enabled' ) ) {
			return new WP_Error( 'wprm_analytics_disabled', __( 'Local analytics collection is disabled.', 'wp-recipe-maker' ), array( 'status' => 403 ) );
		}
		$start = trim( (string) $request->get_param( 'start_date' ) );
		$end = trim( (string) $request->get_param( 'end_date' ) );
		$valid = self::validate_initial_request( $start, $end, 500 );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( (int) self::date( $start )->diff( self::date( $end ) )->format( '%a' ) > 6 ) {
			return new WP_Error( 'wprm_links_range', __( 'Link exports are limited to seven calendar days.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}
		$table = WPRM_Analytics_Database::get_table_name();
		$created = gmdate( 'c' );
		$state = self::observe_state();
		$retention = self::configured_retention();
		$boundary = 'unlimited' === $retention ? $start : self::date( self::today() )->modify( '-' . $retention . ' days' )->format( 'Y-m-d' );
		if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
			return new WP_Error( 'wprm_links_failed', __( 'Link snapshot unavailable.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
		}
		$max_id = intval( $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) as local_date,type as event_type,recipe_id,post_id,meta,COUNT(*) as action_count FROM `%1s` WHERE id <= %d AND created_at >= %s AND created_at <= %s AND type IN ('ingredient-link','equipment-link','instruction-link') GROUP BY local_date,type,recipe_id,post_id,BINARY meta ORDER BY local_date,type,recipe_id,post_id LIMIT 25001",
			$table, $max_id, max( $start, $boundary ) . ' 00:00:00', $end . ' 23:59:59'
		) );
		$error = $wpdb->last_error;
		if ( $error ) { $wpdb->query( 'ROLLBACK' ); } elseif ( false === $wpdb->query( 'COMMIT' ) ) { $error = 'commit_failed'; }
		if ( ! is_array( $rows ) || $error ) { return new WP_Error( 'wprm_links_failed', __( 'Link snapshot unavailable.', 'wp-recipe-maker' ), array( 'status' => 500 ) ); }
		if ( count( $rows ) > 25000 ) { return new WP_Error( 'wprm_links_dense', __( 'Retry with fewer days.', 'wp-recipe-maker' ), array( 'status' => 413 ) ); }
		$destinations = array();
		$normalized = array();
		$ids = array_values( array_unique( array_map( function( $row ) { return intval( $row->recipe_id ); }, $rows ) ) );
		_prime_post_caches( array_filter( $ids ), true, false );
		foreach ( $rows as $row ) {
			// Objects are never instantiated from stored client metadata.
			$meta = is_string( $row->meta ) ? @unserialize( $row->meta, array( 'allowed_classes' => false ) ) : array();
			$target = is_array( $meta ) ? self::normalize_link_target( isset( $meta['link_target'] ) ? $meta['link_target'] : ( isset( $meta['url'] ) ? $meta['url'] : '' ) ) : null;
			$post = get_post( intval( $row->post_id ) );
			$recipe = get_post( intval( $row->recipe_id ) );
			if ( ! $recipe || 'publish' !== $recipe->post_status || '' !== $recipe->post_password || ! $post || ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) { $target = null; }
			$target_key = null === $target ? 'unavailable' : $target;
			if ( ! isset( $destinations[ $row->local_date ] ) ) { $destinations[ $row->local_date ] = array(); }
			$destinations[ $row->local_date ][ $target_key ] = ( isset( $destinations[ $row->local_date ][ $target_key ] ) ? $destinations[ $row->local_date ][ $target_key ] : 0 ) + intval( $row->action_count );
			$normalized[] = array( 'local_date' => $row->local_date, 'event_type' => $row->event_type, 'recipe_id' => intval( $row->recipe_id ) > 0 ? intval( $row->recipe_id ) : null, 'post_id' => intval( $row->post_id ) > 0 ? intval( $row->post_id ) : null, 'target' => $target_key, 'count' => intval( $row->action_count ) );
		}
		foreach ( $destinations as $date => $counts ) {
			unset( $counts['unavailable'] );
			uksort( $counts, function( $a, $b ) use ( $counts ) { return $counts[ $b ] === $counts[ $a ] ? strcmp( $a, $b ) : $counts[ $b ] - $counts[ $a ]; } );
			$destinations[ $date ] = array_fill_keys( array_slice( array_keys( $counts ), 0, 100 ), true );
		}
		$buckets = array();
		foreach ( $normalized as $bucket ) {
			if ( 'unavailable' !== $bucket['target'] && ! isset( $destinations[ $bucket['local_date'] ][ $bucket['target'] ] ) ) { $bucket['target'] = 'other'; }
			$key = wp_json_encode( array( $bucket['local_date'], $bucket['event_type'], $bucket['recipe_id'], $bucket['post_id'], $bucket['target'] ) );
			if ( isset( $buckets[ $key ] ) ) { $buckets[ $key ]['count'] += $bucket['count']; } else { $buckets[ $key ] = $bucket; }
		}
		ksort( $buckets, SORT_STRING ); $buckets = array_values( $buckets );
		if ( count( $buckets ) > 5000 ) { return new WP_Error( 'wprm_links_dense', __( 'Retry with fewer days.', 'wp-recipe-maker' ), array( 'status' => 413 ) ); }
		$manifests = self::range_manifests( $start, $end, $state, $boundary );
		$response = rest_ensure_response( array( 'schema_version' => 'wprm.analytics.links.v1', 'metric_version' => self::METRIC_VERSION, 'site_id' => WPRM_Api_App_Kitchen::get_site_id(), 'timezone' => self::timezone(), 'request_range' => array( 'start_date' => $start, 'end_date' => $end ), 'created_at' => $created, 'snapshot_id' => hash( 'sha256', $max_id . '|' . wp_json_encode( $buckets ) . '|' . wp_json_encode( $manifests ) ), 'coverage' => $manifests['coverage'], 'replacement_ranges' => $manifests['replacement_ranges'], 'buckets' => $buckets ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** Supported v1 event types. */
	const EVENT_TYPES = array(
		'print',
		'equipment-link',
		'ingredient-link',
		'instruction-link',
		'adjust-servings',
		'unit-conversion',
		'comment',
		'user-rating',
		'jump-to-recipe',
		'jump-to-video',
		'pin-button',
		'facebook-share-button',
		'messenger-share-button',
		'twitter-share-button',
		'bluesky-share-button',
		'mastodon-share-button',
		'tumblr-share-button',
		'text-share-button',
		'whatsapp-share-button',
		'email-share-button',
		'favorite-button',
		'add-to-collections-button',
		'add-to-shopping-list-button',
		'cook-mode-button',
		'product-add-to-cart',
		'add-products-to-cart',
	);

	/** Register settings-history tracking. */
	public static function init() {
		add_filter( 'wprm_settings_update', array( __CLASS__, 'record_settings_update' ), 20, 2 );
	}

	/**
	 * Record only settings needed to explain future aggregate coverage.
	 *
	 * @param array $new_settings New WPRM settings.
	 * @param array $old_settings Previous WPRM settings.
	 * @return array Unchanged settings.
	 */
	public static function record_settings_update( $new_settings, $old_settings ) {
		$old = self::settings_state( $old_settings );
		$new = self::settings_state( $new_settings );
		$state = self::get_state( $old_settings );
		$changed = false;

		if ( $old['enabled'] !== $new['enabled'] || $old['excluded_event_types'] !== $new['excluded_event_types'] ) {
			$state['tracking_history'][] = array(
				'effective_at' => gmdate( 'c' ),
				'knowledge' => 'recorded',
				'enabled' => $new['enabled'],
				'excluded_event_types' => $new['excluded_event_types'],
			);
			$changed = true;
		}

		if ( $changed ) {
			$state['updated_at'] = gmdate( 'c' );
			self::save_state( $state );
		}

		return $new_settings;
	}

	/** Handle an authenticated v1 export request. */
	public static function api_export( $request ) {
		// Connecting to WPRM Kitchen is the sync authorization: the connection token
		// grants access to all supported features; the permission callback validates it.
		$cursor = trim( (string) $request->get_param( 'cursor' ) );
		if ( '' !== $cursor ) {
			if ( 512 < strlen( $cursor ) ) {
				return new WP_Error( 'wprm_analytics_invalid_cursor', __( 'The analytics cursor is invalid.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
			}
			return self::continue_snapshot( $cursor );
		}

		$start_date = trim( (string) $request->get_param( 'start_date' ) );
		$end_date = trim( (string) $request->get_param( 'end_date' ) );
		$page_size_param = $request->get_param( 'page_size' );
		$page_size = null === $page_size_param || '' === $page_size_param ? self::DEFAULT_PAGE_SIZE : intval( $page_size_param );

		$validation = self::validate_initial_request( $start_date, $end_date, $page_size );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$snapshot = self::create_snapshot( $start_date, $end_date, $page_size );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		return self::snapshot_page( $snapshot, 0 );
	}

	/** Validate bounded, explicit source-local calendar dates. */
	private static function validate_initial_request( $start_date, $end_date, $page_size ) {
		if ( ! self::is_iso_date( $start_date ) || ! self::is_iso_date( $end_date ) ) {
			return new WP_Error( 'wprm_analytics_invalid_date', __( 'start_date and end_date must be valid YYYY-MM-DD calendar dates.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}
		if ( $start_date > $end_date ) {
			return new WP_Error( 'wprm_analytics_invalid_range', __( 'start_date must not be after end_date.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}

		$days = (int) self::date( $start_date )->diff( self::date( $end_date ) )->format( '%a' ) + 1;
		if ( $days > self::MAX_RANGE_DAYS ) {
			return new WP_Error(
				'wprm_analytics_range_too_large',
				sprintf( __( 'Analytics exports are limited to %d calendar days per request.', 'wp-recipe-maker' ), self::MAX_RANGE_DAYS ),
				array( 'status' => 400, 'max_range_days' => self::MAX_RANGE_DAYS )
			);
		}
		if ( $end_date > self::today() ) {
			return new WP_Error( 'wprm_analytics_future_range', __( 'end_date cannot be after the current source-local date.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}
		if ( $page_size < 1 || $page_size > self::MAX_PAGE_SIZE ) {
			return new WP_Error(
				'wprm_analytics_invalid_page_size',
				sprintf( __( 'page_size must be between 1 and %d.', 'wp-recipe-maker' ), self::MAX_PAGE_SIZE ),
				array( 'status' => 400, 'max_page_size' => self::MAX_PAGE_SIZE )
			);
		}

		return true;
	}

	/** Create and persist one immutable aggregate snapshot. */
	private static function create_snapshot( $start_date, $end_date, $page_size ) {
		global $wpdb;

		$table_name = WPRM_Analytics_Database::get_table_name();
		$created_at = gmdate( 'c' );
		$expires_at = gmdate( 'c', time() + self::SNAPSHOT_TTL );
		$state = self::observe_state();
		$query_start_date = $start_date;
		$retention = self::configured_retention();
		if ( 'unlimited' !== $retention ) {
			$retention_boundary = self::date( self::today() )->modify( '-' . $retention . ' days' )->format( 'Y-m-d' );
			$query_start_date = max( $query_start_date, $retention_boundary );
		}
		$placeholders = implode( ', ', array_fill( 0, count( self::EVENT_TYPES ), '%s' ) );
		$variant_sql = self::variant_sql();
		$query_args = array_merge(
			array( $table_name, $query_start_date . ' 00:00:00', $end_date . ' 23:59:59' ),
			self::EVENT_TYPES
		);

		if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
			return new WP_Error( 'wprm_analytics_snapshot_failed', __( 'The analytics snapshot could not be created. Retry the request.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
		}
		$max_id = intval( $wpdb->get_var( "SELECT MAX(id) FROM `{$table_name}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$available_bounds = null;
		// Only unlimited retention needs the oldest stored date for its manifest.
		if ( 'unlimited' === $retention ) {
			$available_bounds = $wpdb->get_row( "SELECT DATE(created_at) AS oldest_date FROM `{$table_name}` WHERE id <= {$max_id} ORDER BY created_at ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$query_args[] = $max_id;
		$query_args[] = self::MAX_SNAPSHOT_BUCKETS + 1;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				DATE(created_at) AS local_date,
				type AS event_type,
				recipe_id,
				post_id,
				{$variant_sql} AS action_variant,
				COUNT(*) AS action_count
			FROM `%1s`
			WHERE created_at >= %s
				AND created_at <= %s
				AND type IN ({$placeholders})
				AND id <= %d
			GROUP BY local_date, event_type, recipe_id, post_id, action_variant
			ORDER BY local_date ASC, event_type ASC, recipe_id ASC, post_id ASC, action_variant ASC
			LIMIT %d",
			$query_args
		) );
		$query_error = $wpdb->last_error;
		if ( $query_error ) {
			$wpdb->query( 'ROLLBACK' );
		} elseif ( false === $wpdb->query( 'COMMIT' ) ) {
			$query_error = $wpdb->last_error ? $wpdb->last_error : 'commit_failed';
		}

		if ( ! is_array( $rows ) || $query_error ) {
			return new WP_Error( 'wprm_analytics_snapshot_failed', __( 'The analytics snapshot could not be created. Retry the request.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
		}
		if ( count( $rows ) > self::MAX_SNAPSHOT_BUCKETS ) {
			return new WP_Error(
				'wprm_analytics_snapshot_too_large',
				__( 'This range contains too many aggregate rows. Retry with a shorter date range.', 'wp-recipe-maker' ),
				array( 'status' => 413, 'max_snapshot_buckets' => self::MAX_SNAPSHOT_BUCKETS )
			);
		}

		$buckets = array_map( array( __CLASS__, 'format_bucket' ), $rows );
		$source = self::source_manifest( $state, $available_bounds, $created_at );
		$manifests = self::range_manifests( $start_date, $end_date, $state, $source['local_retention']['available_from'] );
		$source_revision = hash( 'sha256', $max_id . '|' . wp_json_encode( $state ) . '|' . $start_date . '|' . $end_date . '|' . wp_json_encode( $buckets ) );
		$snapshot_id = bin2hex( self::random_bytes( 24 ) );
		$manifest_data = array(
			'schema_version' => self::SCHEMA_VERSION,
			'metric_versions' => array( self::METRIC_VERSION ),
			'source' => $source,
			'request_range' => array( 'start_date' => $start_date, 'end_date' => $end_date ),
			'coverage' => $manifests['coverage'],
			'replacement_ranges' => $manifests['replacement_ranges'],
			'snapshot_id' => $snapshot_id,
			'source_revision' => $source_revision,
			'created_at' => $created_at,
			'expires_at' => $expires_at,
		);
		$snapshot = array_merge( $manifest_data, array(
			'manifest_sha256' => hash( 'sha256', wp_json_encode( $manifest_data ) ),
			'page_size' => $page_size,
			'buckets' => $buckets,
		) );

		return self::store_snapshot( $snapshot );
	}

	/** Store bounded chunks before publishing the manifest that makes them readable. */
	private static function store_snapshot( $snapshot ) {
		$buckets = $snapshot['buckets'];
		unset( $snapshot['buckets'] );
		$snapshot['storage_version'] = 1;
		$snapshot['chunk_size'] = self::SNAPSHOT_CHUNK_SIZE;
		$snapshot['bucket_count'] = count( $buckets );
		$key = self::SNAPSHOT_PREFIX . $snapshot['snapshot_id'];
		$expires = strtotime( $snapshot['expires_at'] );
		$written = array();
		$error = null;

		if ( strlen( serialize( $snapshot ) ) > self::MAX_SNAPSHOT_STORAGE_BYTES ) {
			return self::storage_size_error();
		}
		for ( $offset = 0; $offset < count( $buckets ); $offset += self::SNAPSHOT_CHUNK_SIZE ) {
			$chunk = array_slice( $buckets, $offset, self::SNAPSHOT_CHUNK_SIZE );
			if ( strlen( serialize( $chunk ) ) > self::MAX_SNAPSHOT_STORAGE_BYTES ) {
				$error = self::storage_size_error();
				break;
			}
			$ttl = $expires - time();
			if ( $ttl <= 0 ) {
				$error = self::snapshot_expired_error();
				break;
			}
			$chunk_key = $key . '_chunk_' . (int) ( $offset / self::SNAPSHOT_CHUNK_SIZE );
			// Include a failed key too: a backend can partially write before returning false.
			$written[] = $chunk_key;
			if ( ! set_transient( $chunk_key, $chunk, $ttl ) ) {
				$error = self::storage_failed_error();
				break;
			}
		}
		if ( null === $error ) {
			$ttl = $expires - time();
			if ( $ttl <= 0 ) {
				$error = self::snapshot_expired_error();
			} else {
				$written[] = $key;
				if ( ! set_transient( $key, $snapshot, $ttl ) ) {
					$error = self::storage_failed_error();
				}
			}
		}
		if ( null !== $error ) {
			foreach ( $written as $written_key ) {
				delete_transient( $written_key );
				// A failed database insert can leave only its timeout option behind.
				if ( ! wp_using_ext_object_cache() ) {
					delete_option( '_transient_timeout_' . $written_key );
				}
			}
			return $error;
		}

		return $snapshot;
	}

	/** Known byte-limit violations are distinct from arbitrary backend write failures. */
	private static function storage_size_error() {
		return new WP_Error( 'wprm_analytics_snapshot_storage_too_large', __( 'The analytics snapshot exceeds the storage size limit. Retry with a shorter date range.', 'wp-recipe-maker' ), array( 'status' => 413, 'max_storage_bytes' => self::MAX_SNAPSHOT_STORAGE_BYTES ) );
	}

	private static function storage_failed_error() {
		return new WP_Error( 'wprm_analytics_snapshot_failed', __( 'The analytics snapshot could not be stored. Retry the request.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
	}

	private static function snapshot_expired_error() {
		return new WP_Error( 'wprm_analytics_snapshot_expired', __( 'This analytics snapshot has expired. Start a new export.', 'wp-recipe-maker' ), array( 'status' => 410 ) );
	}

	/** Continue an existing snapshot with an opaque authenticated cursor. */
	private static function continue_snapshot( $cursor ) {
		$decoded = self::decode_cursor( $cursor );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$snapshot = get_transient( self::SNAPSHOT_PREFIX . $decoded['snapshot_id'] );
		if ( ! is_array( $snapshot ) ) {
			return self::snapshot_expired_error();
		}
		// Keep cursors issued before the storage change readable for their remaining TTL.
		$bucket_count = isset( $snapshot['buckets'] ) ? count( $snapshot['buckets'] ) : $snapshot['bucket_count'];
		if ( $decoded['offset'] < 1 || $decoded['offset'] >= $bucket_count || 0 !== $decoded['offset'] % $snapshot['page_size'] ) {
			return new WP_Error( 'wprm_analytics_invalid_cursor', __( 'The analytics cursor is invalid for this snapshot.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}

		return self::snapshot_page( $snapshot, $decoded['offset'] );
	}

	/** Format one page and repeat the immutable manifest. */
	private static function snapshot_page( $snapshot, $offset ) {
		if ( isset( $snapshot['buckets'] ) ) {
			$bucket_count = count( $snapshot['buckets'] );
			$buckets = array_slice( $snapshot['buckets'], $offset, $snapshot['page_size'] );
		} else {
			if ( 1 !== $snapshot['storage_version'] || strtotime( $snapshot['expires_at'] ) <= time() ) {
				return self::snapshot_expired_error();
			}
			$bucket_count = $snapshot['bucket_count'];
			$page_end = min( $bucket_count, $offset + $snapshot['page_size'] );
			$buckets = array();
			for ( $position = $offset; $position < $page_end; ) {
				$index = (int) floor( $position / $snapshot['chunk_size'] );
				$chunk_start = $index * $snapshot['chunk_size'];
				$chunk = get_transient( self::SNAPSHOT_PREFIX . $snapshot['snapshot_id'] . '_chunk_' . $index );
				if ( ! is_array( $chunk ) || count( $chunk ) !== min( $snapshot['chunk_size'], $bucket_count - $chunk_start ) ) {
					return self::snapshot_expired_error();
				}
				$length = min( $page_end - $position, count( $chunk ) - ( $position - $chunk_start ) );
				$buckets = array_merge( $buckets, array_slice( $chunk, $position - $chunk_start, $length ) );
				$position += $length;
			}
		}
		$next_offset = $offset + count( $buckets );
		$complete = $next_offset >= $bucket_count;
		$next_cursor = $complete ? null : self::encode_cursor( $snapshot['snapshot_id'], $next_offset );

		return rest_ensure_response( array(
			'schema_version' => $snapshot['schema_version'],
			'metric_versions' => $snapshot['metric_versions'],
			'source' => $snapshot['source'],
			'request_range' => $snapshot['request_range'],
			'coverage' => $snapshot['coverage'],
			'replacement_ranges' => $snapshot['replacement_ranges'],
			'snapshot' => array(
				'id' => $snapshot['snapshot_id'],
				'source_revision' => $snapshot['source_revision'],
				'manifest_sha256' => $snapshot['manifest_sha256'],
				'created_at' => $snapshot['created_at'],
				'expires_at' => $snapshot['expires_at'],
			),
			'page' => array(
				'number' => (int) floor( $offset / $snapshot['page_size'] ) + 1,
				'size' => $snapshot['page_size'],
				'next_cursor' => $next_cursor,
				'snapshot_complete' => $complete,
			),
			'buckets' => $buckets,
		) );
	}

	/** Build bounded variants in SQL without returning raw metadata. */
	private static function variant_sql() {
		return "CASE
			WHEN type = 'print' THEN CASE
				WHEN LOCATE('s:8:\"location\";s:6:\"recipe\"', meta) > 0 THEN 'recipe'
				WHEN LOCATE('s:8:\"location\";s:7:\"snippet\"', meta) > 0 THEN 'snippet'
				WHEN LOCATE('s:8:\"location\";s:7:\"roundup\"', meta) > 0 THEN 'roundup'
				WHEN LOCATE('s:8:\"location\";', meta) > 0 THEN 'other'
				ELSE 'unknown' END
			WHEN type = 'equipment-link' THEN CASE
				WHEN LOCATE('s:4:\"type\";s:5:\"image\"', meta) > 0 THEN 'image'
				WHEN LOCATE('s:4:\"type\";s:4:\"text\"', meta) > 0 THEN 'text'
				ELSE 'unknown' END
			WHEN type = 'adjust-servings' THEN CASE
				WHEN LOCATE('s:4:\"type\";s:5:\"input\"', meta) > 0 THEN 'input'
				WHEN LOCATE('s:4:\"type\";s:6:\"slider\"', meta) > 0 THEN 'slider'
				WHEN LOCATE('s:4:\"type\";s:6:\"button\"', meta) > 0 THEN 'button'
				WHEN LOCATE('s:4:\"type\";s:9:\"cook-mode\"', meta) > 0 THEN 'cook-mode'
				ELSE 'unknown' END
			WHEN type = 'unit-conversion' THEN CASE
				WHEN LOCATE('s:4:\"type\";s:4:\"link\"', meta) > 0 THEN 'link'
				WHEN LOCATE('s:4:\"type\";s:6:\"switch\"', meta) > 0 THEN 'switch'
				WHEN LOCATE('s:4:\"type\";s:8:\"dropdown\"', meta) > 0 THEN 'dropdown'
				WHEN LOCATE('s:4:\"type\";s:9:\"cook-mode\"', meta) > 0 THEN 'cook-mode'
				ELSE 'unknown' END
			WHEN type = 'favorite-button' THEN CASE
				WHEN LOCATE('s:6:\"action\";s:3:\"add\"', meta) > 0 THEN 'add'
				WHEN LOCATE('s:6:\"action\";s:6:\"remove\"', meta) > 0 THEN 'remove'
				ELSE 'legacy-unknown' END
			WHEN type = 'product-add-to-cart' THEN CASE
				WHEN LOCATE('s:9:\"item_type\";s:10:\"ingredient\"', meta) > 0 THEN 'ingredient'
				WHEN LOCATE('s:9:\"item_type\";s:9:\"equipment\"', meta) > 0 THEN 'equipment'
				ELSE 'unknown' END
			ELSE NULL END";
	}

	/** Convert a database row to the strict public bucket shape. */
	private static function format_bucket( $row ) {
		return array(
			'local_date' => (string) $row->local_date,
			'recipe_id' => intval( $row->recipe_id ) > 0 ? intval( $row->recipe_id ) : null,
			'post_id' => intval( $row->post_id ) > 0 ? intval( $row->post_id ) : null,
			'metric_version' => self::METRIC_VERSION,
			'count' => intval( $row->action_count ),
			'event_type' => (string) $row->event_type,
			'action_variant' => null === $row->action_variant ? null : (string) $row->action_variant,
		);
	}

	/** Build safe source, settings, and retention metadata. */
	private static function source_manifest( $state, $bounds, $observed_at ) {
		$retention = self::configured_retention();
		$available_from = null;

		if ( 'unlimited' !== $retention ) {
			$available_from = self::date( self::today() )->modify( '-' . $retention . ' days' )->format( 'Y-m-d' );
		} else {
			$introduced_timezone = self::timezone_for_instant( $state['introduced_at'], $state['timezone_history'] );
			$introduced_date = self::instant_local_date( $state['introduced_at'], $introduced_timezone );
			$available_from = $bounds && $bounds->oldest_date && $bounds->oldest_date < $introduced_date
				? (string) $bounds->oldest_date
				: $introduced_date;
		}

		return array(
			'site_id' => WPRM_Api_App_Kitchen::get_site_id(),
			'site_url' => untrailingslashit( home_url() ),
			'timezone' => self::timezone(),
			'timezone_history' => $state['timezone_history'],
			'tracking_history' => $state['tracking_history'],
			'local_retention' => array(
				'configured_days' => $retention,
				'available_from' => $available_from,
				'available_through' => self::today(),
				'observed_at' => $observed_at,
			),
			'cloud_sync' => array(
				'authorized' => true,
				'authorized_at' => self::connection_authorized_at( $state ),
			),
		);
	}

	/** When the current Kitchen connection, and with it analytics sync, was approved. */
	private static function connection_authorized_at( $state ) {
		$connection = get_option( WPRM_Api_App_Kitchen::CONNECTION_OPTION, false );
		if ( is_array( $connection ) && ! empty( $connection['connected_at'] ) ) {
			$connected_at = date_create_immutable( $connection['connected_at'], wp_timezone() );
			if ( $connected_at ) {
				return $connected_at->format( 'c' );
			}
		}

		// Tokens created outside the pairing flow have no connection record.
		return $state['cloud_sync_authorized_at'];
	}

	/** Normalized local retention configuration. */
	private static function configured_retention() {
		$retention = WPRM_Settings::get( 'analytics_days_to_keep' );
		return 'unlimited' === $retention ? 'unlimited' : max( 1, intval( $retention ) ?: 90 );
	}

	/** Build complete coverage and replacement manifests from recorded state. */
	private static function range_manifests( $start_date, $end_date, $state, $available_from ) {
		$broad = array();
		$excluded = array();
		$replacement = array();
		$timezone_change_dates = self::timezone_change_dates( $state['timezone_history'] );
		$cursor = self::date( $start_date );
		$end = self::date( $end_date );

		while ( $cursor <= $end ) {
			$date = $cursor->format( 'Y-m-d' );
			$tracking = self::tracking_state_at_date( $state['tracking_history'], $state['timezone_history'], $date );

			if ( $available_from && $date < $available_from ) {
				$status = 'unavailable';
				$reason = 'local-retention-expired';
			} elseif ( ! $tracking ) {
				$status = 'unknown';
				$reason = 'historical-state-unknown';
			} elseif ( ! $tracking['enabled'] ) {
				$status = 'unavailable';
				$reason = 'tracking-disabled';
			} elseif ( isset( $timezone_change_dates[ $date ] ) ) {
				$status = 'partial';
				$reason = 'timezone-changed';
			} elseif ( self::today() === $date ) {
				$status = 'partial';
				$reason = 'current-day';
			} else {
				$status = 'complete';
				$reason = 'recorded';
			}

			self::append_interval( $broad, $date, array(
				'event_types' => 'all',
				'status' => $status,
				'reason' => $reason,
			) );

			$excluded_types = $tracking && $tracking['enabled'] ? $tracking['excluded_event_types'] : array();
			if ( $excluded_types && ( ! $available_from || $date >= $available_from ) ) {
				self::append_interval( $excluded, $date, array(
					'event_types' => $excluded_types,
					'status' => 'unavailable',
					'reason' => 'event-excluded',
				) );
			}

			if ( in_array( $status, array( 'complete', 'partial' ), true ) ) {
				$included = array_values( array_diff( self::EVENT_TYPES, $excluded_types ) );
				if ( $included ) {
					self::append_interval( $replacement, $date, array( 'event_types' => $included ) );
				}
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return array(
			'coverage' => array_merge( $broad, $excluded ),
			'replacement_ranges' => $replacement,
		);
	}

	/** Merge adjacent dates with identical manifest fields. */
	private static function append_interval( &$intervals, $date, $fields ) {
		$last_index = count( $intervals ) - 1;
		if ( 0 <= $last_index ) {
			$last = $intervals[ $last_index ];
			$last_fields = $last;
			unset( $last_fields['start_date'], $last_fields['end_date'] );
			$next_date = self::date( $last['end_date'] )->modify( '+1 day' )->format( 'Y-m-d' );
			if ( $next_date === $date && $last_fields === $fields ) {
				$intervals[ $last_index ]['end_date'] = $date;
				return;
			}
		}

		$intervals[] = array_merge( array( 'start_date' => $date, 'end_date' => $date ), $fields );
	}

	/** Observe timezone and settings changed outside the normal settings API. */
	private static function observe_state() {
		$settings = get_option( 'wprm_settings', array() );
		$state = self::get_state( $settings );
		$current = self::settings_state( $settings );
		$changed = false;
		$last = end( $state['tracking_history'] );

		if ( ! is_array( $last ) || 'recorded' !== $last['knowledge'] || $last['enabled'] !== $current['enabled'] || $last['excluded_event_types'] !== $current['excluded_event_types'] ) {
			$state['tracking_history'][] = array(
				'effective_at' => gmdate( 'c' ),
				'knowledge' => 'recorded',
				'enabled' => $current['enabled'],
				'excluded_event_types' => $current['excluded_event_types'],
			);
			$changed = true;
		}

		$last_timezone = end( $state['timezone_history'] );
		if ( ! is_array( $last_timezone ) || $last_timezone['timezone'] !== self::timezone() ) {
			$state['timezone_history'][] = array(
				'effective_at' => gmdate( 'c' ),
				'timezone' => self::timezone(),
				'knowledge' => 'recorded',
			);
			$changed = true;
		}

		if ( empty( $state['cloud_sync_authorized_at'] ) ) {
			$state['cloud_sync_authorized_at'] = gmdate( 'c' );
			$changed = true;
		}
		if ( $changed ) {
			$state['updated_at'] = gmdate( 'c' );
			self::save_state( $state );
		}

		return $state;
	}

	/** Get or initialize honest history boundaries. */
	private static function get_state( $settings = null ) {
		$state = get_option( self::STATE_OPTION, array() );
		if ( is_array( $state ) && ! empty( $state['introduced_at'] ) ) {
			return $state;
		}

		$settings = is_array( $settings ) ? $settings : get_option( 'wprm_settings', array() );
		$current = self::settings_state( $settings );
		$now = gmdate( 'c' );

		$state = array(
			'introduced_at' => $now,
			'updated_at' => $now,
			'timezone_history' => array(
				array( 'effective_at' => null, 'timezone' => null, 'knowledge' => 'historical-unknown' ),
				array( 'effective_at' => $now, 'timezone' => self::timezone(), 'knowledge' => 'recorded' ),
			),
			'tracking_history' => array(
				array( 'effective_at' => null, 'knowledge' => 'historical-unknown', 'enabled' => null, 'excluded_event_types' => null ),
				array( 'effective_at' => $now, 'knowledge' => 'recorded', 'enabled' => $current['enabled'], 'excluded_event_types' => $current['excluded_event_types'] ),
			),
			'cloud_sync_authorized_at' => null,
		);

		// Persist the first observation even when settings are already enabled.
		// Otherwise every export invents a new history and authorization instant.
		self::save_state( $state );
		return $state;
	}

	/** Store compact state without autoloading it. */
	private static function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/** Normalize the tracked subset of settings. */
	private static function settings_state( $settings ) {
		$excluded = isset( $settings['analytics_exclude_actions'] ) ? preg_split( "/\r\n|\n|\r/", (string) $settings['analytics_exclude_actions'] ) : array();
		$excluded = array_values( array_intersect( self::EVENT_TYPES, array_filter( array_map( 'sanitize_key', $excluded ) ) ) );
		sort( $excluded );

		return array(
			'enabled' => ! empty( $settings['analytics_enabled'] ),
			'excluded_event_types' => $excluded,
		);
	}

	/** Find the latest recorded tracking state effective by source-local date. */
	private static function tracking_state_at_date( $history, $timezone_history, $date ) {
		$match = null;
		foreach ( $history as $entry ) {
			if ( 'recorded' !== $entry['knowledge'] || empty( $entry['effective_at'] ) ) {
				continue;
			}
			$timezone = self::timezone_for_instant( $entry['effective_at'], $timezone_history );
			if ( self::instant_local_date( $entry['effective_at'], $timezone ) <= $date ) {
				$match = $entry;
			}
		}

		return $match;
	}

	/** Return only actual recorded timezone-change dates, not first observation. */
	private static function timezone_change_dates( $history ) {
		$dates = array();
		$previous = null;
		foreach ( $history as $entry ) {
			if ( 'recorded' !== $entry['knowledge'] || empty( $entry['effective_at'] ) ) {
				continue;
			}
			if ( null !== $previous && $previous !== $entry['timezone'] ) {
				$dates[ self::instant_local_date( $entry['effective_at'], $entry['timezone'] ) ] = true;
			}
			$previous = $entry['timezone'];
		}

		return $dates;
	}

	/** Convert an RFC3339 instant to its current source-local calendar date. */
	private static function instant_local_date( $instant, $timezone ) {
		return ( new DateTimeImmutable( $instant ) )->setTimezone( new DateTimeZone( $timezone ) )->format( 'Y-m-d' );
	}

	/** Resolve the timezone that was in force when an instant was recorded. */
	private static function timezone_for_instant( $instant, $history ) {
		$match = self::timezone();
		$instant_timestamp = strtotime( $instant );
		foreach ( $history as $entry ) {
			if ( 'recorded' !== $entry['knowledge'] || empty( $entry['effective_at'] ) || empty( $entry['timezone'] ) ) {
				continue;
			}
			if ( strtotime( $entry['effective_at'] ) <= $instant_timestamp ) {
				$match = $entry['timezone'];
			}
		}

		return $match;
	}

	/** Cursor authentication prevents changing snapshot offsets. */
	private static function encode_cursor( $snapshot_id, $offset ) {
		$payload = $snapshot_id . ':' . intval( $offset );
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return rtrim( strtr( base64_encode( $payload . ':' . $signature ), '+/', '-_' ), '=' );
	}

	/** Decode and validate an opaque cursor. */
	private static function decode_cursor( $cursor ) {
		$padding = strlen( $cursor ) % 4;
		if ( $padding ) {
			$cursor .= str_repeat( '=', 4 - $padding );
		}
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		$parts = $raw ? explode( ':', $raw ) : array();
		if ( 3 !== count( $parts ) || ! ctype_xdigit( $parts[0] ) || ! ctype_digit( $parts[1] ) ) {
			return new WP_Error( 'wprm_analytics_invalid_cursor', __( 'The analytics cursor is invalid.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}

		$payload = $parts[0] . ':' . $parts[1];
		$expected = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $parts[2] ) ) {
			return new WP_Error( 'wprm_analytics_invalid_cursor', __( 'The analytics cursor is invalid.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
		}

		return array( 'snapshot_id' => $parts[0], 'offset' => intval( $parts[1] ) );
	}

	/** Strict ISO calendar-date validation. */
	private static function is_iso_date( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/** Source-local immutable midnight date. */
	private static function date( $date ) {
		return new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
	}

	/** Source-local today. */
	private static function today() {
		return current_datetime()->format( 'Y-m-d' );
	}

	/** WordPress timezone name or fixed offset. */
	private static function timezone() {
		$timezone = wp_timezone_string();
		return $timezone ? $timezone : '+00:00';
	}

	/** Random bytes with a fallback for older hosts. */
	private static function random_bytes( $length ) {
		try {
			return random_bytes( $length );
		} catch ( Exception $exception ) {
			return openssl_random_pseudo_bytes( $length );
		}
	}
}

WPRM_Analytics_Export::init();
