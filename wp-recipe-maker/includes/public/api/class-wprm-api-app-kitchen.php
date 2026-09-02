<?php
/**
 * Secure bidirectional pairing with WPRM Kitchen.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.9.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public/api
 */

/**
 * Versioned, origin-pinned HTTP adapter for Kitchen-owned endpoints.
 *
 * @since 10.9.0
 */
class WPRM_Kitchen_Client {
	/** @var string Production Kitchen origin. */
	const DEFAULT_ORIGIN = 'https://kitchen.wprecipemaker.com';

	/**
	 * Get and validate the configured Kitchen origin.
	 *
	 * @since 10.9.0
	 * @return string|WP_Error
	 */
	public static function get_origin() {
		$origin = defined( 'WPRM_KITCHEN_URL' ) ? WPRM_KITCHEN_URL : self::DEFAULT_ORIGIN;
		$origin = apply_filters( 'wprm_kitchen_base_url', $origin );

		return self::validate_origin( $origin );
	}

	/**
	 * Get the optional server-only Kitchen origin used by local containers.
	 *
	 * Browser redirects are always validated against get_origin().
	 *
	 * @since 10.9.0
	 * @return string|WP_Error
	 */
	public static function get_request_origin() {
		$origin = defined( 'WPRM_KITCHEN_INTERNAL_URL' ) ? WPRM_KITCHEN_INTERNAL_URL : self::get_origin();
		$origin = apply_filters( 'wprm_kitchen_internal_base_url', $origin );

		return is_wp_error( $origin ) ? $origin : self::validate_origin( $origin );
	}

	/**
	 * Return the adapter's endpoint paths.
	 *
	 * @since 10.9.0
	 * @return array
	 */
	public static function get_paths() {
		return apply_filters( 'wprm_kitchen_api_paths', array(
			'pairings' => '/api/v1/pairings',
			'claim' => '/api/v1/pairings/%s/claim',
			'complete' => '/api/v1/pairings/%s/complete',
			'events' => '/api/v1/site-connections/%s/events',
		) );
	}

