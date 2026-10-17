<?php
/**
 * Streaming POSIX ustar headers with PAX extensions (no ZipArchive, no
 * PharData, nothing held in memory beyond one header).
 *
 * The writer is stateless: callers write a header, then exactly `size` bytes
 * of content, then padding(size). This lets one entry span several steps
 * (the packer persists how many content bytes were already written).
 *
 * Long names: split into ustar prefix (155) + name (100) when possible,
 * otherwise a PAX "x" header carries the full path. Sizes above the octal
 * field limit (8 GiB) are carried in a PAX "size" record.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Tar header builder.
 */
final class Tar_Writer {

	const BLOCK          = 512;
	const RECORD         = 10240;
	const MAX_OCTAL_SIZE = 8589934591;
	const TYPE_FILE      = '0';
	const TYPE_DIR       = '5';
	const TYPE_PAX       = 'x';

	/**
	 * Header (plus PAX header when needed) of a regular file.
	 *
	 * @param string $name  Archive path.
	 * @param int    $size  Content size.
	 * @param int    $mtime Modification time.
	 * @param int    $mode  Permission bits.
	 */
	public static function file_header( string $name, int $size, int $mtime, int $mode = 0644 ): string {
		return self::header( $name, $size, $mtime, $mode, self::TYPE_FILE );
	}

	/**
	 * Header of a directory (name must end with "/").
	 *
	 * @param string $name  Archive path.
	 * @param int    $mtime Modification time.
	 * @param int    $mode  Permission bits.
	 */
	public static function dir_header( string $name, int $mtime, int $mode = 0755 ): string {
		if ( '/' !== substr( $name, -1 ) ) {
			$name .= '/';
		}
		return self::header( $name, 0, $mtime, $mode, self::TYPE_DIR );
	}

	/**
	 * Zero padding after `size` content bytes.
	 *
	 * @param int $size Content size.
	 */
	public static function padding( int $size ): string {
		$rest = $size % self::BLOCK;
		return 0 === $rest ? '' : str_repeat( "\0", self::BLOCK - $rest );
	}

	/**
	 * End of archive: two zero blocks, then zeros up to a 10 KiB record.
	 *
	 * @param int $written Bytes written so far (multiple of 512).
	 */
	public static function end_of_archive( int $written ): string {
		$total = $written + 2 * self::BLOCK;
		$rest  = $total % self::RECORD;
		$pad   = 0 === $rest ? 0 : self::RECORD - $rest;
		return str_repeat( "\0", 2 * self::BLOCK + $pad );
	}

	/**
	 * Splits a path into ustar prefix and name; null name when impossible.
	 *
	 * @param string $path Archive path.
	 * @return array{0:string,1:string|null}
	 */
	public static function split_name( string $path ): array {
		if ( strlen( $path ) <= 100 ) {
			return array( '', $path );
		}
		$offset = 0;
		while ( false !== ( $slash = strpos( $path, '/', $offset ) ) ) {
			$prefix = substr( $path, 0, $slash );
			$rest   = substr( $path, $slash + 1 );
			if ( strlen( $prefix ) <= 155 && strlen( $rest ) <= 100 && '' !== $rest && '/' !== $rest ) {
				return array( $prefix, $rest );
			}
			if ( strlen( $prefix ) > 155 ) {
				break;
			}
			$offset = $slash + 1;
		}
		return array( '', null );
	}

	/**
	 * PAX records ("<len> key=value\n", len counting itself).
	 *
	 * @param array<string,string> $records Records.
	 */
	public static function pax_records( array $records ): string {
		$out = '';
		foreach ( $records as $key => $value ) {
			$body   = ' ' . $key . '=' . $value . "\n";
			$length = strlen( $body ) + 1;
			while ( strlen( (string) $length ) + strlen( $body ) !== $length ) {
				$length = strlen( (string) $length ) + strlen( $body );
			}
			$out .= $length . $body;
		}
		return $out;
	}

	/**
	 * Header with PAX extension when the path or size does not fit.
	 *
	 * @param string $name  Path.
	 * @param int    $size  Size.
	 * @param int    $mtime Mtime.
	 * @param int    $mode  Mode.
	 * @param string $type  Type flag.
	 */
	private static function header( string $name, int $size, int $mtime, int $mode, string $type ): string {
		$mtime            = max( 0, min( self::MAX_OCTAL_SIZE, $mtime ) );
		$pax              = array();
		list( $prefix, $short ) = self::split_name( $name );
		if ( null === $short ) {
			$pax['path'] = $name;
			$prefix      = '';
			$short       = self::truncated_name( $name );
		}
		$size_field = $size;
		if ( $size > self::MAX_OCTAL_SIZE ) {
			$pax['size'] = (string) $size;
			$size_field  = 0;
		}
		$out = '';
		if ( ! empty( $pax ) ) {
			$records = self::pax_records( $pax );
			$out    .= self::raw_header( 'PaxHeaders/' . substr( basename( $short ), 0, 80 ), strlen( $records ), $mtime, 0644, self::TYPE_PAX, '' );
			$out    .= $records . self::padding( strlen( $records ) );
		}
		return $out . self::raw_header( $short, $size_field, $mtime, $mode, $type, $prefix );
	}

	/**
	 * Fallback ustar name when a PAX path carries the real one.
	 *
	 * @param string $name Full path.
	 */
	private static function truncated_name( string $name ): string {
		$is_dir = '/' === substr( $name, -1 );
		$base   = basename( rtrim( $name, '/' ) );
		$short  = substr( $base, 0, $is_dir ? 99 : 100 );
		return $is_dir ? $short . '/' : $short;
	}

	/**
	 * One 512-byte ustar header block.
	 *
	 * @param string $name   Name field (<= 100 bytes).
	 * @param int    $size   Size.
	 * @param int    $mtime  Mtime.
	 * @param int    $mode   Mode.
	 * @param string $type   Type flag.
	 * @param string $prefix Prefix field (<= 155 bytes).
	 */
	private static function raw_header( string $name, int $size, int $mtime, int $mode, string $type, string $prefix ): string {
		$header = str_pad( substr( $name, 0, 100 ), 100, "\0" )
			. sprintf( '%07o', $mode & 07777 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%011o', $size ) . "\0"
			. sprintf( '%011o', $mtime ) . "\0"
			. '        '
			. $type
			. str_repeat( "\0", 100 )
			. "ustar\0" . '00'
			. str_repeat( "\0", 32 )
			. str_repeat( "\0", 32 )
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. str_pad( substr( $prefix, 0, 155 ), 155, "\0" )
			. str_repeat( "\0", 12 );
		$sum = array_sum( (array) unpack( 'C*', $header ) );
		return substr_replace( $header, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}
}
