<?php
/**
 * Inline SVG icons (Lucide, ISC license) and the BladeCP marks.
 *
 * The Lucide paths are copyright Lucide Icons and Contributors, with some
 * icons derived from Feather (MIT, copyright Cole Bemis). Their complete
 * license and permission notice ships next to this file in
 * LICENSE-lucide.txt.
 *
 * Fixed markup, no external file: the screen never loads anything from
 * outside the plugin. Every icon is decorative (aria-hidden); the text next
 * to it carries the meaning.
 *
 * @package BladeCP\Migrator
 */

namespace BladeCP\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Icons.
 */
final class Icons {

	/**
	 * Lucide paths (viewBox 0 0 24 24, stroke 2).
	 */
	const PATHS = array(
		'check'           => '<path d="M20 6 9 17l-5-5"/>',
		'circle-check'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'circle-alert'    => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
		'circle-x'        => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
		'triangle-alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'loader'          => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
		'hourglass'       => '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
		'eye'             => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
		'search'          => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
		'scan-search'     => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><circle cx="12" cy="12" r="3"/><path d="m16 16-1.9-1.9"/>',
		'globe'           => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
		'rotate-ccw'      => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
		'refresh'         => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
		'ban'             => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
		'external-link'   => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'chevron-down'    => '<path d="m6 9 6 6 6-6"/>',
		'info'            => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
		'package'         => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/>',
		'app-window'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 4v4"/><path d="M2 8h20"/><path d="M6 4v4"/>',
		'code'            => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
		'layers'          => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
		'lock'            => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
		'cpu'             => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
		'shield-check'    => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
		'cloud-upload'    => '<path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/>',
		'archive'         => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
		'gauge'           => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
		'folder'          => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
		'database'        => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
		'hard-drive'      => '<line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/>',
		'clock'           => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'calendar'        => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
		'clipboard-check' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
		'link'            => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		'rocket'          => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
		'log-in'          => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
		'unplug'          => '<path d="m19 5 3-3"/><path d="m2 22 3-3"/><path d="M6.3 20.3a2.4 2.4 0 0 0 3.4 0L12 18l-6-6-2.3 2.3a2.4 2.4 0 0 0 0 3.4Z"/><path d="M7.5 13.5 10 11"/><path d="M10.5 16.5 13 14"/><path d="m12 6 6 6 2.3-2.3a2.4 2.4 0 0 0 0-3.4l-2.6-2.6a2.4 2.4 0 0 0-3.4 0Z"/>',
		'circle-stop'     => '<circle cx="12" cy="12" r="10"/><rect x="9" y="9" width="6" height="6" rx="1"/>',
		'copy'            => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
	);

	/**
	 * Icons that point somewhere and mirror in right-to-left languages.
	 */
	const FLIP = array( 'external-link', 'log-in' );

