<?php
/**
 * Admin bootstrap.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Admin;

use MicroStudio\MicroSyncPro\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers menus, assets, form handlers and AJAX endpoints.
 */
class Admin {

	/** @var Plugin */
	private $plugin;

	/** @var string[] Hook suffixes of plugin screens. */
	private $screens = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MSP_FILE ), array( $this, 'action_links' ) );
		( new Actions( $this->plugin ) )->register_hooks();
		( new Ajax( $this->plugin ) )->register_hooks();
	}

	/**
	 * Register menu pages.
	 */
	public function menu(): void {
		$cap   = Plugin::capability();
		$pages = new Pages( $this->plugin );
		$this->screens[] = add_menu_page(
			__( 'Micro Sync Pro', 'micro-sync-pro' ),
			__( 'Micro Sync', 'micro-sync-pro' ),
			$cap,
			'msp-dashboard',
			array( $pages, 'dashboard' ),
			'dashicons-update-alt',
			56
		);
		$callbacks = array(
			'msp-dashboard' => 'dashboard',
			'msp-sources'   => 'sources',
			'msp-sync'      => 'sync',
			'msp-mapping'   => 'mapping',
			'msp-schedule'  => 'schedule',
			'msp-history'   => 'history',
			'msp-logs'      => 'logs',
			'msp-settings'  => 'settings',
		);
		foreach ( View::pages() as $slug => $title ) {
			$this->screens[] = add_submenu_page( 'msp-dashboard', $title . ' ‹ ' . __( 'Micro Sync Pro', 'micro-sync-pro' ), $title, $cap, $slug, array( $pages, $callbacks[ $slug ] ) );
		}
	}

	/**
	 * Enqueue assets on plugin screens only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ): void {
		if ( ! in_array( $hook, $this->screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'msp-admin', MSP_URL . 'assets/css/admin.css', array(), MSP_VERSION );
		wp_enqueue_script( 'msp-admin', MSP_URL . 'assets/js/admin.js', array(), MSP_VERSION, true );
		wp_localize_script(
			'msp-admin',
			'MSP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'msp_ajax' ),
				'i18n'    => array(
					'queued'        => __( 'Queued', 'micro-sync-pro' ),
					'running'       => __( 'Running', 'micro-sync-pro' ),
					'cancelling'    => __( 'Cancelling…', 'micro-sync-pro' ),
					'completed'     => __( 'Completed', 'micro-sync-pro' ),
					'failed'        => __( 'Failed', 'micro-sync-pro' ),
					'cancelled'     => __( 'Cancelled', 'micro-sync-pro' ),
					'interrupted'   => __( 'Interrupted', 'micro-sync-pro' ),
					'prepare'       => __( 'Reading and validating the source…', 'micro-sync-pro' ),
					'process'       => __( 'Processing records…', 'micro-sync-pro' ),
					'finalize'      => __( 'Finishing…', 'micro-sync-pro' ),
					'networkError'  => __( 'Connection problem. Retrying…', 'micro-sync-pro' ),
					'confirmCancel' => __( 'Stop this synchronization after the current batch?', 'micro-sync-pro' ),
					'reloading'     => __( 'Finished. Loading results…', 'micro-sync-pro' ),
				),
			)
		);
	}

	/**
	 * Plugin list links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( View::url( 'msp-settings' ) ) . '">' . esc_html__( 'Settings', 'micro-sync-pro' ) . '</a>' );
		array_unshift( $links, '<a href="' . esc_url( View::url( 'msp-dashboard' ) ) . '">' . esc_html__( 'Dashboard', 'micro-sync-pro' ) . '</a>' );
		return $links;
	}
}
