<?php
namespace CGM\FinancialNews\Core;

use CGM\FinancialNews\Core\Repository\TickerRepository;

/**
 * Settings and Option Management service.
 */
class Settings {

	private const OPTION_NAME = 'cgm_financial_news_settings';

	private ?TickerRepository $ticker_repo = null;

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private ?array $settings = null;

	/**
	 * Constructor.
	 *
	 * @param TickerRepository|null $ticker_repo
	 */
	public function __construct( ?TickerRepository $ticker_repo = null ) {
		$this->ticker_repo = $ticker_repo;
	}

	/**
	 * Get Options name for settings storage.
	 */
	public static function get_option_name(): string {
		return self::OPTION_NAME;
	}

	/**
	 * Get all plugin settings, with defaults merged.
	 *
	 * @return array
	 */
	public function get_all(): array {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$saved = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$defaults = $this->get_defaults();
		$this->settings = array_replace_recursive( $defaults, $saved );

		if ( ! isset( $saved['prompt_template'] ) || empty( $saved['prompt_template'] ) || $saved['prompt_template'] === $this->get_legacy_prompt_template() ) {
			$this->settings['prompt_template'] = $this->get_default_prompt_template();
		}

		if ( ! isset( $saved['verify_prompt'] ) || empty( $saved['verify_prompt'] ) || $saved['verify_prompt'] === $this->get_legacy_verification_prompt() ) {
			$this->settings['verify_prompt'] = $this->get_default_verification_prompt();
		}

		return $this->settings;
	}

	/**
	 * Get default settings values.
	 *
	 * @return array
	 */
	public function get_defaults(): array {
		return [
			'eodhd_api_key'       => '',
			'openai_api_key'      => '',
			'openai_model'        => 'gpt-5.4-mini', // Default model for rewriting
			'publishing_status'   => 'publish', // 'publish' or 'draft'
			'min_relevance'       => 5, // Scale 1-10
			'translation_enabled' => false,
			'translation_lang'    => 'de', // e.g. German for AW
			'prompt_template'     => $this->get_default_prompt_template(),
			'verify_prompt'       => $this->get_default_verification_prompt(),
		];
	}

	/**
	 * Save settings values.
	 *
	 * @param array $new_settings
	 * @return bool
	 */
	public function save( array $new_settings ): bool {
		$defaults = $this->get_defaults();
		// Sanitize and filter input settings to prevent unauthorized changes or bad format.
		$sanitized = [];

		$sanitized['eodhd_api_key']  = sanitize_text_field( $new_settings['eodhd_api_key'] ?? '' );
		$sanitized['openai_api_key'] = sanitize_text_field( $new_settings['openai_api_key'] ?? '' );
		$sanitized['openai_model']   = sanitize_text_field( $new_settings['openai_model'] ?? 'gpt-5.4-mini' );
		$sanitized['publishing_status'] = in_array( $new_settings['publishing_status'] ?? '', [ 'publish', 'draft' ], true ) ? $new_settings['publishing_status'] : 'publish';
		$sanitized['min_relevance']  = max( 1, min( 10, intval( $new_settings['min_relevance'] ?? 5 ) ) );
		$sanitized['translation_enabled'] = ! empty( $new_settings['translation_enabled'] );
		$sanitized['translation_lang']    = sanitize_text_field( $new_settings['translation_lang'] ?? 'de' );
		
		if ( isset( $new_settings['prompt_template'] ) ) {
			$sanitized['prompt_template'] = wp_kses_post( $new_settings['prompt_template'] );
		} else {
			$sanitized['prompt_template'] = $defaults['prompt_template'];
		}

		if ( isset( $new_settings['verify_prompt'] ) ) {
			$sanitized['verify_prompt'] = wp_kses_post( $new_settings['verify_prompt'] );
		} else {
			$sanitized['verify_prompt'] = $defaults['verify_prompt'];
		}

		$this->settings = $sanitized;
		return update_option( self::OPTION_NAME, $sanitized );
	}

	/**
	 * Helper methods to get specific keys.
	 */
	public function get_eodhd_key(): string {
		$settings = $this->get_all();
		return $settings['eodhd_api_key'];
	}

	public function get_openai_key(): string {
		$settings = $this->get_all();
		return $settings['openai_api_key'];
	}

	public function get_openai_model(): string {
		$settings = $this->get_all();
		return $settings['openai_model'] ?: 'gpt-5.4-mini';
	}

	public function get_tickers(): array {
		if ( $this->ticker_repo ) {
			return $this->ticker_repo->get_all();
		}
		$settings = $this->get_all();
		return $settings['tickers'] ?? [];
	}

	/**
	 * Set the ticker repository (used after construction).
	 *
	 * @param TickerRepository $ticker_repo
	 */
	public function set_ticker_repo( TickerRepository $ticker_repo ): void {
		$this->ticker_repo = $ticker_repo;
	}

