<?php
/**
 * Converts a source into a normalized staging file.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sources;

use MicroStudio\MicroSyncPro\Mapping\FieldMapper;
use MicroStudio\MicroSyncPro\Parser\ParseException;
use MicroStudio\MicroSyncPro\Parser\ParserFactory;
use MicroStudio\MicroSyncPro\Repository\SourceRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Staging streams the source once, keeps only mapped fields, writes one JSON
 * line per record and counts SKUs to detect duplicates. Batches later read the
 * staging file from a byte offset, so memory use is independent of file size
 * and every batch of a run (and its later apply/resume) sees the same snapshot.
 */
class SourceStager {

	/** @var ParserFactory */
	private $parsers;
	/** @var FieldMapper */
	private $mapper;
	/** @var RemoteFetcher */
	private $fetcher;
	/** @var Storage */
	private $storage;
	/** @var SourceRepository */
	private $sources;

	/**
	 * Constructor.
	 *
	 * @param ParserFactory    $parsers Parsers.
	 * @param FieldMapper      $mapper  Mapper.
	 * @param RemoteFetcher    $fetcher Fetcher.
	 * @param Storage          $storage Storage.
	 * @param SourceRepository $sources Sources.
	 */
	public function __construct( ParserFactory $parsers, FieldMapper $mapper, RemoteFetcher $fetcher, Storage $storage, SourceRepository $sources ) {
		$this->parsers = $parsers;
		$this->mapper  = $mapper;
		$this->fetcher = $fetcher;
		$this->storage = $storage;
		$this->sources = $sources;
	}

	/**
	 * Obtain a local raw file for the source.
	 *
	 * @param Source $source Source.
	 * @return array{name:string,temporary:bool}|WP_Error
	 */
	public function acquire( Source $source ) {
		if ( $source->is_remote() ) {
			$url = $this->sources->remote_url( $source );
			if ( '' === $url ) {
				return new WP_Error( 'msp_url_missing', __( 'The remote URL could not be read. Edit the source and enter the URL again.', 'micro-sync-pro' ) );
			}
			$name = $this->fetcher->download( $url, $this->sources->auth_headers( $source ), $source->format() );
			if ( is_wp_error( $name ) ) {
				return $name;
			}
			return array(
				'name'      => $name,
				'temporary' => true,
			);
		}
		$file = (string) $source->opt( 'file', '' );
		if ( '' === $file || ! $this->storage->exists( $file ) ) {
			return new WP_Error( 'msp_file_missing', __( 'No file has been uploaded for this source.', 'micro-sync-pro' ) );
		}
		return array(
			'name'      => $file,
			'temporary' => false,
		);
	}

	/**
	 * Detect columns and a few sample rows (for the mapping screen).
	 *
	 * @param Source $source Source.
	 * @return array{columns:string[],sample:array<int,array<string,string>>}|WP_Error
	 */
	public function inspect( Source $source ) {
		$raw = $this->acquire( $source );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		try {
			$parser  = $this->parsers->for_format( $source->format() );
			$path    = $this->storage->path( $raw['name'] );
			$columns = $parser->headers( $path, $source->parser_options() );
			$sample  = array();
			foreach ( $parser->rows( $path, $source->parser_options() ) as $row ) {
				$sample[] = $row;
				if ( count( $sample ) >= 5 ) {
					break;
				}
			}
			return array(
				'columns' => $columns,
				'sample'  => $sample,
			);
		} catch ( ParseException $e ) {
			return new WP_Error( 'msp_parse', $e->getMessage() );
		} finally {
			if ( $raw['temporary'] ) {
				$this->storage->delete( $raw['name'] );
			}
		}
	}

