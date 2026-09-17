<?php
/**
 * CSCC_Paths: the one file in this plugin that reads a core path constant.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * WordPress.org's guidelines say a plugin must determine directories through the
 * API -- plugin_dir_path(), plugins_url(), wp_upload_dir(), get_theme_root(),
 * plugin_basename() -- rather than by reading core's internal constants. The shared
 * submission gate stopped accepting per-file exemptions on 16Sep26, because the
 * review of 14Sep26 quoted five of another plugin's exempted lines back verbatim:
 * a written reason does not stop a reviewer reading the line. Their own guidance is
 * to resolve the value once and share it, so that is what this file is.
 *
 * This plugin needs only two locations, and has four reads of them:
 *
 *   the install root   one URL-to-path conversion, when checking whether an <img>
 *                      in a post still has a file behind it. The URL has already
 *                      been confirmed to sit under wp-content/uploads/, and the
 *                      host part is stripped, so what remains is a path relative
 *                      to the root and only the root can complete it.
 *
 *   the content dir    the three Site Health disk metrics. They are deliberately
 *                      measured at wp-content rather than at the uploads directory:
 *                      uploads can be relocated to another partition, and the number
 *                      this plugin reports is about the partition its cleanup work
 *                      frees space on.
 *
 * It returns core's own value whenever the constant is defined and derives only as
 * a fallback. The derivation is a reasonable answer, not a better one: a disk metric
 * taken at a path core disagrees with is a wrong number reported confidently, and a
 * file existence check against the wrong root deletes the wrong thing. The rule being
 * satisfied is that exactly one file reads these constants, and this is that file.
 *
 * Nothing here touches the filesystem. It returns strings; callers decide what to do
 * with them.
 *
 * @package CloudScale_Cleanup
 * @since   2.5.117
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CSCC_PLUGIN_FILE' ) ) {
	// Only reached when this file is loaded on its own, outside the main plugin
	// file. The main file defines it first, to the same value.
	define( 'CSCC_PLUGIN_FILE', dirname( __DIR__ ) . '/cloudscale-cleanup.php' );
}

if ( ! class_exists( 'CSCC_Paths' ) ) {

	final class CSCC_Paths {

		/**
		 * Forward slashes only, no trailing separator, so comparisons work on every host.
		 *
		 * @param string $path Any path.
		 * @return string
		 */
		private static function norm( string $path ): string {
			return rtrim( str_replace( '\\', '/', $path ), '/' );
		}

		/**
		 * The directory all plugins are installed in, no trailing slash.
		 *
		 * Not used directly by this plugin; it is how content_dir() finds its answer
		 * when core has not stated one. plugin_basename() is core's own answer to
		 * "where do plugins live" and follows the realpath map a symlinked install
		 * registers.
		 *
		 * @since 2.5.117
		 * @return string
		 */
		public static function plugins_dir(): string {
			if ( defined( 'WP_PLUGIN_DIR' ) ) {
				return self::norm( (string) WP_PLUGIN_DIR );
			}
			$file = str_replace( '\\', '/', CSCC_PLUGIN_FILE );
			if ( function_exists( 'plugin_basename' ) ) {
				$tail = '/' . str_replace( '\\', '/', (string) plugin_basename( CSCC_PLUGIN_FILE ) );
				if ( strlen( $tail ) > 1 && substr( $file, -strlen( $tail ) ) === $tail ) {
					return substr( $file, 0, -strlen( $tail ) );
				}
			}
			return dirname( $file, 2 );
		}

		/**
		 * The content directory, no trailing slash.
		 *
		 * @since 2.5.117
		 * @return string
		 */
		public static function content_dir(): string {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				return self::norm( (string) WP_CONTENT_DIR );
			}
			return dirname( self::plugins_dir() );
		}

		/**
		 * The WordPress install root, where wp-load.php lives, no trailing slash.
		 *
		 * @since 2.5.117
		 * @return string
		 */
		public static function site_root(): string {
			return self::norm( (string) ABSPATH );
		}

		/**
		 * A file inside the install root.
		 *
		 * @since 2.5.117
		 * @param string $rel Path relative to the root, with or without a leading slash.
		 * @return string
		 */
		public static function root_file( string $rel ): string {
			return self::site_root() . '/' . ltrim( str_replace( '\\', '/', $rel ), '/' );
		}
	}
}
