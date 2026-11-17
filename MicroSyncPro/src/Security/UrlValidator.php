<?php
/**
 * Remote URL validation with SSRF protection.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates remote source URLs: scheme, credentials, port, host name and every
 * resolved IP address (blocking private, loopback, link-local, reserved,
 * CGNAT, multicast and IPv4-mapped ranges).
 */
class UrlValidator {

	/**
	 * DNS resolver: callable(string $host): string[]  (injectable for tests).
	 *
	 * @var callable|null
	 */
	private $resolver;

	/** Blocked IPv4 CIDRs. */
	const BLOCKED_V4 = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'255.255.255.255/32',
	);

	/** Blocked IPv6 CIDRs. */
	const BLOCKED_V6 = array(
		'::/128',
		'::1/128',
		'::ffff:0:0/96',
		'64:ff9b::/96',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'2002::/16',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	);

	/**
	 * Constructor.
	 *
	 * @param callable|null $resolver Optional resolver.
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver;
	}

	/**
	 * Allowed ports.
	 *
	 * @return int[]
	 */
	public function allowed_ports(): array {
		/**
		 * Filter the destination ports allowed for remote sources.
		 *
		 * @param int[] $ports Ports.
		 */
		$ports = apply_filters( 'micro_sync_pro_allowed_ports', array( 80, 443, 8080, 8443 ) );
		return array_values( array_filter( array_map( 'absint', (array) $ports ) ) );
	}

	/**
	 * Validate a URL. Performs DNS resolution.
	 *
	 * @param string $url URL.
	 * @return array{url:string,scheme:string,host:string,port:int,ips:string[]}|WP_Error
	 */
	public function validate( string $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return new WP_Error( 'msp_url_empty', __( 'The URL is empty.', 'micro-sync-pro' ) );
		}
		if ( strlen( $url ) > 2048 ) {
			return new WP_Error( 'msp_url_long', __( 'The URL is too long.', 'micro-sync-pro' ) );
		}
		if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return new WP_Error( 'msp_url_chars', __( 'The URL contains invalid characters.', 'micro-sync-pro' ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'msp_url_invalid', __( 'The URL is not valid.', 'micro-sync-pro' ) );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'msp_url_scheme', __( 'Only http and https URLs are allowed.', 'micro-sync-pro' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'msp_url_userinfo', __( 'Credentials inside the URL are not allowed. Use the authentication fields instead.', 'micro-sync-pro' ) );
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, $this->allowed_ports(), true ) ) {
			return new WP_Error( 'msp_url_port', __( 'The URL port is not allowed.', 'micro-sync-pro' ) );
		}

		$host = strtolower( trim( $parts['host'], '[]' ) );
		$host = rtrim( $host, '.' );

		if ( $this->host_explicitly_allowed( $host ) ) {
			$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : $this->resolve( $host );
			if ( empty( $ips ) ) {
				return new WP_Error( 'msp_url_dns', __( 'The URL host name could not be resolved.', 'micro-sync-pro' ) );
			}
			return compact( 'url', 'scheme', 'host', 'port', 'ips' );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! self::is_public_ip( $host ) ) {
				return new WP_Error( 'msp_url_private', __( 'Requests to private, loopback or reserved addresses are not allowed.', 'micro-sync-pro' ) );
			}
			$ips = array( $host );
			return compact( 'url', 'scheme', 'host', 'port', 'ips' );
		}

		if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host ) ) {
			return new WP_Error( 'msp_url_host', __( 'The URL host name is not valid.', 'micro-sync-pro' ) );
		}
		if ( preg_match( '/(^|\.)(localhost|local|localdomain|internal|intranet|lan|home|corp|arpa)$/', $host ) ) {
			return new WP_Error( 'msp_url_private', __( 'Requests to private, loopback or reserved addresses are not allowed.', 'micro-sync-pro' ) );
		}

		$ips = $this->resolve( $host );
		if ( empty( $ips ) ) {
			return new WP_Error( 'msp_url_dns', __( 'The URL host name could not be resolved.', 'micro-sync-pro' ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new WP_Error( 'msp_url_private', __( 'Requests to private, loopback or reserved addresses are not allowed.', 'micro-sync-pro' ) );
			}
		}
		return compact( 'url', 'scheme', 'host', 'port', 'ips' );
	}

	/**
	 * Whether a host was explicitly allowed by the site owner (e.g. a local test server).
	 *
	 * @param string $host Host.
	 */
	private function host_explicitly_allowed( string $host ): bool {
		/**
		 * Filter host names that bypass the private-network check. Empty by default.
		 * Only use this for trusted internal suppliers.
		 *
		 * @param string[] $hosts Allowed hosts.
		 */
		$allowed = (array) apply_filters( 'micro_sync_pro_trusted_hosts', array() );
		return in_array( $host, array_map( 'strtolower', $allowed ), true );
	}

	/**
	 * Resolve a host name to all A and AAAA records.
	 *
	 * @param string $host Host.
	 * @return string[]
	 */
	public function resolve( string $host ): array {
		if ( null !== $this->resolver ) {
			return array_values( array_filter( (array) call_user_func( $this->resolver, $host ), 'is_string' ) );
		}
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are handled below.
			if ( is_array( $records ) ) {
				foreach ( $records as $r ) {
					if ( ! empty( $r['ipv6'] ) ) {
						$ips[] = $r['ipv6'];
					}
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Whether an IP is publicly routable.
	 *
	 * @param string $ip IP address.
	 */
	public static function is_public_ip( string $ip ): bool {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			foreach ( self::BLOCKED_V4 as $cidr ) {
				if ( self::in_cidr( $ip, $cidr ) ) {
					return false;
				}
			}
			return true;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			foreach ( self::BLOCKED_V6 as $cidr ) {
				if ( self::in_cidr( $ip, $cidr ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * CIDR membership for IPv4/IPv6.
	 *
	 * @param string $ip   IP.
	 * @param string $cidr CIDR.
	 */
	public static function in_cidr( string $ip, string $cidr ): bool {
		list( $net, $bits ) = explode( '/', $cidr );
		$ip_bin  = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$net_bin = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		if ( $rem ) {
			$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
			if ( ( $ip_bin[ $bytes ] & $mask ) !== ( $net_bin[ $bytes ] & $mask ) ) {
				return false;
			}
		}
		return true;
	}
}
