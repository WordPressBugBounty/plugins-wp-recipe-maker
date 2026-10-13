<?php
/** Modern-template API using connection-wide Kitchen access. No Premium gate for free templates. */
class WPRM_Api_App_Templates {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		add_action( 'wprm_template_operation_cleanup', array( 'WPRM_Template_Store', 'prune' ) );
		add_action( 'init', function () { if ( ! wp_next_scheduled( 'wprm_template_operation_cleanup' ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wprm_template_operation_cleanup' ); } } );
	}
	public static function register() {
		foreach ( array( '/templates' => array( 'GET', 'inventory' ), '/templates/(?P<local_id>[a-f0-9-]{32,36})' => array( 'GET', 'export' ), '/templates/import' => array( 'POST', 'import' ) ) as $route => $handler ) {
			register_rest_route( 'wp-recipe-maker/v1', '/app' . $route, array( 'methods' => $handler[0], 'callback' => array( __CLASS__, $handler[1] ), 'permission_callback' => array( __CLASS__, 'permissions' ) ) );
		}
	}
	public static function permissions( $request ) {
		return WPRM_Api_App::api_app_token_only_permissions( $request );
	}
	public static function inventory() {
		$all = WPRM_Template_Manager::get_templates();
		$in_use = array();
		foreach ( WPRM_Settings::get_details() as $id => $setting ) {
			if ( isset( $setting['type'] ) && 'dropdownTemplateModern' === $setting['type'] ) {
				$slug = WPRM_Settings::get( $id );
				if ( is_string( $slug ) && '' !== $slug ) { $in_use[ $slug ][] = $id; }
			}
		}
		$items = array();
		foreach ( $all['modern'] as $t ) {
			$p = WPRM_Template_Package::export( $t );
			$items[] = array( 'local_id' => $t['localTemplateId'], 'slug' => $t['slug'], 'name' => $t['name'], 'type' => $t['type'], 'revision' => $t['revision'], 'editable' => 'database' === $t['location'], 'checksum' => $p['checksum'], 'algorithm' => $p['algorithm'], 'kitchen' => $t['kitchen'] ?? null, 'in_use' => array_slice( $in_use[ $t['slug'] ] ?? array(), 0, 32 ) );
		}
		$features = array(); foreach ( array( 'premium', 'unit-conversion', 'custom-fields', 'recipe-collections' ) as $feature ) { if ( WPRM_Addons::is_active( $feature ) ) { $features[] = $feature; } }
		// Versions keep Kitchen's install compatibility current on every refresh, not only at pairing.
		return array( 'complete' => true, 'templates' => $items, 'features' => $features, 'algorithms' => array( WPRM_Template_Package::ALGORITHM ), 'wprm_version' => WPRM_VERSION, 'premium_active' => WPRM_Addons::is_active( 'premium' ), 'premium_version' => WPRM_Template_Package::premium_version() );
	}
	public static function export( $request ) {
		$t = WPRM_Template_Store::find( $request['local_id'] );
		if ( ! $t ) { return new WP_Error( 'wprm_template_missing', 'Template not found.', array( 'status' => 404 ) ); }
		$p = WPRM_Template_Package::export( $t );
		$validated = WPRM_Template_Package::validate( $p, false );
		if ( is_wp_error( $validated ) ) { return $validated; }
		return array( 'local_id' => $t['localTemplateId'], 'revision' => $t['revision'], 'package' => $p );
	}
	public static function import( $request ) { return WPRM_Template_Store::import( $request->get_json_params() ); }
}
WPRM_Api_App_Templates::init();
