<?php
/**
 * Core bootstrap and service container.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro;

use MicroStudio\MicroSyncPro\Admin\Admin;
use MicroStudio\MicroSyncPro\Database\Migrator;
use MicroStudio\MicroSyncPro\Logging\Logger;
use MicroStudio\MicroSyncPro\Logging\Retention;
use MicroStudio\MicroSyncPro\Mapping\FieldMapper;
use MicroStudio\MicroSyncPro\Matching\SkuMatcher;
use MicroStudio\MicroSyncPro\Parser\ParserFactory;
use MicroStudio\MicroSyncPro\Pricing\PricingEngine;
use MicroStudio\MicroSyncPro\Repository\ItemRepository;
use MicroStudio\MicroSyncPro\Repository\LogRepository;
use MicroStudio\MicroSyncPro\Repository\RunRepository;
use MicroStudio\MicroSyncPro\Repository\SourceRepository;
use MicroStudio\MicroSyncPro\Scheduling\JobQueue;
use MicroStudio\MicroSyncPro\Scheduling\Scheduler;
use MicroStudio\MicroSyncPro\Security\Crypto;
use MicroStudio\MicroSyncPro\Security\UrlValidator;
use MicroStudio\MicroSyncPro\Settings\Settings;
use MicroStudio\MicroSyncPro\Sources\RemoteFetcher;
use MicroStudio\MicroSyncPro\Sources\SourceStager;
use MicroStudio\MicroSyncPro\Sources\Storage;
use MicroStudio\MicroSyncPro\Sync\ProductUpdater;
use MicroStudio\MicroSyncPro\Sync\RollbackService;
use MicroStudio\MicroSyncPro\Sync\RunManager;
use MicroStudio\MicroSyncPro\Sync\SyncEngine;
use MicroStudio\MicroSyncPro\Validation\RecordValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Lazily created services.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Key passed by the protected license check (license.php) to unlock the plugin.
	 * This file is protected together with license.php, so the key is not readable.
	 */
	const UNLOCK_KEY = '003c2d08bf4929e5ab5b32220999e66b985c4ae6';

	/**
	 * Whether an active RTL-Theme license unlocked the plugin.
	 *
	 * @var bool
	 */
	private $licensed = false;

	/**
	 * Get the singleton.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot hooks. Runs on plugins_loaded.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		// Schema upgrades run regardless of WooCommerce state (tables are ours).
		Migrator::maybe_upgrade();

		if ( ! $this->licensed ) {
			add_action( 'admin_notices', array( $this, 'render_license_notice' ) );
			return;
		}

		if ( ! Dependencies::woocommerce_ready() ) {
			add_action( 'admin_notices', array( Dependencies::class, 'render_missing_notice' ) );
			return;
		}

		$this->queue()->register_hooks();
		$this->scheduler()->register_hooks();
		$this->retention()->register_hooks();

		if ( is_admin() ) {
			( new Admin( $this ) )->register_hooks();
		}

		/**
		 * Fires when Micro Sync Pro finished booting.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'micro_sync_pro_loaded', $this );
	}

	/**
	 * Unlock the plugin. Called by the protected license check when the license is active.
	 *
	 * @param string $key Unlock key.
	 */
	public function unlock( string $key ): void {
		if ( hash_equals( self::UNLOCK_KEY, $key ) ) {
			$this->licensed = true;
		}
	}

	/**
	 * Whether the plugin is unlocked by an active license.
	 */
	public function is_licensed(): bool {
		return $this->licensed;
	}

	/**
	 * Admin notice shown while the plugin is locked.
	 */
	public function render_license_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! is_file( MSP_DIR . 'RTL_License_1f777b937ec5d599.php' ) ) {
			$message = __( 'The Micro Sync Pro license file is missing. Reinstall the plugin from the original package. The plugin is inactive until then.', 'micro-sync-pro' );
		} else {
			$message = __( 'The Micro Sync Pro license is not active. Activate your license from your Rtl-Theme account to use the plugin.', 'micro-sync-pro' );
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Micro Sync Pro', 'micro-sync-pro' ) . ':</strong> ' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'micro-sync-pro', false, dirname( plugin_basename( MSP_FILE ) ) . '/languages' );
	}

	/**
	 * Capability required to use the plugin.
	 */
	public static function capability(): string {
		/**
		 * Filter the capability required to manage Micro Sync Pro.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'micro_sync_pro_capability', 'manage_woocommerce' );
	}

	/**
	 * Generic lazy service getter.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	private function get( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}

	/**
	 * Reset services (used by tests).
	 */
	public function reset_services(): void {
		$this->services = array();
	}

	/** Settings service. */
	public function settings(): Settings {
		return $this->get( 'settings', static function () { return new Settings(); } );
	}

	/** Crypto. */
	public function crypto(): Crypto {
		return $this->get( 'crypto', static function () { return new Crypto(); } );
	}

	/** Source repository. */
	public function sources(): SourceRepository {
		return $this->get( 'sources', function () { return new SourceRepository( $this->crypto() ); } );
	}

	/** Run repository. */
	public function runs(): RunRepository {
		return $this->get( 'runs', static function () { return new RunRepository(); } );
	}

	/** Run item repository. */
	public function items(): ItemRepository {
		return $this->get( 'items', static function () { return new ItemRepository(); } );
	}

	/** Log repository. */
	public function log_repository(): LogRepository {
		return $this->get( 'log_repo', static function () { return new LogRepository(); } );
	}

	/** Logger. */
	public function logger(): Logger {
		return $this->get( 'logger', function () { return new Logger( $this->log_repository() ); } );
	}

	/** File storage. */
	public function storage(): Storage {
		return $this->get( 'storage', static function () { return new Storage(); } );
	}

	/** URL validator. */
	public function url_validator(): UrlValidator {
		return $this->get( 'url_validator', static function () { return new UrlValidator(); } );
	}

	/** Remote fetcher. */
	public function fetcher(): RemoteFetcher {
		return $this->get(
			'fetcher',
			function () {
				return new RemoteFetcher( $this->url_validator(), $this->settings(), $this->storage() );
			}
		);
	}

	/** Parser factory. */
	public function parsers(): ParserFactory {
		return $this->get( 'parsers', static function () { return new ParserFactory(); } );
	}

	/** Field mapper. */
	public function mapper(): FieldMapper {
		return $this->get( 'mapper', static function () { return new FieldMapper(); } );
	}

	/** Source stager. */
	public function stager(): SourceStager {
		return $this->get(
			'stager',
			function () {
				return new SourceStager( $this->parsers(), $this->mapper(), $this->fetcher(), $this->storage(), $this->sources() );
			}
		);
	}

	/** Record validator. */
	public function validator(): RecordValidator {
		return $this->get( 'validator', function () { return new RecordValidator( $this->settings() ); } );
	}

	/** SKU matcher. */
	public function matcher(): SkuMatcher {
		return $this->get( 'matcher', static function () { return new SkuMatcher(); } );
	}

	/** Pricing engine. */
	public function pricing(): PricingEngine {
		return $this->get( 'pricing', function () { return new PricingEngine( $this->settings() ); } );
	}

	/** Product updater. */
	public function updater(): ProductUpdater {
		return $this->get(
			'updater',
			function () {
				return new ProductUpdater( $this->validator(), $this->pricing(), $this->settings() );
			}
		);
	}

	/** Rollback service. */
	public function rollback(): RollbackService {
		return $this->get( 'rollback', function () { return new RollbackService( $this->items() ); } );
	}

	/** Sync engine. */
	public function engine(): SyncEngine {
		return $this->get(
			'engine',
			function () {
				return new SyncEngine(
					$this->runs(),
					$this->items(),
					$this->sources(),
					$this->stager(),
					$this->matcher(),
					$this->updater(),
					$this->rollback(),
					$this->storage(),
					$this->settings(),
					$this->logger()
				);
			}
		);
	}

	/** Run manager (create/cancel/resume runs). */
	public function run_manager(): RunManager {
		return $this->get(
			'run_manager',
			function () {
				return new RunManager( $this->runs(), $this->sources(), $this->storage(), $this->logger(), $this->queue() );
			}
		);
	}

	/** Background job queue. */
	public function queue(): JobQueue {
		return $this->get( 'queue', function () { return new JobQueue( $this ); } );
	}

	/** Scheduler. */
	public function scheduler(): Scheduler {
		return $this->get( 'scheduler', function () { return new Scheduler( $this ); } );
	}

	/** Retention. */
	public function retention(): Retention {
		return $this->get( 'retention', function () { return new Retention( $this ); } );
	}
}
