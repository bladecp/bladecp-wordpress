<?php
/**
 * Multipart upload planning and resume (pure functions over a plain array
 * that is persisted with the job).
 *
 * API: POST upload-url { sizeBytes, filename, resume: true }
 * returns { uploadId, partSize, parts: [{ partNumber, url }],
 * uploadedParts: [{ partNumber, etag, sizeBytes }], expiresIn }. With
 * resume, an open upload of the same size is reused: uploadedParts lists what
 * the storage already has and parts only carries fresh URLs for the rest.
 * Part N covers bytes [(N-1) * partSize, min(N * partSize, size)).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Upload session helpers.
 */
final class Upload_Session {

	const MAX_PARTS       = 10000;
	const URL_SAFETY_SECS = 300;

	/**
	 * Empty session for a package size.
	 *
	 * @param int $size Package size in bytes.
	 */
	public static function fresh( int $size ): array {
		return array(
			'size'           => $size,
			'upload_id'      => '',
			'part_size'      => 0,
			'part_count'     => 0,
			'etags'          => array(),
			'urls'           => array(),
			'urls_expire_at' => 0,
			'completed'      => false,
			'confirmed'      => false,
			'part_progress'  => 0,
			'resets'         => 0,
		);
	}

	/**
	 * Number of parts for a size and part size.
	 *
	 * @param int $size      Total bytes.
	 * @param int $part_size Bytes per part.
	 */
	public static function part_count( int $size, int $part_size ): int {
		if ( $part_size <= 0 ) {
			return 0;
		}
		return max( 1, (int) ceil( $size / $part_size ) );
	}

	/**
	 * Byte range of a part: array( offset, length ).
	 *
	 * @param int $part      Part number (1-based).
	 * @param int $part_size Bytes per part.
	 * @param int $size      Total bytes.
	 */
	public static function part_range( int $part, int $part_size, int $size ): array {
		$offset = ( $part - 1 ) * $part_size;
		$length = max( 0, min( $part_size, $size - $offset ) );
		return array( $offset, $length );
	}

	/**
	 * Merges an upload-url response into the session.
	 *
	 * @param array $session Session.
	 * @param array $data    API `data`.
	 * @param int   $now     Current time.
	 * @throws \UnexpectedValueException When the response is inconsistent.
	 */
	public static function apply_api_response( array $session, array $data, int $now ): array {
		$upload_id = isset( $data['uploadId'] ) && is_string( $data['uploadId'] ) ? $data['uploadId'] : '';
		$part_size = isset( $data['partSize'] ) && is_numeric( $data['partSize'] ) ? (int) $data['partSize'] : 0;
		if ( '' === $upload_id || $part_size <= 0 ) {
			throw new \UnexpectedValueException( 'upload-url response without uploadId or partSize' );
		}
		$count = self::part_count( (int) $session['size'], $part_size );
		if ( $count > self::MAX_PARTS ) {
			throw new \UnexpectedValueException( 'too many parts' );
		}
		if ( $upload_id !== $session['upload_id'] || $part_size !== (int) $session['part_size'] ) {
			// A different multipart upload: parts sent before belong to an aborted upload.
			$session['etags'] = array();
		}
		$session['upload_id']  = $upload_id;
		$session['part_size']  = $part_size;
		$session['part_count'] = $count;
		$session['urls']       = array();
		$session['completed']  = false;

		foreach ( self::list_of( $data, 'uploadedParts' ) as $uploaded ) {
			$number = self::part_number( $uploaded, $count );
			$etag   = isset( $uploaded['etag'] ) && is_string( $uploaded['etag'] ) ? $uploaded['etag'] : '';
			if ( '' === $etag ) {
				throw new \UnexpectedValueException( 'uploaded part without etag' );
			}
			$session['etags'][ $number ] = $etag;
		}
		foreach ( self::list_of( $data, 'parts' ) as $part ) {
			$number = self::part_number( $part, $count );
			$url    = isset( $part['url'] ) && is_string( $part['url'] ) ? $part['url'] : '';
			if ( ! Config::is_acceptable_url( $url ) ) {
				throw new \UnexpectedValueException( 'part URL is not https' );
			}
			if ( ! isset( $session['etags'][ $number ] ) ) {
				$session['urls'][ $number ] = $url;
			}
		}
		for ( $n = 1; $n <= $count; $n++ ) {
			if ( ! isset( $session['etags'][ $n ] ) && ! isset( $session['urls'][ $n ] ) ) {
				throw new \UnexpectedValueException( 'part ' . (int) $n . ' has neither an etag nor a URL' );
			}
		}
		ksort( $session['etags'] );
		ksort( $session['urls'] );
		$expires                   = isset( $data['expiresIn'] ) && is_numeric( $data['expiresIn'] ) ? (int) $data['expiresIn'] : 3600;
		$session['urls_expire_at'] = $now + max( 60, $expires - self::URL_SAFETY_SECS );
		$session['part_progress']  = 0;
		return $session;
	}

