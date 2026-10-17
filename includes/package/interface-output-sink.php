<?php
/**
 * Where the tar stream goes (gzip member or plain file).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Byte sink.
 */
interface Output_Sink {

	/**
	 * Writes bytes.
	 *
	 * @param string $bytes Bytes.
	 * @throws \RuntimeException When the disk refuses the write.
	 */
	public function write( string $bytes ): void;

	/**
	 * Finishes what this step wrote (a complete gzip member).
	 *
	 * @throws \RuntimeException When the disk refuses the write.
	 */
	public function close(): void;
}
