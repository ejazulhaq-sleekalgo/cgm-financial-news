<?php
namespace CGM\FinancialNews\Core\Repository;

/**
 * Repository for custom logging.
 */
class LogRepository {

	/**
	 * Write a log entry.
	 *
	 * @param string      $level    info|warning|error
	 * @param string|null $ticker   Ticker symbol or null
	 * @param string      $action   API call, processing stage, etc.
	 * @param string      $message  User-friendly description
	 * @param array|null  $context  Raw JSON context details
	 * @return bool
	 */
	public function log( string $level, ?string $ticker, string $action, string $message, ?array $context = null ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_logs';

		$data = [
			'timestamp' => current_time( 'mysql' ),
			'level'     => sanitize_text_field( $level ),
			'ticker'    => $ticker ? strtoupper( sanitize_text_field( $ticker ) ) : null,
			'action'    => sanitize_text_field( $action ),
			'message'   => sanitize_text_field( $message ),
			'context'   => $context ? wp_json_encode( $context ) : null,
		];

		$formats = [ '%s', '%s', '%s', '%s', '%s', '%s' ];
		return (bool) $wpdb->insert( $table, $data, $formats );
	}

	public function info( ?string $ticker, string $action, string $message, ?array $context = null ): bool {
		return $this->log( 'info', $ticker, $action, $message, $context );
	}

	public function warning( ?string $ticker, string $action, string $message, ?array $context = null ): bool {
		return $this->log( 'warning', $ticker, $action, $message, $context );
	}

	public function error( ?string $ticker, string $action, string $message, ?array $context = null ): bool {
		return $this->log( 'error', $ticker, $action, $message, $context );
	}

	/**
	 * Retrieve log entries.
	 *
	 * @param int         $limit
	 * @param int         $offset
	 * @param string|null $level
	 * @param string|null $ticker
	 * @param string|null $search
	 * @return array
	 */
	public function get_logs( int $limit = 100, int $offset = 0, ?string $level = null, ?string $ticker = null, ?string $search = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_logs';

		$conditions = [];
		$params     = [];

		if ( $level ) {
			$conditions[] = 'level = %s';
			$params[]     = $level;
		}

		if ( $ticker ) {
			$conditions[] = 'ticker = %s';
			$params[]     = strtoupper( $ticker );
		}

		if ( $search ) {
			$conditions[] = '(message LIKE %s OR action LIKE %s)';
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_clause = '';
		if ( ! empty( $conditions ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $conditions );
		}

		$query = "SELECT * FROM $table $where_clause ORDER BY timestamp DESC, id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$sql = empty( $params ) ? $query : $wpdb->prepare( $query, ...$params );
		$results = $wpdb->get_results( $sql, ARRAY_A );

		// Decode context JSON fields.
		if ( is_array( $results ) ) {
			foreach ( $results as &$row ) {
				if ( ! empty( $row['context'] ) ) {
					$row['context'] = json_decode( $row['context'], true );
				}
			}
		}

		return $results ?: [];
	}

	/**
	 * Count log entries matching criteria.
	 *
	 * @param string|null $level
	 * @param string|null $ticker
	 * @param string|null $search
	 * @return int
	 */
	public function get_count( ?string $level = null, ?string $ticker = null, ?string $search = null ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_logs';

		$conditions = [];
		$params     = [];

		if ( $level ) {
			$conditions[] = 'level = %s';
			$params[]     = $level;
		}

		if ( $ticker ) {
			$conditions[] = 'ticker = %s';
			$params[]     = strtoupper( $ticker );
		}

		if ( $search ) {
			$conditions[] = '(message LIKE %s OR action LIKE %s)';
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_clause = '';
		if ( ! empty( $conditions ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $conditions );
		}

		$query = "SELECT COUNT(*) FROM $table $where_clause";
		$sql = empty( $params ) ? $query : $wpdb->prepare( $query, ...$params );

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Clear all log entries.
	 *
	 * @return bool
	 */
	public function clear_logs(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_logs';
		return (bool) $wpdb->query( "TRUNCATE TABLE $table" );
	}
}
