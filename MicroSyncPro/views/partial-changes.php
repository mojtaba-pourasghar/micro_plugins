<?php
/**
 * Change list (before → after).
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var array<string,mixed>|null $changes
 */

use MicroStudio\MicroSyncPro\Admin\View;

defined( 'ABSPATH' ) || exit;

if ( ! is_array( $changes ) || empty( $changes ) ) {
	echo '<span class="msp-muted">—</span>';
	return;
}
echo '<ul class="msp-changes">';
foreach ( $changes as $field => $c ) {
	if ( ! is_array( $c ) || ! array_key_exists( 'before', $c ) ) {
		continue;
	}
	printf(
		'<li><span class="msp-changes__field">%1$s:</span> <del>%2$s</del> <span aria-hidden="true">&rarr;</span><span class="screen-reader-text">%4$s</span> <ins>%3$s</ins></li>',
		esc_html( View::field_label( (string) $field ) ),
		esc_html( View::change_value( (string) $field, $c['before'] ) ),
		esc_html( View::change_value( (string) $field, $c['after'] ) ),
		esc_html__( 'changed to', 'micro-sync-pro' )
	);
}
echo '</ul>';
