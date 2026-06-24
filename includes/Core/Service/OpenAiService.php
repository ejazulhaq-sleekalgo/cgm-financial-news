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
	private const FALLBACK_MODEL = 'gpt-5.4-mini'; // Fallback model if the configured one is unavailable

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
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			$this->logger->error( null, 'openai_test_conn_fail', 'No OpenAI API key was provided for connection testing.' );
			return false;
		}

		$headers = [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'User-Agent'    => 'CGM-Financial-News/1.0',
		];

		$this->logger->info( null, 'openai_test_conn', 'Testing OpenAI API connectivity...' );

		$models_to_try = $this->get_models_to_try( $this->settings->get_openai_model() );
		foreach ( $models_to_try as $model ) {
			$body = [
				'model'      => $model,
				'messages'   => [
					[
						'role'    => 'user',
						'content' => 'Ping',
					],
				],
				'max_tokens' => 5,
			];

			$api_response = $this->post_to_openai( $headers, $body, 20 );
			$status_code  = $api_response['status_code'];
			$response_body = $api_response['body'];

			if ( $api_response['success'] ) {
				$this->logger->info( null, 'openai_test_conn_success', 'OpenAI API credentials validated successfully.' );
				return true;
			}

			if ( $this->should_retry_with_fallback( $status_code, $response_body, $model, $models_to_try ) ) {
				$this->logger->warning( null, 'openai_test_conn_retry', sprintf( 'Model %s was rejected by OpenAI, retrying with %s.', $model, self::FALLBACK_MODEL ) );
				continue;
			}

			$this->logger->error(
				null,
				'openai_test_conn_fail',
				sprintf( 'OpenAI API returned HTTP %d', $status_code ),
				[ 'response' => substr( $response_body, 0, 500 ) ]
			);
			return false;
		}

		return false;
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

		$models_to_try = $this->get_models_to_try( $model );
		foreach ( $models_to_try as $attempt_model ) {
			$body = [
				'model'           => $attempt_model,
				'messages'        => $messages,
				'response_format' => [ 'type' => 'json_object' ],
				'temperature'     => 0.2, // Lower temperature is better for factual consistency
			];

			$this->logger->info( $ticker, $action . '_request', sprintf( 'Sending request to OpenAI using model %s.', $attempt_model ) );

			$api_response = $this->post_to_openai( $headers, $body, 90 );
			$status_code  = $api_response['status_code'];
			$response_body = $api_response['body'];

			if ( $api_response['success'] ) {
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

			if ( $this->should_retry_with_fallback( $status_code, $response_body, $attempt_model, $models_to_try ) ) {
				$this->logger->warning( $ticker, $action . '_retry', sprintf( 'Model %s was rejected by OpenAI, retrying with %s.', $attempt_model, self::FALLBACK_MODEL ) );
				continue;
			}

			$this->logger->error(
				$ticker,
				$action . '_failed',
				sprintf( 'OpenAI API request failed. HTTP code %d', $status_code ),
				[ 'response' => json_decode( $response_body, true ) ?: substr( $response_body, 0, 1000 ) ]
			);
			return null;
		}

		return null;
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

	/**
	 * Send a request to the OpenAI API using WordPress HTTP first and cURL as a fallback.
	 *
	 * @param array $headers
	 * @param array $body
	 * @param int   $timeout
	 * @return array{success: bool, status_code: int, body: string, error_message: string}
	 */
	private function post_to_openai( array $headers, array $body, int $timeout ): array {
		$payload = wp_json_encode( $body );
		if ( ! is_string( $payload ) ) {
			$payload = '{"error":"failed_to_encode_request_body"}';
		}

		$wp_response = wp_remote_post(
			self::API_URL,
			[
				'headers'     => $headers,
				'body'        => $payload,
				'timeout'     => $timeout,
				'sslverify'   => true,
				'httpversion' => '1.1',
			]
		);

		if ( ! is_wp_error( $wp_response ) ) {
			$status_code = (int) wp_remote_retrieve_response_code( $wp_response );
			$body_text   = (string) wp_remote_retrieve_body( $wp_response );
			return [
				'success'      => 200 === $status_code,
				'status_code'  => $status_code,
				'body'         => $body_text,
				'error_message' => '',
			];
		}

		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( self::API_URL );
			if ( false !== $ch ) {
				$curl_headers = [];
				foreach ( $headers as $name => $value ) {
					$curl_headers[] = $name . ': ' . $value;
				}

				curl_setopt_array(
					$ch,
					[
						CURLOPT_POST           => true,
						CURLOPT_POSTFIELDS     => $payload,
						CURLOPT_HTTPHEADER     => $curl_headers,
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_TIMEOUT        => $timeout,
						CURLOPT_CONNECTTIMEOUT => 10,
						CURLOPT_SSL_VERIFYPEER => true,
						CURLOPT_SSL_VERIFYHOST => 2,
						CURLOPT_USERAGENT      => 'CGM-Financial-News/1.0',
					]
				);

				$curl_body = curl_exec( $ch );
				$curl_error = curl_error( $ch );
				$curl_status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				curl_close( $ch );

				if ( false !== $curl_body ) {
					return [
						'success'      => 200 === $curl_status,
						'status_code'  => $curl_status,
						'body'         => (string) $curl_body,
						'error_message' => $curl_error,
					];
				}
			}
		}

		return [
			'success'      => false,
			'status_code'  => 0,
			'body'         => '',
			'error_message' => $wp_response->get_error_message(),
		];
	}

	/**
	 * Resolve which models to try for a request.
	 *
	 * @param string $configured_model
	 * @return array
	 */
	private function get_models_to_try( string $configured_model ): array {
		$configured_model = trim( $configured_model );
		if ( '' === $configured_model ) {
			$configured_model = self::FALLBACK_MODEL;
		}

		$models = [ $configured_model ];
		if ( self::FALLBACK_MODEL !== $configured_model ) {
			$models[] = self::FALLBACK_MODEL;
		}

		return array_values( array_unique( $models ) );
	}

	/**
	 * Check whether a model-related error should trigger a fallback retry.
	 *
	 * @param int    $status_code
	 * @param string $response_body
	 * @param string $current_model
	 * @param array  $models_to_try
	 * @return bool
	 */
	private function should_retry_with_fallback( int $status_code, string $response_body, string $current_model, array $models_to_try ): bool {
		if ( ! in_array( $status_code, [ 400, 404, 422 ], true ) ) {
			return false;
		}

		if ( self::FALLBACK_MODEL === $current_model ) {
			return false;
		}

		$decoded = json_decode( $response_body, true );
		$message = '';
		if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
			$message = strtolower( (string) $decoded['error']['message'] );
		}

		if ( '' === $message ) {
			$message = strtolower( $response_body );
		}

		return str_contains( $message, 'model' )
			&& (
				str_contains( $message, 'not found' )
				|| str_contains( $message, 'does not exist' )
				|| str_contains( $message, 'unsupported' )
				|| str_contains( $message, 'not available' )
				|| str_contains( $message, 'invalid' )
			);
	}
}
