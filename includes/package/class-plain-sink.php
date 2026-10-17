<?php
/**
 * Uncompressed sink (used when the zlib extension is missing; the engine
 * accepts plain .tar as well as .tar.gz).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Plain file sink.
 */
final class Plain_Sink implements Output_Sink {

	/**
	 * Open handle (append position).
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Constructor.
	 *
	 * @param resource $handle File handle positioned at the end.
	 */
	public function __construct( $handle ) {
		$this->handle = $handle;
	}

	/**
	 * Writes bytes.
	 *
	 * @param string $bytes Bytes.
	 */
	public function write( string $bytes ): void {
		self::write_all( $this->handle, $bytes );
	}

	/**
	 * Nothing to finish.
	 */
	public function close(): void {
		fflush( $this->handle );
	}

	/**
	 * fwrite until everything is written; a short write means a full disk.
	 *
	 * @param resource $handle Handle.
	 * @param string   $bytes  Bytes.
	 * @throws \RuntimeException On a failed write.
	 */
	public static function write_all( $handle, string $bytes ): void {
		$length  = strlen( $bytes );
		$written = 0;
		while ( $written < $length ) {
			$n = fwrite( $handle, 0 === $written ? $bytes : substr( $bytes, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming local package file.
			if ( false === $n || 0 === $n ) {
				throw new \RuntimeException( 'disk_write_failed' );
			}
			$written += $n;
		}
	}
}
