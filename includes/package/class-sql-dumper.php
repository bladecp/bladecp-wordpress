<?php
/**
 * Resumable SQL dump in mysqldump-compatible text.
 *
 * Format decisions:
 * - The first 8 KiB are a comment header only. The engine sniffs the engine
 *   and version from the first 8 KiB ("MariaDB" anywhere, then
 *   "Server version <x.y>"), so table data must not appear there (a post
 *   mentioning MariaDB on a MySQL server would otherwise flip the guess).
 * - mysqldump session preamble and postamble (SET NAMES, TIME_ZONE +00:00,
 *   FOREIGN_KEY_CHECKS=0, SQL_MODE NO_AUTO_VALUE_ON_ZERO, ...).
 * - Per table: DROP TABLE IF EXISTS, the server's own SHOW CREATE TABLE,
 *   then extended INSERTs of at most ~1 MiB each.
 * - Values: NULL, unquoted numbers for numeric columns, 0x hex for binary
 *   and spatial columns, otherwise single-quoted with the
 *   mysql_real_escape_string set (\0 \n \r \\ \' \" \Z).
 * - Generated columns are left out (explicit column list) because MySQL
 *   refuses values for them.
 * - Views, triggers and routines are not dumped.
 *
 * Batches use keyset pagination on a single integer primary key, otherwise
 * LIMIT/OFFSET ordered by the primary key (or unordered without one).
 * Output is appended and the committed size is saved with the state, so a
 * step killed halfway is cut back and redone.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator\Package;

use BladeCP\Migrator\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * SQL dumper.
 */
final class Sql_Dumper {

	const HEADER_BYTES            = 8192;
	const DEFAULT_BATCH_ROWS      = 200;
	const DEFAULT_STATEMENT_BYTES = 1048576;

	/**
	 * Source.
	 *
	 * @var Database_Source
	 */
	private $db;

	/**
	 * Output file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * Options: prefix, database, charset, plugin_version, now (footer
	 * timestamp), batch_rows, statement_bytes, row_filter
	 * (callable(string $table, array $columns, array $row): bool).
	 *
	 * @param Database_Source $db      Source.
	 * @param string          $file    Output file.
	 * @param array           $options Options.
	 */
	public function __construct( Database_Source $db, string $file, array $options ) {
		$this->db      = $db;
		$this->file    = $file;
		$this->options = array_merge(
			array(
				'prefix'          => '',
				'database'        => '',
				'charset'         => 'utf8mb4',
				'plugin_version'  => '',
				'now'             => 0,
				'batch_rows'      => self::DEFAULT_BATCH_ROWS,
				'statement_bytes' => self::DEFAULT_STATEMENT_BYTES,
				'row_filter'      => null,
			),
			$options
		);
	}

	/**
	 * Initial state.
	 */
	public static function initial_state(): array {
		return array(
			'started'        => false,
			'done'           => false,
			'size'           => 0,
			'server_version' => '',
			'tables'         => array(),
			'table_index'    => 0,
			'table'          => null,
			'rows'           => 0,
		);
	}

	/**
	 * Engine name from a server version string.
	 *
	 * @param string $version VERSION().
	 */
	public static function engine( string $version ): string {
		return false !== stripos( $version, 'mariadb' ) ? 'mariadb' : 'mysql';
	}

	/**
	 * Major.minor from a server version string ("5.5.5-10.11.6-MariaDB" gives "10.11").
	 *
	 * @param string $version VERSION().
	 */
	public static function major_minor( string $version ): string {
		$version = (string) preg_replace( '/^5\.5\.5-/', '', $version );
		return 1 === preg_match( '/^(\d+\.\d+)/', $version, $m ) ? $m[1] : '';
	}

