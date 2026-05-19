<?php
/**
 * Template for the plugin settings structure.
 *
 * @link       https://bootstrapped.ventures
 * @since      9.1.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/templates/settings
 */

$amazon_stores = array(
	'australia' => array(
		'label' => 'Australia',
		'host' => 'webservices.amazon.com.au',
		'region' => 'us-west-2',
		'marketplace' => 'www.amazon.com.au',
		'credential_version' => '2.3',
		'auth_region' => 'ap-southeast-1',
	),
	'belgium' => array(
		'label' => 'Belgium',
		'host' => 'webservices.amazon.com.be',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.com.be',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'brazil' => array(
		'label' => 'Brazil',
		'host' => 'webservices.amazon.com.br',
		'region' => 'us-east-1',
		'marketplace' => 'www.amazon.com.br',
		'credential_version' => '2.1',
		'auth_region' => 'us-west-2',
	),
	'canada' => array(
		'label' => 'Canada',
		'host' => 'webservices.amazon.ca',
		'region' => 'us-east-1',
		'marketplace' => 'www.amazon.ca',
		'credential_version' => '2.1',
		'auth_region' => 'us-west-2',
	),
	'egypt' => array(
		'label' => 'Egypt',
		'host' => 'webservices.amazon.eg',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.eg',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'france' => array(
		'label' => 'France',
		'host' => 'webservices.amazon.fr',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.fr',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'germany' => array(
		'label' => 'Germany',
		'host' => 'webservices.amazon.de',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.de',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'india' => array(
		'label' => 'India',
		'host' => 'webservices.amazon.in',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.in',
		'credential_version' => '2.3',
		'auth_region' => 'ap-southeast-1',
	),
	'italy' => array(
		'label' => 'Italy',
		'host' => 'webservices.amazon.it',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.it',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'japan' => array(
		'label' => 'Japan',
		'host' => 'webservices.amazon.co.jp',
		'region' => 'us-west-2',
		'marketplace' => 'www.amazon.co.jp',
		'credential_version' => '2.3',
		'auth_region' => 'ap-southeast-1',
	),
	'mexico' => array(
		'label' => 'Mexico',
		'host' => 'webservices.amazon.com.mx',
		'region' => 'us-east-1',
		'marketplace' => 'www.amazon.com.mx',
		'credential_version' => '2.1',
		'auth_region' => 'us-west-2',
	),
	'netherlands' => array(
		'label' => 'Netherlands',
		'host' => 'webservices.amazon.nl',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.nl',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'poland' => array(
		'label' => 'Poland',
		'host' => 'webservices.amazon.pl',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.pl',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'singapore' => array(
		'label' => 'Singapore',
		'host' => 'webservices.amazon.sg',
		'region' => 'us-west-2',
		'marketplace' => 'www.amazon.sg',
		'credential_version' => '2.3',
		'auth_region' => 'ap-southeast-1',
	),
	'saudi_arabia' => array(
		'label' => 'Saudi Arabia',
		'host' => 'webservices.amazon.sa',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.sa',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'spain' => array(
		'label' => 'Spain',
		'host' => 'webservices.amazon.es',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.es',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'sweden' => array(
		'label' => 'Sweden',
		'host' => 'webservices.amazon.se',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.se',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'turkey' => array(
		'label' => 'Turkey',
		'host' => 'webservices.amazon.com.tr',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.com.tr',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'united_arab_emirates' => array(
		'label' => 'United Arab Emirates',
		'host' => 'webservices.amazon.ae',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.ae',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'united_kingdom' => array(
		'label' => 'United Kingdom',
		'host' => 'webservices.amazon.co.uk',
		'region' => 'eu-west-1',
		'marketplace' => 'www.amazon.co.uk',
		'credential_version' => '2.2',
		'auth_region' => 'eu-west-1',
	),
	'united_states' => array(
		'label' => 'United States',
		'host' => 'webservices.amazon.com',
		'region' => 'us-east-1',
		'marketplace' => 'www.amazon.com',
		'credential_version' => '2.1',
		'auth_region' => 'us-west-2',
	),
);

