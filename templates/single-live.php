<?php
/**
 * Single live template for the Newspack classic theme family.
 *
 * Mirrors the wrapper markup of the theme's single-wide.php and renders the live block.
 * Themes can override it with their own single-live.php.
 *
 * @package Newspack_Live
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
	<section id="primary" class="content-area newspack-live-area">
		<main id="main" class="site-main">
			<?php
			while ( have_posts() ) :
				the_post();
				echo do_blocks( '<!-- wp:newspack-live/live {"align":"wide"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the block render escapes its output.
			endwhile;
			?>
		</main>
	</section>
<?php
get_footer();
