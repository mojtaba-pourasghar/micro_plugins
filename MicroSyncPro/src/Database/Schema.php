<?php
/**
 * Database schema definitions.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and CREATE TABLE statements (dbDelta format).
 */
final class Schema {

	/** Current schema version. Bump when the SQL below changes. */
	const VERSION = '1.0.0';

	/** Option holding the installed schema version. */
	const OPTION = 'msp_db_version';

	/**
	 * Table name helper.
	 *
	 * @param string $name Short name: sources|runs|items|logs.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		$allowed = array( 'sources', 'runs', 'items', 'logs' );
		if ( ! in_array( $name, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Unknown table.' );
		}
		return $wpdb->prefix . 'msp_' . $name;
	}

	/**
	 * All table names.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array( self::table( 'sources' ), self::table( 'runs' ), self::table( 'items' ), self::table( 'logs' ) );
	}

	/**
	 * CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$sources = self::table( 'sources' );
		$runs    = self::table( 'runs' );
		$items   = self::table( 'items' );
		$logs    = self::table( 'logs' );

		return array(
			"CREATE TABLE {$sources} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  type varchar(32) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  config longtext NULL,
  mapping longtext NULL,
  columns_detected longtext NULL,
  schedule varchar(32) NOT NULL DEFAULT 'none',
  last_run_id bigint(20) unsigned NULL DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status),
  KEY schedule (schedule)
) {$collate};",
			"CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL DEFAULT '',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_run_id bigint(20) unsigned NULL DEFAULT NULL,
  mode varchar(20) NOT NULL DEFAULT 'dry_run',
  run_trigger varchar(20) NOT NULL DEFAULT 'manual',
  status varchar(20) NOT NULL DEFAULT 'queued',
  phase varchar(20) NOT NULL DEFAULT 'prepare',
  total_records int(10) unsigned NOT NULL DEFAULT 0,
  processed int(10) unsigned NOT NULL DEFAULT 0,
  updated_count int(10) unsigned NOT NULL DEFAULT 0,
  unchanged_count int(10) unsigned NOT NULL DEFAULT 0,
  skipped_count int(10) unsigned NOT NULL DEFAULT 0,
  failed_count int(10) unsigned NOT NULL DEFAULT 0,
  cursor_pos bigint(20) unsigned NOT NULL DEFAULT 0,
  staged_file varchar(255) NOT NULL DEFAULT '',
  settings_snapshot longtext NULL,
  message text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  lock_token varchar(64) NULL DEFAULT NULL,
  lock_expires datetime NULL DEFAULT NULL,
  heartbeat_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  started_at datetime NULL DEFAULT NULL,
  finished_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY source_status (source_id,status),
  KEY status_heartbeat (status,heartbeat_at),
  KEY parent_run_id (parent_run_id),
  KEY created_at (created_at)
) {$collate};",
			"CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  row_num bigint(20) unsigned NOT NULL DEFAULT 0,
  sku varchar(191) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT '',
  code varchar(40) NOT NULL DEFAULT '',
  changes longtext NULL,
  message text NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY run_row (run_id,row_num),
  KEY run_status (run_id,status),
  KEY sku (sku),
  KEY product_id (product_id)
) {$collate};",
			"CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NULL DEFAULT NULL,
  level varchar(10) NOT NULL DEFAULT 'info',
  message text NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY level_created (level,created_at),
  KEY created_at (created_at)
) {$collate};",
		);
	}
}
