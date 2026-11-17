<?php
/**
 * Security validation tests.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Unit;

use MicroStudio\MicroSyncPro\Admin\Actions;
use MicroStudio\MicroSyncPro\Mapping\FieldMapper;
use MicroStudio\MicroSyncPro\Security\Crypto;
use MicroStudio\MicroSyncPro\Security\Redactor;
use MicroStudio\MicroSyncPro\Security\UploadHandler;
use MicroStudio\MicroSyncPro\Security\UrlValidator;
use MicroStudio\MicroSyncPro\Settings\Settings;
use MicroStudio\MicroSyncPro\Sources\RemoteFetcher;
use MicroStudio\MicroSyncPro\Sources\Storage;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * Test upload handler that accepts local files as "uploaded".
 */
class TestUploadHandler extends UploadHandler {
	protected function is_uploaded( string $tmp ): bool {
		return is_file( $tmp );
	}
	protected function move( string $from, string $to ): bool {
		return copy( $from, $to );
	}
}

/**
 * @covers \MicroStudio\MicroSyncPro\Security\UrlValidator
 * @covers \MicroStudio\MicroSyncPro\Security\UploadHandler
 * @covers \MicroStudio\MicroSyncPro\Security\Redactor
 * @covers \MicroStudio\MicroSyncPro\Security\Crypto
 * @covers \MicroStudio\MicroSyncPro\Sources\RemoteFetcher
 * @covers \MicroStudio\MicroSyncPro\Sources\Storage
 * @covers \MicroStudio\MicroSyncPro\Mapping\FieldMapper
 */
class SecurityTest extends TestCase {

	private function validator( array $dns = array() ): UrlValidator {
		return new UrlValidator(
			static function ( $host ) use ( $dns ) {
				return $dns[ $host ] ?? array();
			}
		);
	}

	/**
	 * @dataProvider blocked_urls
	 */
	public function test_ssrf_blocked_urls( string $url, array $dns = array() ): void {
		$this->assertWPError( $this->validator( $dns )->validate( $url ), $url );
	}

	public function blocked_urls(): array {
		return array(
			'loopback ip'          => array( 'http://127.0.0.1/feed.csv' ),
			'loopback alt'         => array( 'http://127.1.2.3/' ),
			'private 10'           => array( 'http://10.0.0.5/' ),
			'private 172'          => array( 'https://172.20.1.1/' ),
			'private 192'          => array( 'http://192.168.1.1/' ),
			'link-local metadata'  => array( 'http://169.254.169.254/latest/meta-data/' ),
			'cgnat'                => array( 'http://100.64.0.1/' ),
			'zero'                 => array( 'http://0.0.0.0/' ),
			'ipv6 loopback'        => array( 'http://[::1]/' ),
			'ipv6 ula'             => array( 'http://[fd00::1]/' ),
			'ipv6 link-local'      => array( 'http://[fe80::1]/' ),
			'ipv4-mapped ipv6'     => array( 'http://[::ffff:127.0.0.1]/' ),
			'localhost name'       => array( 'http://localhost/feed.csv' ),
			'local tld'            => array( 'http://printer.local/' ),
			'internal tld'         => array( 'http://api.internal/' ),
			'dns to private'       => array( 'https://evil.example.com/', array( 'evil.example.com' => array( '10.1.1.1' ) ) ),
			'dns mixed'            => array( 'https://mixed.example.com/', array( 'mixed.example.com' => array( '93.184.216.34', '127.0.0.1' ) ) ),
			'dns unresolvable'     => array( 'https://nxdomain.example.com/' ),
			'file scheme'          => array( 'file:///etc/passwd' ),
			'gopher scheme'        => array( 'gopher://example.com/' ),
			'ftp scheme'           => array( 'ftp://example.com/x.csv' ),
			'userinfo'             => array( 'https://user:pass@example.com/', array( 'example.com' => array( '93.184.216.34' ) ) ),
			'port'                 => array( 'http://example.com:6379/', array( 'example.com' => array( '93.184.216.34' ) ) ),
			'empty'                => array( '' ),
			'backslash'            => array( 'http://example.com\\@127.0.0.1/' ),
			'whitespace'           => array( "http://exa mple.com/" ),
			'decimal ip'           => array( 'http://2130706433/' ),
		);
	}

	public function test_public_url_is_allowed_with_resolved_ips(): void {
		$r = $this->validator( array( 'feeds.example.com' => array( '93.184.216.34' ) ) )->validate( 'https://feeds.example.com/prices.csv?token=x' );
		$this->assertIsArray( $r );
		$this->assertSame( 443, $r['port'] );
		$this->assertSame( array( '93.184.216.34' ), $r['ips'] );
		$this->assertIsArray( $this->validator()->validate( 'http://8.8.8.8:8080/a.json' ) );
	}

