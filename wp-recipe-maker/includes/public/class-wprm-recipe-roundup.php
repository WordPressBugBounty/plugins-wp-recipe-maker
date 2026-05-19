<?php
/**
 * Handle the recipe roundup feature.
 *
 * @link       https://bootstrapped.ventures
 * @since      4.3.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 */

/**
 * Handle the recipe roundup feature.
 *
 * @since      4.3.0
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/includes/public
 * @author     Brecht Vandersmissen <brecht@bootstrapped.ventures>
 */
class WPRM_Recipe_Roundup {

	/**
	 * Roundup overrides.
	 *
	 * @since	8.0.0
	 * @access	private
	 * @var		array $roundup_overrides Overrides to use for recipe values in the roundup.
	 */
	private static $roundup_overrides = array();

	/**
	 * Cached ItemList metadata resolution per post.
	 *
	 * @since	10.3.0
	 * @access	private
	 * @var		array $itemlist_metadata_cache Cached ItemList sources and payloads.
	 */
	private static $itemlist_metadata_cache = array();

	/**
	 * Track rendered list occurrences per post.
	 *
	 * @since	10.3.0
	 * @access	private
	 * @var		array $list_render_counts Count of rendered list instances.
	 */
	private static $list_render_counts = array();

	/**
	 * Register actions and filters.
	 *
	 * @since    1.0.0
	 */
	public static function init() {
		add_shortcode( 'wprm-recipe-roundup-item', array( __CLASS__, 'shortcode' ) );

		add_action( 'init', array( __CLASS__, 'meta_fields_in_rest' ) );
		add_action( 'wp_head', array( __CLASS__, 'metadata_in_head' ), 2 );
	}

	/**
	 * Output itemlist metadata in the HTML head.
	 *
	 * @since    4.3.0
	 */
	public static function metadata_in_head() {
		if ( self::can_output_singular_itemlist_metadata() ) {
			$post = self::get_current_singular_post();

			if ( $post ) {
				$payloads = self::get_resolved_itemlist_metadata_payloads( $post );

				foreach ( $payloads as $payload ) {
					if ( 'head' === $payload['location'] ) {
						$debug_context = array(
							'source' => 'head',
							'label' => isset( $payload['list_id'] ) ? sprintf( 'ItemList List #%d (head)', $payload['list_id'] ) : 'ItemList (head)',
							'object_id' => isset( $payload['list_id'] ) ? intval( $payload['list_id'] ) : $post->ID,
						);
						self::output_itemlist_metadata( $payload['url'], $payload['name'], $payload['description'], $payload['post_ids'], $debug_context );
					}
				}
			}
		}

		// Archive pages.
		if ( is_archive() && WPRM_Settings::get( 'itemlist_metadata_archive_pages' ) ) {
			self::list_metadata_for_archive_pages();	
		}
	}

	/**
	 * Output list metadata for archive pages.
	 *
	 * @since	8.0.0
	 */
	public static function list_metadata_for_archive_pages() {
		global $wp_query;

		$recipe_ids = array();
		foreach ( $wp_query->posts as $post ) {
			if ( WPRM_POST_TYPE === $post->post_type ) {
				$recipe_ids[] = $post->ID;
			} else if ( 'all' === WPRM_Settings::get( 'itemlist_metadata_archive_pages_post_types' ) ) {
				$recipe_ids_in_post = WPRM_Recipe_Manager::get_recipe_ids_from_post( $post->ID );

				if ( $recipe_ids_in_post ) {
					if ( ! WPRM_Settings::get( 'metadata_only_show_for_first_recipe' ) ) {
						// Output metadata for all recipes.
						$recipe_ids = array_merge( $recipe_ids, $recipe_ids_in_post );
					} else {
						// Only add metadata for first food recipe on page.
						foreach ( $recipe_ids_in_post as $recipe_id_in_post ) {
							$recipe = WPRM_Recipe_Manager::get_recipe( $recipe_id_in_post );
	
							if ( $recipe && 'other' !== $recipe->type() ) {
								$recipe_ids[] = $recipe_id_in_post;
								break;
							}
						}
					}
				}
			}
		}

		if ( 1 < count( $recipe_ids ) ) {
			// TODO term name.
			$http_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . '://' . $http_host . $request_uri;
			self::output_itemlist_metadata(
				$url,
				'',
				'',
				$recipe_ids,
				array(
					'source' => 'archive',
					'label' => 'ItemList (archive)',
					'object_id' => get_queried_object_id(),
				)
			);
		}
	}

