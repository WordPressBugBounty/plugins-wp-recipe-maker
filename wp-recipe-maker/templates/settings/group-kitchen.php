<?php

$kitchen = array(
	'id' => 'wprmKitchen',
	'icon' => 'plug',
	'name' => __( 'WPRM Kitchen', 'wp-recipe-maker' ),
	'subGroups' => array(
		array(
			'name' => __( 'Menu', 'wp-recipe-maker' ),
			'settings' => array(
				array(
					'id' => 'kitchen_show_menu',
					'name' => __( 'Show WPRM Kitchen in Menu', 'wp-recipe-maker' ),
					'description' => __( 'Show WPRM Kitchen in the WP Recipe Maker admin menu. Reload the page after saving to update the menu.', 'wp-recipe-maker' ),
					'type' => 'toggle',
					'default' => false,
				),
			),
		),
		array(
			'name' => __( 'Connection', 'wp-recipe-maker' ),
			'description' => __( 'Securely connect this site to your WPRM Kitchen workspace. Connection status is available on all sites; recipe and analytics access still requires WP Recipe Maker Premium. Connecting shares your recipes and syncs daily aggregate analytics counts (per recipe, post and action) to Kitchen. Visitor identifiers, IP addresses and user agents are never shared. Disconnect at any time to stop syncing.', 'wp-recipe-maker' ),
			'settings' => array(
				array(
					'id' => 'kitchen_connection',
					'name' => __( 'Kitchen Connection', 'wp-recipe-maker' ),
					'description' => __( 'Connect from either WPRM Kitchen or this settings page.', 'wp-recipe-maker' ),
					'type' => 'kitchenConnection',
				),
			),
		),
	),
);
