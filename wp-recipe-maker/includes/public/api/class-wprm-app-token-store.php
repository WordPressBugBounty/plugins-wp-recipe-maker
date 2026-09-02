<?php
/**
 * Shared lifecycle for the WPRM Kitchen site access token.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.9.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 */

/**
 * Store the WPRM Kitchen token hash and non-sensitive token metadata.
 *
 * @since 10.9.0
 */
class WPRM_App_Token_Store {
	/** @var string Option keyed by SHA-256 token hash. */
	private static $option = 'wprm_app_access';

	/**
	 * Get all stored token metadata.
	 *
	 * @since 10.9.0
	 * @return array
	 */
	public static function get_all() {
		$access = get_option( self::$option, array() );

		return is_array( $access ) ? $access : array();
	}

	/**
	 * Create a token, persisting only its hash.
	 *
	 * Creating a Kitchen token replaces all earlier app/device tokens. The
	 * removed beta device-token feature deliberately has no compatibility path.
	 *
	 * @since 10.9.0
	 * @param string $name Token display name.
	 * @param array  $scopes Explicit scopes.
	 * @param array  $metadata Additional non-secret metadata.
	 * @return array|WP_Error Token secret, id, and metadata on success.
	 */
	public static function create( $name, $scopes = array(), $metadata = array() ) {
		$access = array();
		$scopes = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_scope' ), (array) $scopes ) ) ) );
		$token = self::generate_secret();
		$id = self::generate_id( $access );
		$data = array_merge(
			self::sanitize_metadata( $metadata ),
			array(
				'id' => $id,
				'name' => sanitize_text_field( $name ),
				'kind' => 'kitchen',
				'scopes' => $scopes,
				'created_at' => current_time( 'mysql' ),
				'created_by' => get_current_user_id(),
				'last_used_at' => '',
			)
		);

		$access[ self::hash( $token ) ] = $data;
		update_option( self::$option, $access, false );

		return array(
			'token' => $token,
			'id' => $id,
			'data' => $data,
		);
	}

	/**
	 * Find a valid token using a timing-safe hash comparison.
	 *
	 * @since 10.9.0
	 * @param string $token Raw bearer token.
	 * @return array|false Hash and metadata, or false.
	 */
	public static function find( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		$access = self::get_all();
		$token_hash = self::hash( $token );

		foreach ( $access as $stored_hash => $data ) {
			if ( is_string( $stored_hash ) && is_array( $data ) && isset( $data['kind'] ) && 'kitchen' === $data['kind'] && hash_equals( $stored_hash, $token_hash ) ) {
				return array(
					'hash' => $stored_hash,
					'data' => $data,
				);
			}
		}

		return false;
	}

	/**
	 * Revoke a Kitchen token by its public id.
	 *
	 * @since 10.9.0
	 * @param string $id Token id.
	 * @return bool Whether a matching token existed.
	 */
	public static function revoke_by_id( $id ) {
		$access = self::get_all();
		$removed = false;

		foreach ( $access as $token_hash => $token_data ) {
			if ( is_array( $token_data ) && isset( $token_data['kind'], $token_data['id'] ) && 'kitchen' === $token_data['kind'] && $id === $token_data['id'] ) {
				unset( $access[ $token_hash ] );
				$removed = true;
			}
		}

		self::save( $access );

		return $removed;
	}

	/**
	 * Revoke one exact token by its already validated storage hash.
	 *
	 * @since 10.9.0
	 * @param string $token_hash SHA-256 storage key.
	 * @return bool Whether the exact token existed and was removed.
	 */
	public static function revoke_by_hash( $token_hash ) {
		$access = self::get_all();
		if ( ! isset( $access[ $token_hash ] ) || ! is_array( $access[ $token_hash ] ) ) {
			return false;
		}

		if ( empty( $access[ $token_hash ]['kind'] ) || 'kitchen' !== $access[ $token_hash ]['kind'] ) {
			return false;
		}

		unset( $access[ $token_hash ] );
		self::save( $access );

		return true;
	}

	/**
	 * Revoke every stored Kitchen/app token.
	 *
	 * @since 10.9.0
	 * @return int Number removed.
	 */
	public static function revoke_all() {
		$access = self::get_all();
		$removed = count( $access );
		self::save( array() );

		return $removed;
	}

	/**
	 * Update a token's last-used timestamp.
	 *
	 * @since 10.9.0
	 * @param string $token_hash Stored token hash.
	 */
	public static function touch( $token_hash ) {
		$access = self::get_all();

		if ( ! isset( $access[ $token_hash ] ) ) {
			return;
		}

		$access[ $token_hash ]['last_used_at'] = current_time( 'mysql' );
		update_option( self::$option, $access, false );
	}

	/**
	 * Check whether token metadata grants a scope.
	 *
	 * @since 10.9.0
	 * @param array  $data Token metadata.
	 * @param string $scope Required scope.
	 * @return bool
	 */
	public static function has_scope( $data, $scope ) {
		$scopes = isset( $data['scopes'] ) && is_array( $data['scopes'] ) ? $data['scopes'] : array();

		return in_array( $scope, $scopes, true );
	}

	/**
	 * Hash a bearer token for storage.
	 *
	 * @since 10.9.0
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', $token );
	}

	/** Save or delete the backing option. */
	private static function save( $access ) {
		if ( $access ) {
			update_option( self::$option, $access, false );
		} else {
			delete_option( self::$option );
		}
	}

	/** Generate a raw bearer token. */
	private static function generate_secret() {
		try {
			$random = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			$random = wp_generate_password( 64, false, false );
		}

		return 'wprm_app_' . $random;
	}

	/** Generate a unique public token id. */
	private static function generate_id( $access ) {
		$existing_ids = wp_list_pluck( $access, 'id' );

		do {
			try {
				$id = bin2hex( random_bytes( 4 ) );
			} catch ( Exception $e ) {
				$id = strtolower( wp_generate_password( 8, false, false ) );
			}
		} while ( in_array( $id, $existing_ids, true ) );

		return $id;
	}

	/** Keep arbitrary metadata scalar and non-secret. */
	private static function sanitize_metadata( $metadata ) {
		$sanitized = array();
		foreach ( (array) $metadata as $key => $value ) {
			$key = sanitize_key( $key );
			if ( $key && is_scalar( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/** Sanitize an OAuth-style scope while preserving its separator. */
	private static function sanitize_scope( $scope ) {
		return preg_replace( '/[^a-z0-9:_-]/', '', strtolower( (string) $scope ) );
	}
}