	/**
	 * BladeCP word mark (viewBox 0 0 354.64 63.53).
	 */
	const LOGO = '<path d="M59.99,31.93c5.43-2.29,8.93-6.5,8.93-12.5,0-7.5-6.07-11.72-17.36-11.72h-25.3l-5.92,29.65h34.45l-1.08,5.02c-.59,2.76-3.34,5.02-6.1,5.02h-1.21c-.58.07-1.19.1-1.84.1H13.35c-7.36.49-11.49,4.21-13.35,10.22h43.77c12.86,0,22.94-5,22.94-15.86,0-4.64-2.43-8.07-6.72-9.93h0ZM48.2,17.93c3.72,0,5.93,1.22,5.93,3.93,0,3.72-2.72,5.72-7.72,5.72h-10.15l1.93-9.65h10ZM68.13,57.73h13.58l8.99-45.08c1.56-6.96-3.63-8.68-11.99-7.94l-10.58,53.02ZM123.15,19l-.93,4.64c-2.43-3.72-6.65-5.29-11.86-5.29-11.58,0-21.01,9.5-21.01,22.37,0,11.29,8,17.65,16.36,17.65,4.5,0,8.22-1.5,11.22-4.29l-.79,3.64h12.86l5.34-26.79c.47-8.04-4.16-11.15-11.2-11.94h0ZM110.43,47.44c-4.64,0-7.36-2.86-7.36-7.36,0-6.5,3.86-10.86,9.22-10.86,4.64,0,7.36,2.93,7.36,7.43,0,6.43-3.79,10.79-9.22,10.79ZM173.46,4.71l-3.79,18.79c-2.43-3.57-6.65-5.14-11.79-5.14-11.58,0-21.08,9.5-21.08,22.37,0,11.29,8,17.65,16.36,17.65,4.5,0,8.29-1.64,11.22-4.43l-.79,3.79h12.86l8.99-45.08c1.05-6.36-3.54-8.57-11.99-7.94h0ZM157.88,47.44c-4.64,0-7.29-2.86-7.29-7.36,0-6.5,3.79-10.86,9.22-10.86,4.64,0,7.29,2.93,7.29,7.43,0,6.43-3.79,10.79-9.22,10.79ZM226.55,35.36c0-10.15-7.29-17.01-19.08-17.01-13.93,0-23.44,9.5-23.44,22.37,0,10.79,8.15,17.65,20.58,17.65,5.86,0,11.65-1.43,16.51-5-1.49-3.61-4.62-7.53-10.81-5.88-9.28,2.46-12.49-1.48-12.99-5.76h28.44c.43-1.93.79-4.07.79-6.36h0ZM206.83,28c4.36,0,7.29,2.43,7.22,6.57h-15.93c1.29-3.86,4.14-6.57,8.72-6.57Z"/><path d="M263.7,63.53c10.91,0,19.9-3.06,26.79-11.35-2.46-4.91-6.02-7.84-16.8-4.85-.02,0-.04.02-.06.03-2.6,1.3-5.38,1.85-8.27,1.85-9.34,0-14.92-5.5-14.92-13.7,0-12.04,7.85-21.21,19.72-21.21,2.23,0,4.3.34,6.17,1.04.01,0,.02,0,.03.01,11.94,4.52,17.95-1.84,19.72-3.85-4.36-7.42-13.53-11.52-24.79-11.52-22.69,0-38.31,15.45-38.31,36.65,0,15.97,11.96,26.88,30.72,26.88h0ZM329.85,1.22h-25.83l-12.22,61.09c9.35.01,17.19-3.15,20.42-15.97h12.22c18.42,0,30.2-10.04,30.2-25.48,0-12.39-8.91-19.64-24.79-19.64h0ZM325.23,32.73h-10.3l3.58-17.89h9.08c6.02,0,9.6,2.36,9.6,7.51,0,6.72-4.37,10.39-11.96,10.39h0Z" fill-rule="evenodd"/>';

	/**
	 * BladeCP symbol (viewBox 0 0 118.16 85.75).
	 */
	const MARK = 'M102.85,41.53c9.31-3.92,15.31-11.15,15.31-21.44,0-12.86-10.41-20.09-29.77-20.09h-43.37l-10.16,50.84h59.06l-1.85,8.61c-1.02,4.74-5.73,8.61-10.46,8.61h-2.08c-1.05.11-2.1.17-3.15.17l-53.49-.02c-12.61.86-19.71,7.25-22.89,17.54h75.03c22.05,0,39.32-8.57,39.32-27.2,0-7.96-4.17-13.84-11.52-17.03h.02ZM82.64,17.52c6.37,0,10.17,2.08,10.17,6.74,0,6.37-4.66,9.8-13.23,9.8h-17.4l3.31-16.54h17.15Z';

	/**
	 * Gradient ids already printed on this page.
	 *
	 * @var int
	 */
	private static $marks = 0;

	/**
	 * An icon, hidden from assistive technology. It always carries bcpm-icon
	 * (16px by default; each place in the stylesheet sizes its own icons).
	 *
	 * @param string $name  Key of PATHS; anything else gives an empty string.
	 * @param string $class Extra classes.
	 */
	public static function svg( string $name, string $class = '' ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}
		$classes = trim( 'bcpm-icon ' . $class . ( in_array( $name, self::FLIP, true ) ? ' bcpm-flip' : '' ) );
		return '<svg class="' . esc_attr( $classes ) . '" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::PATHS[ $name ] . '</svg>';
	}

	/**
	 * The BladeCP word mark, named for assistive technology.
	 *
	 * @param string $class Classes.
	 */
	public static function logo( string $class ): string {
		return '<svg class="' . esc_attr( $class ) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 354.64 63.53" fill="currentColor" role="img" aria-label="BladeCP" focusable="false">' . self::LOGO . '</svg>';
	}

	/**
	 * The BladeCP symbol with the brand gradient, decorative. Each one gets its
	 * own gradient id, so several can share a page.
	 *
	 * @param string $class Classes.
	 */
	public static function mark( string $class ): string {
		++self::$marks;
		$id = 'bcpm-mark-' . self::$marks;
		return '<svg class="' . esc_attr( $class ) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 118.16 85.75" aria-hidden="true" focusable="false"><defs>'
			. '<linearGradient id="' . $id . '" x1="47.26" y1="44.94" x2="145.43" y2="46.35" gradientTransform="translate(0 88) scale(1 -1)" gradientUnits="userSpaceOnUse">'
			. '<stop offset="0" stop-color="#8b5cf6"/><stop offset=".7" stop-color="#636ff6"/><stop offset="1" stop-color="#3b82f6"/></linearGradient></defs>'
			. '<path fill="url(#' . $id . ')" d="' . self::MARK . '"/></svg>';
	}
}
