<?php
/**
 * Database_Source over $wpdb.
 *
 * Only identifiers coming from SHOW TABLES / SHOW COLUMNS (backtick-escaped)
 * and validated integers reach the SQL text; there is no user input here.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

defined( 'ABSPATH' ) || exit;

/**
 * $wpdb reader.
 */
final class Wpdb_Source implements Database_Source {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Session time zone saved before a step.
	 *
	 * @var string|null
	 */
	private $saved_time_zone = null;

	/**
	 * suppress_errors() value before a step.
	 *
	 * @var bool
	 */
	private $saved_suppress = false;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * VERSION().
	 */
	public function server_version(): string {
		return (string) $this->wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dump reads the live database.
	}

	/**
	 * Base tables with the prefix.
	 *
	 * @param string $prefix Prefix.
	 */
	public function list_tables( string $prefix ): array {
		$rows   = $this->checked( $this->wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dump reads the live database.
		$tables = array();
		foreach ( $rows as $row ) {
			$name = (string) ( $row[0] ?? '' );
			$type = strtoupper( (string) ( $row[1] ?? 'BASE TABLE' ) );
			if ( 'BASE TABLE' === $type && '' !== $name && 0 === strpos( $name, $prefix ) ) {
				$tables[] = $name;
			}
		}
		sort( $tables, SORT_STRING );
		return $tables;
	}

	/**
	 * SHOW CREATE TABLE.
	 *
	 * @param string $table Table.
	 * @throws \RuntimeException On a database error.
	 */
	public function create_table( string $table ): string {
		$row = $this->wpdb->get_row( 'SHOW CREATE TABLE ' . Sql_Dumper::ident( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- escaped identifier from SHOW TABLES.
		if ( ! is_array( $row ) || ! isset( $row[1] ) ) {
			throw new \RuntimeException( 'database_error' );
		}
		return (string) $row[1];
	}

	/**
	 * SHOW COLUMNS.
	 *
	 * @param string $table Table.
	 */
	public function columns( string $table ): array {
		$rows = $this->checked( $this->wpdb->get_results( 'SHOW COLUMNS FROM ' . Sql_Dumper::ident( $table ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- escaped identifier from SHOW TABLES.
		$out  = array();
		foreach ( $rows as $row ) {
			$extra = strtoupper( (string) ( $row['Extra'] ?? '' ) );
			$out[] = array(
				'name'      => (string) $row['Field'],
				'type'      => (string) $row['Type'],
				'generated' => false !== strpos( $extra, 'GENERATED' ) || false !== strpos( $extra, 'VIRTUAL' ) || false !== strpos( $extra, 'PERSISTENT' ) || false !== strpos( $extra, 'STORED' ),
			);
		}
		return $out;
	}

	/**
	 * Primary key columns.
	 *
	 * @param string $table Table.
	 */
	public function primary_key( string $table ): array {
		$rows = $this->checked( $this->wpdb->get_results( 'SHOW KEYS FROM ' . Sql_Dumper::ident( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- escaped identifier from SHOW TABLES.
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
			}
		);
		return array_map(
			static function ( $row ) {
				return (string) $row['Column_name'];
			},
			$rows
		);
	}

	/**
	 * One batch of rows.
	 *
	 * @param string $table Table.
	 * @param array  $query Query.
	 */
	public function fetch_rows( string $table, array $query ): array {
		$bits    = (array) ( $query['bits'] ?? array() );
		$columns = array();
		foreach ( (array) $query['columns'] as $name ) {
			// BIT values as numbers: mysqlnd and libmysqlclient disagree on their raw form.
			$columns[] = in_array( $name, $bits, true ) ? '(' . Sql_Dumper::ident( $name ) . ' + 0)' : Sql_Dumper::ident( $name );
		}
		$sql     = 'SELECT ' . ( empty( $columns ) ? '*' : implode( ', ', $columns ) ) . ' FROM ' . Sql_Dumper::ident( $table );
		$order   = (array) $query['order_by'];
		$limit   = max( 1, (int) $query['limit'] );
		$after   = $query['after'] ?? null;
		if ( 1 === count( $order ) && null !== $after && 1 === preg_match( '/^-?\d+$/', (string) $after ) ) {
			$sql .= ' WHERE ' . Sql_Dumper::ident( $order[0] ) . ' > ' . $after;
		}
		if ( ! empty( $order ) ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_map( array( Sql_Dumper::class, 'ident' ), $order ) );
		}
		if ( null !== $after ) {
			$sql .= ' LIMIT ' . $limit;
		} else {
			$sql .= ' LIMIT ' . max( 0, (int) $query['offset'] ) . ', ' . $limit;
		}
		return $this->checked( $this->wpdb->get_results( $sql, ARRAY_N ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- escaped identifiers and validated integers only.
	}

	/**
	 * UTC session during a step (TIMESTAMP values match the dump's
	 * TIME_ZONE='+00:00'), restored afterwards.
	 *
	 * @param bool $starting Before or after the step.
	 */
	public function session( bool $starting ): void {
		if ( $starting ) {
			$this->saved_suppress  = (bool) $this->wpdb->suppress_errors( true );
			$this->saved_time_zone = (string) $this->wpdb->get_var( 'SELECT @@session.time_zone' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->wpdb->query( "SET time_zone = '+00:00'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		if ( null !== $this->saved_time_zone && '' !== $this->saved_time_zone ) {
			$wpdb = $this->wpdb;
			$wpdb->query( $wpdb->prepare( 'SET time_zone = %s', $this->saved_time_zone ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->saved_time_zone = null;
		$this->wpdb->suppress_errors( $this->saved_suppress );
	}

	/**
	 * Approximate data size of the prefixed tables, in bytes.
	 *
	 * @param string $prefix Table prefix.
	 * @return array{bytes:int,tables:int}
	 */
	public function data_size( string $prefix ): array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COALESCE(SUM(DATA_LENGTH), 0) AS bytes, COUNT(*) AS tables FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = %s AND TABLE_NAME LIKE %s',
				'BASE TABLE',
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);
		return array(
			'bytes'  => is_array( $row ) ? (int) $row['bytes'] : 0,
			'tables' => is_array( $row ) ? (int) $row['tables'] : 0,
		);
	}

	/**
	 * Throws on a database error, otherwise returns the rows.
	 *
	 * @param mixed $rows get_results() output.
	 * @throws \RuntimeException On a database error.
	 */
	private function checked( $rows ): array {
		if ( '' !== (string) $this->wpdb->last_error ) {
			throw new \RuntimeException( 'database_error' );
		}
		return is_array( $rows ) ? $rows : array();
	}
}
