<?php
/**
 * Protected working directory under wp-content/uploads.
 *
 * The folder name carries a random token (so its URL cannot be guessed on
 * servers that ignore .htaccess, such as nginx) and gets deny-all rules for
 * Apache and IIS plus empty index files. Everything the plugin writes to
 * disk lives here and is removed after the upload, on cancel, on reset and
 * on uninstall.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Working directory.
 */
final class Work_Dir {

	const PREFIX = 'bladecp-migrator-';

	/**
	 * Absolute path without trailing slash.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path.
	 */
	public function __construct( string $path ) {
		$this->path = rtrim( $path, '/\\' );
	}

	/**
	 * The site's working directory (token created once, not autoloaded).
	 */
	public static function for_site(): self {
		$token = get_option( Options::WORK_DIR, '' );
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{24}$/', $token ) ) {
			$token = bin2hex( random_bytes( 12 ) );
			Options::set( Options::WORK_DIR, $token );
		}
		$uploads = wp_upload_dir( null, false );
		return new self( rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::PREFIX . $token );
	}

	/**
	 * Path.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Path of a file inside.
	 *
	 * @param string $name File name (no separators).
	 */
	public function file( string $name ): string {
		return $this->path . '/' . basename( $name );
	}

	/**
	 * Creates the folder and its protections.
	 *
	 * @throws \RuntimeException When the folder cannot be created.
	 */
	public function ensure(): string {
		if ( ! is_dir( $this->path ) && ! @mkdir( $this->path, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- reported as a preflight problem.
			throw new \RuntimeException( 'work_dir_unwritable' );
		}
		$files = array(
			'.htaccess'  => "# BladeCP Migrator: no web access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		foreach ( $files as $name => $content ) {
			$target = $this->path . '/' . $name;
			if ( ! file_exists( $target ) && false === @file_put_contents( $target, $content ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protection files.
				throw new \RuntimeException( 'work_dir_unwritable' );
			}
		}
		if ( ! wp_is_writable( $this->path ) ) {
			throw new \RuntimeException( 'work_dir_unwritable' );
		}
		return $this->path;
	}

	/**
	 * Deletes one work file.
	 *
	 * @param string $name File name.
	 */
	public function delete( string $name ): void {
		$file = $this->file( $name );
		if ( is_file( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best effort cleanup.
		}
	}

	/**
	 * Removes the folder and everything in it.
	 */
	public function purge(): void {
		self::remove_tree( $this->path );
	}

	/**
	 * Recursively deletes a folder, but only one of ours.
	 *
	 * @param string $dir Folder.
	 */
	public static function remove_tree( string $dir ): void {
		$dir = rtrim( $dir, '/\\' );
		if ( 0 !== strpos( basename( $dir ), self::PREFIX ) || ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		self::remove_children( $dir );
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup.
	}

	/**
	 * Deletes the content of a folder (links are removed, never followed).
	 *
	 * @param string $dir Folder.
	 */
	private static function remove_children( string $dir ): void {
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return;
		}
		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_children( $path );
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		closedir( $handle );
	}
}
