<?php
/**
 * Synchronization engine tests: dry run, apply, batches, resume, cancel,
 * duplicate prevention, rollback, scheduling, remote JSON and XLSX sources.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Integration;

use MicroStudio\MicroSyncPro\Scheduling\JobQueue;
use MicroStudio\MicroSyncPro\Scheduling\Scheduler;
use MicroStudio\MicroSyncPro\Sources\Source;
use MicroStudio\MicroSyncPro\Sources\SourceTypes;
use MicroStudio\MicroSyncPro\Sync\Run;
use MicroStudio\MicroSyncPro\Tests\TestCase;
use MicroStudio\MicroSyncPro\Tests\Unit\XlsxParserTest;

/**
 * @covers \MicroStudio\MicroSyncPro\Sync\SyncEngine
 * @covers \MicroStudio\MicroSyncPro\Sync\RunManager
 * @covers \MicroStudio\MicroSyncPro\Sync\RollbackService
 * @covers \MicroStudio\MicroSyncPro\Scheduling\JobQueue
 * @covers \MicroStudio\MicroSyncPro\Scheduling\Scheduler
 * @covers \MicroStudio\MicroSyncPro\Sources\SourceStager
 */
class SyncEngineTest extends TestCase {

	private function start( Source $s, string $mode = Run::MODE_DRY_RUN ): Run {
		$run = $this->plugin()->run_manager()->start( $s->id, $mode, 'manual', 1 );
		$this->assertInstanceOf( Run::class, $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		return $run;
	}

	public function test_dry_run_reports_everything_and_changes_nothing(): void {
		$a = $this->simple( 'A', '100', 5 );
		$b = $this->simple( 'B', '200', 1 );
		list( , $vs ) = $this->variable( 'V', array( 'V-1' => '30' ) );
		$csv = "sku,price,stock\nA,150,7\nB,200,1\nV-1,35,2\nGHOST,10,1\n,5,5\nDUP,1,1\nDUP,2,2\nA2,abc,1\n";
		$this->simple( 'A2', '10', 1 );
		$s   = $this->csv_source( $csv );
		$run = $this->complete( $this->start( $s )->id );

		$this->assertSame( Run::STATUS_COMPLETED, $run->status );
		$this->assertSame( 8, $run->total );
		$this->assertSame( 8, $run->processed );
		$this->assertSame( 2, $run->updated, 'A and V-1 would change.' );
		$this->assertSame( 1, $run->unchanged );
		$this->assertSame( 4, $run->skipped, 'GHOST, empty SKU, 2×DUP.' );
		$this->assertSame( 1, $run->failed );
		$this->assertNotEmpty( $run->uuid );
		$this->assertNotEmpty( $run->started_at );
		$this->assertNotEmpty( $run->finished_at );

		$this->assertSame( 'would_update', $this->item( $run->id, 'A' )->status );
		$this->assertSame( 'unmatched', $this->item( $run->id, 'GHOST' )->code );
		$this->assertSame( 'empty_sku', $this->item( $run->id, '' )->code );
		$this->assertSame( 'duplicate_sku', $this->item( $run->id, 'DUP' )->code );
		$this->assertSame( 'invalid_value', $this->item( $run->id, 'A2' )->code );
		$this->assertSame( 2, (int) $this->item( $run->id, 'A' )->row_num, 'Row numbers refer to the source line.' );

		$this->assertSame( '100', $this->product( $a->get_id() )->get_regular_price() );
		$this->assertSame( 5, $this->product( $a->get_id() )->get_stock_quantity() );
		$this->assertSame( '30', $this->product( $vs[0]->get_id() )->get_regular_price() );
		$this->assertTrue( $this->plugin()->storage()->exists( $run->staged_file ), 'Preview data is kept for apply.' );
		unset( $b );
	}

	public function test_confirm_and_apply_uses_preview_snapshot(): void {
		$a   = $this->simple( 'A', '100', 5 );
		$this->settings( array( 'margin_percent' => 10 ) );
		$s   = $this->csv_source( "sku,price,stock\nA,200,9\n" );
		$pre = $this->complete( $this->start( $s )->id );
		$this->assertSame( '220.00', json_decode( $this->item( $pre->id, 'A' )->changes, true )['regular_price']['after'] );

		// Settings changed after preview must not alter what is applied.
		$this->settings( array( 'margin_percent' => 50 ) );
		$apply = $this->plugin()->run_manager()->apply_preview( $pre->id, 1 );
		$this->assertInstanceOf( Run::class, $apply );
		$apply = $this->complete( $apply->id );
		$this->assertSame( Run::STATUS_COMPLETED, $apply->status );
		$this->assertSame( 1, $apply->updated );
		$this->assertSame( '220.00', $this->product( $a->get_id() )->get_regular_price() );
		$this->assertSame( 9, $this->product( $a->get_id() )->get_stock_quantity() );
		$this->assertSame( $pre->id, $apply->parent_run_id );
		$this->assertSame( '', $apply->staged_file, 'Apply run staged data is removed after completion.' );

		$again = $this->plugin()->run_manager()->apply_preview( $pre->id, 1 );
		$this->assertWPError( $again, 'A preview can be applied only once.' );
	}

	public function test_batch_processing_with_cursor_and_progress(): void {
		$this->settings( array( 'batch_size' => 3 ) );
		$csv = "sku,price,stock\n";
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->simple( 'B' . $i, '10', 1 );
			$csv .= "B{$i}," . ( 10 + $i ) . ",1\n";
		}
		$s       = $this->csv_source( $csv );
		$run     = $this->start( $s, Run::MODE_APPLY );
		$engine  = $this->plugin()->engine();
		$cursors = array();
		$steps   = 0;
		$run     = $engine->step( $run, microtime( true ) ); // Prepare.
		$this->assertSame( Run::PHASE_PROCESS, $run->phase );
		$this->assertSame( 10, $run->total );
		while ( $run->is_active() && $steps < 20 ) {
			$run       = $engine->step( $run, microtime( true ) ); // Exactly one batch per call.
			$cursors[] = array( $run->processed, $run->cursor );
			++$steps;
		}
		$this->assertSame( Run::STATUS_COMPLETED, $run->status );
		$this->assertSame( array( 3, 6, 9, 10 ), array_slice( array_column( $cursors, 0 ), 0, 4 ), 'Progress advances one batch at a time.' );
		$this->assertSame( 10, $run->updated );
		$this->assertSame( 100, $run->percent() );
	}

