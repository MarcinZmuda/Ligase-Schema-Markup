<?php
/**
 * Ligase - Logger
 *
 * Handles plugin logging with file rotation and debug mode support.
 *
 * @package Ligase
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton logging class for Ligase.
 *
 * Writes structured log entries to wp-content/uploads/ligase-logs/.
 * Supports automatic log rotation when files exceed 5 MB.
 */
final class Ligase_Logger {

	private const MAX_FILE_SIZE   = 5 * 1024 * 1024; // 5 MB
	private const MAX_ROTATIONS   = 3;
	private const LOG_DIR_NAME    = 'ligase-logs';
	private const LOG_FILE_NAME   = 'ligase-debug.log';

	private static ?self $instance = null;

	private string $log_dir;
	private string $log_file;

	/**
	 * Private constructor enforces singleton usage.
	 */
	private function __construct() {
		$upload_dir     = wp_upload_dir();
		$this->log_dir  = trailingslashit( $upload_dir['basedir'] ) . self::LOG_DIR_NAME;
		$this->log_file = trailingslashit( $this->log_dir ) . self::LOG_FILE_NAME;

		$this->ensure_log_directory();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}

	/**
	 * Return the singleton instance.
	 */
	private static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ------------------------------------------------------------------
	// Public static API
	// ------------------------------------------------------------------

	/**
	 * Log an informational message.
	 *
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional associative context data.
	 */
	public static function info( string $message, array $context = [] ): void {
		self::get_instance()->log( 'INFO', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional associative context data.
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::get_instance()->log( 'WARNING', $message, $context );
	}

	/**
	 * Log an error message. Errors are always logged regardless of debug_mode.
	 *
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional associative context data.
	 */
	public static function error( string $message, array $context = [] ): void {
		self::get_instance()->log( 'ERROR', $message, $context, true );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional associative context data.
	 */
	public static function debug( string $message, array $context = [] ): void {
		self::get_instance()->log( 'DEBUG', $message, $context );
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Check whether debug mode is enabled in plugin options.
	 */
	private function is_debug_enabled(): bool {
		$options = get_option( 'ligase_options', [] );

		if ( is_array( $options ) && ! empty( $options['debug_mode'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Write a single log entry.
	 *
	 * @param string  $level       Log level label.
	 * @param string  $message     Human-readable message.
	 * @param mixed[] $context     Arbitrary context data.
	 * @param bool    $force_write If true, write even when debug mode is off.
	 */
	private function log(
		string $level,
		string $message,
		array $context = [],
		bool $force_write = false,
	): void {
		if ( ! $force_write && ! $this->is_debug_enabled() ) {
			return;
		}

		$this->maybe_rotate();

		$timestamp    = gmdate( 'c' ); // ISO 8601
		$context_json = ! empty( $context ) ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
		$line         = sprintf(
			'[%s] [%s] %s',
			$timestamp,
			$level,
			$message,
		);

		if ( '' !== $context_json ) {
			$line .= ' | ' . $context_json;
		}

		$line .= PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Create the log directory (and an .htaccess to deny direct access).
	 */
	private function ensure_log_directory(): void {
		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		$htaccess = trailingslashit( $this->log_dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		// Nginx fallback — index.php blocks directory listing and direct access
		$index = trailingslashit( $this->log_dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, '<?php // Silence is golden.' );
		}
	}

	/**
	 * Rotate the log file when it exceeds MAX_FILE_SIZE.
	 *
	 * Rotation scheme:
	 *   ligase-debug.log   -> ligase-debug.log.1
	 *   ligase-debug.log.1 -> ligase-debug.log.2
	 *   ligase-debug.log.2 -> ligase-debug.log.3
	 *   ligase-debug.log.3 -> deleted
	 */
	private function maybe_rotate(): void {
		if ( ! file_exists( $this->log_file ) ) {
			return;
		}

		$size = filesize( $this->log_file );

		if ( false === $size || $size < self::MAX_FILE_SIZE ) {
			return;
		}

		// Remove the oldest rotation if it exists.
		$oldest = $this->log_file . '.' . self::MAX_ROTATIONS;
		if ( file_exists( $oldest ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $oldest );
		}

		// Shift existing rotations up by one.
		for ( $i = self::MAX_ROTATIONS - 1; $i >= 1; $i-- ) {
			$source      = $this->log_file . '.' . $i;
			$destination = $this->log_file . '.' . ( $i + 1 );

			if ( file_exists( $source ) ) {
				rename( $source, $destination );
			}
		}

		// Rotate the current log file.
		rename( $this->log_file, $this->log_file . '.1' );
	}
}
