<?php
/**
 * Read access to the site database for the SQL dump (a $wpdb adapter in
 * production, an in-memory fake in tests).
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Database reader.
 */
interface Database_Source {

	/**
	 * SELECT VERSION(), for example "8.0.36" or "10.11.6-MariaDB".
	 */
	public function server_version(): string;

	/**
	 * Base tables (no views) whose name starts with the prefix, sorted.
	 *
	 * @param string $prefix Table prefix.
	 * @return string[]
	 */
	public function list_tables( string $prefix ): array;

	/**
	 * SHOW CREATE TABLE statement (without trailing semicolon).
	 *
	 * @param string $table Table.
	 */
	public function create_table( string $table ): string;

	/**
	 * Columns in table order.
	 *
	 * @param string $table Table.
	 * @return array<int,array{name:string,type:string,generated:bool}>
	 */
	public function columns( string $table ): array;

	/**
	 * Primary key columns in key order (empty when none).
	 *
	 * @param string $table Table.
	 * @return string[]
	 */
	public function primary_key( string $table ): array;

	/**
	 * Fetches a batch of rows as numeric arrays (values as strings or null).
	 *
	 * Query keys: columns (string[] to select), bits (BIT columns, read as
	 * numbers), order_by (string[]),
	 * after (string|null, keyset on the single order_by column), offset (int),
	 * limit (int).
	 *
	 * @param string $table Table.
	 * @param array  $query Query.
	 * @return array<int,array<int,string|null>>
	 * @throws \RuntimeException On a database error.
	 */
	public function fetch_rows( string $table, array $query ): array;

	/**
	 * Called before and after one dump step (session time zone).
	 *
	 * @param bool $starting True before the step, false after.
	 */
	public function session( bool $starting ): void;
}
