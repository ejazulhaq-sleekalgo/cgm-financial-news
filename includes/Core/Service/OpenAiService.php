<?php
namespace CGM\FinancialNews\Core\Service;

use CGM\FinancialNews\Core\Settings;
use CGM\FinancialNews\Core\Repository\LogRepository;

/**
 * Service Client for OpenAI API.
 */
class OpenAiService {

	private Settings $settings;
	private LogRepository $logger;

	private const API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, LogRepository $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Test the OpenAI API connection.
	 *
	 * @param string $api_key
	 * @return bool True if connection is successful
	 */
	public function test_connection( string $api_key ): bool {
		$headers = [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		];

		$body = [
			'model'       => 'gpt-4o-mini',
			'messages'    => [
				[
					'role'    => 'user',
					'content' => 'Ping',
				],
			],
			'max_tokens'  => 5,
		];

		$this->logger->info( null, 'openai_test_conn', 'Testing OpenAI API connectivity...' );

		$response = wp_remote_post(
			self::API_URL,
			[
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( null, 'openai_test_conn_fail', 'HTTP error testing OpenAI: ' . $response->get_error_message() );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$this->logger->error(
				null,
				'openai_test_conn_fail',
				sprintf( 'OpenAI API returned HTTP %d', $status_code ),
				[ 'response' => substr( $response_body, 0, 500 ) ]
			);
			return false;
		}

		$this->logger->info( null, 'openai_test_conn_success', 'OpenAI API credentials validated successfully.' );
		return true;
	}

	/**
	 * Execute a chat completion request with JSON mode enabled.
	 *
	 * @param string $ticker Ticker context for logging
	 * @param string $action Action name for logging
	 * @param array  $messages Chat messages
	 * @return array|null Parsed JSON response array or null on failure
	 */
	public function execute_json_request( string $ticker, string $action, array $messages ): ?array {
		$api_key = $this->settings->get_openai_key();
		$model   = $this->settings->get_openai_model();

		if ( empty( $api_key ) ) {
			$this->logger->error( $ticker, $action . '_error', 'Missing OpenAI API Key. Please configure it in settings.' );
			return null;
		}

		$headers = [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		];

		$body = [
			'model'           => $model,
			'messages'        => $messages,
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => 0.2, // Lower temperature is better for factual consistency
		];

		$this->logger->info( $ticker, $action . '_request', sprintf( 'Sending request to OpenAI using model %s.', $model ) );

		$response = wp_remote_post(
			self::API_URL,
			[
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 90, // AI processing can take some time
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( $ticker, $action . '_failed', 'HTTP error requesting OpenAI: ' . $response->get_error_message() );
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$this->logger->error(
				$ticker,
				$action . '_failed',
				sprintf( 'OpenAI API request failed. HTTP code %d', $status_code ),
				[ 'response' => json_decode( $response_body, true ) ?: substr( $response_body, 0, 1000 ) ]
			);
			return null;
		}

		$result = json_decode( $response_body, true );
		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			$this->logger->error( $ticker, $action . '_failed', 'OpenAI response structure was unexpected.', [ 'response' => $result ] );
			return null;
		}

		$content = $result['choices'][0]['message']['content'];
		$parsed_json = json_decode( $content, true );

		if ( null === $parsed_json ) {
			$this->logger->error( $ticker, $action . '_failed', 'Failed to parse JSON content from OpenAI message response.', [ 'raw_content' => $content ] );
			return null;
		}

		return $parsed_json;
	}

	/**
	 * Rewrite a source article into an investor-focused news item.
	 *
	 * @param string $ticker Ticker symbol
	 * @param string $source_title Original article title
	 * @param string $source_content Original article content
	 * @return array|null Rewritten article array containing relevance, sentiment, summary, title, content, extracted_facts
	 */
	public function rewrite_article( string $ticker, string $source_title, string $source_content ): ?array {
		$template = $this->settings->get_all()['prompt_template'] ?? $this->settings->get_default_prompt_template();

		// Replace placeholders in prompt template.
		$prompt = str_replace(
			[ '{ticker}', '{source_title}', '{source_content}' ],
			[ $ticker, $source_title, $source_content ],
			$template
		);

		$messages = [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		return $this->execute_json_request( $ticker, 'ai_rewrite', $messages );
	}

	/**
	 * Verify factual consistency of the rewritten article against original facts.
	 *
	 * @param string $ticker Ticker symbol
	 * @param array  $original_facts Array of facts extracted during rewrite
	 * @param string $rewritten_title Rewritten title
	 * @param string $rewritten_content Rewritten markdown content
	 * @return array|null Array containing isValid (bool) and errors (array)
	 */
	public function verify_facts( string $ticker, array $original_facts, string $rewritten_title, string $rewritten_content ): ?array {
		$template = $this->settings->get_all()['verify_prompt'] ?? $this->settings->get_default_verification_prompt();

		$facts_string = implode( "\n- ", $original_facts );

		// Replace placeholders in prompt template.
		$prompt = str_replace(
			[ '{ticker}', '{original_facts}', '{rewritten_title}', '{rewritten_content}' ],
			[ $ticker, $facts_string, $rewritten_title, $rewritten_content ],
			$template
		);

		$messages = [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		return $this->execute_json_request( $ticker, 'ai_fact_check', $messages );
	}
}
