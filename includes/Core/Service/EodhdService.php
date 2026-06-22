<?php
namespace CGM\FinancialNews\Core\Service;

use CGM\FinancialNews\Core\Settings;
use CGM\FinancialNews\Core\Repository\LogRepository;

/**
 * Service Client for EODHD News API.
 */
class EodhdService {

	private Settings $settings;
	private LogRepository $logger;

	private const API_URL = 'https://eodhd.com/api/news';

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, LogRepository $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Test the EODHD API connection.
	 *
	 * @param string $api_key
	 * @return bool True if connection is successful
	 */
	public function test_connection( string $api_key ): bool {
		// Use a standard public ticker like AAPL.US to verify key validity.
		$url = add_query_arg(
			[
				's'         => 'AAPL.US',
				'api_token' => $api_key,
				'fmt'       => 'json',
				'limit'     => 1,
			],
			self::API_URL
		);

		$this->logger->info( null, 'eodhd_test_conn', 'Testing EODHD API connectivity...' );

		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( null, 'eodhd_test_conn_fail', 'HTTP error testing EODHD: ' . $response->get_error_message() );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$this->logger->error(
				null,
				'eodhd_test_conn_fail',
				sprintf( 'EODHD API returned HTTP %d', $status_code ),
				[ 'response' => substr( $body, 0, 500 ) ]
			);
			return false;
		}

		$data = json_decode( $body, true );
		if ( null === $data ) {
			$this->logger->error( null, 'eodhd_test_conn_fail', 'EODHD response was not valid JSON.' );
			return false;
		}

		$this->logger->info( null, 'eodhd_test_conn_success', 'EODHD API credentials validated successfully.' );
		return true;
	}

	/**
	 * Fetch latest news articles for a given ticker symbol.
	 *
	 * @param string $symbol e.g. DSEAF, AKEMF
	 * @param int    $limit  Maximum articles to fetch
	 * @return array List of news items
	 */
	public function fetch_news( string $symbol, int $limit = 10 ): array {
		$api_key = $this->settings->get_eodhd_key();

		if ( empty( $api_key ) ) {
			$this->logger->error( $symbol, 'fetch_news_error', 'Missing EODHD API Key. Please configure it in settings.' );
			return [];
		}

		// Clean symbol
		$symbol = strtoupper( trim( $symbol ) );

		// Construct EODHD endpoint query.
		$url = add_query_arg(
			[
				's'         => $symbol,
				'api_token' => $api_key,
				'fmt'       => 'json',
				'limit'     => $limit,
			],
			self::API_URL
		);

		$this->logger->info( $symbol, 'fetch_news_request', sprintf( 'Fetching news from EODHD for ticker "%s" with limit %d.', $symbol, $limit ) );

		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( $symbol, 'fetch_news_failed', 'HTTP error requesting EODHD news: ' . $response->get_error_message() );
			return [];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$this->logger->error(
				$symbol,
				'fetch_news_failed',
				sprintf( 'EODHD news request failed. HTTP code %d', $status_code ),
				[ 'response' => substr( $body, 0, 500 ) ]
			);
			return [];
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$this->logger->error( $symbol, 'fetch_news_failed', 'Failed to parse JSON response from EODHD.', [ 'raw_body' => substr( $body, 0, 1000 ) ] );
			return [];
		}

		$this->logger->info( $symbol, 'fetch_news_success', sprintf( 'Successfully fetched %d news items from EODHD.', count( $data ) ) );

		return $data;
	}
}
