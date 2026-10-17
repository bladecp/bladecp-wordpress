<?php
/**
 * Single-runner lock with expiry.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Lock.
 */
interface Lock {

	/**
	 * Takes the lock when free or expired.
	 *
	 * @param int $ttl Seconds.
	 * @return string|null Token, or null when held by someone else.
	 */
	public function acquire( int $ttl ): ?string;

	/**
	 * Extends the lock and stores progress details.
	 *
	 * @param string $token Token.
	 * @param int    $ttl   Seconds.
	 * @param array  $extra Details shown on the progress screen.
	 */
	public function refresh( string $token, int $ttl, array $extra = array() ): void;

	/**
	 * Releases the lock if still ours.
	 *
	 * @param string $token Token.
	 */
	public function release( string $token ): void;

	/**
	 * Current holder data (token, expires, extra) or null.
	 */
	public function read(): ?array;
}
