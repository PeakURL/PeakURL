<?php
/**
 * Process Concurrency Trait for executing genuinely overlapping transaction tests.
 *
 * @package PeakURL\Tests\Integration
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Trait providing robust child process execution with socket IPC synchronization.
 *
 * Enables genuine overlapping transaction lifetimes across separate operating system
 * processes and independent database sessions.
 */
trait ProcessConcurrencyTrait {

	/**
	 * Run two test tasks in concurrent operating system processes with genuine overlap.
	 *
	 * Uses pcntl_fork() and bidirectional socket synchronization.
	 * If pcntl_fork is unavailable, marks the test skipped with an honest prerequisite statement.
	 *
	 * @param callable $proc1 Callable for parent process: fn(resource $socket): mixed.
	 * @param callable $proc2 Callable for child process:  fn(resource $socket): mixed.
	 * @param int      $timeout_seconds Maximum execution time before aborting.
	 * @return array{0: array{status: string, result?: mixed, class?: string, message?: string, code?: int}, 1: array{status: string, result?: mixed, class?: string, message?: string, code?: int}}
	 */
	protected function run_concurrent_test_processes( callable $proc1, callable $proc2, int $timeout_seconds = 10 ): array {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl_fork is not available in this PHP runtime. Real concurrency test NOT RUN.' );
		}

		$sockets = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		if ( false === $sockets ) {
			$this->markTestSkipped( 'stream_socket_pair failed to create IPC sockets. Real concurrency test NOT RUN.' );
		}

		// Disconnect and reset any shared singleton connection and i18n service before forking so no socket is inherited
		if ( function_exists( 'set_i18n_service' ) ) {
			set_i18n_service( new \PeakURL\Services\I18n( \PeakURL\Core\Config\Configuration::get_current() ) );
		}
		if ( class_exists( '\PeakURL\Services\Database\Connection' ) ) {
			\PeakURL\Services\Database\Connection::reset_instance();
		}

		$pid = pcntl_fork();
		if ( -1 === $pid ) {
			fclose( $sockets[0] );
			fclose( $sockets[1] );
			$this->markTestSkipped( 'pcntl_fork failed to spawn child process. Real concurrency test NOT RUN.' );
		}

		if ( 0 === $pid ) {
			// CHILD PROCESS
			fclose( $sockets[0] );
			$child_sock = $sockets[1];
			stream_set_timeout( $child_sock, $timeout_seconds );

			// Ensure child process uses its own isolated i18n and database instances
			if ( function_exists( 'set_i18n_service' ) ) {
				set_i18n_service( new \PeakURL\Services\I18n( \PeakURL\Core\Config\Configuration::get_current() ) );
			}
			if ( class_exists( '\PeakURL\Services\Database\Connection' ) ) {
				\PeakURL\Services\Database\Connection::reset_instance();
			}

			try {
				$res     = $proc2( $child_sock );
				$payload = array(
					'status' => 'ok',
					'result' => $res,
				);
			} catch ( \Throwable $e ) {
				$payload = array(
					'status'  => 'error',
					'class'   => get_class( $e ),
					'message' => $e->getMessage(),
					'code'    => (int) $e->getCode(),
				);
			}

			$serialized = serialize( $payload );
			$len        = strlen( $serialized );
			fwrite( $child_sock, pack( 'N', $len ) . $serialized );
			fflush( $child_sock );
			fclose( $child_sock );

			// Explicitly disconnect child connection and exit cleanly
			if ( class_exists( '\PeakURL\Services\Database\Connection' ) ) {
				\PeakURL\Services\Database\Connection::reset_instance();
			}
			exit( 0 );
		}

		// PARENT PROCESS
		fclose( $sockets[1] );
		$parent_sock = $sockets[0];
		stream_set_timeout( $parent_sock, $timeout_seconds );

		$parent_payload = null;
		try {
			$res            = $proc1( $parent_sock );
			$parent_payload = array(
				'status' => 'ok',
				'result' => $res,
			);
		} catch ( \Throwable $e ) {
			$parent_payload = array(
				'status'  => 'error',
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'code'    => (int) $e->getCode(),
			);
		}

