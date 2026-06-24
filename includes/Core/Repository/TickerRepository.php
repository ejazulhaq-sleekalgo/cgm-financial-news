<?php
namespace CGM\FinancialNews\Core\Repository;

/**
 * Repository for managing the wp_cgm_tickers custom database table.
 */
class TickerRepository {

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cgm_tickers';
	}

	/**
	 * Retrieve all tickers, ordered by symbol.
	 *
	 * @param string|null $status Filter by status (active/inactive), or null for all.
	 * @return array
	 */
	public function get_all( ?string $status = null ): array {
		global $wpdb;

		if ( $status ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE status = %s ORDER BY symbol ASC",
				$status
			);
		} else {
			$sql = "SELECT * FROM {$this->table()} ORDER BY symbol ASC";
		}

		$results = $wpdb->get_results( $sql, ARRAY_A );
		return $results ?: [];
	}

	/**
	 * Get active tickers only.
	 *
	 * @return array
	 */
	public function get_active(): array {
		return $this->get_all( 'active' );
	}

	/**
	 * Get a single ticker by symbol.
	 *
	 * @param string $symbol
	 * @return array|null
	 */
	public function get_by_symbol( string $symbol ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE symbol = %s",
				strtoupper( $symbol )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Check if a ticker symbol exists.
	 *
	 * @param string $symbol
	 * @return bool
	 */
	public function exists( string $symbol ): bool {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE symbol = %s",
				strtoupper( $symbol )
			)
		);

		return ! empty( $id );
	}

	/**
	 * Create a new ticker.
	 *
	 * @param array $data {
	 *     symbol   => string (required, uppercase)
	 *     alias    => string (optional, defaults to symbol)
	 *     news_limit    => int    (optional, defaults to 3)
	 *     status   => string (optional, 'active'|'inactive', defaults to 'active')
	 * }
	 * @return int|false Inserted ID or false on failure.
	 */
	public function create( array $data ) {
		global $wpdb;

		$symbol = strtoupper( sanitize_text_field( $data['symbol'] ) );
		if ( empty( $symbol ) ) {
			return false;
		}

		$row = [
			'symbol'     => $symbol,
			'alias'      => strtoupper( sanitize_text_field( $data['alias'] ?? $symbol ) ),
			'news_limit'      => max( 1, intval( $data['news_limit'] ?? 3 ) ),
			'status'     => in_array( $data['status'] ?? '', [ 'active', 'inactive' ], true ) ? $data['status'] : 'active',
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		];

		$result = $wpdb->insert( $this->table(), $row, [ '%s', '%s', '%d', '%s', '%s', '%s' ] );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing ticker by symbol.
	 *
	 * @param string $symbol
	 * @param array  $data Fields to update (alias, limit, status).
	 * @return bool
	 */
	public function update( string $symbol, array $data ): bool {
		global $wpdb;

		$row    = [];
		$formats = [];

		if ( isset( $data['alias'] ) ) {
			$row['alias'] = strtoupper( sanitize_text_field( $data['alias'] ) );
			$formats[]    = '%s';
		}

		if ( isset( $data['news_limit'] ) ) {
			$row['news_limit'] = max( 1, intval( $data['news_limit'] ) );
			$formats[]    = '%d';
		}

		if ( isset( $data['status'] ) ) {
			$row['status'] = in_array( $data['status'], [ 'active', 'inactive' ], true ) ? $data['status'] : 'active';
			$formats[]     = '%s';
		}

		if ( empty( $row ) ) {
			return false;
		}

		$row['updated_at'] = current_time( 'mysql' );
		$formats[]         = '%s';

		return false !== $wpdb->update(
			$this->table(),
			$row,
			[ 'symbol' => strtoupper( $symbol ) ],
			$formats,
			[ '%s' ]
		);
	}

	/**
	 * Delete a ticker by symbol.
	 *
	 * @param string $symbol
	 * @return bool
	 */
	public function delete( string $symbol ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			$this->table(),
			[ 'symbol' => strtoupper( $symbol ) ],
			[ '%s' ]
		);
	}

	/**
	 * Migrate tickers from the old settings array into the custom table.
	 * Idempotent — skips symbols that already exist.
	 *
	 * @return int Number of tickers migrated.
	 */
	public function migrate_from_settings(): int {
		$saved       = get_option( 'cgm_financial_news_settings', [] );
		$old_tickers = is_array( $saved ) ? ( $saved['tickers'] ?? [] ) : [];
		$count       = 0;

		foreach ( $old_tickers as $ticker ) {
			$symbol = strtoupper( sanitize_text_field( $ticker['symbol'] ?? '' ) );
			if ( empty( $symbol ) || $this->exists( $symbol ) ) {
				continue;
			}

			$created = $this->create( [
				'symbol' => $symbol,
				'alias'  => $ticker['alias'] ?? $symbol,
				'news_limit'  => $ticker['limit'] ?? 3,
				'status' => $ticker['status'] ?? 'active',
			] );

			if ( $created ) {
				$count++;
			}
		}

		return $count;
	}
}