	public function test_interrupted_batch_is_resumed_without_duplicates(): void {
		$this->settings( array( 'batch_size' => 2, 'max_resume_attempts' => 0 ) );
		$p1  = $this->simple( 'R1', '10', 1 );
		$p2  = $this->simple( 'R2', '10', 1 );
		$p3  = $this->simple( 'R3', '10', 1 );
		$s   = $this->csv_source( "sku,price,stock\nR1,11,1\nR2,12,1\nR3,13,1\n" );
		$run = $this->start( $s, Run::MODE_APPLY );
		$e   = $this->plugin()->engine();
		$run = $e->step( $run, microtime( true ) ); // Prepare.
		$run = $e->step( $run, microtime( true ) ); // Batch 1 (R1, R2).
		$this->assertSame( 2, $run->processed );

		// Simulate a crash after items were written but before the cursor was committed.
		$this->plugin()->runs()->update( $run->id, array( 'cursor' => 0, 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
		$this->plugin()->queue()->watchdog();
		$run = $this->plugin()->runs()->find( $run->id );
		$this->assertSame( Run::STATUS_INTERRUPTED, $run->status, 'Stalled run is marked interrupted once attempts are exhausted.' );
		$this->assertTrue( $this->plugin()->storage()->exists( $run->staged_file ), 'Interrupted run keeps its data.' );

		$this->assertTrue( $this->plugin()->run_manager()->resume( $run->id ) );
		$run = $this->complete( $run->id );
		$this->assertSame( Run::STATUS_COMPLETED, $run->status );
		$this->assertSame( 3, $run->processed, 'No duplicated items.' );
		$this->assertSame( 3, $run->updated );
		$this->assertCount( 3, $this->items_by_sku( $run->id ) );
		$this->assertSame( '11.00', $this->product( $p1->get_id() )->get_regular_price() );
		$this->assertSame( '13.00', $this->product( $p3->get_id() )->get_regular_price() );
		unset( $p2 );
	}

	public function test_watchdog_resumes_stalled_run_automatically(): void {
		$this->simple( 'W1', '10', 1 );
		$s   = $this->csv_source( "sku,price,stock\nW1,20,1\n" );
		$run = $this->start( $s, Run::MODE_APPLY );
		wp_clear_scheduled_hook( JobQueue::HOOK_PROCESS, array( $run->id ) );
		$this->plugin()->runs()->update( $run->id, array( 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
		$this->plugin()->queue()->watchdog();
		$fresh = $this->plugin()->runs()->find( $run->id );
		$this->assertSame( 1, $fresh->attempts );
		$this->assertSame( Run::STATUS_QUEUED, $fresh->status );
		$this->assertNotFalse( wp_next_scheduled( JobQueue::HOOK_PROCESS, array( $run->id ) ), 'Re-queued via WP-Cron fallback.' );
		$this->assertSame( Run::STATUS_COMPLETED, $this->complete( $run->id )->status );
	}

	public function test_duplicate_execution_is_prevented_and_lock_is_exclusive(): void {
		$this->simple( 'D1', '10', 1 );
		$s     = $this->csv_source( "sku,price,stock\nD1,20,1\n" );
		$run   = $this->start( $s );
		$again = $this->plugin()->run_manager()->start( $s->id, Run::MODE_APPLY, 'manual', 1 );
		$this->assertWPError( $again );
		$this->assertSame( 'msp_run_active', $again->get_error_code() );

		$runs = $this->plugin()->runs();
		$this->assertTrue( $runs->claim_lock( $run->id, 'worker-a', 60 ) );
		$this->assertFalse( $runs->claim_lock( $run->id, 'worker-b', 60 ), 'Second worker cannot claim.' );
		$this->assertNull( $this->plugin()->queue()->work( $run->id, 5 ), 'Locked run is not processed concurrently.' );
		$runs->release_lock( $run->id, 'worker-a' );
		$this->assertSame( Run::STATUS_COMPLETED, $this->complete( $run->id )->status );

		$scheduled = $this->plugin()->run_manager()->start( $s->id, Run::MODE_APPLY, 'schedule', 0 );
		$this->assertInstanceOf( Run::class, $scheduled, 'A new run is allowed once the previous one finished.' );
	}

	public function test_cancellation_stops_at_batch_boundary(): void {
		$this->settings( array( 'batch_size' => 2 ) );
		$csv = "sku,price,stock\n";
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->simple( 'C' . $i, '10', 1 );
			$csv .= "C{$i},50,1\n";
		}
		$s   = $this->csv_source( $csv );
		$run = $this->start( $s, Run::MODE_APPLY );
		$e   = $this->plugin()->engine();
		$run = $e->step( $run, microtime( true ) );
		$run = $e->step( $run, microtime( true ) );
		$this->assertTrue( $this->plugin()->run_manager()->cancel( $run->id ) );
		$this->assertSame( Run::STATUS_CANCELLING, $this->plugin()->runs()->find( $run->id )->status );
		$run = $this->complete( $run->id );
		$this->assertSame( Run::STATUS_CANCELLED, $run->status );
		$this->assertSame( 2, $run->processed );
		$this->assertSame( '', $run->staged_file );

		$queued = $this->start( $s );
		$this->assertTrue( $this->plugin()->run_manager()->cancel( $queued->id ) );
		$this->assertSame( Run::STATUS_CANCELLED, $this->plugin()->runs()->find( $queued->id )->status, 'Queued runs cancel immediately.' );
	}

	public function test_rollback_restores_previous_values_and_detects_conflicts(): void {
		$a   = $this->simple( 'RB1', '100', 5 );
		$b   = $this->simple( 'RB2', '100', 5 );
		$c   = $this->simple( 'RB3', '10', null );
		$s   = $this->csv_source( "sku,price,stock\nRB1,150,8\nRB2,175,9\nRB3,20,4\n", null, array( 'enable_stock_management' => true ) );
		$run = $this->complete( $this->start( $s, Run::MODE_APPLY )->id );
		$this->assertSame( 3, $run->updated );
		$this->assertTrue( $run->can_rollback() );

		// Someone edits RB2 after the sync.
		$edited = $this->product( $b->get_id() );
		$edited->set_regular_price( '999' );
		$edited->save();

		$rb = $this->plugin()->run_manager()->rollback( $run->id, 1 );
		$this->assertInstanceOf( Run::class, $rb );
		$rb = $this->complete( $rb->id );
		$this->assertSame( Run::STATUS_COMPLETED, $rb->status );
		$this->assertSame( 2, $rb->updated );
		$this->assertSame( 1, $rb->skipped );

		$this->assertSame( '100', $this->product( $a->get_id() )->get_regular_price() );
		$this->assertSame( 5, $this->product( $a->get_id() )->get_stock_quantity() );
		$this->assertSame( '999', $this->product( $b->get_id() )->get_regular_price(), 'Conflicting product untouched.' );
		$this->assertSame( 'conflict', $this->item( $rb->id, 'RB2' )->code );
		$c_fresh = $this->product( $c->get_id() );
		$this->assertFalse( $c_fresh->get_manage_stock(), 'Stock management flag restored.' );
		$this->assertSame( '10', $c_fresh->get_regular_price() );

		$this->assertFalse( $this->plugin()->runs()->find( $run->id )->can_rollback(), 'A run can be rolled back only once.' );
		$this->assertWPError( $this->plugin()->run_manager()->rollback( $run->id, 1 ) );
	}

	public function test_scheduling_uses_wp_cron_fallback_and_starts_apply_runs(): void {
		$this->simple( 'SC1', '10', 1 );
		$s           = $this->csv_source( "sku,price,stock\nSC1,99,1\n" );
		$s->schedule = 'hourly';
		$this->plugin()->sources()->save( $s );
		$sched = $this->plugin()->scheduler();
		$sched->sync_source( $s );
		$next = wp_next_scheduled( Scheduler::HOOK_SOURCE, array( $s->id ) );
		$this->assertNotFalse( $next );
		$this->assertGreaterThan( time() + 3000, $next );
		$this->assertSame( (int) $next, $sched->next_for_source( $s->id ) );

		$sched->run_scheduled( $s->id );
		$active = $this->plugin()->runs()->active_for_source( $s->id );
		$this->assertNotNull( $active );
		$this->assertSame( Run::MODE_APPLY, $active->mode );
		$this->assertSame( 'schedule', $active->trigger );
		$this->assertSame( Run::STATUS_COMPLETED, $this->complete( $active->id )->status );

		$s->schedule = 'none';
		$this->plugin()->sources()->save( $s );
		$sched->sync_source( $s );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_SOURCE, array( $s->id ) ) );

		$sched->ensure_system_events( true );
		$this->assertNotFalse( wp_next_scheduled( JobQueue::HOOK_WATCHDOG ) );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_RETENTION ) );
		Scheduler::unschedule_everything();
		$this->assertFalse( wp_next_scheduled( JobQueue::HOOK_WATCHDOG ) );
	}

	public function test_action_scheduler_is_used_when_available(): void {
		remove_all_filters( 'micro_sync_pro_use_action_scheduler' );
		$this->assertTrue( JobQueue::use_action_scheduler(), 'WooCommerce bundles Action Scheduler.' );
		$this->simple( 'AS1', '10', 1 );
		$s   = $this->csv_source( "sku,price,stock\nAS1,15,1\n" );
		$run = $this->start( $s, Run::MODE_APPLY );
		$this->assertTrue( as_has_scheduled_action( JobQueue::HOOK_PROCESS, array( $run->id ), JobQueue::GROUP ) );
		$this->assertSame( Run::STATUS_COMPLETED, $this->complete( $run->id )->status );
		as_unschedule_all_actions( JobQueue::HOOK_PROCESS );
	}

	public function test_remote_json_source_end_to_end_without_leaking_credentials(): void {
		list( , $vs ) = $this->variable( 'J', array( 'J-RED' => '100' ) );
		$this->simple( 'J-2', '50', 3 );
		$repo   = $this->plugin()->sources();
		$s      = new Source();
		$s->name = 'Supplier API';
		$s->type = SourceTypes::JSON_URL;
		$cfg    = $repo->build_config(
			SourceTypes::JSON_URL,
			array(
				'url'          => 'https://93.184.216.34/api/v1/products?api_key=TOPSECRET',
				'auth_type'    => 'bearer',
				'auth_secret'  => 'BEARERSECRET',
				'records_path' => 'data.items',
			)
		);
		$this->assertIsArray( $cfg );
		$s->config  = $cfg;
		$s->mapping = array(
			'sku'            => array( 'column' => 'code', 'enabled' => true ),
			'regular_price'  => array( 'column' => 'price.amount', 'enabled' => true ),
			'stock_quantity' => array( 'column' => 'qty', 'enabled' => true ),
		);
		$repo->save( $s );
		$auth = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( &$auth ) {
				$auth = $args['headers']['Authorization'] ?? null;
				file_put_contents( $args['filename'], '{"data":{"items":[{"code":"J-RED","price":{"amount":"۱۲۰"},"qty":4},{"code":"J-2","price":{"amount":55.5},"qty":"0"}]}}' );
				return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] );
			},
			10,
			2
		);
		$info = $this->plugin()->stager()->inspect( $this->plugin()->sources()->find( $s->id ) );
		$this->assertIsArray( $info );
		$this->assertContains( 'price.amount', $info['columns'] );

		$run = $this->complete( $this->start( $repo->find( $s->id ), Run::MODE_APPLY )->id );
		$this->assertSame( 'Bearer BEARERSECRET', $auth );
		$this->assertSame( Run::STATUS_COMPLETED, $run->status, $run->message );
		$this->assertSame( 2, $run->updated );
		$this->assertSame( '120.00', $this->product( $vs[0]->get_id() )->get_regular_price() );

		global $wpdb;
		$dump = $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT(message, IFNULL(context,'')) SEPARATOR ' ') FROM {$wpdb->prefix}msp_logs" );
		$dump .= $wpdb->get_var( "SELECT GROUP_CONCAT(settings_snapshot) FROM {$wpdb->prefix}msp_runs" );
		$dump .= $wpdb->get_var( "SELECT GROUP_CONCAT(config) FROM {$wpdb->prefix}msp_sources" );
		$this->assertStringNotContainsString( 'TOPSECRET', (string) $dump );
		$this->assertStringNotContainsString( 'BEARERSECRET', (string) $dump );
	}

	public function test_remote_failure_marks_run_failed_with_redacted_log(): void {
		$repo    = $this->plugin()->sources();
		$s       = new Source();
		$s->name = 'Broken';
		$s->type = SourceTypes::CSV_URL;
		$s->config  = $repo->build_config( SourceTypes::CSV_URL, array( 'url' => 'https://93.184.216.34/feed.csv?token=LEAKME' ) );
		$s->mapping = $this->mapping();
		$repo->save( $s );
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'cURL error 28: timeout for https://93.184.216.34/feed.csv?token=LEAKME' );
			}
		);
		$run = $this->complete( $this->start( $repo->find( $s->id ), Run::MODE_APPLY )->id );
		$this->assertSame( Run::STATUS_FAILED, $run->status );
		$this->assertStringNotContainsString( 'LEAKME', $run->message );
		$logs = $this->plugin()->log_repository()->paginate( array( 'run_id' => $run->id ), 1, 50 )['items'];
		$this->assertNotEmpty( $logs );
		foreach ( $logs as $l ) {
			$this->assertStringNotContainsString( 'LEAKME', $l->message . $l->context );
		}
	}

	public function test_xlsx_source_end_to_end(): void {
		$p     = $this->simple( 'X-1', '10', 1 );
		$sheet = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>45.5</v></c></row>';
		$path  = XlsxParserTest::make_xlsx( $sheet, array( 'sku', 'price', 'X-1' ), $this->temp_file( '', '.xlsx' ) );
		$name  = $this->stored_file( (string) file_get_contents( $path ), 'xlsx' );
		$s          = new Source();
		$s->name    = 'Excel';
		$s->type    = SourceTypes::XLSX_UPLOAD;
		$s->config  = array( 'file' => $name, 'sheet' => 1 );
		$s->mapping = $this->mapping( array( 'regular_price' ) );
		$this->plugin()->sources()->save( $s );
		$run = $this->complete( $this->start( $s, Run::MODE_APPLY )->id );
		$this->assertSame( 1, $run->updated, $run->message );
		$this->assertSame( '45.50', $this->product( $p->get_id() )->get_regular_price() );
	}

	public function test_missing_mapped_column_fails_staging_cleanly(): void {
		$s   = $this->csv_source( "code,amount\nA,1\n" );
		$run = $this->complete( $this->start( $s )->id );
		$this->assertSame( Run::STATUS_FAILED, $run->status );
		$this->assertStringContainsString( 'sku', $run->message );
	}

	public function test_retention_purges_old_runs_logs_and_files(): void {
		$s   = $this->csv_source( "sku,price,stock\nZ,1,1\n" );
		$run = $this->complete( $this->start( $s )->id );
		$staged = $this->plugin()->storage()->path( $run->staged_file );
		$this->assertFileExists( $staged );
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		$wpdb->update( $wpdb->prefix . 'msp_runs', array( 'created_at' => $old ), array( 'id' => $run->id ) );
		$wpdb->update( $wpdb->prefix . 'msp_logs', array( 'created_at' => $old ), array( 'run_id' => $run->id ) );
		$this->settings( array( 'history_retention_days' => 30, 'log_retention_days' => 30 ) );
		$r = $this->plugin()->retention()->run();
		$this->assertGreaterThanOrEqual( 1, $r['runs'] );
		$this->assertGreaterThanOrEqual( 1, $r['logs'] );
		$this->assertNull( $this->plugin()->runs()->find( $run->id ) );
		$this->assertSame( 0, $this->plugin()->items()->paginate( $run->id, array(), 1, 10 )['total'] );
		$this->assertFileDoesNotExist( $staged );
		$this->assertTrue( $this->plugin()->storage()->exists( (string) $s->opt( 'file' ) ), 'Source files are kept.' );
	}
}