	/**
	 * Output ItemList metadata for a set of recipe ids.
	 *
	 * @since	8.0.0
	 * @param	mixed $url			URL of the roundup page.
	 * @param	mixed $name			Name for the ItemList.
	 * @param	mixed $description	Description for the ItemList.
	 * @param	mixed $post_ids 	IDs of the posts to get the ItemList metadata for.
	 * @param	array $debug_context Optional context for debug tracking.
	 */
	public static function output_itemlist_metadata( $url, $name, $description, $post_ids, $debug_context = array() ) {
		$metadata = self::get_itemlist_metadata( $url, $name, $description, $post_ids );

		if ( $metadata ) {
			$source = isset( $debug_context['source'] ) ? $debug_context['source'] : 'head';
			$label = isset( $debug_context['label'] ) && $debug_context['label'] ? $debug_context['label'] : sprintf( 'ItemList (%s)', str_replace( '_shortcode', '', $source ) );
			$object_id = isset( $debug_context['object_id'] ) ? intval( $debug_context['object_id'] ) : 0;

			WPRM_Debug::track_metadata_output(
				array(
					'type' => 'ItemList',
					'source' => $source,
					'label' => $label,
					'object_id' => $object_id,
					'payload' => $metadata,
				)
			);

			echo '<script type="application/ld+json">' . wp_json_encode( $metadata ) . '</script>';
		}
	}

	/**
	 * Build ItemList metadata for a set of recipe ids.
	 *
	 * @since	10.3.0
	 * @param	mixed $url			URL of the roundup page.
	 * @param	mixed $name			Name for the ItemList.
	 * @param	mixed $description	Description for the ItemList.
	 * @param	mixed $post_ids 	IDs of the posts to get the ItemList metadata for.
	 */
	private static function get_itemlist_metadata( $url, $name, $description, $post_ids ) {
		$metadata = array(
			'@context' => 'http://schema.org',
			'@type' => 'ItemList',
			'url' => $url,
			'itemListElement' => array(),
		);

		if ( $name ) {
			$metadata['name'] = wp_strip_all_tags( $name );
		}

		if ( $description ) {
			$metadata['description'] = wp_strip_all_tags( $description );
		}

		$item_list_counter = 0;
		foreach ( $post_ids as $post_id ) {
			$url = false;
			
			if ( WPRM_POST_TYPE === get_post_type( $post_id ) ) {
				$recipe = WPRM_Recipe_Manager::get_recipe( $post_id );

				if ( $recipe ) {
					$url = $recipe->permalink();
				}
			} else {
				$url = get_permalink( $post_id );
			}

			if ( $url ) {
				$item_list_counter++;
				$metadata['itemListElement'][] = array(
					'@type'    => 'ListItem',
					'position' => $item_list_counter,
					'url'      => $url,
				);
			}
		}

		$metadata['numberOfItems'] = $item_list_counter;

		if ( 1 < $item_list_counter ) {
			return $metadata;
		}

		return false;
	}

	/**
	 * Check if singular roundup ItemList metadata can be output.
	 *
	 * @since	10.3.0
	 */
	private static function can_output_singular_itemlist_metadata() {
		return is_singular() && ( ! WPRM_Metadata::has_outputted_metadata() || false === WPRM_Settings::get( 'recipe_roundup_no_metadata_when_recipe' ) );
	}

