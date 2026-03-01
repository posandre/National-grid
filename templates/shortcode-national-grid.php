<?php
/**
 * National Grid shortcode template.
 *
 * @var string $instance_id
 * @var string $title
 * @var string $description
 * @var int    $limit
 * @var string $payload_json
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="national-grid-frontend" id="<?php echo esc_attr( (string) $instance_id ); ?>" data-limit="<?php echo esc_attr( (int) $limit ); ?>">
    <h3 class="national-grid-frontend-title"><?php echo esc_html( (string) $title ); ?></h3>
    <p class="national-grid-frontend-description"><?php echo esc_html( (string) $description ); ?></p>
    <div class="national-grid-frontend-chart-wrap">
        <canvas class="national-grid-frontend-chart" aria-label="<?php esc_attr_e( 'National Grid chart', 'national-grid' ); ?>" role="img"></canvas>
    </div>
    <div class="national-grid-frontend-status" aria-live="polite"></div>
    <script type="application/json" class="national-grid-frontend-payload"><?php echo (string) $payload_json; ?></script>
</div>
