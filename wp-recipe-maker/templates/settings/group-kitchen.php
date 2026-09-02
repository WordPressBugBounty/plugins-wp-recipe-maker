<?php

$kitchen = array(
	'id' => 'wprmKitchen',
	'icon' => 'plug',
	'name' => __( 'WPRM Kitchen', 'wp-recipe-maker' ),
	'subGroups' => array(
		array(
			'name' => __( 'Connection', 'wp-recipe-maker' ),
			'description' => __( 'Securely connect this site to your WPRM Kitchen workspace. Connection status is available on all sites; recipe and analytics access still requires WP Recipe Maker Premium.', 'wp-recipe-maker' ),
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