	/**
	 * Stage a source.
	 *
	 * @param Source $source Source.
	 * @return array{file:string,dup_file:string,total:int,duplicates:int,empty_sku:int}|WP_Error
	 */
	public function stage( Source $source ) {
		if ( ! $this->mapper->is_complete( $source->mapping ) ) {
			return new WP_Error( 'msp_mapping_incomplete', __( 'The field mapping for this source is incomplete. Map SKU and at least one field.', 'micro-sync-pro' ) );
		}
		$raw = $this->acquire( $source );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$staged = $this->storage->new_name( 'jsonl', 'stage' );
		$out    = null;
		try {
			$parser  = $this->parsers->for_format( $source->format() );
			$path    = $this->storage->path( $raw['name'] );
			$headers = $parser->headers( $path, $source->parser_options() );
			$missing = $this->mapper->missing_columns( $source->mapping, $headers );
			if ( ! empty( $missing ) ) {
				return new WP_Error(
					'msp_columns_missing',
					sprintf(
						/* translators: %s: list of column names. */
						__( 'Mapped columns are missing from the source: %s', 'micro-sync-pro' ),
						implode( ', ', $missing )
					)
				);
			}
			$out = fopen( $this->storage->path( $staged ), 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $out ) {
				return new WP_Error( 'msp_storage', __( 'The staging file could not be created.', 'micro-sync-pro' ) );
			}
			$total     = 0;
			$empty_sku = 0;
			$counts    = array();
			foreach ( $parser->rows( $path, $source->parser_options() ) as $row_num => $row ) {
				$data = $this->mapper->extract( $row, $source->mapping );
				$sku  = trim( (string) ( $data['sku'] ?? '' ) );
				if ( '' === $sku ) {
					++$empty_sku;
				} else {
					$counts[ $sku ] = ( $counts[ $sku ] ?? 0 ) + 1;
				}
				fwrite( $out, wp_json_encode( array( 'r' => (int) $row_num, 'd' => $data ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				++$total;
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$out = null;

			$dups     = array_filter(
				$counts,
				static function ( $c ) {
					return $c > 1;
				}
			);
			$dup_file = '';
			if ( ! empty( $dups ) ) {
				$dup_file = $this->storage->new_name( 'json', 'dup' );
				file_put_contents( $this->storage->path( $dup_file ), wp_json_encode( $dups ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			return array(
				'file'       => $staged,
				'dup_file'   => $dup_file,
				'total'      => $total,
				'duplicates' => count( $dups ),
				'empty_sku'  => $empty_sku,
			);
		} catch ( ParseException $e ) {
			if ( $out ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$this->storage->delete( $staged );
			return new WP_Error( 'msp_parse', $e->getMessage() );
		} finally {
			if ( $raw['temporary'] ) {
				$this->storage->delete( $raw['name'] );
			}
		}
	}

	/**
	 * Read up to $limit staged records from a byte offset.
	 *
	 * @param string $file   Staged file name.
	 * @param int    $offset Byte offset.
	 * @param int    $limit  Max records.
	 * @return array{records:array<int,array{r:int,d:array<string,string>}>,offset:int,eof:bool}|WP_Error
	 */
	public function read_batch( string $file, int $offset, int $limit ) {
		$path = $this->storage->path( $file );
		if ( '' === $path || ! is_file( $path ) ) {
			return new WP_Error( 'msp_stage_missing', __( 'The staged data for this run is no longer available.', 'micro-sync-pro' ) );
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new WP_Error( 'msp_stage_missing', __( 'The staged data for this run is no longer available.', 'micro-sync-pro' ) );
		}
		fseek( $fh, $offset );
		$records = array();
		while ( count( $records ) < $limit ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$rec = json_decode( $line, true );
			if ( is_array( $rec ) && isset( $rec['r'], $rec['d'] ) && is_array( $rec['d'] ) ) {
				$records[] = $rec;
			}
		}
		$pos = (int) ftell( $fh );
		$eof = false === fgets( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'records' => $records,
			'offset'  => $pos,
			'eof'     => $eof,
		);
	}

	/**
	 * Load the duplicate SKU map.
	 *
	 * @param string $dup_file File name.
	 * @return array<string,int>
	 */
	public function duplicates( string $dup_file ): array {
		if ( '' === $dup_file || ! $this->storage->exists( $dup_file ) ) {
			return array();
		}
		$v = json_decode( (string) file_get_contents( $this->storage->path( $dup_file ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $v ) ? $v : array();
	}
}
