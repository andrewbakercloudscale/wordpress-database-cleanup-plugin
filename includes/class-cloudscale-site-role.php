<?php
/**
 * class-cloudscale-site-role.php, one answer to "is this site a copy?".
 *
 * WHY THIS IS SHARED RATHER THAN PER-PLUGIN
 *
 * Five plugins run on every box in this estate, and on a standby all five inherit
 * the primary's database — which is where their schedules live. Measured on QA and
 * DR, 2026-09-13: the SEO plugin's daily AI batch was armed on both, so two copies
 * were about to spend real money generating meta descriptions into databases that
 * the next restore deletes. The uptime heartbeat was armed on both, reporting
 * production alive using production's inherited token.
 *
 * Exactly one plugin of the five gated anything on being a standby. The mechanism
 * was never missing — /etc/cloudscale/site-role is documented as read by every
 * service on the box, and cyber-devtools already read it correctly — it was just
 * never wired to anything that acts.
 *
 * WHY IT DOES NOT SIMPLY CALL csbr_is_standby()
 *
 * It does when that function exists, because the backup plugin owns the full
 * precedence chain and duplicating it would be the drift this file exists to stop.
 * But it must also answer when that plugin is deactivated, which is the same
 * reasoning cyber-devtools' standby banner already records: a suppression that
 * switches itself off when another plugin is disabled is worse than none, because
 * it fails in the expensive direction silently.
 *
 * DENY BY DEFAULT, IN THE DIRECTION THAT COSTS LESS
 *
 * A host flag that exists but cannot be read means standby. Being wrongly
 * suppressed costs a skipped nightly batch on the live site, which is visible and
 * recoverable. Being wrongly allowed costs money on a copy, alerts that look like
 * production's, and deletions on a box somebody thought was inert.
 *
 * @package CloudScale_Shared
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CloudScale_Site_Role' ) ) {

	/**
	 * Is this install a copy of another site?
	 */
	class CloudScale_Site_Role {

		/** Words people actually reach for, all meaning "not the live site". */
		const STANDBY_WORDS = array( 'standby', 'stand-by', 'slave', 'replica', 'secondary', 'qa', 'staging', 'dr', 'passive', 'test' );

		/** Cached for the request. A global, not a static, so tests can clear it. */
		const CACHE_KEY = 'cloudscale_site_role_cache';

		/**
		 * True when this site must not act on the primary's behalf.
		 *
		 * @return bool
		 */
		public static function is_standby(): bool {
			if ( isset( $GLOBALS[ self::CACHE_KEY ] ) ) {
				return (bool) $GLOBALS[ self::CACHE_KEY ];
			}

			// The backup plugin owns the canonical chain, including the wp-content
			// marker that is the ONLY signal on a host with no /etc/cloudscale — which
			// is how DR is deliberately built.
			if ( function_exists( 'csbr_is_standby' ) ) {
				return (bool) ( $GLOBALS[ self::CACHE_KEY ] = csbr_is_standby() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- CACHE_KEY is 'cloudscale_site_role_cache'
			}

			return (bool) ( $GLOBALS[ self::CACHE_KEY ] = self::resolve_without_backup_plugin() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- CACHE_KEY is 'cloudscale_site_role_cache'
		}

		/**
		 * Forget the cached answer.
		 *
		 * @return void
		 */
		public static function reset_cache(): void {
			unset( $GLOBALS[ self::CACHE_KEY ] );
		}

		/**
		 * The same precedence the backup plugin implements, for when it is not active.
		 *
		 * @return bool
		 */
		private static function resolve_without_backup_plugin(): bool {
			$flag = '/etc/cloudscale/site-role';
			if ( defined( 'CSBR_HOST_ROLE_FLAG' ) && '' !== trim( (string) constant( 'CSBR_HOST_ROLE_FLAG' ) ) ) {
				$flag = (string) constant( 'CSBR_HOST_ROLE_FLAG' );
			}

			// The mechanism being DEPLOYED is what makes a missing file meaningful. On a
			// host that has never heard of it, absence says nothing and must not imply
			// standby, or every ordinary WordPress install suppresses itself.
			if ( is_dir( dirname( $flag ) ) ) {
				if ( ! is_readable( $flag ) ) {
					return true; // Deployed and unreadable: assume copy.
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a host flag outside the WP tree; WP_Filesystem does not apply
				$raw = file_get_contents( $flag );
				if ( false === $raw ) {
					return true;
				}
				$word = self::first_word( (string) $raw );
				if ( '' === $word ) {
					return true; // Present but unparseable: assume copy.
				}
				return in_array( $word, self::STANDBY_WORDS, true );
			}

			if ( defined( 'CSBR_SITE_ROLE' ) ) {
				return in_array( strtolower( trim( (string) constant( 'CSBR_SITE_ROLE' ) ) ), self::STANDBY_WORDS, true );
			}

			// The marker the admin screen writes, which is the only durable signal on a
			// host without /etc/cloudscale.
			$marker = self::content_dir() . '/csbr-site-role.json';
			if ( is_readable( $marker ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small marker the estate owns
				$data = json_decode( (string) file_get_contents( $marker ), true );
				if ( is_array( $data ) && ! empty( $data['role'] ) ) {
					return in_array( strtolower( trim( (string) $data['role'] ) ), self::STANDBY_WORDS, true );
				}
			}

			return in_array( strtolower( trim( (string) get_option( 'csbr_site_role', '' ) ) ), self::STANDBY_WORDS, true );
		}

		/**
		 * The content directory, derived from this file's own location.
		 *
		 * plugin_basename() is core's answer to "which plugins directory is this file
		 * under", including the realpath map a symlinked install registers, so removing
		 * that tail from the file's own path yields the plugins directory as core sees
		 * it, and its parent is the content directory. No core constant is read; the
		 * marker is only ever read here, never written.
		 *
		 * @return string No trailing slash.
		 */
		private static function content_dir(): string {
			$file = str_replace( '\\', '/', __FILE__ );
			if ( function_exists( 'plugin_basename' ) ) {
				$tail = '/' . plugin_basename( __FILE__ );
				if ( strlen( $tail ) > 1 && substr( $file, -strlen( $tail ) ) === $tail ) {
					return dirname( substr( $file, 0, -strlen( $tail ) ) );
				}
			}
			// includes/ -> plugin -> plugins -> content.
			return dirname( $file, 3 );
		}

		/**
		 * First meaningful word of a flag file, tolerating comments and KEY=VALUE.
		 *
		 * @param string $raw File contents.
		 * @return string Lowercased, or '' when nothing usable was found.
		 */
		private static function first_word( string $raw ): string {
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line || 0 === strpos( $line, '#' ) || 0 === strpos( $line, ';' ) ) {
					continue;
				}
				if ( false !== strpos( $line, '=' ) ) {
					$line = trim( substr( $line, strpos( $line, '=' ) + 1 ) );
				}
				return strtolower( trim( $line, "\"' \t" ) );
			}
			return '';
		}

		/**
		 * Stop a copy emailing anybody, and say what it swallowed.
		 *
		 * WHY THIS IS NOT OPTIONAL ON A REFRESHED COPY
		 *
		 * A copy holds the primary's USERS, ORDERS AND HOOKS. Nothing in WordPress
		 * distinguishes "this is a test database" from the real one, so a password
		 * reset, an order confirmation, a comment notification or any plugin's
		 * lifecycle mail goes to the real customer, from a machine nobody thinks of
		 * as live. It is the one suppression whose blast radius reaches people
		 * outside the estate.
		 *
		 * WHY IT IS NOT A BLANKET BLOCK ON EVERY STANDBY
		 *
		 * A HOT STANDBY is expected to become the live site, and a site that cannot
		 * send a password reset the moment it takes over is broken exactly when it
		 * matters. So this is driven by the caller's purpose, not by is_standby()
		 * alone: refreshed copies are silenced, hot standbys are not.
		 *
		 * WHY IT LOGS RATHER THAN JUST DROPPING
		 *
		 * A copy that silently eats mail looks identical to a copy whose mail is
		 * broken. When somebody asks "did the test send the invoice?", the answer has
		 * to be findable. wp_mail_failed carries the recipient and subject so the
		 * record says what was stopped, not merely that something was.
		 *
		 * @param string $reason Human-readable reason, used in the failure notice.
		 * @return void
		 */
		public static function block_outbound_mail( string $reason ): void {
			add_filter(
				'pre_wp_mail',
				static function ( $short_circuit, $atts ) use ( $reason ) {
					$to      = is_array( $atts['to'] ?? '' ) ? implode( ', ', $atts['to'] ) : (string) ( $atts['to'] ?? '' );
					$subject = (string) ( $atts['subject'] ?? '' );

					if ( function_exists( 'error_log' ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational: a copy that silently eats mail is indistinguishable from one whose mail is broken
						error_log( '[CloudScale] Outbound email BLOCKED on a copy: to=' . $to . ' subject=' . $subject . ' (' . $reason . ')' );
					}

					// true short-circuits wp_mail() and reports success to the caller,
					// which is deliberate: a plugin that treats a mail failure as a
					// hard error would otherwise break checkout flows on the copy, and
					// the point is to make the copy USABLE for testing, not to make it
					// throw. The log and the hook are where the truth lives.
					return true;
				},
				5,
				2
			);
		}

		/**
		 * Disarm a cron hook that must not run on a copy.
		 *
		 * ON EVERY REQUEST, not at schedule time. A standby does not acquire a schedule
		 * by being configured; it acquires one by RESTORING THE PRIMARY'S DATABASE,
		 * which is neither activation nor a settings save. The backup plugin learned
		 * this the hard way — both its events were found armed on QA, overdue and due
		 * "now", held off only by that box happening to have no wp-cron runner.
		 *
		 * wp_next_scheduled() reads the already-loaded cron option, so this costs
		 * nothing on the requests where there is nothing to do, which is all of them.
		 *
		 * @param string[] $hooks  Cron hook names.
		 * @param string   $source Plugin name, for the log line.
		 * @return string[] The hooks actually cleared.
		 */
		public static function disarm( array $hooks, string $source ): array {
			if ( ! self::is_standby() ) {
				return array();
			}
			$cleared = array();
			foreach ( $hooks as $hook ) {
				if ( wp_next_scheduled( $hook ) ) {
					wp_clear_scheduled_hook( $hook );
					$cleared[] = $hook;
				}
			}
			if ( array() !== $cleared && function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational: says which inherited schedules were removed and why
				error_log( '[' . $source . '] Standby: cleared inherited schedule(s) ' . implode( ', ', $cleared ) . '. They arrived with the primary\'s database.' );
			}
			return $cleared;
		}
	}
}
