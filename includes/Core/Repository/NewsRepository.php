<?php
namespace CGM\FinancialNews\Core\Repository;

/**
 * Repository for managing Custom News Registry.
 */
class NewsRepository {

	/**
	 * Insert a fetched news item into the registry.
	 *
	 * @param array $data
	 * @return int|false Inserted ID or false
	 */
	public function add( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$row = [
			'ticker'         => strtoupper( sanitize_text_field( $data['ticker'] ) ),
			'source_id'      => sanitize_text_field( $data['source_id'] ),
			'source_url'     => esc_url_raw( $data['source_url'] ),
			'source_title'   => wp_kses_post( $data['source_title'] ),
			'source_content' => wp_kses_post( $data['source_content'] ),
			'content_hash'   => sanitize_text_field( $data['content_hash'] ),
			'status'         => 'pending',
			'created_at'     => current_time( 'mysql' ),
		];

		$result = $wpdb->insert( $table, $row, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Check if a news article already exists in the registry.
	 * Returns true if duplicate is found.
	 *
	 * @param string $source_id
	 * @param string $url
	 * @param string $content_hash
	 * @return bool
	 */
	public function exists( string $source_id, string $url, string $content_hash ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$sql = "SELECT id FROM $table WHERE source_id = %s OR source_url = %s OR content_hash = %s LIMIT 1";
		$id = $wpdb->get_var( $wpdb->prepare( $sql, $source_id, $url, $content_hash ) );

		return ! empty( $id );
	}

	/**
	 * Update processing status of a registry record.
	 *
	 * @param int         $id
	 * @param string      $status         pending|processed|skipped_irrelevant|failed
	 * @param int|null    $post_id        The WP published Post ID
	 * @param string|null $error_message  Any error logs if failed
	 * @return bool
	 */
	public function update_status( int $id, string $status, ?int $post_id = null, ?string $error_message = null ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$data = [
			'status' => sanitize_text_field( $status ),
		];
		$formats = [ '%s' ];

		if ( null !== $post_id ) {
			$data['post_id']      = intval( $post_id );
			$data['published_at'] = current_time( 'mysql' );
			$formats[]            = '%d';
			$formats[]            = '%s';
		}

		if ( null !== $error_message ) {
			$data['error_message'] = sanitize_textarea_field( $error_message );
			$formats[]             = '%s';
		}

		return false !== $wpdb->update( $table, $data, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	/**
	 * Persist rewrite data for an item.
	 *
	 * @param int    $id
	 * @param array  $rewrite_data
	 * @param string $status
	 * @return bool
	 */
	public function save_rewrite( int $id, array $rewrite_data, string $status = 'rewritten' ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$data = [
			'rewrite_title'     => wp_kses_post( $rewrite_data['title'] ?? '' ),
			'rewrite_content'   => wp_kses_post( $rewrite_data['content'] ?? '' ),
			'rewrite_summary'   => wp_kses_post( $rewrite_data['summary'] ?? '' ),
			'rewrite_sentiment' => sanitize_text_field( $rewrite_data['sentiment'] ?? '' ),
			'rewrite_relevance' => intval( $rewrite_data['relevance'] ?? 0 ),
			'rewrite_facts'     => wp_kses_post( wp_json_encode( $rewrite_data['extracted_facts'] ?? [] ) ),
			'rewrite_model'     => sanitize_text_field( $rewrite_data['model'] ?? '' ),
			'rewrite_status'    => sanitize_text_field( $status ),
			'rewrite_error'     => sanitize_textarea_field( $rewrite_data['error'] ?? '' ),
		];

		return false !== $wpdb->update( $table, $data, [ 'id' => $id ], [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );
	}

	/**
	 * Persist the final post content for an item.
	 *
	 * @param int    $id
	 * @param string $post_title
	 * @param string $post_content
	 * @return bool
	 */
	public function save_published_post( int $id, string $post_title, string $post_content ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$data = [
			'post_title'   => wp_kses_post( $post_title ),
			'post_content' => wp_kses_post( $post_content ),
		];

		return false !== $wpdb->update( $table, $data, [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
	}

	/**
	 * Get a single registry record by ID.
	 *
	 * @param int $id
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Retrieve a list of items in the queue (processed or pending).
	 *
	 * @param int         $limit
	 * @param int         $offset
	 * @param string|null $status
	 * @param string|null $ticker
	 * @return array
	 */
	public function get_queue( int $limit = 50, int $offset = 0, ?string $status = null, ?string $ticker = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$conditions = [];
		$params     = [];

		if ( $status ) {
			$conditions[] = 'status = %s';
			$params[]     = $status;
		}

		if ( $ticker ) {
			$conditions[] = 'ticker = %s';
			$params[]     = strtoupper( $ticker );
		}

		$where_clause = '';
		if ( ! empty( $conditions ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $conditions );
		}

		$query = "SELECT * FROM $table $where_clause ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$results = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );
		return $results ?: [];
	}

	/**
	 * Count total items in the queue/registry matching criteria.
	 *
	 * @param string|null $status
	 * @param string|null $ticker
	 * @return int
	 */
	public function get_queue_count( ?string $status = null, ?string $ticker = null ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$conditions = [];
		$params     = [];

		if ( $status ) {
			$conditions[] = 'status = %s';
			$params[]     = $status;
		}

		if ( $ticker ) {
			$conditions[] = 'ticker = %s';
			$params[]     = strtoupper( $ticker );
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
	 * Get the number of articles successfully published today for a given ticker.
	 *
	 * @param string $ticker
	 * @return int
	 */
	public function get_today_published_count( string $ticker ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		$today_start = date( 'Y-m-d 00:00:00' );
		$today_end   = date( 'Y-m-d 23:59:59' );

		$sql = "SELECT COUNT(*) FROM $table WHERE ticker = %s AND status = 'processed' AND published_at >= %s AND published_at <= %s";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, strtoupper( $ticker ), $today_start, $today_end ) );
	}

	/**
	 * Reset/retry queue item status back to pending.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function reset_item( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cgm_news_registry';

		return false !== $wpdb->update(
			$table,
			[ 'status' => 'pending', 'error_message' => null ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}
}