	/**
	 * Build a path containing one opaque id.
	 *
	 * @since 10.9.0
	 * @param string $key Endpoint key.
	 * @param string $id Opaque Kitchen id.
	 * @return string|WP_Error
	 */
	public static function path( $key, $id = '' ) {
		$paths = self::get_paths();
		if ( empty( $paths[ $key ] ) || ! is_string( $paths[ $key ] ) || 0 !== strpos( $paths[ $key ], '/api/v1/' ) ) {
			return new WP_Error( 'wprm_kitchen_invalid_configuration', __( 'The WPRM Kitchen endpoint configuration is invalid.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
		}

		return $id ? sprintf( $paths[ $key ], rawurlencode( $id ) ) : $paths[ $key ];
	}

	/**
	 * Post a JSON request to a pinned Kitchen endpoint.
	 *
	 * @since 10.9.0
	 * @param string $path Endpoint path.
	 * @param array  $body JSON body.
	 * @param array  $headers Extra headers.
	 * @return array|WP_Error
	 */
	public static function post( $path, $body, $headers = array() ) {
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return new WP_Error( 'wprm_kitchen_invalid_request', __( 'Could not encode the WPRM Kitchen request.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
		}

		return self::post_json( $path, $json, $headers );
	}

	/**
	 * Post an exact JSON string (used when the body is part of a signature).
	 *
	 * @since 10.9.0
	 * @param string $path Endpoint path.
	 * @param string $json Exact body.
	 * @param array  $headers Extra headers.
	 * @return array|WP_Error
	 */
	public static function post_json( $path, $json, $headers = array(), $expect_json = true ) {
		$origin = self::get_request_origin();
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		if ( ! is_string( $path ) || 0 !== strpos( $path, '/api/v1/' ) ) {
			return new WP_Error( 'wprm_kitchen_invalid_configuration', __( 'The WPRM Kitchen endpoint path is invalid.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
		}

		$development_origin = self::is_constant_development_origin( $origin );
		if ( $development_origin ) {
			add_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_configured_development_host' ), 10, 3 );
			add_filter( 'http_allowed_safe_ports', array( __CLASS__, 'allow_configured_development_port' ), 10, 3 );
		}

		$response = wp_safe_remote_post( $origin . $path, array(
			'timeout' => min( 15, max( 3, intval( apply_filters( 'wprm_kitchen_http_timeout', 8 ) ) ) ),
			'redirection' => 0,
			'sslverify' => true,
			'headers' => array_merge( array(
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
				'User-Agent' => 'WP-Recipe-Maker/' . WPRM_VERSION,
			), $headers ),
			'body' => $json,
			'data_format' => 'body',
		) );

		if ( $development_origin ) {
			remove_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_configured_development_host' ), 10 );
			remove_filter( 'http_allowed_safe_ports', array( __CLASS__, 'allow_configured_development_port' ), 10 );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wprm_kitchen_remote_error',
				__( 'WPRM Kitchen could not be reached. The local connection remains safe; please try again.', 'wp-recipe-maker' ),
				array( 'status' => 502, 'remote_code' => sanitize_key( $response->get_error_code() ) )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		if ( 65536 < strlen( $response_body ) ) {
			return self::invalid_response();
		}

		if ( 200 > $status || 299 < $status ) {
			$data = json_decode( $response_body, true );
			$data = is_array( $data ) ? $data : array();
			$error_data = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : $data;
			$remote_code = isset( $error_data['code'] ) ? sanitize_key( $error_data['code'] ) : 'unknown';
			$message = isset( $error_data['message'] ) ? sanitize_text_field( $error_data['message'] ) : __( 'WPRM Kitchen rejected the request.', 'wp-recipe-maker' );
			return new WP_Error( 'wprm_kitchen_remote_error', $message, array( 'status' => 502, 'remote_code' => $remote_code ) );
		}
		if ( ! $expect_json ) {
			return array( 'status' => $status );
		}

		$data = json_decode( $response_body, true );
		if ( ! is_array( $data ) ) {
			return self::invalid_response();
		}

		return $data;
	}

	/** Allow only the explicitly configured local Kitchen host through safe HTTP validation. */
	public static function allow_configured_development_host( $external, $host, $url ) {
		return self::is_configured_request_url( $url ) ? true : $external;
	}

	/** Allow only the explicitly configured local Kitchen port through safe HTTP validation. */
	public static function allow_configured_development_port( $ports, $host, $url ) {
		if ( self::is_configured_request_url( $url ) ) {
			$parts = wp_parse_url( $url );
			if ( isset( $parts['port'] ) ) {
				$ports[] = intval( $parts['port'] );
			}
		}

		return array_values( array_unique( $ports ) );
	}

	/** Validate that a Kitchen browser URL uses the pinned origin. */
	public static function validate_browser_url( $url ) {
		$origin = self::get_origin();
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;

		if ( is_wp_error( $origin ) || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return hash_equals( $origin, self::origin_from_parts( $parts ) );
	}

	/** Build a normalized origin. */
	private static function origin_from_parts( $parts ) {
		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . intval( $parts['port'] );
		}

		return $origin;
	}

	/** Validate and normalize a configured browser or internal request origin. */
	private static function validate_origin( $origin ) {
		$origin = is_string( $origin ) ? untrailingslashit( trim( $origin ) ) : '';
		$parts = wp_parse_url( $origin );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::origin_error();
		}

		$scheme = strtolower( $parts['scheme'] );
		$allow_http = self::is_constant_development_origin( $origin );
		$allow_http = (bool) apply_filters( 'wprm_kitchen_allow_insecure_local', $allow_http, $origin );
		if ( 'https' !== $scheme && ( 'http' !== $scheme || ! $allow_http ) ) {
			return self::origin_error();
		}

		foreach ( array( 'user', 'pass', 'query', 'fragment' ) as $forbidden ) {
			if ( isset( $parts[ $forbidden ] ) ) {
				return self::origin_error();
			}
		}

		if ( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] ) {
			return self::origin_error();
		}

		return self::origin_from_parts( $parts );
	}

	/** Check that safe HTTP is evaluating the exact configured internal request origin. */
	private static function is_configured_request_url( $url ) {
		$origin = self::get_request_origin();
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;

		return ! is_wp_error( $origin )
			&& self::is_constant_development_origin( $url )
			&& is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& hash_equals( $origin, self::origin_from_parts( $parts ) );
	}

	/** Check the narrow constant-based opt-in used by local Docker/browser development. */
	private static function is_constant_development_origin( $origin ) {
		$parts = is_string( $origin ) ? wp_parse_url( $origin ) : false;
		$development_hosts = array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' );

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'http' === strtolower( $parts['scheme'] )
			&& defined( 'WPRM_KITCHEN_ALLOW_INSECURE_LOCAL' )
			&& WPRM_KITCHEN_ALLOW_INSECURE_LOCAL
			&& 'local' === wp_get_environment_type()
			&& in_array( strtolower( $parts['host'] ), $development_hosts, true );
	}

	/** Return the stable invalid-origin error. */
	private static function origin_error() {
		return new WP_Error( 'wprm_kitchen_invalid_origin', __( 'The configured WPRM Kitchen origin must be a valid HTTPS origin.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
	}

	/** Return the stable invalid-response error. */
	private static function invalid_response() {
		return new WP_Error( 'wprm_kitchen_invalid_response', __( 'WPRM Kitchen returned an invalid response.', 'wp-recipe-maker' ), array( 'status' => 502 ) );
	}
}

/**
 * REST and wp-admin handlers for WPRM Kitchen pairing.
 *
 * @since 10.9.0
 */
class WPRM_Api_App_Kitchen {
	const PROTOCOL_VERSION = 1;
	const PAIRING_TTL = 600;
	const CONNECTION_OPTION = 'wprm_kitchen_connection';
	const PENDING_OPTION = 'wprm_kitchen_pending';
	const ERROR_OPTION = 'wprm_kitchen_error';
	const SITE_ID_OPTION = 'wprm_kitchen_site_id';
	const TRANSIENT_PREFIX = 'wprm_kitchen_pairing_';

	/** Register hooks. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'api_register_data' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_callback' ), 1 );
	}

	/** Register public discovery and protected local mutation endpoints. */
	public static function api_register_data() {
		register_rest_route( 'wp-recipe-maker/v1', '/app/discovery', array(
			'callback' => array( __CLASS__, 'api_discovery' ),
			'methods' => 'GET',
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-recipe-maker/v1', '/app/kitchen', array(
			array(
				'callback' => array( __CLASS__, 'api_status' ),
				'methods' => 'GET',
				'permission_callback' => array( __CLASS__, 'api_admin_permissions' ),
			),
			array(
				'callback' => array( __CLASS__, 'api_disconnect' ),
				'methods' => 'DELETE',
				'permission_callback' => array( __CLASS__, 'api_admin_permissions' ),
			),
		) );
		foreach ( array(
			'connect' => 'api_connect',
			'approve' => 'api_approve',
			'complete' => 'api_complete',
			'test' => 'api_test',
			'cancel' => 'api_cancel',
		) as $route => $callback ) {
			register_rest_route( 'wp-recipe-maker/v1', '/app/kitchen/' . $route, array(
				'callback' => array( __CLASS__, $callback ),
				'methods' => 'POST',
				'permission_callback' => array( __CLASS__, 'api_admin_permissions' ),
			) );
		}
	}

	/** Explicit capability and nonce protection for every local admin action. */
	public static function api_admin_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wprm_kitchen_forbidden', __( 'You are not allowed to manage the WPRM Kitchen connection.', 'wp-recipe-maker' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Cookie check failed', 'wp-recipe-maker' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/** Public, deliberately non-sensitive capability discovery. */
	public static function api_discovery() {
		$origin = WPRM_Kitchen_Client::get_origin();
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		return rest_ensure_response( array(
			'protocol_version' => self::PROTOCOL_VERSION,
			'capabilities' => array( 'kitchen_pairing', 'app_info', 'app_recipes', 'signed_events' ),
			'site_id' => self::get_site_id(),
			'site_url' => untrailingslashit( home_url() ),
			'api_url' => self::api_url(),
			'authorize_url' => self::authorize_url(),
			'wprm_version' => WPRM_VERSION,
		) );
	}

	/** Return safe connection/pairing status for the settings UI. */
	public static function api_status() {
		return rest_ensure_response( self::get_status() );
	}

	/** Begin plugin-start pairing with PKCE S256. */
	public static function api_connect() {
		if ( self::get_connection() ) {
			return new WP_Error( 'wprm_kitchen_already_connected', __( 'This site is already connected to WPRM Kitchen.', 'wp-recipe-maker' ), array( 'status' => 409 ) );
		}

		self::clear_pending();
		self::clear_error();
		$verifier = self::random_base64url( 32 );
		$challenge = self::base64url_encode( hash( 'sha256', $verifier, true ) );
		$path = WPRM_Kitchen_Client::path( 'pairings' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$response = WPRM_Kitchen_Client::post( $path, array(
			'protocol_version' => self::PROTOCOL_VERSION,
			'site_url' => untrailingslashit( home_url() ),
			'api_url' => self::api_url(),
			'callback_url' => self::authorize_url(),
			'code_challenge' => $challenge,
			'code_challenge_method' => 'S256',
		) );
		if ( is_wp_error( $response ) ) {
			self::remember_error( $response );
			return $response;
		}

		$validated = self::validate_pairing_response( $response );
		if ( is_wp_error( $validated ) ) {
			self::remember_error( $validated );
			return $validated;
		}

		self::set_pending( array(
			'direction' => 'plugin_start',
			'state' => 'awaiting_kitchen',
			'pairing_id' => $validated['pairing_id'],
			'authorize_url' => $validated['authorize_url'],
			'code_challenge' => $challenge,
			'created_by' => get_current_user_id(),
		), array( 'verifier' => $verifier ), $validated['expires_at'] - time() );

		return rest_ensure_response( self::get_status() );
	}

	/** Explicitly approve a Kitchen-start pairing. */
	public static function api_approve() {
		return self::complete_pending( 'kitchen_start' );
	}

	/** Finish plugin-start after its fixed wp-admin callback was ingested. */
	public static function api_complete() {
		return self::complete_pending( 'plugin_start' );
	}

	/** Send a signed connectivity test event. */
	public static function api_test() {
		$connection = self::get_connection();
		if ( ! $connection ) {
			return self::not_connected_error();
		}

		$result = self::send_event( $connection, 'connection.status' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = self::get_status();
		$status['test'] = array( 'ok' => true, 'tested_at' => gmdate( 'c' ) );
		return rest_ensure_response( $status );
	}

	/** Disconnect locally and attempt an idempotent signed remote revoke. */
	public static function api_disconnect() {
		$connection = self::get_connection();
		$remote_notified = false;
		$warning = false;

		if ( $connection ) {
			$event = self::send_event( $connection, 'connection.revoked' );
			if ( is_wp_error( $event ) ) {
				$warning = array( 'code' => $event->get_error_code(), 'message' => $event->get_error_message() );
			} else {
				$remote_notified = true;
			}

			if ( ! empty( $connection['site_token_id'] ) ) {
				WPRM_App_Token_Store::revoke_by_id( $connection['site_token_id'] );
			}
		}

		// Clean up any orphaned Kitchen credential.
		WPRM_App_Token_Store::revoke_all();
		delete_option( self::CONNECTION_OPTION );
		self::clear_pending();
		self::clear_error();

		$status = self::get_status();
		$status['remote_notified'] = $remote_notified;
		if ( $warning ) {
			$status['warning'] = $warning;
		}

		return rest_ensure_response( $status );
	}

	/**
	 * Remove metadata associated with a Kitchen bearer revoked by Kitchen.
	 *
	 * No callback event is sent here: the remote service initiated the revoke.
	 * Pending state is also cleared so a stale callback cannot reconnect the
	 * site without a fresh, explicitly approved pairing.
	 *
	 * @since 10.9.0
	 * @param array $token_data Validated Kitchen token metadata.
	 */
	public static function handle_token_self_revocation( $token_data ) {
		$connection = self::get_connection();
		$token_id = isset( $token_data['id'] ) ? (string) $token_data['id'] : '';

		if ( $connection && $token_id && ! empty( $connection['site_token_id'] ) && hash_equals( (string) $connection['site_token_id'], $token_id ) ) {
			delete_option( self::CONNECTION_OPTION );
		}

		self::clear_pending();
		self::clear_error();
	}

	/** Cancel a pending pairing without affecting an active connection. */
	public static function api_cancel() {
		self::clear_pending();
		self::clear_error();

		return rest_ensure_response( self::get_status() );
	}

	/**
	 * Ingest a Kitchen-start request or plugin-start callback, then immediately
	 * redirect to a clean settings URL. Actual connection mutations happen via
	 * the nonce-protected REST actions above.
	 */
	public static function handle_admin_callback() {
		$canonical_pairing = isset( $_GET['wprm_kitchen_pairing_id'] ) && ( isset( $_GET['wprm_kitchen_claim_code'] ) || isset( $_GET['wprm_kitchen_authorization_code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$legacy_pairing = isset( $_GET['pairing_id'] ) && ( isset( $_GET['claim_code'] ) || isset( $_GET['authorization_code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$has_pairing_callback = $canonical_pairing || $legacy_pairing;
		if ( empty( $_GET['wprm_kitchen_authorize'] ) && ! $has_pairing_callback ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site to WPRM Kitchen.', 'wp-recipe-maker' ), '', array( 'response' => 403 ) );
		}

		$pairing_id_raw = isset( $_GET['wprm_kitchen_pairing_id'] ) ? $_GET['wprm_kitchen_pairing_id'] : ( isset( $_GET['pairing_id'] ) ? $_GET['pairing_id'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$claim_code_raw = isset( $_GET['wprm_kitchen_claim_code'] ) ? $_GET['wprm_kitchen_claim_code'] : ( isset( $_GET['claim_code'] ) ? $_GET['claim_code'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$authorization_code_raw = isset( $_GET['wprm_kitchen_authorization_code'] ) ? $_GET['wprm_kitchen_authorization_code'] : ( isset( $_GET['authorization_code'] ) ? $_GET['authorization_code'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pairing_id = sanitize_text_field( wp_unslash( $pairing_id_raw ) );
		$claim_code = sanitize_text_field( wp_unslash( $claim_code_raw ) );
		$authorization_code = sanitize_text_field( wp_unslash( $authorization_code_raw ) );
		$notice = 'invalid';

		if ( $claim_code ) {
			$protocol_version = isset( $_GET['wprm_kitchen_protocol_version'] ) ? intval( $_GET['wprm_kitchen_protocol_version'] ) : ( $canonical_pairing ? 0 : self::PROTOCOL_VERSION ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result = self::PROTOCOL_VERSION === $protocol_version ? self::ingest_kitchen_start( $pairing_id, $claim_code ) : self::invalid_pairing_error();
			if ( is_wp_error( $result ) ) {
				self::remember_error( $result );
			}
			$notice = is_wp_error( $result ) ? $result->get_error_code() : 'approval_required';
		} elseif ( $authorization_code ) {
			$result = self::ingest_plugin_callback( $pairing_id, $authorization_code );
			$notice = is_wp_error( $result ) ? $result->get_error_code() : 'completing';
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'wprm_settings', 'wprm_kitchen_notice' => sanitize_key( $notice ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Store a Kitchen-start code in a short-lived transient. */
	private static function ingest_kitchen_start( $pairing_id, $claim_code ) {
		if ( self::get_connection() ) {
			return new WP_Error( 'wprm_kitchen_already_connected', __( 'This site is already connected to WPRM Kitchen.', 'wp-recipe-maker' ) );
		}
		if ( ! self::valid_opaque_id( $pairing_id ) || ! self::valid_secret( $claim_code ) ) {
			$error = self::invalid_pairing_error();
			self::remember_error( $error );
			return $error;
		}

		self::clear_pending();
		self::clear_error();
		self::set_pending( array(
			'direction' => 'kitchen_start',
			'state' => 'awaiting_approval',
			'pairing_id' => $pairing_id,
			'created_by' => get_current_user_id(),
		), array( 'claim_code' => $claim_code ), self::PAIRING_TTL );

		return true;
	}

	/** Validate plugin-start callback state and store its one-time code. */
	private static function ingest_plugin_callback( $pairing_id, $authorization_code ) {
		$pending = self::get_pending();
		if ( ! $pending || 'plugin_start' !== $pending['direction'] || 'awaiting_kitchen' !== $pending['state'] ) {
			return new WP_Error( 'wprm_kitchen_pairing_replayed', __( 'This WPRM Kitchen pairing is no longer active.', 'wp-recipe-maker' ) );
		}
		if ( intval( $pending['created_by'] ) !== get_current_user_id() || $pending['pairing_id'] !== $pairing_id || ! self::valid_secret( $authorization_code ) ) {
			$error = self::invalid_pairing_error();
			self::remember_error( $error );
			return $error;
		}
		$secrets = get_transient( self::TRANSIENT_PREFIX . $pending['pending_key'] );
		if ( ! is_array( $secrets ) || empty( $secrets['verifier'] ) ) {
			return self::expire_pending();
		}
		$secrets['authorization_code'] = $authorization_code;
		set_transient( self::TRANSIENT_PREFIX . $pending['pending_key'], $secrets, max( 1, $pending['expires_at'] - time() ) );
		$pending['state'] = 'callback_received';
		update_option( self::PENDING_OPTION, $pending, false );

		return true;
	}

	/** Complete either direction exactly once. */
	private static function complete_pending( $direction ) {
		$pending = self::get_pending();
		$expected_state = 'kitchen_start' === $direction ? 'awaiting_approval' : 'callback_received';
		if ( ! $pending || $direction !== $pending['direction'] || $expected_state !== $pending['state'] ) {
			if ( self::get_connection() ) {
				return new WP_Error( 'wprm_kitchen_pairing_replayed', __( 'This WPRM Kitchen pairing has already been used.', 'wp-recipe-maker' ), array( 'status' => 409 ) );
			}
			return self::invalid_pairing_error();
		}
		if ( intval( $pending['created_by'] ) !== get_current_user_id() ) {
			return new WP_Error( 'wprm_kitchen_forbidden', __( 'Only the administrator who started this pairing can finish it.', 'wp-recipe-maker' ), array( 'status' => 403 ) );
		}

		$secrets = get_transient( self::TRANSIENT_PREFIX . $pending['pending_key'] );
		if ( ! is_array( $secrets ) ) {
			return self::expire_pending();
		}

		// Consume locally before the remote exchange to make retries/replays safe.
		self::clear_pending();
		$flow_credential = array();
		if ( 'kitchen_start' === $direction ) {
			if ( empty( $secrets['claim_code'] ) ) {
				return self::expire_pending();
			}
			$claim_path = WPRM_Kitchen_Client::path( 'claim', $pending['pairing_id'] );
			if ( is_wp_error( $claim_path ) ) {
				return $claim_path;
			}
			$claim_response = WPRM_Kitchen_Client::post( $claim_path, array(
				'protocol_version' => self::PROTOCOL_VERSION,
				'claim_code' => $secrets['claim_code'],
				'site' => self::site_descriptor(),
			) );
			if ( is_wp_error( $claim_response ) ) {
				self::remember_error( $claim_response );
				return $claim_response;
			}
			$claimed = self::validate_claim_response( $claim_response, $pending['pairing_id'] );
			if ( is_wp_error( $claimed ) ) {
				self::remember_error( $claimed );
				return $claimed;
			}
			$flow_credential['completion_token'] = $claimed['completion_token'];
		} else {
			if ( empty( $secrets['verifier'] ) || empty( $secrets['authorization_code'] ) ) {
				return self::expire_pending();
			}
			$flow_credential['authorization_code'] = $secrets['authorization_code'];
			$flow_credential['code_verifier'] = $secrets['verifier'];
		}

		$token = self::create_kitchen_token( $pending['pairing_id'] );
		if ( is_wp_error( $token ) ) {
			self::remember_error( $token );
			return $token;
		}
		$payload = array_merge( array(
			'protocol_version' => self::PROTOCOL_VERSION,
			'wordpress_access_token' => $token['token'],
			'site' => self::site_descriptor(),
		), $flow_credential );
		$path = WPRM_Kitchen_Client::path( 'complete', $pending['pairing_id'] );
		if ( is_wp_error( $path ) ) {
			WPRM_App_Token_Store::revoke_by_id( $token['id'] );
			return $path;
		}
		$response = WPRM_Kitchen_Client::post( $path, $payload );
		if ( is_wp_error( $response ) ) {
			WPRM_App_Token_Store::revoke_by_id( $token['id'] );
			self::remember_error( $response );
			return $response;
		}

		$connection = self::validate_connection_response( $response );
		if ( is_wp_error( $connection ) ) {
			WPRM_App_Token_Store::revoke_by_id( $token['id'] );
			self::remember_error( $connection );
			return $connection;
		}

		$origin = WPRM_Kitchen_Client::get_origin();
		$stored = array_merge( $connection, array(
			'site_token_id' => $token['id'],
			'kitchen_origin' => $origin,
			'connected_at' => current_time( 'mysql' ),
		) );
		update_option( self::CONNECTION_OPTION, $stored, false );
		self::clear_error();

		$status = self::get_status();
		if ( 'kitchen_start' === $direction ) {
			$status['kitchen_return_url'] = $origin . '/app/sites/pairing/' . rawurlencode( $pending['pairing_id'] );
		}

		return rest_ensure_response( $status );
	}

	/** Create exactly one typed Kitchen token, persisting only its hash. */
	private static function create_kitchen_token( $pairing_id ) {
		WPRM_App_Token_Store::revoke_all();

		return WPRM_App_Token_Store::create(
			__( 'WPRM Kitchen', 'wp-recipe-maker' ),
			array( 'site:read', 'recipes:read', 'analytics:read' ),
			array( 'pairing_id' => $pairing_id )
		);
	}

	/** Send an HMAC signed lifecycle event. */
	private static function send_event( $connection, $type ) {
		if ( empty( $connection['id'] ) || empty( $connection['events_path'] ) || empty( $connection['callback_secret_encrypted'] ) ) {
			return self::not_connected_error();
		}
		$callback_secret = self::decrypt_callback_secret( $connection['callback_secret_encrypted'] );
		if ( is_wp_error( $callback_secret ) ) {
			return $callback_secret;
		}
		$path = $connection['events_path'];

		$event = array(
			'protocol_version' => self::PROTOCOL_VERSION,
			'type' => $type,
			'occurred_at' => gmdate( 'c' ),
		);
		if ( 'connection.status' === $type ) {
			$event['status'] = 'connected';
		} elseif ( 'connection.revoked' === $type ) {
			$event['reason'] = 'wordpress_admin_disconnect';
		}
		$body = wp_json_encode( $event );
		$timestamp = (string) time();
		$nonce = bin2hex( self::random_bytes( 16 ) );
		$canonical = $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );
		$signature = hash_hmac( 'sha256', $canonical, $callback_secret );
		unset( $callback_secret );

		return WPRM_Kitchen_Client::post_json( $path, $body, array(
			'X-WPRM-Timestamp' => $timestamp,
			'X-WPRM-Nonce' => $nonce,
			'X-WPRM-Signature' => $signature,
		), false );
	}

	/** Validate Kitchen's pairing creation response. */
	private static function validate_pairing_response( $response ) {
		$pairing_id = isset( $response['pairing_id'] ) ? (string) $response['pairing_id'] : '';
		$authorize_url = isset( $response['authorize_url'] ) ? esc_url_raw( $response['authorize_url'] ) : '';
		$expires_at = isset( $response['expires_at'] ) ? strtotime( $response['expires_at'] ) : false;
		$status = isset( $response['status'] ) ? (string) $response['status'] : '';
		if ( self::PROTOCOL_VERSION !== intval( isset( $response['protocol_version'] ) ? $response['protocol_version'] : 0 ) || 'awaiting_kitchen_approval' !== $status || ! self::valid_opaque_id( $pairing_id ) || ! $authorize_url || ! WPRM_Kitchen_Client::validate_browser_url( $authorize_url ) || ! $expires_at || time() >= $expires_at || time() + self::PAIRING_TTL < $expires_at ) {
			return new WP_Error( 'wprm_kitchen_invalid_response', __( 'WPRM Kitchen returned invalid pairing details.', 'wp-recipe-maker' ), array( 'status' => 502 ) );
		}

		return array( 'pairing_id' => $pairing_id, 'authorize_url' => $authorize_url, 'expires_at' => $expires_at );
	}

	/** Validate the Kitchen-start claim response. */
	private static function validate_claim_response( $response, $expected_pairing_id ) {
		$pairing_id = isset( $response['pairing_id'] ) ? (string) $response['pairing_id'] : '';
		$status = isset( $response['status'] ) ? (string) $response['status'] : '';
		$completion_token = isset( $response['completion_token'] ) ? (string) $response['completion_token'] : '';
		$expires_at = isset( $response['expires_at'] ) ? strtotime( $response['expires_at'] ) : false;
		if ( self::PROTOCOL_VERSION !== intval( isset( $response['protocol_version'] ) ? $response['protocol_version'] : 0 ) || $expected_pairing_id !== $pairing_id || 'claimed' !== $status || ! self::valid_secret( $completion_token ) || ! $expires_at || time() >= $expires_at || time() + self::PAIRING_TTL < $expires_at ) {
			return new WP_Error( 'wprm_kitchen_invalid_response', __( 'WPRM Kitchen returned invalid claim details.', 'wp-recipe-maker' ), array( 'status' => 502 ) );
		}

		return array( 'completion_token' => $completion_token, 'expires_at' => $expires_at );
	}

	/** Validate the successful complete response and encrypt its callback secret. */
	private static function validate_connection_response( $response ) {
		$id = isset( $response['connection_id'] ) ? (string) $response['connection_id'] : '';
		$status = isset( $response['status'] ) ? (string) $response['status'] : '';
		$callback_secret = isset( $response['callback_secret'] ) ? (string) $response['callback_secret'] : '';
		$events_path = isset( $response['events_path'] ) ? (string) $response['events_path'] : '';
		$expected_path = WPRM_Kitchen_Client::path( 'events', $id );
		if ( self::PROTOCOL_VERSION !== intval( isset( $response['protocol_version'] ) ? $response['protocol_version'] : 0 ) || ! self::valid_opaque_id( $id ) || 'connected' !== $status || ! self::valid_secret( $callback_secret ) || is_wp_error( $expected_path ) || $expected_path !== $events_path ) {
			return new WP_Error( 'wprm_kitchen_invalid_response', __( 'WPRM Kitchen returned invalid connection details.', 'wp-recipe-maker' ), array( 'status' => 502 ) );
		}
		$encrypted = self::encrypt_callback_secret( $callback_secret );
		unset( $callback_secret );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		return array(
			'id' => $id,
			'status' => 'connected',
			'events_path' => $events_path,
			'callback_secret_encrypted' => $encrypted,
		);
	}

	/** Build a safe status object. */
	private static function get_status() {
		$origin = WPRM_Kitchen_Client::get_origin();
		$base = array(
			'state' => 'disconnected',
			'connected' => false,
			'premium_active' => WPRM_Addons::is_active( 'premium' ),
			'kitchen_origin' => is_wp_error( $origin ) ? '' : $origin,
		);
		if ( is_wp_error( $origin ) ) {
			$base['state'] = 'error';
			$base['error'] = array( 'code' => $origin->get_error_code(), 'message' => $origin->get_error_message() );
			return $base;
		}

		$connection = self::get_connection();
		if ( $connection ) {
			$base['state'] = 'connected';
			$base['connected'] = true;
			$base['connection'] = array(
				'id' => isset( $connection['id'] ) ? $connection['id'] : '',
				'connected_at' => isset( $connection['connected_at'] ) ? $connection['connected_at'] : '',
			);
			return $base;
		}

		$pending = self::get_pending();
		if ( $pending ) {
			$base['state'] = $pending['state'];
			$base['direction'] = $pending['direction'];
			$base['expires_at'] = gmdate( 'c', $pending['expires_at'] );
			if ( 'awaiting_kitchen' === $pending['state'] ) {
				$base['authorize_url'] = $pending['authorize_url'];
			} elseif ( 'awaiting_approval' === $pending['state'] ) {
				$base['action_required'] = 'approve';
			} elseif ( 'callback_received' === $pending['state'] ) {
				$base['action_required'] = 'complete';
			}
			return $base;
		}

		$error = get_option( self::ERROR_OPTION, false );
		if ( is_array( $error ) && ! empty( $error['code'] ) ) {
			$base['state'] = 'error';
			$base['error'] = array( 'code' => $error['code'], 'message' => $error['message'] );
		}

		return $base;
	}

	/** Get connection metadata, including only encrypted callback material. */
	private static function get_connection() {
		$connection = get_option( self::CONNECTION_OPTION, false );

		return is_array( $connection ) && ! empty( $connection['id'] ) ? $connection : false;
	}

	/** Get pending metadata, expiring it when needed. */
	private static function get_pending() {
		$pending = get_option( self::PENDING_OPTION, false );
		if ( ! is_array( $pending ) || empty( $pending['pending_key'] ) || empty( $pending['expires_at'] ) ) {
			return false;
		}
		if ( time() >= intval( $pending['expires_at'] ) ) {
			self::expire_pending();
			return false;
		}

		return $pending;
	}

	/** Store pending public metadata separately from transient secrets. */
	private static function set_pending( $metadata, $secrets, $ttl ) {
		$ttl = min( self::PAIRING_TTL, max( 1, intval( $ttl ) ) );
		$key = bin2hex( self::random_bytes( 16 ) );
		$metadata['pending_key'] = $key;
		$metadata['expires_at'] = time() + $ttl;
		update_option( self::PENDING_OPTION, $metadata, false );
		set_transient( self::TRANSIENT_PREFIX . $key, $secrets, $ttl );
	}

	/** Remove pending metadata and its transient secrets. */
	private static function clear_pending() {
		$pending = get_option( self::PENDING_OPTION, false );
		if ( is_array( $pending ) && ! empty( $pending['pending_key'] ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $pending['pending_key'] );
		}
		delete_option( self::PENDING_OPTION );
	}

	/** Expire pending state with an actionable error. */
	private static function expire_pending() {
		self::clear_pending();
		$error = new WP_Error( 'wprm_kitchen_pairing_expired', __( 'The WPRM Kitchen pairing expired. Start a new connection.', 'wp-recipe-maker' ), array( 'status' => 410 ) );
		self::remember_error( $error );

		return $error;
	}

	/** Store a safe, non-secret error for the admin UI. */
	private static function remember_error( $error ) {
		if ( is_wp_error( $error ) ) {
			update_option( self::ERROR_OPTION, array(
				'code' => sanitize_key( $error->get_error_code() ),
				'message' => sanitize_text_field( $error->get_error_message() ),
				'at' => time(),
			), false );
		}
	}

	/** Clear the retained safe error. */
	private static function clear_error() {
		delete_option( self::ERROR_OPTION );
	}

	/** Stable site descriptor shared by both directions. */
	private static function site_descriptor() {
		return array(
			'site_id' => self::get_site_id(),
			'site_url' => untrailingslashit( home_url() ),
			'api_url' => self::api_url(),
			'site_name' => get_bloginfo( 'name' ),
			'wprm_version' => WPRM_VERSION,
			'api_version' => 1,
			'premium_active' => WPRM_Addons::is_active( 'premium' ),
		);
	}

	/** Stable random non-secret site id stored in a non-autoloaded option. */
	public static function get_site_id() {
		$site_id = get_option( self::SITE_ID_OPTION, '' );
		if ( ! is_string( $site_id ) || ! preg_match( '/^[a-f0-9]{32}$/', $site_id ) ) {
			$site_id = bin2hex( self::random_bytes( 16 ) );
			update_option( self::SITE_ID_OPTION, $site_id, false );
		}

		return $site_id;
	}

	/** Canonical app API URL. */
	private static function api_url() {
		return rtrim( get_rest_url( null, 'wp-recipe-maker/v1/app' ), '/' );
	}

	/** Fixed wp-admin authorize/callback URL. */
	private static function authorize_url() {
		return add_query_arg( array( 'page' => 'wprm_settings' ), admin_url( 'admin.php' ) );
	}

	/** Validate an opaque Kitchen resource id. */
	private static function valid_opaque_id( $value ) {
		return is_string( $value ) && 8 <= strlen( $value ) && 200 >= strlen( $value ) && (bool) preg_match( '/^[A-Za-z0-9._~-]+$/', $value );
	}

	/** Validate high-entropy URL-safe state/code material. */
	private static function valid_secret( $value ) {
		return is_string( $value ) && 32 <= strlen( $value ) && 256 >= strlen( $value ) && (bool) preg_match( '/^[A-Za-z0-9._~-]+$/', $value );
	}

	/** Encrypt the event-only callback secret before persistent storage. */
	private static function encrypt_callback_secret( $secret ) {
		$key = self::callback_encryption_key();
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = self::random_bytes( 12 );
			$tag = '';
			$ciphertext = openssl_encrypt( $secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $ciphertext && 16 === strlen( $tag ) ) {
				return array(
					'version' => 'aes-256-gcm-v1',
					'iv' => base64_encode( $iv ),
					'tag' => base64_encode( $tag ),
					'ciphertext' => base64_encode( $ciphertext ),
				);
			}
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = self::random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return array(
				'version' => 'secretbox-v1',
				'nonce' => base64_encode( $nonce ),
				'ciphertext' => base64_encode( sodium_crypto_secretbox( $secret, $nonce, $key ) ),
			);
		}

		return new WP_Error( 'wprm_kitchen_encryption_unavailable', __( 'This server cannot safely store the WPRM Kitchen callback credential.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
	}

	/** Decrypt the event-only callback secret only for an outbound event. */
	private static function decrypt_callback_secret( $encrypted ) {
		if ( ! is_array( $encrypted ) || empty( $encrypted['version'] ) || empty( $encrypted['ciphertext'] ) ) {
			return self::callback_decryption_error();
		}

		$key = self::callback_encryption_key();
		$ciphertext = base64_decode( $encrypted['ciphertext'], true );
		$secret = false;
		if ( 'aes-256-gcm-v1' === $encrypted['version'] && function_exists( 'openssl_decrypt' ) && isset( $encrypted['iv'], $encrypted['tag'] ) ) {
			$iv = base64_decode( $encrypted['iv'], true );
			$tag = base64_decode( $encrypted['tag'], true );
			if ( false !== $ciphertext && false !== $iv && false !== $tag ) {
				$secret = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			}
		} elseif ( 'secretbox-v1' === $encrypted['version'] && function_exists( 'sodium_crypto_secretbox_open' ) && isset( $encrypted['nonce'] ) ) {
			$nonce = base64_decode( $encrypted['nonce'], true );
			if ( false !== $ciphertext && false !== $nonce ) {
				$secret = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
			}
		}

		return is_string( $secret ) && self::valid_secret( $secret ) ? $secret : self::callback_decryption_error();
	}

	/** Derive an encryption key from WordPress secrets without persisting it. */
	private static function callback_encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|wprm-kitchen-callback-v1|' . self::get_site_id(), true );
	}

	/** Stable callback decryption error that never includes secret material. */
	private static function callback_decryption_error() {
		return new WP_Error( 'wprm_kitchen_callback_unavailable', __( 'The WPRM Kitchen callback credential can no longer be decrypted. Disconnect and reconnect the site.', 'wp-recipe-maker' ), array( 'status' => 500 ) );
	}

	/** Generate cryptographic random bytes with a WordPress-compatible fallback. */
	private static function random_bytes( $length ) {
		try {
			return random_bytes( $length );
		} catch ( Exception $e ) {
			return substr( hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ), true ), 0, $length );
		}
	}

	/** Generate base64url random material. */
	private static function random_base64url( $length ) {
		return self::base64url_encode( self::random_bytes( $length ) );
	}

	/** Encode without URL-unsafe padding. */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** Stable invalid-pairing error. */
	private static function invalid_pairing_error() {
		return new WP_Error( 'wprm_kitchen_invalid_pairing', __( 'The WPRM Kitchen pairing details are invalid or no longer active.', 'wp-recipe-maker' ), array( 'status' => 400 ) );
	}

	/** Stable not-connected error. */
	private static function not_connected_error() {
		return new WP_Error( 'wprm_kitchen_not_connected', __( 'This site is not connected to WPRM Kitchen.', 'wp-recipe-maker' ), array( 'status' => 409 ) );
	}
}

WPRM_Api_App_Kitchen::init();