	/**
	 * Lowest part number still missing an etag, or null.
	 *
	 * @param array $session Session.
	 */
	public static function next_pending_part( array $session ): ?int {
		for ( $n = 1; $n <= (int) $session['part_count']; $n++ ) {
			if ( ! isset( $session['etags'][ $n ] ) ) {
				return $n;
			}
		}
		return null;
	}

	/**
	 * Whether URLs must be (re)requested before the next PUT.
	 *
	 * @param array $session Session.
	 * @param int   $now     Current time.
	 */
	public static function needs_urls( array $session, int $now ): bool {
		if ( '' === $session['upload_id'] || (int) $session['part_count'] < 1 ) {
			return true;
		}
		$next = self::next_pending_part( $session );
		if ( null === $next ) {
			return false;
		}
		return empty( $session['urls'][ $next ] ) || $now >= (int) $session['urls_expire_at'];
	}

	/**
	 * Records a part's ETag.
	 *
	 * @param array  $session Session.
	 * @param int    $part    Part number.
	 * @param string $etag    ETag header value.
	 */
	public static function record_etag( array $session, int $part, string $etag ): array {
		$session['etags'][ $part ] = $etag;
		unset( $session['urls'][ $part ] );
		ksort( $session['etags'] );
		$session['part_progress'] = 0;
		return $session;
	}

	/**
	 * Drops the URLs (expired or rejected): the next step asks for new ones.
	 *
	 * @param array $session Session.
	 */
	public static function drop_urls( array $session ): array {
		$session['urls']           = array();
		$session['urls_expire_at'] = 0;
		$session['part_progress']  = 0;
		return $session;
	}

	/**
	 * Forgets the multipart upload entirely (stale upload id).
	 *
	 * @param array $session Session.
	 */
	public static function reset( array $session ): array {
		$fresh           = self::fresh( (int) $session['size'] );
		$fresh['resets'] = (int) $session['resets'] + 1;
		return $fresh;
	}

	/**
	 * Whether every part has an ETag.
	 *
	 * @param array $session Session.
	 */
	public static function all_parts_sent( array $session ): bool {
		return (int) $session['part_count'] > 0 && null === self::next_pending_part( $session );
	}

	/**
	 * Body of complete-upload: parts sorted by number.
	 *
	 * @param array $session Session.
	 */
	public static function complete_payload( array $session ): array {
		$parts = array();
		$etags = $session['etags'];
		ksort( $etags );
		foreach ( $etags as $number => $etag ) {
			$parts[] = array(
				'partNumber' => (int) $number,
				'etag'       => (string) $etag,
			);
		}
		return $parts;
	}

	/**
	 * Bytes already accepted by the storage (plus the part in flight).
	 *
	 * @param array $session Session.
	 */
	public static function uploaded_bytes( array $session ): int {
		$total = 0;
		foreach ( array_keys( $session['etags'] ) as $number ) {
			$range  = self::part_range( (int) $number, (int) $session['part_size'], (int) $session['size'] );
			$total += $range[1];
		}
		return min( (int) $session['size'], $total + (int) $session['part_progress'] );
	}

	/**
	 * List value of a response key.
	 *
	 * @param array  $data Data.
	 * @param string $key  Key.
	 */
	private static function list_of( array $data, string $key ): array {
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return array();
		}
		return array_values( array_filter( $data[ $key ], 'is_array' ) );
	}

	/**
	 * Validated part number.
	 *
	 * @param array $item  Item.
	 * @param int   $count Part count.
	 * @throws \UnexpectedValueException When out of range.
	 */
	private static function part_number( array $item, int $count ): int {
		$number = isset( $item['partNumber'] ) && is_numeric( $item['partNumber'] ) ? (int) $item['partNumber'] : 0;
		if ( $number < 1 || $number > $count ) {
			throw new \UnexpectedValueException( 'part number out of range' );
		}
		return $number;
	}
}
