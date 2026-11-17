<?php
/**
 * Logger.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Logging;

use MicroStudio\MicroSyncPro\Repository\LogRepository;
use MicroStudio\MicroSyncPro\Security\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Writes redacted log entries; never stores credentials or full URLs.
 */
class Logger {

	/** @var LogRepository */
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $repo Repo.
	 */
	public function __construct( LogRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Log.
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @param int|null            $run_id  Run.
	 */
	public function log( string $level, string $message, array $context = array(), ?int $run_id = null ): void {
		$ctx = Redactor::context( $context );
		$this->repo->insert( $level, Redactor::text( $message ), is_array( $ctx ) ? $ctx : array(), $run_id );
	}

	/**
	 * Info.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @param int|null            $run_id  Run.
	 */
	public function info( string $message, array $context = array(), ?int $run_id = null ): void {
		$this->log( 'info', $message, $context, $run_id );
	}

	/**
	 * Warning.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @param int|null            $run_id  Run.
	 */
	public function warning( string $message, array $context = array(), ?int $run_id = null ): void {
		$this->log( 'warning', $message, $context, $run_id );
	}

	/**
	 * Error.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @param int|null            $run_id  Run.
	 */
	public function error( string $message, array $context = array(), ?int $run_id = null ): void {
		$this->log( 'error', $message, $context, $run_id );
	}
}
