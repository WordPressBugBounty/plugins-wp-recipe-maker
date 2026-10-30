<?php
/**
 * Template to be used as a fallback when the plugin is deactivated.
 *
 * @link       https://bootstrapped.ventures
 * @since      1.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/templates/public
 */

?>
<!--WPRM Recipe <?php echo intval( $recipe->id() ); ?>-->
<?php
if ( count( $args ) > 0 ) {
	$fallback_args = array();
	foreach ( $args as $key => $value ) {
		$fallback_args[] = esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
	}
	echo '<!--' . wp_kses_post( implode( ' ', $fallback_args ) ) . '-->';
}
?>
<div class="wprm-fallback-recipe">
	<h2 class="wprm-fallback-recipe-name"><?php echo wp_kses_post( $recipe->name() ); ?></h2>
	<?php
	if ( $recipe->image_url() ) {
		echo '<img class="wprm-fallback-recipe-image" src="' . esc_attr( $recipe->image_url() ) . '"/>';
	}
	?>
	<p class="wprm-fallback-recipe-summary">
		<?php echo wp_kses_post( $recipe->summary() ); ?>
	</p>
	<div class="wprm-fallback-recipe-equipment">
		<?php
		$equipment = $recipe->equipment();
		
		if ( $equipment ) {
			echo '<ul>';
			foreach ( $equipment as $item ) {
				echo '<li>';
				echo wp_kses_post( $item['name'] );
				echo '</li>';
			}
			echo '</ul>';
		}
		?>
	</div>
	<div class="wprm-fallback-recipe-ingredients">
		<?php
		$ingredients = $recipe->ingredients();

		foreach ( $ingredients as $ingredient_group ) {
			if ( $ingredient_group['name'] ) {
				echo '<h4>'. wp_kses_post( $ingredient_group['name'] ) . '</h4>';
			}

			if ( count( $ingredient_group['ingredients'] ) > 0 ) {
				echo '<ul>';

				foreach ( $ingredient_group['ingredients'] as $ingredient ) {
					echo '<li>';
					if ( $ingredient['amount'] ) {
						echo wp_kses_post( $ingredient['amount'] ) . ' ';
					}
					if ( $ingredient['unit'] ) {
						echo wp_kses_post( $ingredient['unit'] ) . ' ';
					}
					echo wp_kses_post( $ingredient['name'] );
					if ( $ingredient['notes'] ) {
						echo ' (' . wp_kses_post( $ingredient['notes'] ) . ')';
					}
					echo '</li>';
				}

				echo '</ul>';
			}
		}
		?>
	</div>
	<div class="wprm-fallback-recipe-instructions">
		<?php
		$instructions = $recipe->instructions();

		foreach ( $instructions as $instruction_group ) {
			if ( $instruction_group['name'] ) {
				echo '<h4>'. wp_kses_post( $instruction_group['name'] ) . '</h4>';
			}

			// Tips sit between steps without being one, so close the list around them and keep numbering going after.
			$step_number = 0;
			$list_open = false;

			foreach ( $instruction_group['instructions'] as $instruction ) {
				if ( isset( $instruction['type'] ) && 'tip' === $instruction['type'] ) {
					if ( $list_open ) {
						echo '</ol>';
						$list_open = false;
					}

					if ( ! empty( $instruction['text'] ) ) {
						echo '<div class="wprm-fallback-recipe-tip">' . wp_kses_post( $instruction['text'] ) . '</div>';
					}
					continue;
				}

				if ( ! $list_open ) {
					echo 0 === $step_number ? '<ol>' : '<ol start="' . intval( $step_number + 1 ) . '">';
					$list_open = true;
				}
				$step_number++;

				echo '<li>';
				if ( $instruction['text'] ) {
					echo wp_kses_post( $instruction['text'] );
				}

				if ( ! empty( $instruction['image'] ) ) {
					$thumb = wp_get_attachment_image_src( $instruction['image'], 'thumbnail' );
					$image_url = $thumb && isset( $thumb[0] ) ? $thumb[0] : '';

					if ( $instruction['text'] && $image_url ) {
						echo '<br />';
					}

					if ( $image_url ) {
						echo '<img src="' . esc_attr( $image_url ) . '"/>';
						echo '<br />';
					}
				}
				echo '</li>';
			}

			if ( $list_open ) {
				echo '</ol>';
			}
		}
		?>
	</div>
	<div class="wprm-fallback-recipe-notes">
		<?php echo wp_kses_post( $recipe->notes() ); ?>
	</div>
	<div class="wprm-fallback-recipe-meta">
		<?php
		$taxonomies = WPRM_Taxonomies::get_taxonomies();
		foreach ( $taxonomies as $taxonomy => $options ) {
			$key = substr( $taxonomy, 5 );

			$terms = $recipe->tags( $key, true );

			if ( $terms ) {
				echo '<div class="wprm-fallback-recipe-meta-' . esc_attr( $key ) . '">';
				echo wp_kses_post( implode( ', ', $terms ) );
				echo '</div>';
			}
		}
		?>
	</div>
</div>
<!--End WPRM Recipe-->
