<?php
/**
 * Server-side rendering of evive-language/switcher.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$evive_items = Evive\Connector\Language\Plugin::switcher_items();
if ( count( $evive_items ) < 2 ) {
	return;
}
$evive_display = in_array( $attributes['display'] ?? 'code', [ 'code', 'label', 'both' ], true ) ? $attributes['display'] : 'code';
?>
<nav <?php echo get_block_wrapper_attributes( [ 'aria-label' => esc_attr__( 'Language', 'evive-language' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<ul class="wp-block-evive-language-switcher__list">
		<?php foreach ( $evive_items as $evive_item ) : ?>
			<li class="wp-block-evive-language-switcher__item<?php echo $evive_item['current'] ? ' is-current' : ''; ?>">
				<a href="<?php echo esc_url( $evive_item['url'] ); ?>" lang="<?php echo esc_attr( $evive_item['hreflang'] ); ?>" hreflang="<?php echo esc_attr( $evive_item['hreflang'] ); ?>"<?php echo $evive_item['current'] ? ' aria-current="true"' : ''; ?>>
					<?php if ( 'label' === $evive_display ) : ?>
						<?php echo esc_html( $evive_item['label'] ); ?>
					<?php elseif ( 'both' === $evive_display ) : ?>
						<?php echo esc_html( strtoupper( $evive_item['code'] ) . ' · ' . $evive_item['label'] ); ?>
					<?php else : ?>
						<?php echo esc_html( strtoupper( $evive_item['code'] ) ); ?><span class="wp-block-evive-language-switcher__sr"> <?php echo esc_html( $evive_item['label'] ); ?></span>
					<?php endif; ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