$amazon_stores_dropdown = array_map( function( $store ) {
	return $store['label'];
}, $amazon_stores );

$amazon_product_status_options = array(
	'AVAILABLE_DATE' => __( 'Available Date', 'wp-recipe-maker' ),
	'IN_STOCK' => __( 'In Stock', 'wp-recipe-maker' ),
	'IN_STOCK_SCARCE' => __( 'In Stock (Scarce)', 'wp-recipe-maker' ),
	'LEADTIME' => __( 'Leadtime', 'wp-recipe-maker' ),
	'OUT_OF_STOCK' => __( 'Out of Stock', 'wp-recipe-maker' ),
	'PREORDER' => __( 'Preorder', 'wp-recipe-maker' ),
	'UNAVAILABLE' => __( 'Unavailable', 'wp-recipe-maker' ),
	'UNKNOWN' => __( 'Unknown', 'wp-recipe-maker' ),
	'NOT_FOUND' => __( 'Not Found', 'wp-recipe-maker' ),
);

$amazon = array(
	'id' => 'amazon',
	'icon' => 'basket',
	'name' => __( 'Amazon Products', 'wp-recipe-maker' ),
	'required' => 'premium',
	'description' => __( 'Use the Amazon Product API to easily search for Amazon products to link to your equipment.', 'wp-recipe-maker' ),
	'documentation' => 'https://help.bootstrapped.ventures/article/336-amazon-products',
	'subGroups' => array(
		array(
			'name' => __( 'General', 'wp-recipe-maker' ),
			'settings' => array(
				array(
					'id' => 'amazon_store',
					'name' => __( 'Amazon Store', 'wp-recipe-maker' ),
					'description' => __( 'The Amazon store to use for your affiliate links.', 'wp-recipe-maker' ),
					'type' => 'dropdown',
					'options' => $amazon_stores_dropdown,
					'default' => 'united_states',
				),
				array(
					'id' => 'amazon_partner_tag',
					'name' => __( 'Amazon Store ID', 'wp-recipe-maker' ),
					'description' => __( 'Make sure this is the partner tag or tracking ID for the store selected above.', 'wp-recipe-maker' ),
					'type' => 'text',
					'default' => '',
				),
				array(
					'id' => 'amazon_api_type',
					'name' => __( 'API Type', 'wp-recipe-maker' ),
					'description' => __( 'Choose which Amazon API to use. "Automatically Switch" will detect which credentials you have filled in and use Creators API if available, with PA-API as fallback.', 'wp-recipe-maker' ),
					'type' => 'dropdown',
					'options' => array(
						'auto' => __( 'Automatically Switch', 'wp-recipe-maker' ),
						'paapi' => __( 'PA-API (Product Advertising API)', 'wp-recipe-maker' ),
						'creators' => __( 'Creators API', 'wp-recipe-maker' ),
					),
					'default' => 'auto',
				),
				array(
					'id' => 'amazon_api_request_timeout',
					'name' => __( 'Amazon API Request Timeout', 'wp-recipe-maker' ),
					'description' => __( 'Maximum number of seconds to wait for Amazon API responses, between 1 and 120 seconds. Lower this to prevent slow Amazon responses from tying up server workers.', 'wp-recipe-maker' ),
					'type' => 'number',
					'suffix' => 's',
					'default' => '15',
					'sanitize' => function( $value ) {
						$value = intval( $value );
						$value = max( 1, $value );
						$value = min( 120, $value );

						return (string) $value;
					},
				),
			),
		),
		array(
			'name' => __( 'PA-API Details', 'wp-recipe-maker' ),
			'description' => __( 'Your Amazon Product Advertising API (PA-API) credentials.', 'wp-recipe-maker' ),
			'settings' => array(
				array(
					'id' => 'amazon_access_key',
					'name' => __( 'Amazon Access Key', 'wp-recipe-maker' ),
					'type' => 'text',
					'default' => '',
				),
				array(
					'id' => 'amazon_secret_key',
					'name' => __( 'Amazon Secret Key', 'wp-recipe-maker' ),
					'type' => 'text',
					'default' => '',
				),
			),
			'dependency' => array(
				'id' => 'amazon_api_type',
				'value' => 'creators',
				'type' => 'inverse',
			),
		),
		array(
			'name' => __( 'Creators API Details', 'wp-recipe-maker' ),
			'description' => __( 'Your Amazon Creators API credentials. Get these from Associates Central > Tools > CreatorsAPI.', 'wp-recipe-maker' ),
			'settings' => array(
				array(
					'id' => 'amazon_credential_id',
					'name' => __( 'Credential ID', 'wp-recipe-maker' ),
					'type' => 'text',
					'default' => '',
				),
				array(
					'id' => 'amazon_credential_secret',
					'name' => __( 'Credential Secret', 'wp-recipe-maker' ),
					'type' => 'text',
					'default' => '',
				),
				array(
					'id' => 'amazon_credential_version',
					'name' => __( 'Credential Version', 'wp-recipe-maker' ),
					'description' => __( 'Your credential version based on region: 2.1 for North America, 2.2 for Europe, 2.3 for Far East.', 'wp-recipe-maker' ),
					'type' => 'text',
					'default' => '',
				),
			),
			'dependency' => array(
				'id' => 'amazon_api_type',
				'value' => 'paapi',
				'type' => 'inverse',
			),
		),
		array(
			'name' => __( 'Product Status Notifications', 'wp-recipe-maker' ),
			'description' => __( 'Receive an email when Amazon equipment products are no longer buyable.', 'wp-recipe-maker' ),
			'settings' => array(
				array(
					'id' => 'amazon_status_notifications_enabled',
					'name' => __( 'Enable Product Status Notifications', 'wp-recipe-maker' ),
					'type' => 'toggle',
					'default' => false,
				),
				array(
					'id' => 'amazon_status_notification_emails',
					'name' => __( 'Send email to', 'wp-recipe-maker' ),
					'description' => __( 'Email addresses to notify. Separate multiple addresses with commas or new lines.', 'wp-recipe-maker' ),
					'type' => 'textarea',
					'rows' => 3,
					'default' => '',
					'sanitize' => function( $value ) {
						if ( is_array( $value ) ) {
							$value = implode( PHP_EOL, $value );
						}

						$emails = preg_split( '/[\s,;]+/', (string) $value );
						$emails = array_filter( array_map( function( $email ) {
							$email = sanitize_email( trim( $email ) );
							return is_email( $email ) ? strtolower( $email ) : '';
						}, $emails ) );
						$emails = array_values( array_unique( $emails ) );

						return implode( PHP_EOL, $emails );
					},
					'dependency' => array(
						'id' => 'amazon_status_notifications_enabled',
						'value' => true,
					),
				),
				array(
					'id' => 'amazon_status_notification_statuses',
					'name' => __( 'Notification Statuses', 'wp-recipe-maker' ),
					'description' => __( 'Amazon product statuses that should trigger an email notification.', 'wp-recipe-maker' ),
					'type' => 'dropdownMultiselect',
					'options' => $amazon_product_status_options,
					'default' => array( 'OUT_OF_STOCK', 'UNAVAILABLE', 'NOT_FOUND' ),
					'dependency' => array(
						'id' => 'amazon_status_notifications_enabled',
						'value' => true,
					),
				),
				array(
					'id' => 'amazon_status_notification_frequency',
					'name' => __( 'Notification Frequency', 'wp-recipe-maker' ),
					'description' => __( 'How often to send product status notification emails.', 'wp-recipe-maker' ),
					'type' => 'dropdown',
					'options' => array(
						'batch' => __( 'As soon as noticed', 'wp-recipe-maker' ),
						'daily' => __( 'Daily digest', 'wp-recipe-maker' ),
						'weekly' => __( 'Weekly digest', 'wp-recipe-maker' ),
					),
					'default' => 'daily',
					'dependency' => array(
						'id' => 'amazon_status_notifications_enabled',
						'value' => true,
					),
				),
			),
		),
	),
);
