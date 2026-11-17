<?php
/**
 * Log and history retention.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Logging;

use MicroStudio\MicroSyncPro\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Daily cleanup according to the retention settings.
 */
class Retention {

	/** @var Plugin */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks are registered by the Scheduler (it owns the event).
	 */
	public function register_hooks(): void {
	}

	/**
	 * Run the cleanup.
	 *
	 * @return array{logs:int,runs:int,files:int}
	 */
	public function run(): array {
		$settings = $this->plugin->settings();
		$logs     = $this->plugin->log_repository()->purge_older_than( (int) $settings->get( 'log_retention_days' ) );

		$runs    = $this->plugin->runs();
		$storage = $this->plugin->storage();
		$deleted = 0;
		do {
			$old = $runs->finished_before( (int) $settings->get( 'history_retention_days' ) );
			$ids = array();
			foreach ( $old as $run ) {
				$ids[] = $run->id;
				if ( '' !== $run->staged_file ) {
					$storage->delete( $run->staged_file );
				}
				if ( ! empty( $run->snapshot['dup_file'] ) ) {
					$storage->delete( (string) $run->snapshot['dup_file'] );
				}
			}
			$n        = $runs->delete_many( $ids );
			$deleted += $n;
		} while ( count( $old ) >= 500 && $n > 0 );

		// Remove orphaned temporary files (older than a day, not referenced by any run or source).
		$keep = $runs->referenced_files();
		foreach ( $this->plugin->sources()->all() as $source ) {
			$file = (string) $source->opt( 'file', '' );
			if ( '' !== $file ) {
				$keep[] = $file;
			}
		}
		$files = $storage->cleanup( DAY_IN_SECONDS, $keep );

		if ( $logs || $deleted || $files ) {
			$this->plugin->logger()->info( 'Retention cleanup completed.', array( 'logs' => $logs, 'runs' => $deleted, 'files' => $files ) );
		}
		return array(
			'logs'  => $logs,
			'runs'  => $deleted,
			'files' => $files,
		);
	}
}