	public function test_trusted_hosts_filter_can_allow_internal_supplier(): void {
		add_filter( 'micro_sync_pro_trusted_hosts', static function () { return array( 'erp.internal' ); } );
		$r = $this->validator( array( 'erp.internal' => array( '10.0.0.9' ) ) )->validate( 'http://erp.internal/stock.json' );
		remove_all_filters( 'micro_sync_pro_trusted_hosts' );
		$this->assertIsArray( $r );
	}

	public function test_cidr_helper(): void {
		$this->assertTrue( UrlValidator::in_cidr( '172.31.255.255', '172.16.0.0/12' ) );
		$this->assertFalse( UrlValidator::in_cidr( '172.32.0.0', '172.16.0.0/12' ) );
		$this->assertFalse( UrlValidator::is_public_ip( 'not-an-ip' ) );
		$this->assertTrue( UrlValidator::is_public_ip( '2606:4700:4700::1111' ) );
	}

	public function test_remote_fetcher_rejects_redirect_to_private_ip(): void {
		$fetcher = new RemoteFetcher( $this->validator( array( 'good.example.com' => array( '93.184.216.34' ) ) ), new Settings(), new Storage() );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				return array(
					'headers'  => array( 'location' => 'http://169.254.169.254/latest' ),
					'body'     => '',
					'response' => array( 'code' => 302, 'message' => 'Found' ),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			3
		);
		$r = $fetcher->download( 'https://good.example.com/feed.csv', array(), 'csv' );
		$this->assertWPError( $r );
		$this->assertSame( 'msp_url_private', $r->get_error_code() );
	}

	public function test_remote_fetcher_validates_content_type_size_and_body(): void {
		$fetcher = new RemoteFetcher( $this->validator( array( 'good.example.com' => array( '93.184.216.34' ) ) ), new Settings(), new Storage() );
		$respond = function ( $body, $type, $code = 200 ) {
			add_filter(
				'pre_http_request',
				static function ( $pre, $args ) use ( $body, $type, $code ) {
					file_put_contents( $args['filename'], $body );
					return array(
						'headers'  => array( 'content-type' => $type ),
						'body'     => '',
						'response' => array( 'code' => $code, 'message' => '' ),
						'cookies'  => array(),
						'filename' => $args['filename'],
					);
				},
				10,
				2
			);
		};
		$respond( '<html><body>Login</body></html>', 'text/html' );
		$this->assertSame( 'msp_remote_type', $fetcher->download( 'https://good.example.com/a.csv', array(), 'csv' )->get_error_code() );
		remove_all_filters( 'pre_http_request' );

		$respond( '<!DOCTYPE html><p>x</p>', 'text/plain' );
		$this->assertSame( 'msp_remote_content', $fetcher->download( 'https://good.example.com/a.csv', array(), 'csv' )->get_error_code() );
		remove_all_filters( 'pre_http_request' );

		$respond( 'sku,price', 'application/json' );
		$this->assertSame( 'msp_remote_content', $fetcher->download( 'https://good.example.com/a.json', array(), 'json' )->get_error_code() );
		remove_all_filters( 'pre_http_request' );

		$respond( 'nope', 'text/plain', 403 );
		$this->assertSame( 'msp_remote_status', $fetcher->download( 'https://good.example.com/a.csv', array(), 'csv' )->get_error_code() );
		remove_all_filters( 'pre_http_request' );

		$this->settings( array( 'max_remote_mb' => 1 ) );
		$respond( str_repeat( "A,1\n", 300000 ), 'text/csv' );
		$this->assertSame( 'msp_remote_size', $fetcher->download( 'https://good.example.com/a.csv', array(), 'csv' )->get_error_code() );
		remove_all_filters( 'pre_http_request' );

		$respond( "sku,price\nA,1\n", 'text/csv; charset=utf-8' );
		$name = $fetcher->download( 'https://good.example.com/a.csv', array(), 'csv' );
		$this->assertIsString( $name );
		$storage = new Storage();
		$this->assertTrue( $storage->exists( $name ) );
		$storage->delete( $name );
	}