	/**
	 * Get the current singular post.
	 *
	 * @since	10.3.0
	 */
	private static function get_current_singular_post() {
		$post = get_post();

		if ( $post instanceof WP_Post ) {
			return $post;
		}

		$post_id = get_the_ID();

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				return $post;
			}
		}

		return false;
	}

	/**
	 * Get the ItemList metadata payload for the current list render instance.
	 *
	 * @since	10.3.0
	 * @param	mixed $list List object to get metadata payload for.
	 */
	public static function get_itemlist_metadata_payload_for_list( $list ) {
		if ( ! $list || ! method_exists( $list, 'id' ) ) {
			return false;
		}

		if ( ! self::can_output_singular_itemlist_metadata() ) {
			return false;
		}

		$post = self::get_current_singular_post();
		if ( ! $post ) {
			return self::get_fallback_itemlist_metadata_payload_for_list( $list );
		}

		$post_id = $post->ID;
		$list_id = intval( $list->id() );

		if ( ! isset( self::$list_render_counts[ $post_id ] ) ) {
			self::$list_render_counts[ $post_id ] = array();
		}
		if ( ! isset( self::$list_render_counts[ $post_id ][ $list_id ] ) ) {
			self::$list_render_counts[ $post_id ][ $list_id ] = 0;
		}

		$instance_index = self::$list_render_counts[ $post_id ][ $list_id ];
		self::$list_render_counts[ $post_id ][ $list_id ]++;

		$resolved = self::get_resolved_itemlist_metadata_payloads( $post );
		foreach ( $resolved as $payload ) {
			if ( 'list' === $payload['location'] && $list_id === $payload['list_id'] && $instance_index === $payload['instance_index'] ) {
				return $payload;
			}
		}

		$sources = self::get_itemlist_metadata_sources_for_post( $post );
		foreach ( $sources as $source ) {
			if ( 'list' === $source['type'] && $list_id === $source['list_id'] && $instance_index === $source['instance_index'] ) {
				return false;
			}
		}

		return self::get_fallback_itemlist_metadata_payload_for_list( $list );
	}

	/**
	 * Get fallback ItemList metadata payload for a list render that is not discoverable from post content.
	 *
	 * @since	10.3.0
	 * @param	mixed $list List object to get metadata payload for.
	 */
	private static function get_fallback_itemlist_metadata_payload_for_list( $list ) {
		if ( ! $list || ! $list->metadata_output() ) {
			return false;
		}

		$post = self::get_current_singular_post();
		$url = $post ? get_permalink( $post ) : '';

		return array(
			'location' => 'list',
			'list_id' => intval( $list->id() ),
			'instance_index' => 0,
			'url' => $url,
			'name' => $list->metadata_name(),
			'description' => $list->metadata_description(),
			'post_ids' => self::get_deduplicated_itemlist_post_ids( self::get_internal_post_ids_for_list( $list ) ),
		);
	}

	/**
	 * Get resolved ItemList metadata payloads for a singular post.
	 *
	 * @since	10.3.0
	 * @param	mixed $post Post to resolve metadata for.
	 */
	private static function get_resolved_itemlist_metadata_payloads( $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$post_id = $post->ID;
		if ( ! isset( self::$itemlist_metadata_cache[ $post_id ]['payloads'] ) ) {
			$sources = self::get_itemlist_metadata_sources_for_post( $post );
			$payloads = array();
			$mode = WPRM_Settings::get( 'recipe_roundup_multiple_itemlist_metadata' );

			if ( ! in_array( $mode, array( 'combine_first', 'first_only', 'multiple' ), true ) ) {
				$mode = 'combine_first';
			}

			if ( 'multiple' === $mode ) {
				foreach ( $sources as $source ) {
					$payloads[] = self::get_itemlist_metadata_payload_from_source( $source );
				}
			} elseif ( ! empty( $sources ) ) {
				$first_source = $sources[0];

				if ( 'first_only' === $mode ) {
					$payloads[] = self::get_itemlist_metadata_payload_from_source(
						$first_source,
						self::get_deduplicated_itemlist_post_ids( $first_source['post_ids'] )
					);
				} else {
					$combined_post_ids = array();
					foreach ( $sources as $source ) {
						$combined_post_ids = array_merge( $combined_post_ids, $source['post_ids'] );
					}

					$payloads[] = self::get_itemlist_metadata_payload_from_source(
						$first_source,
						self::get_deduplicated_itemlist_post_ids( $combined_post_ids )
					);
				}
			}

			if ( ! isset( self::$itemlist_metadata_cache[ $post_id ] ) ) {
				self::$itemlist_metadata_cache[ $post_id ] = array();
			}
			self::$itemlist_metadata_cache[ $post_id ]['sources'] = $sources;
			self::$itemlist_metadata_cache[ $post_id ]['payloads'] = $payloads;
		}

		return self::$itemlist_metadata_cache[ $post_id ]['payloads'];
	}

	/**
	 * Get collected ItemList metadata sources for a post.
	 *
	 * @since	10.3.0
	 * @param	mixed $post Post to collect sources for.
	 */
	private static function get_itemlist_metadata_sources_for_post( $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$post_id = $post->ID;
		if ( ! isset( self::$itemlist_metadata_cache[ $post_id ]['sources'] ) ) {
			$sources = array();
			$occurrences = self::get_itemlist_source_occurrences_from_content( $post->post_content );
			$list_instance_counts = array();
			$roundup_source = false;

			foreach ( $occurrences as $occurrence ) {
				if ( 'roundup' === $occurrence['type'] ) {
					if ( ! $roundup_source ) {
						$roundup_source = array(
							'type' => 'roundup',
							'order' => $occurrence['offset'],
							'location' => 'head',
							'url' => get_permalink( $post ),
							'name' => get_post_meta( $post_id, 'wprm-recipe-roundup-name', true ),
							'description' => get_post_meta( $post_id, 'wprm-recipe-roundup-description', true ),
							'post_ids' => array(),
						);
					}

					if ( ! empty( $occurrence['post_id'] ) ) {
						$roundup_source['post_ids'][] = $occurrence['post_id'];
					}

					continue;
				}

				if ( 'list' === $occurrence['type'] && ! empty( $occurrence['list_id'] ) ) {
					$list_id = $occurrence['list_id'];
					if ( ! isset( $list_instance_counts[ $list_id ] ) ) {
						$list_instance_counts[ $list_id ] = 0;
					}
					$instance_index = $list_instance_counts[ $list_id ];
					$list_instance_counts[ $list_id ]++;

					$list = WPRM_List_Manager::get_list( $list_id );
					if ( ! $list || ! $list->metadata_output() ) {
						continue;
					}

					$post_ids = self::get_internal_post_ids_for_list( $list );
					if ( empty( $post_ids ) ) {
						continue;
					}

					$sources[] = array(
						'type' => 'list',
						'order' => $occurrence['offset'],
						'location' => 'list',
						'list_id' => $list_id,
						'instance_index' => $instance_index,
						'url' => get_permalink( $post ),
						'name' => $list->metadata_name(),
						'description' => $list->metadata_description(),
						'post_ids' => $post_ids,
					);
				}
			}

			if ( $roundup_source && ! empty( $roundup_source['post_ids'] ) ) {
				$sources[] = $roundup_source;
			}

			usort(
				$sources,
				function( $a, $b ) {
					if ( $a['order'] === $b['order'] ) {
						return 0;
					}

					return $a['order'] < $b['order'] ? -1 : 1;
				}
			);

			if ( ! isset( self::$itemlist_metadata_cache[ $post_id ] ) ) {
				self::$itemlist_metadata_cache[ $post_id ] = array();
			}
			self::$itemlist_metadata_cache[ $post_id ]['sources'] = $sources;
		}

		return self::$itemlist_metadata_cache[ $post_id ]['sources'];
	}

	/**
	 * Convert a source to a metadata payload.
	 *
	 * @since	10.3.0
	 * @param	array $source Source to convert.
	 * @param	array $post_ids Optional list of post ids to use for the payload.
	 */
	private static function get_itemlist_metadata_payload_from_source( $source, $post_ids = false ) {
		$payload = array(
			'location' => $source['location'],
			'url' => $source['url'],
			'name' => $source['name'],
			'description' => $source['description'],
			'post_ids' => false === $post_ids ? $source['post_ids'] : $post_ids,
		);

		if ( 'list' === $source['type'] ) {
			$payload['list_id'] = $source['list_id'];
			$payload['instance_index'] = $source['instance_index'];
		}

		return $payload;
	}

	/**
	 * Get ordered ItemList source occurrences from content.
	 *
	 * @since	10.3.0
	 * @param	string $content Post content to scan.
	 */
	private static function get_itemlist_source_occurrences_from_content( $content ) {
		$occurrences = array();

		$shortcode_pattern = get_shortcode_regex( array( 'wprm-list', 'wprm-recipe-roundup-item' ) );
		if ( preg_match_all( '/' . $shortcode_pattern . '/s', $content, $matches, PREG_OFFSET_CAPTURE ) && isset( $matches[2] ) ) {
			foreach ( $matches[2] as $key => $match ) {
				$tag = $match[0];
				$offset = $matches[0][ $key ][1];
				$attributes = shortcode_parse_atts( stripslashes( $matches[3][ $key ][0] ) );
				$attributes = is_array( $attributes ) ? $attributes : array();

				if ( 'wprm-list' === $tag ) {
					$list_id = isset( $attributes['id'] ) ? intval( $attributes['id'] ) : 0;
					if ( $list_id ) {
						$occurrences[] = array(
							'type' => 'list',
							'offset' => $offset,
							'list_id' => $list_id,
						);
					}
				}

				if ( 'wprm-recipe-roundup-item' === $tag ) {
					$post_id = isset( $attributes['id'] ) ? intval( $attributes['id'] ) : 0;
					if ( $post_id ) {
						$occurrences[] = array(
							'type' => 'roundup',
							'offset' => $offset,
							'post_id' => $post_id,
						);
					}
				}
			}
		}

		$block_pattern = '/<!--\s+wp:(wp\-recipe\-maker\/list|wp\-recipe\-maker\/recipe-roundup-item)(\s+(\{.*?\}))?\s+(\/)?-->/';
		if ( preg_match_all( $block_pattern, $content, $matches, PREG_OFFSET_CAPTURE ) && isset( $matches[1] ) ) {
			foreach ( $matches[1] as $key => $match ) {
				$block_name = $match[0];
				$offset = $matches[0][ $key ][1];
				$attributes_json = isset( $matches[3][ $key ][0] ) ? $matches[3][ $key ][0] : '';
				$attributes = $attributes_json ? json_decode( $attributes_json, true ) : array();
				$attributes = is_array( $attributes ) ? $attributes : array();

				if ( 'wp-recipe-maker/list' === $block_name ) {
					$list_id = isset( $attributes['id'] ) ? intval( $attributes['id'] ) : 0;
					if ( $list_id ) {
						$occurrences[] = array(
							'type' => 'list',
							'offset' => $offset,
							'list_id' => $list_id,
						);
					}
				}

				if ( 'wp-recipe-maker/recipe-roundup-item' === $block_name ) {
					$post_id = isset( $attributes['id'] ) ? intval( $attributes['id'] ) : 0;
					if ( $post_id ) {
						$occurrences[] = array(
							'type' => 'roundup',
							'offset' => $offset,
							'post_id' => $post_id,
						);
					}
				}
			}
		}

		usort(
			$occurrences,
			function( $a, $b ) {
				if ( $a['offset'] === $b['offset'] ) {
					return 0;
				}

				return $a['offset'] < $b['offset'] ? -1 : 1;
			}
		);

		return $occurrences;
	}

	/**
	 * Get internal post ids from a roundup list.
	 *
	 * @since	10.3.0
	 * @param	mixed $list List object to get ids for.
	 */
	private static function get_internal_post_ids_for_list( $list ) {
		$post_ids = array();

		if ( ! $list || ! method_exists( $list, 'items' ) ) {
			return $post_ids;
		}

		$items = $list->items();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'] ) || 'roundup' !== $item['type'] ) {
				continue;
			}

			$data = isset( $item['data'] ) ? $item['data'] : array();
			if ( ! is_array( $data ) || ! isset( $data['type'] ) ) {
				continue;
			}

			if ( ( 'internal' === $data['type'] || 'post' === $data['type'] ) && ! empty( $data['id'] ) ) {
				$post_ids[] = intval( $data['id'] );
			}
		}

		return $post_ids;
	}

	/**
	 * Deduplicate post ids while preserving their first occurrence.
	 *
	 * @since	10.3.0
	 * @param	array $post_ids Post ids to deduplicate.
	 */
	private static function get_deduplicated_itemlist_post_ids( $post_ids ) {
		$deduplicated = array();
		$seen = array();

		foreach ( $post_ids as $post_id ) {
			$post_id = intval( $post_id );

			if ( ! $post_id || isset( $seen[ $post_id ] ) ) {
				continue;
			}

			$seen[ $post_id ] = true;
			$deduplicated[] = $post_id;
		}

		return $deduplicated;
	}

	/**
	 * Get recipe roundup items from the content.
	 *
	 * @since    4.3.0
	 * @param    mixed $content Content to get the recipe roundup items from.
	 */
	public static function get_items_from_content( $content ) {
		$post_ids = array();

		$recipe_shortcodes = array();
		$pattern = get_shortcode_regex( array( 'wprm-recipe-roundup-item' ) );

		if ( preg_match_all( '/' . $pattern . '/s', $content, $matches ) && array_key_exists( 2, $matches ) ) {
			foreach ( $matches[2] as $key => $value ) {
				if ( 'wprm-recipe-roundup-item' === $value ) {
					$recipe_shortcodes[ $matches[0][ $key ] ] = shortcode_parse_atts( stripslashes( $matches[3][ $key ] ) );
				}
			}
		}

		foreach ( $recipe_shortcodes as $shortcode => $shortcode_options ) {
			$post_id = isset( $shortcode_options['id'] ) ? intval( $shortcode_options['id'] ) : 0;

			if ( $post_id ) {
				$post_ids[] = $post_id;
			}
		}

		return $post_ids;
	}

	/**
	 * Register the meta fields in the REST API.
	 *
	 * @since    4.3.0
	 */
	public static function meta_fields_in_rest() {
		register_meta( 'post', 'wprm-recipe-roundup-name', array( 'show_in_rest' => true, 'single' => true ) );
		register_meta( 'post', 'wprm-recipe-roundup-description', array( 'show_in_rest' => true, 'single' => true ) );
	}

	/**
	 * Output for the recipe roundup item shortcode.
	 *
	 * @since    4.3.0
	 * @param    array $atts Options passed along with the shortcode.
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => false,
				'align' => '',
				'link' => '',
				'image' => '',
				'image_url' => '',
				'credit' => '',
				'summary' => '',
				'name' => '',
				'button' => '',
				'template' => '',
				'nofollow' => false,
				'newtab' => true,
			),
			$atts,
			'wprm_recipe_roundup_item'
		);

		$recipe = false;
		$recipe_template = trim( $atts['template'] );
		$recipe_id = intval( $atts['id'] );
		self::$roundup_overrides = array();

		if ( $recipe_id ) {
			$type = 'internal';
			$recipe = WPRM_Recipe_Manager::get_recipe( $recipe_id );

			if ( $atts['image'] && 0 < intval( $atts['image'] ) ) { self::$roundup_overrides['image_id'] = intval( $atts['image'] ); }
			if ( $atts['name'] ) 	{ self::$roundup_overrides['name'] = self::sanitize_roundup_text( $atts['name'] ); }
			if ( $atts['summary'] ) { self::$roundup_overrides['summary'] = self::sanitize_roundup_text( $atts['summary'], true ); }

			// If no recipe was found, maybe it was of a "post" type instead.
			if ( ! $recipe ) {
				$post = get_post( $recipe_id );

				if ( $post && WPRM_POST_TYPE !== $post->post_type ) {
					$type = 'post';

					$image = $atts['image'] && 0 < intval( $atts['image'] ) ? intval( $atts['image'] ) : get_post_thumbnail_id( $post );
					$name = self::sanitize_roundup_text( $atts['name'] );
					$summary = self::sanitize_roundup_text( $atts['summary'], true );

					$recipe_data = array(
						'type' => 'food',
						'parent_id' => true,
						'parent_url' => get_permalink( $post ),
						'permalink' => get_permalink( $post ),
						'post_status' => $post->post_status,
						'name' => $name ? $name : $post->post_title,
						'summary' => $summary ? $summary : '',
						'image_id' => $image,
						'parent_url_new_tab' => false,
						'parent_url_nofollow' => false,
						'parent_url_noopener' => false,
					);

					$recipe = new WPRM_Recipe_Shell( $recipe_data );
				}
			}

			// Only display published recipes/posts.
			if ( WPRM_Settings::get( 'recipe_roundup_published_only' ) ) {
				if ( $recipe && 'publish' !== $recipe->post_status() ) {
					// If not in Gutenberg preview, return empty shortcode.
					if ( ! WPRM_Context::is_gutenberg_preview() ) {
						return '';
					}
				}
			}
		} else {
			$type = 'external';
			$link = esc_url_raw( rawurldecode( $atts['link'] ) );
			$recipe_data = array(
				'type' => 'food',
				'parent_id' => true,
				'parent_url' => $link,
				'parent_external' => true,
				'permalink' => $link,
				'name' => self::sanitize_roundup_text( $atts['name'] ),
				'summary' => self::sanitize_roundup_text( $atts['summary'], true ),
				'parent_url_new_tab' => $atts['newtab'] ? true : false,
				'parent_url_nofollow' => $atts['nofollow'] ? true : false,
				'parent_url_noopener' => WPRM_Settings::get( 'recipe_roundup_external_noopener' ),
				'credit' => self::sanitize_roundup_text( $atts['credit'] ),
			);

			$image_id = intval( $atts['image'] );
			if ( -1 === $image_id ) {
				$recipe_data['image_id'] = 'url';
				$recipe_data['image_url'] = esc_url_raw( $atts['image_url'] );
			} else {
				$recipe_data['image_id'] = $image_id;
			}

			$recipe = new WPRM_Recipe_Shell( $recipe_data );
		}

		// Both internal and external.
		if ( $atts['button'] ) { self::$roundup_overrides['roundup_link_button_text'] = self::sanitize_roundup_text( $atts['button'] ); }

		if ( $recipe ) {
			$template = false;
			$template_slug = trim( $atts['template'] );

			if ( $template_slug ) {
				$template = WPRM_Template_Manager::get_template_by_slug( $template_slug );
			}

			if ( ! $template ) {
				$template = WPRM_Template_Manager::get_template_by_type( 'roundup', $recipe->type() );
			}

			if ( $template ) {
				// Add to used templates.
				WPRM_Template_Manager::add_used_template( $template );

				$align_class = '';
				if ( isset( $atts['align'] ) && $atts['align'] ) {
					$align_class = ' align' . esc_attr( $atts['align'] );
				}

				$output = '<div class="wprm-recipe wprm-recipe-roundup-item wprm-recipe-roundup-item-' . esc_attr( $recipe->id() ) . ' wprm-recipe-template-' . esc_attr( $template['slug'] ) . esc_attr( $align_class ) . '" data-servings="' . esc_attr( $recipe->servings() ). '">';

				// Add filters for overrides and immediately remove after doing shortcode.
				add_filter( 'wprm_recipe_roundup_link_text', array( __CLASS__, 'roundup_link_text_override' ) );
				if ( 'internal' === $type ) {
					add_filter( 'wprm_recipe_field', array( __CLASS__, 'recipe_field_overrides' ), 10, 2 );
					WPRM_Template_Shortcodes::set_current_recipe_id( $recipe->id() );
					$output .= do_shortcode( $template['html'] );
					WPRM_Template_Shortcodes::set_current_recipe_id( false );
					remove_filter( 'wprm_recipe_field', array( __CLASS__, 'recipe_field_overrides' ), 10, 2 );
				} else {
					WPRM_Template_Shortcodes::set_current_recipe_shell( $recipe );
					$output .= do_shortcode( $template['html'] );
					WPRM_Template_Shortcodes::set_current_recipe_shell( false );
				}
				remove_filter( 'wprm_recipe_roundup_link_text', array( __CLASS__, 'roundup_link_text_override' ) );

				$output .= '</div>';

				return $output;
			}
		}

		return '';
	}

	/**
	 * Maybe apply overrides to recipe fields.
	 *
	 * @since    8.0.0
	 * @param    mixed $output	Current recipe field output.
	 * @param    mixed $field	Current recipe field getting output.
	 */
	public static function recipe_field_overrides( $output, $field ) {
		foreach ( self::$roundup_overrides as $key => $value ) {
			if ( $value && $field === $key ) {
				return $value;
			}
		}

		return $output;
	}

	/**
	 * Maybe apply override to the roundup link text..
	 *
	 * @since    8.0.0
	 * @param    mixed $output	Current roundup link text.
	 */
	public static function roundup_link_text_override( $output ) {
		if ( isset( self::$roundup_overrides['roundup_link_button_text'] ) && self::$roundup_overrides['roundup_link_button_text'] ) {
			return self::$roundup_overrides['roundup_link_button_text'];
		}

		return $output;
	}

	/**
	 * Sanitize user-supplied text for roundup fields.
	 *
	 * @since	10.2.3
	 * @param	string $text Text to sanitize.
	 */
	private static function sanitize_roundup_text( $text, $multiline = false ) {
		if ( is_array( $text ) || is_object( $text ) ) {
			return '';
		}

		if ( ! is_string( $text ) ) {
			$text = (string) $text;
		}

		// Decode shortcode-provided values before sanitizing.
		$text = rawurldecode( $text );

		if ( $multiline ) {
			$text = str_replace( '%0A', '<br/>', $text );
		}

		return WPRM_Shortcode_Helper::sanitize_html( $text );
	}
}

WPRM_Recipe_Roundup::init();
