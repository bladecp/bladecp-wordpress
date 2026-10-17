<?php
/**
 * Incremental gzip: each step appends one complete gzip member.
 *
 * Concatenated gzip members form a valid gzip file (RFC 1952 section 2.2);
 * GNU tar, bsdtar and Go's compress/gzip (multistream by default) read them
 * as one stream. This is what makes the archive resumable across requests:
 * a deflate context cannot survive the end of a PHP request, but a finished
 * member can.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Gzip member sink.
 */
final class Gzip_Member_Sink implements Output_Sink {

	/**
	 * Handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Deflate context (created on first write).
	 *
	 * @var mixed
	 */
	private $context = null;

	/**
	 * Compression level.
	 *
	 * @var int
	 */
	private $level;

	/**
	 * Constructor.
	 *
	 * @param resource $handle Handle positioned at the end.
	 * @param int      $level  1 (fast) to 9 (small).
	 */
	public function __construct( $handle, int $level = 6 ) {
		$this->handle = $handle;
		$this->level  = $level;
	}

	/**
	 * Whether incremental zlib is available.
	 */
	public static function is_available(): bool {
		return function_exists( 'deflate_init' ) && function_exists( 'deflate_add' ) && defined( 'ZLIB_ENCODING_GZIP' );
	}

	/**
	 * Compresses and writes bytes.
	 *
	 * @param string $bytes Bytes.
	 * @throws \RuntimeException When deflate fails.
	 */
	public function write( string $bytes ): void {
		if ( '' === $bytes ) {
			return;
		}
		if ( null === $this->context ) {
			$this->context = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => $this->level ) );
			if ( false === $this->context ) {
				throw new \RuntimeException( 'deflate_init_failed' );
			}
		}
		$out = deflate_add( $this->context, $bytes, ZLIB_NO_FLUSH );
		if ( false === $out ) {
			throw new \RuntimeException( 'deflate_failed' );
		}
		if ( '' !== $out ) {
			Plain_Sink::write_all( $this->handle, $out );
		}
	}

	/**
	 * Finishes the member.
	 *
	 * @throws \RuntimeException When deflate fails.
	 */
	public function close(): void {
		if ( null === $this->context ) {
			return;
		}
		$out = deflate_add( $this->context, '', ZLIB_FINISH );
		if ( false === $out ) {
			throw new \RuntimeException( 'deflate_failed' );
		}
		Plain_Sink::write_all( $this->handle, $out );
		$this->context = null;
		fflush( $this->handle );
	}
}
