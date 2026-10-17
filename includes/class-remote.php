<?php
/**
 * The remote migration as BladeCP sees it (GET, cached for a few seconds to
 * stay far below the API rate limit while the page polls).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Remote migration status.
 */
final class Remote {

	const CACHE     = 'bladecp_migrator_remote';
	const CACHE_TTL = 8;

	/**
	 * Current remote migration.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array{data:array|null,error:Api_Error|null}
	 */
	public static function migration( bool $fresh = false ): array {
		$uuid = Connection::migration_uuid();
		$api  = Job_Service::api();
		if ( null === $uuid || null === $api ) {
			return array(
				'data'  => null,
				'error' => new Api_Error( Api_Error::KIND_AUTH, 401, 'NOT_CONNECTED' ),
			);
		}
		$cached = get_transient( self::CACHE );
		if ( ! $fresh && is_array( $cached ) && ( $cached['uuid'] ?? '' ) === $uuid && is_array( $cached['data'] ?? null ) ) {
			return array(
				'data'  => $cached['data'],
				'error' => null,
			);
		}
		try {
			$data = $api->get_migration( $uuid );
		} catch ( Api_Error $e ) {
			return array(
				'data'  => null,
				'error' => $e,
			);
		}
		set_transient(
			self::CACHE,
			array(
				'uuid' => $uuid,
				'data' => $data,
			),
			self::CACHE_TTL
		);
		return array(
			'data'  => $data,
			'error' => null,
		);
	}

	/**
	 * Drops the cache after an action.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE );
	}
}
