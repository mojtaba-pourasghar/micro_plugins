<?php
/**
 * Flash notices across redirects.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Stores per-user notices in a short-lived transient (Post/Redirect/Get).
 */
final class Notices {

	/**
	 * Transient key for the current user.
	 */
	private static function key(): string {
		return 'msp_notices_' . get_current_user_id();
	}

	/**
	 * Queue a notice.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message (plain text).
	 */
	public static function add( string $type, string $message ): void {
		$list   = get_transient( self::key() );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => $message,
		);
		set_transient( self::key(), array_slice( $list, -10 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Render and clear queued notices.
	 */
	public static function render(): void {
		$list = get_transient( self::key() );
		if ( ! is_array( $list ) || empty( $list ) ) {
			return;
		}
		delete_transient( self::key() );
		foreach ( $list as $n ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible" role="%3$s"><p>%2$s</p></div>',
				esc_attr( $n['type'] ),
				esc_html( $n['message'] ),
				'error' === $n['type'] ? 'alert' : 'status'
			);
		}
	}
}