	/**
	 * Default prompt template for OpenAI rewriting.
	 *
	 * @return string
	 */
	public function get_default_prompt_template(): string {
		return "You are an expert financial journalist and investment analyst.
Translate the following source news article into a completely original, investor-focused publication for traders, analysts, and investors.

Do NOT simply paraphrase. Instead, follow this structured editorial workflow:
1. Extract the core financial facts, figures, percentage changes, dates, company names, and ticker symbols.
2. Analyze the significance and relevance of the news for shareholders and the stock market.
3. Determine the market sentiment (Positive, Negative, or Neutral).
4. Write a compelling, professional article using the extracted facts, ensuring an objective, professional, and analytical tone.

CRITICAL INSTRUCTIONS:
- You MUST preserve all specific financial numbers, percentages, dates, ticker symbols, and company names exactly as they are. Never hallucinate or alter figures.
- Do not mention that this is an AI-generated or rewritten article. It should read like an original article written by a professional Wall Street journalist.
- Do not copy sentences, clauses, or phrases from the source article. The rewritten article must be genuinely new and original.
- Do not repeat the same sentence, paragraph, or idea more than once. Avoid filler and repetitive phrasing.
- Keep the article concise and readable, usually 3-5 short paragraphs with a clear opening, development, and conclusion.
- Return the output strictly as a JSON object with the following structure:
{
  \"relevance\": 7, // Score from 1 to 10 evaluating how important this news is for the stock ticker
  \"sentiment\": \"Positive\", // Positive, Negative, or Neutral
  \"summary\": \"A short 1-2 sentence executive summary.\",
  \"title\": \"A strong, professional, rewritten news headline.\",
  \"content\": \"The full rewritten article content in WordPress block editor HTML format. Use standard HTML tags like <p>, <h2>, <h3>, <ul>, <li>, <strong>, <em>. Do NOT use Markdown syntax. Output clean HTML only.\",
  \"extracted_facts\": [\"Fact 1\", \"Fact 2\"] // List of core financial figures or facts preserved
}

Source Ticker: {ticker}
Source Title: {source_title}
Source Content:
{source_content}";
	}

	/**
	 * Default verification prompt template.
	 *
	 * @return string
	 */
	public function get_default_verification_prompt(): string {
		return "You are a professional financial fact-checker. 
Compare the original facts extracted from the source news with the rewritten article content to ensure complete factual consistency.

Original Ticker: {ticker}
Original Facts:
{original_facts}

Rewritten Title: {rewritten_title}
Rewritten Content:
{rewritten_content}

CRITICAL INSTRUCTIONS:
- Check for any numeric discrepancies, changed dates, altered company names, or hallucinated details not supported by the original facts.
- Reject rewritten content that repeats the same sentence, paragraph, or idea multiple times.
- Answer strictly in JSON format:
{
  \"isValid\": true, // Set to false if there are any factual discrepancies or altered numbers
  \"errors\": [] // List of specific errors found, or empty array if none
}";
	}

	/**
	 * Legacy prompt template used by older installations.
	 *
	 * @return string
	 */
	private function get_legacy_prompt_template(): string {
		return "You are an expert financial journalist and investment analyst.
Translate the following source news article into a completely original, investor-focused publication for traders, analysts, and investors.

Do NOT simply paraphrase. Instead, follow this structured editorial workflow:
1. Extract the core financial facts, figures, percentage changes, dates, company names, and ticker symbols.
2. Analyze the significance and relevance of the news for shareholders and the stock market.
3. Determine the market sentiment (Positive, Negative, or Neutral).
4. Write a compelling, professional article using the extracted facts, ensuring an objective, professional, and analytical tone.

CRITICAL INSTRUCTIONS:
- You MUST preserve all specific financial numbers, percentages, dates, ticker symbols, and company names exactly as they are. Never hallucinate or alter figures.
- Do not mention that this is an AI-generated or rewritten article. It should read like an original article written by a professional Wall Street journalist.
- Return the output strictly as a JSON object with the following structure:
{
  \"relevance\": 7, // Score from 1 to 10 evaluating how important this news is for the stock ticker
  \"sentiment\": \"Positive\", // Positive, Negative, or Neutral
  \"summary\": \"A short 1-2 sentence executive summary.\",
  \"title\": \"A strong, professional, rewritten news headline.\",
  \"content\": \"The full rewritten article content in WordPress block editor HTML format. Use standard HTML tags like <p>, <h2>, <h3>, <ul>, <li>, <strong>, <em>. Do NOT use Markdown syntax. Output clean HTML only.\",
  \"extracted_facts\": [\"Fact 1\", \"Fact 2\"] // List of core financial figures or facts preserved
}

Source Ticker: {ticker}
Source Title: {source_title}
Source Content:
{source_content}";
	}

	/**
	 * Legacy verification prompt used by older installations.
	 *
	 * @return string
	 */
	private function get_legacy_verification_prompt(): string {
		return "You are a professional financial fact-checker. 
Compare the original facts extracted from the source news with the rewritten article content to ensure complete factual consistency.

Original Ticker: {ticker}
Original Facts:
{original_facts}

Rewritten Title: {rewritten_title}
Rewritten Content:
{rewritten_content}

CRITICAL INSTRUCTIONS:
- Check for any numeric discrepancies, changed dates, altered company names, or hallucinated details not supported by the original facts.
- Answer strictly in JSON format:
{
  \"isValid\": true, // Set to false if there are any factual discrepancies or altered numbers
  \"errors\": [] // List of specific errors found, or empty array if none
}";
	}
}
