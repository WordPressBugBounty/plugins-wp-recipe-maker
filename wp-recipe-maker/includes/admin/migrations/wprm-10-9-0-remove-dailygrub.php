<?php
/**
 * Remove leftover DailyGrub integration data.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.9.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/admin/migrations
 */

// Read the stored option directly; unknown keys are not part of the settings structure.
$stored_settings = get_option( 'wprm_settings', array() );

if ( is_array( $stored_settings ) && ( array_key_exists( 'honey_home_integration', $stored_settings ) || array_key_exists( 'honey_home_token', $stored_settings ) ) ) {
	unset( $stored_settings['honey_home_integration'], $stored_settings['honey_home_token'] );
	update_option( 'wprm_settings', $stored_settings );

	// Unprefixed name, so only delete it on sites that used the integration (saving a token also saved these keys).
	delete_option( 'hh_integration_status' );
}
