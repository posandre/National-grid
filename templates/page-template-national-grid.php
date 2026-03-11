<?php
/**
 * National Grid full-page template loaded from plugin.
 *
 * @package national-grid
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<?php
while (have_posts()):
    the_post();
    ?>

    <main class='main'>
        <?php the_content(); ?>
    </main>

<?php
endwhile;
?>

<?php
get_footer();
