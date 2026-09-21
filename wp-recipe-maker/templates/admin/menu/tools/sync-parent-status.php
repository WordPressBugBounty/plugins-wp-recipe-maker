<?php
/**
 * Template for sync parent status page.
 *
 * @link       https://bootstrapped.ventures
 * @since      10.8.4
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/templates/admin/menu/tools
 */
?>

<div class="wrap wprm-tools">
	<h2><?php esc_html_e( 'Sync Parent Status', 'wp-recipe-maker' ); ?></h2>
	<p><?php esc_html_e( 'Only recipe statuses are changed. Recipes without a valid assigned parent, or whose assigned parent does not contain the recipe, are skipped.', 'wp-recipe-maker' ); ?></p>
	<?php
	// translators: %d: number of recipes left to search through.
	printf( esc_html( _n( 'Syncing the status of %d recipe', 'Syncing the status of %d recipes', count( $posts ), 'wp-recipe-maker' ) ), count( $posts ) );
	?>.
	<?php
	$progress_bar_type = 'tools';
	include WPRM_DIR . 'templates/admin/progress-bar.php';
	?>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=wprm_manage' ) ); ?>" id="wprm-tools-finished"><?php esc_html_e( 'Finished successfully. Click here to continue.', 'wp-recipe-maker' ); ?></a>
</div>
