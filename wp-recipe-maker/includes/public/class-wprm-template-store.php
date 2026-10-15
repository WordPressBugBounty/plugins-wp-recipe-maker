<?php
/** Verified template writes, revisions and recoverable Kitchen operations. */
class WPRM_Template_Store {
	private static $held = false;
	private static $before = array();
	private static $expected = array();
	private static $verified = array();
	private static $pending_imports = array();
	private static $stage = '';
	private static $engine = null;

	/**
	 * Add a stable identity and revision to a legacy template.
	 *
	 * @param array $template Stored or file template.
	 * @return array Template with identity and revision.
	 */
	public static function describe( $template ) {
		if ( empty( $template['localTemplateId'] ) ) {
			$template['localTemplateId'] = substr( hash( 'sha256', $template['location'] . ':' . $template['slug'] ), 0, 32 );
		}
		$template['revision'] = isset( $template['revision'] ) ? intval( $template['revision'] ) : 0;
		return $template;
	}

	/**
	 * Read raw storage without mistaking database errors for missing rows.
	 *
	 * @param string $name Option name.
	 * @return array|null Serialized value and autoload flag, or null when absent.
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function row( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Template storage read failed.' ); }
		return $row;
	}

	/**
	 * Read data required by a write, rejecting malformed values.
	 *
	 * @param string $name Option name.
	 * @return array|false Stored array, or false when absent.
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function read( $name ) {
		$row = self::row( $name );
		if ( null === $row ) { return false; }
		$value = maybe_unserialize( $row['option_value'] );
		if ( ! is_array( $value ) ) { throw new RuntimeException( 'wprm_templates' === $name ? 'The stored template index is invalid. Contact support to repair it without losing templates.' : 'Invalid stored template data.' ); }
		return $value;
	}

	/**
	 * Load catalogue arrays, skipping malformed values; writers bypass caches.
	 *
	 * @param string $name Option name.
	 * @param mixed $default Fallback for absent or malformed data.
	 * @return mixed Stored array or fallback.
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	public static function get_option( $name, $default = false ) {
		if ( self::$held ) {
			$row = self::row( $name );
			$value = null === $row ? $default : maybe_unserialize( $row['option_value'] );
		} else {
			$value = get_option( $name, $default );
		}
		return is_array( $value ) ? $value : $default;
	}

	/**
	 * Reserve even malformed or unlisted rows when choosing a new slug.
	 *
	 * @param string $name Option name.
	 * @return bool Whether a raw row exists.
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	public static function option_exists( $name ) {
		return null !== self::row( $name );
	}

	/**
	 * Capture the original raw row once for recovery.
	 *
	 * @param string $name Option name.
	 * @return void
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function remember( $name ) {
		if ( ! array_key_exists( $name, self::$before ) ) { self::$before[$name] = self::row( $name ); }
	}

	/**
	 * Write or delete a raw row and invalidate its verification checkpoint.
	 *
	 * @param string $name Option name.
	 * @param array|null $row Serialized value and autoload flag, or null to delete.
	 * @return void
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function put_row( $name, $row ) {
		global $wpdb;
		unset( self::$verified[$name] );
		if ( null === $row ) {
			$result = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} else {
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
				$name, $row['option_value'], $row['autoload']
			) );
		}
		if ( false === $result ) { throw new RuntimeException( 'Template storage write failed.' ); }
	}

	/**
	 * Record a recoverable array write and its expected stored bytes.
	 *
	 * @param string $name Option name.
	 * @param array $value Value to serialize and persist.
	 * @return void
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function write( $name, $value ) {
		self::$stage = 'write';
		self::remember( $name );
		self::$expected[$name] = array( 'option_value' => maybe_serialize( $value ), 'autoload' => self::$before[$name]['autoload'] ?? 'no' );
		self::put_row( $name, self::$expected[$name] );
	}

	/**
	 * Inspect the options table engine, tolerating restricted metadata.
	 *
	 * @return string|null Engine name, or null if metadata is unavailable.
	 */
	private static function storage_engine() {
		global $wpdb;
		$parts = explode( '.', str_replace( '`', '', $wpdb->options ) );
		$table = array_pop( $parts );
		$schema = $parts ? array_pop( $parts ) : null;
		$previous = $wpdb->suppress_errors( true );
		try {
			$sql = 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . ( $schema ? '%s' : 'DATABASE()' ) . ' AND TABLE_NAME = %s';
			$args = $schema ? array( $schema, $table ) : array( $table );
			$engine = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
			if ( ! is_string( $engine ) || '' === $engine ) {
				$from = $schema ? ' FROM `' . str_replace( '`', '``', $schema ) . '`' : '';
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS' . $from . ' WHERE Name = %s', $table ), ARRAY_A );
				$engine = $status['Engine'] ?? null;
			}
			return is_string( $engine ) && preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $engine ) ? $engine : null;
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	/**
	 * Check that this connection still owns the template write lock.
	 *
	 * @param string $lock Database lock name.
	 * @return bool Whether the lock is still owned.
	 */
	private static function owns_lock( $lock ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock ) );
	}

	/**
	 * Compare stored bytes and autoload flags against expected rows.
	 *
	 * @param array $rows Option names mapped to raw rows or null.
	 * @param bool $skip_verified Reuse checkpoints under the same lock before commit only.
	 * @return void
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function verify( $rows, $skip_verified = false ) {
		self::$stage = 'verify';
		foreach ( $rows as $name => $row ) {
			if ( $skip_verified && array_key_exists( $name, self::$verified ) && self::$verified[$name] === $row ) { continue; }
			if ( self::row( $name ) !== $row ) { throw new RuntimeException( 'Template storage verification failed.' ); }
			self::$verified[$name] = $row;
		}
	}

	/**
	 * Roll back or compensate failures; retain receipts until effects are restored.
	 *
	 * @param bool $transaction Whether START TRANSACTION succeeded.
	 * @param string $lock Database lock name.
	 * @return bool Whether every original row was confirmed restored.
	 */
	private static function recover( $transaction, $lock ) {
		global $wpdb;
		try {
			if ( ! self::owns_lock( $lock ) ) { return false; }
			if ( $transaction ) {
				if ( false === $wpdb->query( 'ROLLBACK' ) ) { return false; }
				try {
					self::verify( self::$before );
					return true;
				} catch ( Throwable $error ) {
					// START TRANSACTION also succeeds for nontransactional tables. Unknown
					// engines need compensation if ROLLBACK did not restore the rows.
					if ( null !== self::$engine ) { return false; }
				}
			}
			// Undo can itself be interrupted. Mark completed effects pending before restoring them.
			foreach ( self::$pending_imports as $name => $record ) {
				$row = array( 'option_value' => maybe_serialize( $record ), 'autoload' => 'no' );
				self::put_row( $name, $row );
				self::verify( array( $name => $row ) );
			}
			$receipts = array();
			foreach ( array_reverse( self::$before, true ) as $name => $row ) {
				if ( 0 === strpos( $name, 'wprm_template_op_' ) ) { $receipts[$name] = $row; continue; }
				self::put_row( $name, $row );
				self::verify( array( $name => $row ) );
			}
			// Only forget the recovery receipt after every template change was restored.
			foreach ( $receipts as $name => $row ) { self::put_row( $name, $row ); }
			self::verify( $receipts );
			return true;
		} catch ( Throwable $error ) { return false; }
	}

	/**
	 * Serialize template writes and verify their result or recovery.
	 *
	 * @param callable $callback Write operation executed while holding the lock.
	 * @return mixed|WP_Error Callback result, or an error with recovery status.
	 */
	public static function atomic( $callback ) {
		global $wpdb;
		if ( self::$held ) { return call_user_func( $callback ); }
		$lock = 'wprm-template-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->options ), 0, 40 );
		$transaction = false;
		$committed = false;
		$locked = false;
		self::$before = self::$expected = self::$verified = self::$pending_imports = array();
		self::$stage = 'lock';
		self::$engine = null;
		try {
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
			if ( '0' === (string) $acquired ) { return new WP_Error( 'wprm_template_busy', 'A template write is in progress. Try again.', array( 'status' => 409 ) ); }
			if ( '1' !== (string) $acquired ) { throw new RuntimeException( 'Template write locking is unavailable.' ); }
			$locked = true;
			self::$stage = 'metadata';
			self::$engine = self::storage_engine();
			if ( null === self::$engine || 0 === strcasecmp( 'InnoDB', self::$engine ) ) {
				self::$stage = 'begin';
				$transaction = false !== $wpdb->query( 'START TRANSACTION' );
				if ( ! $transaction && false === $wpdb->query( 'ROLLBACK' ) ) { throw new RuntimeException( 'Template transaction state is unknown.' ); }
			}
			self::$held = true;
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			WPRM_Template_Manager::reset_cache();
			self::$stage = 'prepare';
			$result = call_user_func( $callback );
			if ( is_wp_error( $result ) ) {
				if ( ! self::recover( $transaction, $lock ) ) { throw new RuntimeException( 'Template recovery could not be verified.' ); }
				return $result;
			}
			if ( ! self::owns_lock( $lock ) ) { throw new RuntimeException( 'Template write lock was lost.' ); }
			self::verify( self::$expected, true );
			if ( $transaction ) {
				self::$stage = 'commit';
				if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Template commit failed.' ); }
				$committed = true;
				// A successful COMMIT return alone cannot prove that the intended rows survived.
				if ( ! self::owns_lock( $lock ) ) { throw new RuntimeException( 'Template write lock was lost.' ); }
				self::verify( self::$expected );
			}
			return $result;
		} catch ( Throwable $error ) {
			$stage = self::$stage;
			// Capture the useful error before rollback or verification replaces wpdb's last error.
			$db_error = preg_replace( '/([\"\']).*?\\1/s', '[redacted]', (string) $wpdb->last_error );
			$restored = $locked && ! $committed && self::recover( $transaction, $lock );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { error_log( 'WPRM template storage: ' . $stage . '; ' . get_class( $error ) . '; ' . $error->getMessage() . '; database=' . $db_error . '; recovery=' . ( $restored ? 'confirmed' : 'unconfirmed' ) ); }
			$recovery = empty( self::$before ) ? 'not_started' : ( $restored ? 'restored' : 'unconfirmed' );
			$message = 'not_started' === $recovery ? 'Template storage is unavailable. No template changes were written. Please try again.' : ( $restored ? 'Template storage failed. The previous stored state was restored. Your changes have not been saved.' : 'Template storage could not be verified. Check the site templates before retrying; your changes may not have been saved.' );
			if ( false !== strpos( $error->getMessage(), 'stored template index is invalid' ) ) { $message = $error->getMessage() . ' No template changes were saved.'; }
			return new WP_Error( 'wprm_template_storage', $message, array( 'status' => 503, 'stage' => $stage, 'recovery' => $recovery ) );
		} finally {
			self::$held = false;
			foreach ( self::$before as $name => $row ) { wp_cache_delete( $name, 'options' ); }
			self::$before = self::$expected = self::$verified = self::$pending_imports = array();
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			WPRM_Template_Manager::reset_cache();
			if ( $locked ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		}
	}

	/**
	 * Normalize a template and check its identity and revision before writing.
	 *
	 * @param array $template Template to save.
	 * @param int|null $expected Required current revision, or null to omit the check.
	 * @param array|false|null $before Receives the original target data, before legacy identity normalization.
	 * @return array|WP_Error Prepared template, or a revision conflict.
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function prepare( $template, $expected, &$before = null ) {
		$slug = ! empty( $template['slug'] ) ? WPRM_Template_Manager::slugify( $template['slug'] ) : false;
		$current = $slug ? self::read( 'wprm_template_' . $slug ) : false;
		$before = $current;
		if ( $current ) { $current = self::describe( $current ); }
		if ( null !== $expected && ( ! $current || $current['revision'] !== $expected || ( isset( $template['localTemplateId'] ) && $template['localTemplateId'] !== $current['localTemplateId'] ) ) ) {
			return new WP_Error( 'wprm_template_stale', 'This template changed since you opened it. Save your changes as a copy or reload.', array( 'status' => 409 ) );
		}
		if ( $slug ) { wp_cache_delete( 'wprm_template_' . $slug, 'options' ); }
		$saved = WPRM_Template_Manager::prepare_template_for_save( $template );
		// A newly allocated slug was checked for raw row existence by the manager.
		if ( $saved['slug'] !== $slug ) { $before = false; }
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
		return $saved;
	}

	/**
	 * Store template contents before adding a new slug to the shared index.
	 *
	 * @param array $saved Prepared template with a stable slug and identity.
	 * @return void
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function persist( $saved ) {
		$index = self::read( 'wprm_templates' );
		$index = false === $index ? array() : $index;
		// Contents first: failed new installs must never list a nonexistent template.
		self::write( 'wprm_template_' . $saved['slug'], $saved );
		if ( ! in_array( $saved['slug'], $index, true ) ) {
			$index[] = $saved['slug'];
			self::write( 'wprm_templates', $index );
		}
	}

	/**
	 * Save an editor template with verified storage and recovery.
	 *
	 * @param array $template Template to save.
	 * @param int|null $expected Required current revision, or null.
	 * @return array|WP_Error Saved template, or a failure with recovery details.
	 */
	public static function save( $template, $expected = null ) {
		return self::atomic( function () use ( $template, $expected ) {
			$saved = self::prepare( $template, $expected );
			if ( is_wp_error( $saved ) ) { return $saved; }
			self::persist( $saved );
			return $saved;
		} );
	}

	/**
	 * Delete a template and its index entry with verified recovery.
	 *
	 * @param string $slug Template slug.
	 * @return string|WP_Error Deleted slug, or a storage failure.
	 */
	public static function delete( $slug ) {
		return self::atomic( function () use ( $slug ) {
			$slug = WPRM_Template_Manager::slugify( $slug );
			$index = self::read( 'wprm_templates' );
			self::write( 'wprm_templates', array_values( array_diff( is_array( $index ) ? $index : array(), array( $slug ) ) ) );
			$name = 'wprm_template_' . $slug;
			self::$stage = 'delete';
			self::remember( $name );
			self::$expected[$name] = null;
			self::put_row( $name, null );
			return $slug;
		} );
	}

	/**
	 * Install or replace a Kitchen package using a replayable operation receipt.
	 *
	 * @param array $request Operation identity, package, and optional replacement revision.
	 * @return array|WP_Error Installation result with optional engine advisory, or an error.
	 */
	public static function import( $request ) {
		$result = self::atomic( function () use ( $request ) {
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
				if ( isset( $previous['template'] ) ) { return self::resume_import( $name, $previous ); }
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
			$before = false;
			$saved = self::prepare( $template, $expected, $before );
			if ( is_wp_error( $saved ) ) { return $saved; }
			$export = WPRM_Template_Package::export( $saved );
			if ( ! hash_equals( $package['checksum'], $export['checksum'] ) ) { return new WP_Error( 'wprm_template_checksum', 'Installed content did not match the package.', array( 'status' => 422 ) ); }
			$result = array( 'local_id' => $saved['localTemplateId'], 'slug' => $saved['slug'], 'name' => $saved['name'], 'revision' => $saved['revision'], 'checksum' => $export['checksum'], 'algorithm' => $export['algorithm'] );
			$record = array( 'fingerprint' => $fingerprint, 'expires_at' => time() + 7 * DAY_IN_SECONDS, 'result' => $result, 'template' => $saved, 'before_hash' => hash( 'sha256', maybe_serialize( $before ) ) );
			// Persist the target identity before its effect, so an interrupted retry resumes it.
			self::write( $name, $record );
			self::verify( array( $name => self::$expected[$name] ) );
			return self::resume_import( $name, $record );
		} );
		if ( ! is_wp_error( $result ) && null !== self::$engine && 0 !== strcasecmp( 'InnoDB', self::$engine ) ) {
			$result['storage_notice'] = array( 'code' => 'wprm_template_innodb_recommended', 'engine' => self::$engine );
		}
		return $result;
	}

	/**
	 * Resume the recorded target without duplicating it or overwriting later edits.
	 *
	 * @param string $name Receipt option name.
	 * @param array $record Pending receipt containing target, prior hash, and result.
	 * @return array|WP_Error Original installation result, or a revision conflict.
	 * @throws RuntimeException When storage cannot be read, written, or verified.
	 */
	private static function resume_import( $name, $record ) {
		$saved = $record['template'];
		$current = self::read( 'wprm_template_' . $saved['slug'] );
		// A retry may finish its own effect, but must never overwrite intervening edits.
		if ( $current !== $saved && ! hash_equals( $record['before_hash'], hash( 'sha256', maybe_serialize( $current ) ) ) ) {
			return new WP_Error( 'wprm_template_stale', 'This template changed after an interrupted installation. Refresh the site templates before trying again.', array( 'status' => 409 ) );
		}
		self::$pending_imports[$name] = $record;
		self::persist( $saved );
		self::verify( self::$expected );
		$complete = $record;
		unset( $complete['template'], $complete['before_hash'] );
		self::write( $name, $complete );
		return $record['result'];
	}

	/**
	 * Find an available modern template by its stable local identity.
	 *
	 * @param string $local_id Local template identity.
	 * @return array|false Matching template, or false when absent.
	 */
	public static function find( $local_id ) {
		$templates = WPRM_Template_Manager::get_templates();
		foreach ( $templates['modern'] as $template ) {
			$template = self::describe( $template );
			if ( $template['localTemplateId'] === $local_id ) { return $template; }
		}
		return false;
	}

	/**
	 * Remove expired Kitchen receipts, leaving unreadable data untouched.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000", $wpdb->esc_like( 'wprm_template_op_' ) . '%' ) );
		foreach ( $names as $name ) {
			try { $record = self::read( $name ); } catch ( Throwable $error ) { continue; }
			if ( is_array( $record ) && isset( $record['expires_at'] ) && is_int( $record['expires_at'] ) && $record['expires_at'] < time() ) { delete_option( $name ); }
		}
	}
}
