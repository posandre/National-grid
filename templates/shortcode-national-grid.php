<?php
/**
 * National Grid shortcode template.
 *
 * @var string $instance_id
 * @var string $title
 * @var string $description
 * @var string $additional_class
 * @var bool $hide_title
 * @var string $live_heading
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="national-grid-frontend<?php echo '' !== trim( (string) $additional_class ) ? ' ' . esc_attr( trim( (string) $additional_class ) ) : ''; ?>" id="<?php echo esc_attr( (string) $instance_id ); ?>">
    <?php if ( ! $hide_title && ( '' !== trim( (string) $title ) || '' !== trim( (string) $description ) ) ) : ?>
        <div class="national-grid-frontend-header">
            <?php if ( '' !== trim( (string) $title ) ) : ?>
                <h2 class="national-grid-frontend-title"><?php echo esc_html( (string) $title ); ?></h2>
            <?php endif; ?>
            <?php if ( '' !== trim( (string) $description ) ) : ?>
                <div class="national-grid-frontend-description"><?php echo wp_kses_post( (string) $description ); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="national-grid-frontend-wrapper">
        <div class="container">
            <p class="national-grid-frontend-live-heading"><?php echo esc_html( (string) $live_heading ); ?></p>
            <p class="national-grid-frontend-clean-power-heading"></p>
            <div class="national-grid-frontend-charts">
                <div class="national-grid-frontend-chart-wrap">
                    <canvas class="national-grid-frontend-chart national-grid-frontend-chart-pie" aria-label="<?php esc_attr_e( 'National Grid pie chart', 'national-grid' ); ?>" role="img"></canvas>
                </div>
                <div class="national-grid-frontend-chart-wrap">
                    <canvas class="national-grid-frontend-chart national-grid-frontend-chart-bar" aria-label="<?php esc_attr_e( 'National Grid bar chart', 'national-grid' ); ?>" role="img"></canvas>
                </div>
            </div>
            <div class="national-grid-frontend-legend" aria-label="<?php esc_attr_e( 'Chart legend', 'national-grid' ); ?>"></div>
            <div class="national-grid-frontend-status" aria-live="polite"></div>
            <noscript>
                <p class="national-grid-frontend-status">
                    <?php esc_html_e( 'JavaScript is required to load live National Grid charts.', 'national-grid' ); ?>
                </p>
            </noscript>
        </div>
    </div>
</div>
