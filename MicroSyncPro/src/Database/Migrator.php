<?php
/**
 * Schema migrations.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades custom tables with dbDelta and a stored schema version.
 */
final class Migrator {

	/**
	 * Run migrations when the stored schema version is older than the code.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( Schema::OPTION, '' );
		if ( '' !== $installed && version_compare( $installed, Schema::VERSION, '>=' ) ) {
			return;
		}
		// Avoid concurrent upgrades from parallel requests.
		if ( get_transient( 'msp_migrating' ) ) {
			return;
		}
		set_transient( 'msp_migrating', 1, 60 );
		self::migrate();
		delete_transient( 'msp_migrating' );
	}

	/**
	 * Create/upgrade all tables.
	 */
	public static function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$previous = (string) get_option( Schema::OPTION, '' );
		foreach ( Schema::statements() as $sql ) {
			dbDelta( $sql );
		}
		self::run_version_steps( $previous );
		update_option( Schema::OPTION, Schema::VERSION, true );
	}

	/**
	 * Data migrations keyed by version (none yet for 1.0.0).
	 *
	 * @param string $from Previously installed version ('' on fresh install).
	 */
	private static function run_version_steps( string $from ): void {
		/**
		 * Fires after schema changes are applied, allowing data migrations.
		 *
		 * @param string $from Previous schema version.
		 * @param string $to   New schema version.
		 */
		do_action( 'micro_sync_pro_migrated', $from, Schema::VERSION );
	}

	/**
	 * Drop all plugin tables (uninstall only).
	 */
	public static function drop_tables(): void {
		global $wpdb;
		foreach ( Schema::all_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall only (opt-in); table names are internal constants.
		}
	}
}