	/**
	 * Runs until the deadline (always at least one unit of work).
	 *
	 * @param array    $state    State.
	 * @param Deadline $deadline Budget.
	 * @throws \RuntimeException On database or disk errors.
	 */
	public function step( array $state, Deadline $deadline ): array {
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		$this->db->session( true );
		try {
			if ( empty( $state['started'] ) ) {
				$state = self::initial_state();
				File_Scanner::truncate( $this->file, 0 );
				$state['server_version'] = $this->db->server_version();
				$state['tables']         = array_values( $this->db->list_tables( (string) $this->options['prefix'] ) );
				$out                     = File_Scanner::open( $this->file, 'ab' );
				Plain_Sink::write_all( $out, self::header( $state['server_version'], (string) $this->options['database'], (string) $this->options['charset'], (string) $this->options['plugin_version'] ) );
				$state['started'] = true;
			} else {
				File_Scanner::truncate( $this->file, (int) $state['size'] );
				$out = File_Scanner::open( $this->file, 'ab' );
			}
			$first = true;
			while ( $state['table_index'] < count( $state['tables'] ) ) {
				if ( ! $first && $deadline->reached() ) {
					break;
				}
				$first = false;
				$state = $this->advance( $state, $out );
			}
			if ( $state['table_index'] >= count( $state['tables'] ) ) {
				Plain_Sink::write_all( $out, self::footer( (int) $this->options['now'] ) );
				$state['done'] = true;
			}
			fflush( $out );
			$state['size'] = File_Scanner::size_of( $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		} finally {
			$this->db->session( false );
		}
		return $state;
	}

	/**
	 * One unit: a table structure, or one batch of rows.
	 *
	 * @param array    $state State.
	 * @param resource $out   Output.
	 */
	private function advance( array $state, $out ): array {
		$table = (string) $state['tables'][ $state['table_index'] ];
		if ( null === $state['table'] ) {
			$meta = $this->describe( $table );
			Plain_Sink::write_all( $out, self::structure( $table, $this->db->create_table( $table ) ) );
			$state['table'] = $meta;
			return $state;
		}
		$meta  = $state['table'];
		$limit = max( 1, (int) $this->options['batch_rows'] );
		$query = array(
			'columns'  => $meta['columns'],
			'bits'     => $meta['bits'] ?? array(),
			'order_by' => $meta['order_by'],
			'after'    => $meta['after'],
			'offset'   => $meta['offset'],
			'limit'    => $limit,
		);
		$rows  = $this->db->fetch_rows( $table, $query );
		if ( empty( $rows ) ) {
			Plain_Sink::write_all( $out, self::table_end( $table ) );
			$state['table'] = null;
			++$state['table_index'];
			return $state;
		}
		$keep = array();
		$filter = $this->options['row_filter'];
		foreach ( $rows as $row ) {
			if ( null === $filter || $filter( $table, $meta['columns'], $row ) ) {
				$keep[] = $row;
			}
		}
		if ( ! empty( $keep ) ) {
			$this->write_inserts( $out, $table, $meta, $keep );
		}
		$state['rows'] += count( $keep );
		if ( null !== $meta['key_index'] ) {
			$last          = $rows[ count( $rows ) - 1 ];
			$meta['after'] = (string) $last[ $meta['key_index'] ];
		} else {
			$meta['offset'] += count( $rows );
		}
		$state['table'] = $meta;
		if ( count( $rows ) < $limit ) {
			Plain_Sink::write_all( $out, self::table_end( $table ) );
			$state['table'] = null;
			++$state['table_index'];
		}
		return $state;
	}

	/**
	 * Column kinds, selection and pagination plan of a table.
	 *
	 * @param string $table Table.
	 */
	private function describe( string $table ): array {
		$columns   = array();
		$kinds     = array();
		$types     = array();
		$generated = false;
		foreach ( $this->db->columns( $table ) as $column ) {
			if ( ! empty( $column['generated'] ) ) {
				$generated = true;
				continue;
			}
			$columns[]                 = (string) $column['name'];
			$kinds[]                   = self::column_kind( (string) $column['type'] );
			$types[ $column['name'] ] = (string) $column['type'];
		}
		$primary   = $this->db->primary_key( $table );
		$key_index = null;
		if ( 1 === count( $primary ) && isset( $types[ $primary[0] ] ) && self::is_integer_type( $types[ $primary[0] ] ) ) {
			$key_index = array_search( $primary[0], $columns, true );
			$key_index = false === $key_index ? null : (int) $key_index;
		}
		$order_by = array();
		foreach ( $primary as $name ) {
			if ( isset( $types[ $name ] ) ) {
				$order_by[] = (string) $name;
			}
		}
		$bits = array();
		foreach ( $columns as $i => $name ) {
			if ( 'bit' === $kinds[ $i ] ) {
				$bits[] = $name;
			}
		}
		return array(
			'columns'   => $columns,
			'kinds'     => $kinds,
			'bits'      => $bits,
			'explicit'  => $generated,
			'order_by'  => $order_by,
			'key_index' => $key_index,
			'after'     => null,
			'offset'    => 0,
		);
	}

	/**
	 * Writes extended INSERT statements of at most statement_bytes each.
	 *
	 * @param resource $out   Output.
	 * @param string   $table Table.
	 * @param array    $meta  Table plan.
	 * @param array    $rows  Rows.
	 */
	private function write_inserts( $out, string $table, array $meta, array $rows ): void {
		$prefix = 'INSERT INTO ' . self::ident( $table );
		if ( ! empty( $meta['explicit'] ) ) {
			$prefix .= ' (' . implode( ',', array_map( array( __CLASS__, 'ident' ), $meta['columns'] ) ) . ')';
		}
		$prefix .= ' VALUES ';
		$max     = max( 1024, (int) $this->options['statement_bytes'] );
		$current = 0;
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $meta['kinds'] as $i => $kind ) {
				$values[] = self::sql_value( array_key_exists( $i, $row ) ? $row[ $i ] : null, $kind );
			}
			$tuple = '(' . implode( ',', $values ) . ')';
			if ( 0 === $current ) {
				Plain_Sink::write_all( $out, $prefix . $tuple );
				$current = strlen( $prefix ) + strlen( $tuple );
			} elseif ( $current + strlen( $tuple ) + 1 > $max ) {
				Plain_Sink::write_all( $out, ";\n" . $prefix . $tuple );
				$current = strlen( $prefix ) + strlen( $tuple );
			} else {
				Plain_Sink::write_all( $out, ',' . $tuple );
				$current += strlen( $tuple ) + 1;
			}
		}
		if ( $current > 0 ) {
			Plain_Sink::write_all( $out, ";\n" );
		}
	}

	/**
	 * Header, padded so the first 8 KiB hold no table data.
	 *
	 * @param string $server_version VERSION().
	 * @param string $database       Database name.
	 * @param string $charset        Connection charset.
	 * @param string $plugin_version Plugin version.
	 */
	public static function header( string $server_version, string $database, string $charset, string $plugin_version ): string {
		$clean   = static function ( string $value ): string {
			return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		};
		$version = $clean( (string) preg_replace( '/^5\.5\.5-/', '', $server_version ) );
		$charset = 1 === preg_match( '/^[A-Za-z0-9_]+$/', $charset ) ? $charset : 'utf8mb4';
		$lines   = array(
			'-- ' . ( 'mariadb' === self::engine( $server_version ) ? 'MariaDB' : 'MySQL' ) . ' dump compatible output',
			'-- Generator: BladeCP Migrator ' . $clean( $plugin_version ) . ' (PHP)',
			'--',
			'-- Host: localhost    Database: ' . $clean( $database ),
			'-- ------------------------------------------------------',
			"-- Server version\t" . $version,
			'',
			'/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;',
			'/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;',
			'/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;',
			'/*!40101 SET NAMES ' . $charset . ' */;',
			'/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;',
			"/*!40103 SET TIME_ZONE='+00:00' */;",
			'/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;',
			'/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
			"/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;",
			'/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;',
			'',
			'-- The comment lines below keep the first 8 KiB of this file free of',
			'-- table data, so tools that read only the header see only the header.',
		);
		$text = implode( "\n", $lines ) . "\n";
		$fill = '--' . str_repeat( ' ', 61 ) . "\n";
		while ( strlen( $text ) < self::HEADER_BYTES ) {
			$text .= $fill;
		}
		return $text . "\n";
	}

	/**
	 * Footer restoring the session and marking a complete dump.
	 *
	 * @param int $now Timestamp.
	 */
	public static function footer( int $now ): string {
		return "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n"
			. "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
			. "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
			. "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n"
			. "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
			. "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n"
			. "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n"
			. "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n"
			. "\n"
			. '-- Dump completed on ' . gmdate( 'Y-m-d H:i:s', $now ) . "\n";
	}

	/**
	 * Structure block of a table.
	 *
	 * @param string $table  Table.
	 * @param string $create SHOW CREATE TABLE.
	 */
	public static function structure( string $table, string $create ): string {
		$ident = self::ident( $table );
		return "\n--\n-- Table structure for table " . $ident . "\n--\n\n"
			. 'DROP TABLE IF EXISTS ' . $ident . ";\n"
			. rtrim( trim( $create ), ';' ) . ";\n"
			. "\n--\n-- Dumping data for table " . $ident . "\n--\n\n"
			. '/*!40000 ALTER TABLE ' . $ident . " DISABLE KEYS */;\n";
	}

	/**
	 * Closes a table's data block.
	 *
	 * @param string $table Table.
	 */
	public static function table_end( string $table ): string {
		return '/*!40000 ALTER TABLE ' . self::ident( $table ) . " ENABLE KEYS */;\n";
	}

	/**
	 * Backtick identifier.
	 *
	 * @param string $name Name.
	 */
	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * numeric, bit, binary or string. BIT columns are read as numbers
	 * (`col` + 0) because mysqlnd and libmysqlclient return them differently.
	 *
	 * @param string $type Column type from SHOW COLUMNS.
	 */
	public static function column_kind( string $type ): string {
		$type = strtolower( trim( $type ) );
		if ( 1 === preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/', $type ) ) {
			return 'numeric';
		}
		if ( 1 === preg_match( '/^bit\b/', $type ) ) {
			return 'bit';
		}
		if ( 1 === preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/', $type ) ) {
			return 'binary';
		}
		return 'string';
	}

	/**
	 * Integer column (keyset pagination candidate).
	 *
	 * @param string $type Column type.
	 */
	public static function is_integer_type( string $type ): bool {
		return 1 === preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', strtolower( trim( $type ) ) );
	}

	/**
	 * SQL literal of a value.
	 *
	 * @param string|null $value Value.
	 * @param string      $kind  numeric, bit, binary or string.
	 */
	public static function sql_value( ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'binary' === $kind || ( 'bit' === $kind && 1 !== preg_match( '/^\d+$/', $value ) ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		if ( 'bit' === $kind ) {
			return $value;
		}
		if ( 'numeric' === $kind && 1 === preg_match( '/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $value ) ) {
			return $value;
		}
		return self::quote( $value );
	}

	/**
	 * Single-quoted string with the mysql_real_escape_string escapes.
	 *
	 * @param string $value Value.
	 */
	public static function quote( string $value ): string {
		return "'" . strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		) . "'";
	}
}
