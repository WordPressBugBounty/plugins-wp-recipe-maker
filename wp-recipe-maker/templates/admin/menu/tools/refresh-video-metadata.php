<?php
/**
 * Template for refresh video metadata page.
 *
 * @link       https://bootstrapped.ventures
 * @since      6.0.0
 *
 * @package    WP_Recipe_Maker
 * @subpackage WP_Recipe_Maker/templates/admin/menu/tools
 */
?>

<div class="wrap wprm-tools">
	<h2><?php esc_html_e( 'Refresh Video Metadata', 'wp-recipe-maker' ); ?></h2>
	<?php
	// translators: %d: number of recipes left to search through.
	printf( esc_html( _n( 'Scheduling refreshes for %d recipe', 'Scheduling refreshes for %d recipes', count( $posts ), 'wp-recipe-maker' ) ), count( $posts ) );
	?>.
	<?php
	$progress_bar_type = 'tools';
	include WPRM_DIR . 'templates/admin/progress-bar.php';
	?>
	<p><?php esc_html_e( 'This tool schedules background refreshes. The progress bar tracks scheduling, not saved video metadata.', 'wp-recipe-maker' ); ?></p>
	<p id="wprm-video-tool-message" role="status" aria-live="polite"></p>
	<p><button type="button" class="button" id="wprm-video-check-status" disabled><?php esc_html_e( 'Check refresh status', 'wp-recipe-maker' ); ?></button></p>
	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<form id="wprm-video-refresh-now">
			<label for="wprm-video-recipe-id"><?php esc_html_e( 'Recipe ID', 'wp-recipe-maker' ); ?></label>
			<input id="wprm-video-recipe-id" type="number" min="1" step="1" required>
			<button class="button" type="submit" disabled><?php esc_html_e( 'Refresh this recipe now', 'wp-recipe-maker' ); ?></button>
			<p class="description"><?php esc_html_e( 'Runs one refresh here and verifies saved metadata. This can take a while. An active refresh will not be interrupted.', 'wp-recipe-maker' ); ?></p>
		</form>
	<?php endif; ?>
	<div id="wprm-tools-report"></div>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=wprm_manage' ) ); ?>" id="wprm-tools-finished"><?php esc_html_e( 'Scheduling finished. Click here to continue.', 'wp-recipe-maker' ); ?></a>
</div>