		// Read child result with length prefix
		$header        = fread( $parent_sock, 4 );
		$child_payload = null;
		if ( false !== $header && 4 === strlen( $header ) ) {
			$unpacked = unpack( 'Nlen', $header );
			$len      = (int) ( $unpacked['len'] ?? 0 );
			$body     = '';
			while ( strlen( $body ) < $len ) {
				$chunk = fread( $parent_sock, min( 8192, $len - strlen( $body ) ) );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$body .= $chunk;
			}
			$child_payload = @unserialize( $body );
		}
		fclose( $parent_sock );

		// Await child process termination with bounded timeout
		$status = 0;
		$waited = pcntl_waitpid( $pid, $status, WNOHANG );
		$start  = time();
		while ( 0 === $waited && ( time() - $start ) < $timeout_seconds ) {
			usleep( 20000 );
			$waited = pcntl_waitpid( $pid, $status, WNOHANG );
		}

		if ( 0 === $waited ) {
			posix_kill( $pid, SIGKILL );
			pcntl_waitpid( $pid, $status );
		}

		if ( ! is_array( $child_payload ) ) {
			$child_payload = array(
				'status'  => 'error',
				'message' => 'Child process terminated without sending response payload.',
			);
		}

		return array( $parent_payload, $child_payload );
	}

	/**
	 * Verify that a specific transaction is actively waiting for an InnoDB lock.
	 *
	 * Matches the waiting session and optionally the blocker session and fixture table
	 * against performance_schema.data_lock_waits (primary in MySQL 8.0+) or falls back
	 * to information_schema.innodb_trx scoped to the waiting connection ID.
	 *
	 * @param \PDO        $pdo Active database connection.
	 * @param int         $waiting_connection_id Expected waiting MySQL connection ID (from SELECT CONNECTION_ID()).
	 * @param ?int        $blocking_connection_id Optional expected blocking MySQL connection ID.
	 * @param ?string     $expected_table Optional expected fixture table name (without quotes).
	 * @param int         $timeout_ms Maximum time to wait in milliseconds.
	 * @return bool True if the identified transaction was observed in lock wait state.
	 */
	protected function observe_innodb_lock_wait(
		\PDO $pdo,
		int $waiting_connection_id,
		?int $blocking_connection_id = null,
		?string $expected_table = null,
		int $timeout_ms = 2000
	): bool {
		if ( $waiting_connection_id <= 0 ) {
			return false;
		}

		$start_ns = hrtime( true );
		$limit_ns = $timeout_ms * 1_000_000;

		$has_p_schema = null;

		while ( ( hrtime( true ) - $start_ns ) < $limit_ns ) {
			if ( false !== $has_p_schema ) {
				try {
					$where  = array( 'wt.PROCESSLIST_ID = :waiting_conn_id' );
					$params = array( ':waiting_conn_id' => $waiting_connection_id );

					if ( null !== $blocking_connection_id ) {
						$where[]                     = 'bt.PROCESSLIST_ID = :blocking_conn_id';
						$params[':blocking_conn_id'] = $blocking_connection_id;
					}

					if ( null !== $expected_table ) {
						$where[]                   = 'dl.OBJECT_NAME = :expected_table';
						$params[':expected_table'] = $expected_table;
					}

					$sql = 'SELECT COUNT(*)
						FROM performance_schema.data_lock_waits dlw
						JOIN performance_schema.threads wt ON dlw.REQUESTING_THREAD_ID = wt.THREAD_ID
						LEFT JOIN performance_schema.threads bt ON dlw.BLOCKING_THREAD_ID = bt.THREAD_ID
						LEFT JOIN performance_schema.data_locks dl ON dlw.REQUESTING_ENGINE_LOCK_ID = dl.ENGINE_LOCK_ID
						WHERE ' . implode( ' AND ', $where );

					$stmt = $pdo->prepare( $sql );
					$stmt->execute( $params );
					$count        = (int) $stmt->fetchColumn();
					$has_p_schema = true;
					if ( $count > 0 ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					$has_p_schema = false;
				}
			}

			// 2. Scoped fallback: ONLY if performance_schema is unavailable/inaccessible
			if ( false === $has_p_schema ) {
				try {
					$fallback_stmt = $pdo->prepare(
						"SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = :waiting_conn_id AND trx_state = 'LOCK WAIT'"
					);
					$fallback_stmt->execute( array( ':waiting_conn_id' => $waiting_connection_id ) );
					if ( (int) $fallback_stmt->fetchColumn() > 0 ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					// ignore fallback query errors
				}
			}

			usleep( 20000 ); // 20ms poll interval
		}

		return false;
	}
}
