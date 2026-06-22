<?php
namespace CGM\FinancialNews\Core\Service;

use CGM\FinancialNews\Core\Settings;
use CGM\FinancialNews\Core\Repository\LogRepository;

/**
 * Service for translating financial articles using OpenAI.
 */
class TranslationService {

	private Settings $settings;
	private LogRepository $logger;
	private OpenAiService $openai;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, LogRepository $logger, OpenAiService $openai ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->openai   = $openai;
	}

	/**
	 * Translate a rewritten financial article.
	 *
	 * @param string $ticker Ticker symbol
	 * @param string $title Article title
	 * @param string $content Article markdown content
	 * @param string $target_lang Target language (e.g. 'de', 'es', 'fr')
	 * @return array|null Array with 'title' and 'content' translated, or null on failure
	 */
	public function translate( string $ticker, string $title, string $content, string $target_lang ): ?array {
		$lang_names = [
			'de' => 'German',
			'es' => 'Spanish',
			'fr' => 'French',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'ja' => 'Japanese',
			'zh' => 'Chinese',
		];

		$target_lang_name = $lang_names[ strtolower( $target_lang ) ] ?? $target_lang;

		$this->logger->info(
			$ticker,
			'ai_translate',
			sprintf( 'Translating article to target language: %s (%s).', $target_lang_name, $target_lang )
		);

		$prompt = "You are a professional financial translator.
Translate the following rewritten financial news title and content into {$target_lang_name}.

CRITICAL INSTRUCTIONS:
- You MUST preserve all specific financial terminology where standard (e.g., EBITDA, Capex, Operating Margins, Net Income).
- You MUST preserve all company names, ticker symbols, numbers, percentages, dates, and currency values exactly as they are.
- Maintain the original Markdown structure, spacing, headers, and bullet points.
- Return the output strictly as a JSON object with the following structure:
{
  \"title\": \"The translated news headline.\",
  \"content\": \"The full translated article content in clean Markdown.\"
}

Rewritten Title: {$title}
Rewritten Content:
{$content}";

		$messages = [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$result = $this->openai->execute_json_request( $ticker, 'ai_translate', $messages );

		if ( ! is_array( $result ) || empty( $result['title'] ) || empty( $result['content'] ) ) {
			$this->logger->error( $ticker, 'ai_translate_failed', 'Translation failed or returned invalid JSON format.' );
			return null;
		}

		$this->logger->info( $ticker, 'ai_translate_success', 'Article translated successfully.' );
		return $result;
	}
}
