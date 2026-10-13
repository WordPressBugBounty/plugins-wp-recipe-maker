<?php
/** Modern template identity, revisions and atomic Kitchen operations. */
class WPRM_Template_Store {
	private static $held = false;
	private static $touched = array();

	public static function describe( $template ) {
		if ( empty( $template['localTemplateId'] ) ) {
			$template['localTemplateId'] = substr( hash( 'sha256', $template['location'] . ':' . $template['slug'] ), 0, 32 );
		}
		$template['revision'] = isset( $template['revision'] ) ? intval( $template['revision'] ) : 0;
		return $template;
	}

	private static function read( $name ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $value ? false : maybe_unserialize( $value );
	}

	private static function write( $name, $value ) {
		global $wpdb;
		self::$touched[] = $name;
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
			$name, maybe_serialize( $value )
		) );
		if ( false === $result ) { throw new RuntimeException( 'Template storage failed.' ); }
	}

	public static function atomic( $callback ) {
		global $wpdb;
		if ( self::$held ) { return call_user_func( $callback ); }
		$lock = 'wprm-template-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->options ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return new WP_Error( 'wprm_template_busy', 'A template write is in progress. Try again.', array( 'status' => 409 ) );
		}
		try {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options ) );
			if ( 'InnoDB' !== $engine ) { throw new RuntimeException( 'Transactional template storage requires InnoDB.' ); }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'Template transaction unavailable.' ); }
			self::$held = true;
			// All reads under the lock use fresh database state, never option caches.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'wprm_templates', 'options' );
			WPRM_Template_Manager::reset_cache();
			$result = call_user_func( $callback );
			if ( is_wp_error( $result ) ) { $wpdb->query( 'ROLLBACK' ); }
			elseif ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Template commit failed.' ); }
			return $result;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'wprm_template_storage', 'Template storage is unavailable. No success was confirmed.', array( 'status' => 503 ) );
		} finally {
			self::$held = false;
			foreach ( self::$touched as $name ) { wp_cache_delete( $name, 'options' ); }
			self::$touched = array();
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			WPRM_Template_Manager::reset_cache();
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function save( $template, $expected = null ) {
		return self::atomic( function () use ( $template, $expected ) {
			$slug = ! empty( $template['slug'] ) ? WPRM_Template_Manager::slugify( $template['slug'] ) : false;
			$current = $slug ? self::read( 'wprm_template_' . $slug ) : false;
			if ( $current ) { $current = self::describe( $current ); }
			if ( null !== $expected && ( ! $current || $current['revision'] !== $expected || ( isset( $template['localTemplateId'] ) && $template['localTemplateId'] !== $current['localTemplateId'] ) ) ) {
				return new WP_Error( 'wprm_template_stale', 'This template changed since you opened it. Save your changes as a copy or reload.', array( 'status' => 409 ) );
			}
			if ( $slug ) { wp_cache_delete( 'wprm_template_' . $slug, 'options' ); }
			$saved = WPRM_Template_Manager::prepare_template_for_save( $template );
			$saved['localTemplateId'] = $current ? $current['localTemplateId'] : wp_generate_uuid4();
			$saved['revision'] = $current ? $current['revision'] + 1 : 1;
			// Existing provenance cannot be changed by a stale editor's payload.
			$provenance = $current && isset( $current['kitchen'] ) ? $current['kitchen'] : null;
			if ( ! $current && isset( $template['kitchen'] ) && is_array( $template['kitchen'] ) ) {
				$origin = array();
				foreach ( array( 'template_id', 'version_id' ) as $field ) {
					$value = $template['kitchen'][$field] ?? null;
					$origin[$field] = is_string( $value ) && preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : null;
				}
				$provenance = $origin;
			}
			if ( isset( $template['_kitchen_provenance'] ) ) { $provenance = $template['_kitchen_provenance']; }
			if ( $provenance ) { $saved['kitchen'] = $provenance; }
			$index = self::read( 'wprm_templates' );
			$index = is_array( $index ) ? $index : array();
			if ( ! in_array( $saved['slug'], $index, true ) ) { $index[] = $saved['slug']; }
			self::write( 'wprm_templates', $index );
			self::write( 'wprm_template_' . $saved['slug'], $saved );
			return $saved;
		} );
	}

	public static function delete( $slug ) {
		return self::atomic( function () use ( $slug ) {
			global $wpdb;
			$slug = WPRM_Template_Manager::slugify( $slug );
			$index = self::read( 'wprm_templates' );
			self::write( 'wprm_templates', array_values( array_diff( is_array( $index ) ? $index : array(), array( $slug ) ) ) );
			self::$touched[] = 'wprm_template_' . $slug;
			if ( false === $wpdb->delete( $wpdb->options, array( 'option_name' => 'wprm_template_' . $slug ) ) ) { throw new RuntimeException( 'Template delete failed.' ); }
			return $slug;
		} );
	}

	public static function import( $request ) {
		return self::atomic( function () use ( $request ) {
			$key = isset( $request['operation_id'] ) ? $request['operation_id'] : '';
			$issued = isset( $request['issued_at'] ) ? $request['issued_at'] : 0;
			if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9-]{36}$/', $key ) || ! is_int( $issued ) || $issued > time() + 300 || $issued < time() - DAY_IN_SECONDS ) {
				return new WP_Error( 'wprm_template_operation_expired', 'Invalid or expired template operation. Start a new operation.', array( 'status' => 410 ) );
			}
			$name = 'wprm_template_op_' . $key;
			$fingerprint = hash( 'sha256', wp_json_encode( $request ) );
			$previous = self::read( $name );
			if ( $previous ) {
				if ( ! hash_equals( $previous['fingerprint'], $fingerprint ) ) { return new WP_Error( 'wprm_template_operation_conflict', 'Operation key was reused with different content.', array( 'status' => 409 ) ); }
				return $previous['result'];
			}
			$package = WPRM_Template_Package::validate( isset( $request['package'] ) ? $request['package'] : null );
			if ( is_wp_error( $package ) ) { return $package; }
			$template = $package['template'];
			$expected = null;
			if ( ! empty( $request['local_id'] ) ) {
				$current = self::find( $request['local_id'] );
				if ( ! $current || 'database' !== $current['location'] ) { return new WP_Error( 'wprm_template_missing', 'The local template is no longer available for replacement.', array( 'status' => 404 ) ); }
				if ( ! isset( $request['expected_revision'] ) || ! is_int( $request['expected_revision'] ) ) { return new WP_Error( 'wprm_template_revision_required', 'Replacement requires a revision.', array( 'status' => 400 ) ); }
				$expected = $request['expected_revision'];
				$template = WPRM_Template_Package::rewrite_slug( $template, $current['slug'] );
				$template['localTemplateId'] = $current['localTemplateId'];
			} else {
				$template['oldSlug'] = $template['slug'];
				$template['slug'] = false;
			}
			$template['_kitchen_provenance'] = array( 'template_id' => $package['template_id'], 'version_id' => $package['version_id'] );
			$saved = self::save( $template, $expected );
			if ( is_wp_error( $saved ) ) { return $saved; }
			// Verify the persisted bytes inside the same transaction as the effect and replay record.
			$readback = self::read( 'wprm_template_' . $saved['slug'] );
			$export = WPRM_Template_Package::export( $readback );
			if ( ! hash_equals( $package['checksum'], $export['checksum'] ) ) { return new WP_Error( 'wprm_template_checksum', 'Installed content did not match the package.', array( 'status' => 422 ) ); }
			$result = array( 'local_id' => $saved['localTemplateId'], 'slug' => $saved['slug'], 'name' => $saved['name'], 'revision' => $saved['revision'], 'checksum' => $export['checksum'], 'algorithm' => $export['algorithm'] );
			self::write( $name, array( 'fingerprint' => $fingerprint, 'expires_at' => time() + 7 * DAY_IN_SECONDS, 'result' => $result ) );
			return $result;
		} );
	}

	public static function find( $local_id ) {
		$templates = WPRM_Template_Manager::get_templates();
		foreach ( $templates['modern'] as $template ) {
			$template = self::describe( $template );
			if ( $template['localTemplateId'] === $local_id ) { return $template; }
		}
		return false;
	}

	public static function prune() {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000", $wpdb->esc_like( 'wprm_template_op_' ) . '%' ) );
		foreach ( $names as $name ) {
			$record = self::read( $name );
			if ( is_array( $record ) && $record['expires_at'] < time() ) { delete_option( $name ); }
		}
	}
}
