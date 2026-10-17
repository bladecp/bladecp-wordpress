<?php
/**
 * Uploads one byte range of the package to a presigned URL.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Part uploader.
 */
interface Part_Putter {

	/**
	 * PUTs bytes [offset, offset + length) of a file.
	 *
	 * @param string        $url         Presigned URL.
	 * @param string        $file        Local file.
	 * @param int           $offset      Start offset.
	 * @param int           $length      Byte count.
	 * @param callable|null $on_progress Receives the bytes sent so far.
	 * @return array{status:int,etag:string,error:string}
	 */
	public function put( string $url, string $file, int $offset, int $length, ?callable $on_progress = null ): array;
}