	public function test_credentials_not_forwarded_to_other_host_on_redirect(): void {
		$seen    = array();
		$fetcher = new RemoteFetcher(
			$this->validator(
				array(
					'api.example.com' => array( '93.184.216.34' ),
					'cdn.example.net' => array( '93.184.216.35' ),
				)
			),
			new Settings(),
			new Storage()
		);
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen[ $url ] = $args['headers'];
				if ( false !== strpos( $url, 'api.example.com' ) ) {
					return array( 'headers' => array( 'location' => 'https://cdn.example.net/file.json' ), 'body' => '', 'response' => array( 'code' => 302, 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] );
				}
				file_put_contents( $args['filename'], '[{"sku":"A"}]' );
				return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] );
			},
			10,
			3
		);
		$name = $fetcher->download( 'https://api.example.com/v1/items', array( 'Authorization' => 'Bearer SECRET' ), 'json' );
		$this->assertIsString( $name );
		( new Storage() )->delete( $name );
		$this->assertSame( 'Bearer SECRET', $seen['https://api.example.com/v1/items']['Authorization'] );
		$this->assertArrayNotHasKey( 'Authorization', $seen['https://cdn.example.net/file.json'] );
	}

	public function test_upload_validation(): void {
		$storage = new Storage();
		$handler = new TestUploadHandler( $storage, 1024 * 1024 );

		$php = $this->temp_file( "sku,price\n<?php system('id'); ?>,1\n" );
		$this->assertWPError( $handler->handle( array( 'name' => 'x.csv', 'tmp_name' => $php, 'error' => 0, 'size' => filesize( $php ) ), 'csv' ) );

		$bin = $this->temp_file( "sku\0price" );
		$this->assertWPError( $handler->handle( array( 'name' => 'x.csv', 'tmp_name' => $bin, 'error' => 0 ), 'csv' ) );

		$ok = $this->temp_file( "sku,price\nA,1\n" );
		$this->assertWPError( $handler->handle( array( 'name' => 'shell.php', 'tmp_name' => $ok, 'error' => 0 ), 'csv' ), 'Extension must match.' );
		$this->assertWPError( $handler->handle( array( 'name' => 'x.csv.php', 'tmp_name' => $ok, 'error' => 0 ), 'csv' ) );
		$this->assertWPError( $handler->handle( array( 'name' => 'x.csv', 'tmp_name' => $ok, 'error' => UPLOAD_ERR_INI_SIZE ), 'csv' ) );
		$this->assertWPError( $handler->handle( array( 'name' => 'fake.xlsx', 'tmp_name' => $ok, 'error' => 0 ), 'xlsx' ), 'XLSX must be a zip workbook.' );
		$this->assertWPError( ( new TestUploadHandler( $storage, 5 ) )->handle( array( 'name' => 'x.csv', 'tmp_name' => $ok, 'error' => 0 ), 'csv' ), 'Size limit.' );
		$real = new UploadHandler( $storage, 1024 * 1024 );
		$this->assertWPError( $real->handle( array( 'name' => 'x.csv', 'tmp_name' => $ok, 'error' => 0 ), 'csv' ), 'Non-HTTP uploads are rejected.' );

		$res = $handler->handle( array( 'name' => '../../evil name.csv', 'tmp_name' => $ok, 'error' => 0 ), 'csv' );
		$this->assertIsArray( $res );
		$this->assertMatchesRegularExpression( '/^u-[a-f0-9]{32}\.csv$/', $res['name'], 'Stored name is random, never the client name.' );
		$this->assertSame( 'evil-name.csv', $res['original'] );
		$this->assertTrue( $storage->exists( $res['name'] ) );
		$storage->delete( $res['name'] );

		$zip = XlsxParserTest::make_xlsx( '<row r="1"/>', array(), $this->temp_file( '', '.xlsx' ) );
		$z   = new \ZipArchive();
		$z->open( $zip );
		$z->addFromString( 'xl/evil.php', '<?php ?>' );
		$z->close();
		$this->assertWPError( $handler->handle( array( 'name' => 'book.xlsx', 'tmp_name' => $zip, 'error' => 0 ), 'xlsx' ), 'Archives with executable payloads are rejected.' );
	}

	public function test_storage_rejects_path_traversal_names(): void {
		$s = new Storage();
		foreach ( array( '../wp-config.php', 'u-abc/../../x.csv', '/etc/passwd', 'shell.php', 'u-' . str_repeat( 'a', 32 ) . '.php', '' ) as $bad ) {
			$this->assertSame( '', $s->path( $bad ), $bad );
		}
		$this->assertNotSame( '', $s->path( $s->new_name( 'csv' ) ) );
		$s->ensure_directory();
		$this->assertFileExists( $s->base_dir() . '/.htaccess' );
		$this->assertFileExists( $s->base_dir() . '/index.php' );
	}

	public function test_mapping_rejects_unknown_columns_and_fields(): void {
		$m = new FieldMapper();
		$this->assertWPError( $m->sanitize( array( 'sku' => array( 'column' => 'evil' ) ), array( 'sku', 'price' ) ) );
		$this->assertWPError( $m->sanitize( array( 'regular_price' => array( 'column' => 'price', 'enabled' => 1 ) ), array( 'sku', 'price' ) ), 'SKU is required.' );
		$this->assertWPError( $m->sanitize( array( 'sku' => array( 'column' => 'sku' ) ), array( 'sku', 'price' ) ), 'At least one field must be synced.' );
		$this->assertWPError( $m->sanitize( 'string', array() ) );
		$ok = $m->sanitize(
			array(
				'sku'           => array( 'column' => 'sku' ),
				'regular_price' => array( 'column' => 'price', 'enabled' => '1' ),
				'post_title'    => array( 'column' => 'price', 'enabled' => '1' ),
				'stock_status'  => array( 'column' => '', 'enabled' => '1' ),
			),
			array( 'sku', 'price' )
		);
		$this->assertIsArray( $ok );
		$this->assertArrayNotHasKey( 'post_title', $ok );
		$this->assertFalse( $ok['stock_status']['enabled'], 'Enabled without column is disabled.' );
		$this->assertSame( array( 'sku', 'regular_price' ), FieldMapper::enabled_fields( $ok ) );
	}

	public function test_redaction(): void {
		$this->assertSame( 'https://api.example.com/…?…', Redactor::url( 'https://user:pw@API.example.com/v1/items?token=abc' ) );
		$text = Redactor::text( 'Failed GET https://api.example.com/x?key=SECRET with Bearer abcdef123456' );
		$this->assertStringNotContainsString( 'SECRET', $text );
		$this->assertStringNotContainsString( 'abcdef123456', $text );
		$ctx = Redactor::context( array( 'token' => 'x', 'nested' => array( 'password' => 'y', 'url' => 'https://h.example/p?q=1' ), 'count' => 3 ) );
		$this->assertSame( '[redacted]', $ctx['token'] );
		$this->assertSame( '[redacted]', $ctx['nested']['password'] );
		$this->assertSame( 'https://h.example/…?…', $ctx['nested']['url'] );
		$this->assertSame( 3, $ctx['count'] );
	}

	public function test_crypto_roundtrip_and_tamper(): void {
		$c   = new Crypto();
		$enc = $c->encrypt( 'super-secret-token' );
		$this->assertStringNotContainsString( 'super-secret', $enc );
		$this->assertSame( 'super-secret-token', $c->decrypt( $enc ) );
		$this->assertNotSame( $enc, $c->encrypt( 'super-secret-token' ), 'Random nonce.' );
		$this->assertSame( '', $c->decrypt( substr( $enc, 0, -4 ) . 'AAAA' ) );
		$this->assertSame( '', $c->decrypt( 'plain' ) );
	}

	public function test_source_secrets_are_encrypted_and_header_names_validated(): void {
		$repo = $this->plugin()->sources();
		$cfg  = $repo->build_config( 'json_url', array( 'url' => 'https://api.example.com/items?key=abc', 'auth_type' => 'bearer', 'auth_secret' => 'TOKEN123' ) );
		$this->assertIsArray( $cfg );
		$this->assertStringNotContainsString( 'TOKEN123', wp_json_encode( $cfg ) );
		$this->assertStringNotContainsString( 'key=abc', wp_json_encode( $cfg ) );
		$this->assertWPError( $repo->build_config( 'json_url', array( 'url' => 'https://a.example.com/', 'auth_type' => 'header', 'header_name' => "X-Key\r\nHost: evil", 'auth_secret' => 'x' ) ) );
		$this->assertWPError( $repo->build_config( 'json_url', array( 'url' => 'https://a.example.com/', 'auth_type' => 'header', 'header_name' => 'Host', 'auth_secret' => 'x' ) ) );
		$this->assertWPError( $repo->build_config( 'json_url', array( 'url' => 'https://a.example.com/', 'auth_type' => 'bearer', 'auth_secret' => "a\nb" ) ) );
		$this->assertWPError( $repo->build_config( 'json_url', array( 'url' => 'https://a.example.com/', 'records_path' => 'a/../b' ) ) );
		$this->assertWPError( $repo->build_config( 'csv_url', array( 'url' => 'javascript:alert(1)' ) ) );
	}

	public function test_csv_export_neutralizes_formulas(): void {
		$this->assertSame( "'=HYPERLINK(\"x\")", Actions::csv_safe( '=HYPERLINK("x")' ) );
		$this->assertSame( "'@SUM(A1)", Actions::csv_safe( '@SUM(A1)' ) );
		$this->assertSame( '-5', Actions::csv_safe( '-5' ) );
		$this->assertSame( 'SKU-1', Actions::csv_safe( 'SKU-1' ) );
	}

	public function test_admin_actions_require_capability_and_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$actions = new Actions( $this->plugin() );
		try {
			$actions->save_settings();
			$this->fail( 'Expected wp_die.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'invalid';
		try {
			$actions->save_settings();
			$this->fail( 'Expected nonce failure.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
		unset( $_REQUEST['_wpnonce'] );
	}
}
