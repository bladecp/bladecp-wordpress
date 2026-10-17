<?php
/**
 * Dependencies of one job step (real ones in WordPress, fakes in tests).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

use BladeCP\Migrator\Package\Database_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Job step context.
 */
final class Job_Context {

	/**
	 * Working directory.
	 *
	 * @var Work_Dir
	 */
	public $work_dir;

	/**
	 * Site root (ABSPATH without trailing slash).
	 *
	 * @var string
	 */
	public $site_root;

	/**
	 * Paths excluded from the package, relative to the site root.
	 *
	 * @var string[]
	 */
	public $excluded = array();

	/**
	 * Database reader.
	 *
	 * @var Database_Source
	 */
	public $db;

	/**
	 * API client (null when not connected).
	 *
	 * @var Api_Client|null
	 */
	public $api;

	/**
	 * Part uploader (null when this server cannot upload).
	 *
	 * @var Part_Putter|null
	 */
	public $putter;

	/**
	 * Clock.
	 *
	 * @var callable
	 */
	public $clock;

	/**
	 * Upload progress callback (bytes of the current part), or null.
	 *
	 * @var callable|null
	 */
	public $heartbeat;

	/**
	 * Extra Sql_Dumper options (batch sizes in tests).
	 *
	 * @var array
	 */
	public $dump_options = array();

	/**
	 * Free disk space probe: callable(string $dir): ?int.
	 *
	 * @var callable|null
	 */
	public $free_space;

	/**
	 * Current time.
	 */
	public function now(): int {
		return (int) call_user_func( $this->clock );
	}
}
