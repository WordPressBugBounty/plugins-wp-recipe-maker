<?php
/**
 * Use for debugging.
 *
 * @link       https://bootstrapped.ventures
 * @since      8.9.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Use for debugging.
 *
 * @since      8.9.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Debug {

	// Change to enable debugging.
	private static $debugging = false; // Alternatively set WPRM_DEBUGGING constant in wp-config.
	private static $debugger_user_id = false; // Optionally set to only show debug info to this specific user ID.

	// Internal variables.
	private static $log = array();
	private static $metadata_outputs = array();

	/**
	 * Use for debugging something in the footer of the page, after everything is loaded.
	 *
	 * @since    8.9.0
	 */
	public static function debug_in_footer() {
	}

	/**
	 * Only init debugging when user information is available.
	 *
	 * @since    8.9.0
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'debugging_init' ) );
	}

	/**
	 * Register actions and filters.
	 *
	 * @since    8.9.0
	 */
	public static function debugging_init() {
		if ( self::debugging() ) {
			add_action( 'wp_footer', array( __CLASS__, 'show_debug_info' ), 99999 );
			add_action( 'admin_footer', array( __CLASS__, 'show_debug_info' ), 99999 );
		}
	}

	/**
	 * Check if we are debugging.
	 *
	 * @since	8.9.0
	 */
	public static function debugging() {
		if ( self::$debugging || ( defined( 'WPRM_DEBUGGING' ) && WPRM_DEBUGGING ) || isset( $_GET['wprm_debug'] ) ) {
			// We are actually debugging, now check the user.
			if ( self::$debugger_user_id ) {
				// If debugger user ID is set, only show debug info to that specific user.
				return self::$debugger_user_id === get_current_user_id();
			} else {
				// If debugger user ID is not set, show debug info to all admins.
				return current_user_can( 'manage_options' );
			}
		}

		return false;
	}

	/**
	 * Log a message for debugging.
	 *
	 * @since	8.9.0
	 */
	public static function log( ...$args ) {
		$dbt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
        $caller_class = isset($dbt[1]['class']) ? $dbt[1]['class'] : '';
        $caller_function = isset($dbt[1]['function']) ? $dbt[1]['function'] : '';

		$log = array(
			'timestamp' => time(),
			'caller' => $caller_class . '::' . $caller_function . '()',
			'context' => '',
			'message' => '',
		);

		if ( count( $args ) > 1 ) {
			$log['context'] = $args[0];
			$log['message'] = $args[1];
		} else {
			$log['message'] = $args[0];
		}

		self::$log[] = $log;
	}

	/**
	 * Track metadata output for this request.
	 *
	 * @since	10.3.0
	 * @param	array $entry Metadata output details.
	 */
	public static function track_metadata_output( $entry ) {
		if ( ! self::debugging() || ! is_array( $entry ) || ! isset( $entry['payload'] ) || ! is_array( $entry['payload'] ) ) {
			return false;
		}

		$payload = $entry['payload'];
		$type = isset( $entry['type'] ) && $entry['type'] ? $entry['type'] : ( isset( $payload['@type'] ) ? $payload['@type'] : 'Metadata' );
		$source = isset( $entry['source'] ) ? sanitize_key( $entry['source'] ) : '';
		$object_id = isset( $entry['object_id'] ) ? intval( $entry['object_id'] ) : 0;

		self::$metadata_outputs[] = array(
			'type' => is_string( $type ) ? $type : 'Metadata',
			'source' => $source,
			'label' => isset( $entry['label'] ) && $entry['label'] ? wp_strip_all_tags( $entry['label'] ) : self::get_metadata_output_label( $type, $source, $object_id ),
			'object_id' => $object_id,
			'payload' => $payload,
		);

		return true;
	}

	/**
	 * Get metadata outputs tracked for this request.
	 *
	 * @since	10.3.0
	 */
	public static function get_tracked_metadata_outputs() {
		return self::$metadata_outputs;
	}

	/**
	 * Get a default label for tracked metadata output.
	 *
	 * @since	10.3.0
	 * @param	mixed $type Metadata type.
	 * @param	string $source Metadata source.
	 * @param	int    $object_id Associated object ID.
	 */
	private static function get_metadata_output_label( $type, $source, $object_id ) {
		$type = is_string( $type ) && $type ? $type : 'Metadata';
		$source_label = $source ? str_replace( '_shortcode', '', $source ) : 'output';

		if ( 'Recipe' === $type || 'HowTo' === $type ) {
			if ( $object_id ) {
				return sprintf( '%1$s #%2$d (%3$s)', $type, $object_id, $source_label );
			}

			return sprintf( '%1$s (%2$s)', $type, $source_label );
		}

		if ( $object_id ) {
			return sprintf( '%1$s #%2$d (%3$s)', $type, $object_id, $source_label );
		}

		return sprintf( '%1$s (%2$s)', $type, $source_label );
	}

	/**
	 * Show debug information.
	 *
	 * @since    8.9.0
	 */
	public static function show_debug_info() {
		if ( self::debugging() ) {
			// Last minute debug.
			self::debug_in_footer();

			if ( count( self::$log ) ) {
				// Show debug info.
				self::debug_info_css();
				echo '<div id="wprm-debug-spacer" style="height:270px"></div>';
				echo '<div id="wprm-debug-info">';
				echo '<strong>WPRM Debugging</strong>';
				echo '<br/><br/>';
				echo '<div id="wprm-debug-log">';

				foreach ( self::$log as $log ) {
					echo '<div class="wprm-debug-log-entry">';
					echo '<div class="wprm-debug-log-entry-timestamp">' . esc_html( $log['timestamp'] ) . '</div>';
					echo '<div class="wprm-debug-log-entry-caller">' . esc_html( $log['caller'] ) . '</div>';
					echo '<div class="wprm-debug-log-entry-context">' . esc_html( $log['context'] ) . '</div>';

					echo '<div class="wprm-debug-log-entry-message">';
					if ( is_string( $log['message'] ) ) {
						echo '<pre>' . esc_html( var_export( htmlspecialchars( $log['message'] ), true ) ) . '</pre>';
					} else {
						echo '<pre>' . esc_html( var_export( $log['message'], true ) ) . '</pre>';
					}
					echo '</div>';

					echo '</div>';
				}

				echo '</div>';
				echo '</div>';
			}
		}
	}

	/**
	 * Debug info CSS.
	 *
	 * @since    8.9.0
	 */
	public static function debug_info_css() {
		$left_position = is_admin() ? '160px' : '0';

		echo '<style>';
		echo '#wprm-debug-info { position: fixed; bottom: 0; left: ' . esc_attr( $left_position ) . '; right: 0; background-color: white; border-top: 2px dotted darkred; z-index: 9999999; height: 250px; overflow-y: scroll; padding: 20px; font-size: 12px; }';
		echo '.wprm-debug-log-entry { display: flex; border-top: 1px dashed black; padding: 5px; }';
		echo '.wprm-debug-log-entry:first-child { border-top: none; }';
		echo '.wprm-debug-log-entry-timestamp { flex-basis: 80px; margin-right: 10px; }';
		echo '.wprm-debug-log-entry-caller { flex-basis: 200px; margin-right: 10px; }';
		echo '.wprm-debug-log-entry-context { flex-basis: 150px; margin-right: 10px; }';
		echo '.wprm-debug-log-entry-message { flex: 1; }';
		echo '.wprm-debug-log-entry-message pre { margin: 0; }';
		echo '</style>';
	}
}

WPRM_Debug::init();
