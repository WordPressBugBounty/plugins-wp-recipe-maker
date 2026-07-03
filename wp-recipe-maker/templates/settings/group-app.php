<?php

$app = array(
	'id' => 'companionApp',
	'icon' => 'monitor-mobile',
	'name' => __( 'Companion App', 'wp-recipe-maker' ),
	'required' => 'premium',
	'subGroups' => array(
		array(
			'name' => __( 'Companion App Access', 'wp-recipe-maker' ),
			'description' => __( 'Access your recipes and analytics on the go with the WP Recipe Maker companion app. The companion app is currently in beta and only available to selected users. Generate an access token for each device and add it in the app by scanning the QR code.', 'wp-recipe-maker' ),
			'documentation' => 'https://help.bootstrapped.ventures/article/companion-app',
			'settings' => array(
				array(
					'id' => 'app_access_tokens',
					'name' => __( 'Access Tokens', 'wp-recipe-maker' ),
					'description' => __( 'A token gives the app read access to all of your recipes, including drafts and private recipes. Revoke a token at any time to remove access for that device.', 'wp-recipe-maker' ),
					'type' => 'appAccessTokens',
				),
			),
		),
	),
);
