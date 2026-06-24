<?php
namespace CGM\FinancialNews\Core\Service;

use CGM\FinancialNews\Core\Settings;
use CGM\FinancialNews\Core\Repository\NewsRepository;
use CGM\FinancialNews\Core\Repository\LogRepository;

/**
 * Orchestrates the News Quality Workflow.
 */
class NewsProcessor {

	private Settings $settings;
	private NewsRepository $news_repo;
	private LogRepository $logger;
	private EodhdService $eodhd;
	private OpenAiService $openai;
	private TranslationService $translator;

	/**
	 * Constructor.
	 */
	public function __construct(
		Settings $settings,
		NewsRepository $news_repo,
		LogRepository $logger,
		EodhdService $eodhd,
		OpenAiService $openai,
		TranslationService $translator
	) {
		$this->settings   = $settings;
		$this->news_repo  = $news_repo;
		$this->logger     = $logger;
		$this->eodhd      = $eodhd;
		$this->openai     = $openai;
		$this->translator = $translator;
	}

	/**
	 * High-level cron worker: Fetches latest news for all active tickers
	 * and queues them for background processing.
	 */
	public function fetch_and_queue_news_for_all_tickers() {
		$tickers = $this->settings->get_tickers();
		$this->logger->info( null, 'cron_fetch_start', 'Starting background news fetch for all tickers.' );

		foreach ( $tickers as $ticker ) {
			if ( 'active' !== ( $ticker['status'] ?? 'active' ) ) {
				continue;
			}

			$symbol = $ticker['symbol'];
			$news_limit = $ticker['news_limit'] ?? 3;

			// 1. Check if today's limit is already reached.
			$today_published = $this->news_repo->get_today_published_count( $symbol );
			if ( $today_published >= $news_limit ) {
				$this->logger->info(
					$symbol,
					'cron_fetch_skip',
					sprintf( 'Skipping fetch. Daily publication limit (%d) already reached. Today: %d.', $news_limit, $today_published )
				);
				continue;
			}

			// 2. Fetch latest articles from EODHD.
			$news_items = $this->eodhd->fetch_news( $symbol, 10 ); // Fetch a pool of 10 to check for fresh content
			if ( empty( $news_items ) ) {
				continue;
			}

			$queued_count     = 0;
			$allowed_to_queue = $news_limit - $today_published;

			foreach ( $news_items as $item ) {
				if ( $queued_count >= $allowed_to_queue ) {
					break; // Don't queue more than the remaining daily news limit allows
				}

				// Extract article identifier
				$source_id = $item['id'] ?? $item['link'] ?? '';
				$url       = $item['link'] ?? '';
				$title     = $item['title'] ?? '';
				$content   = $item['content'] ?? '';

				if ( empty( $source_id ) || empty( $url ) ) {
					continue;
				}

				$content_hash = md5( $title . $content . $url );

				// 3. Prevent duplicate processing.
				if ( $this->news_repo->exists( $source_id, $url, $content_hash ) ) {
					continue;
				}

				// 4. Store in registry as pending.
				$registry_id = $this->news_repo->add([
					'ticker'         => $symbol,
					'source_id'      => $source_id,
					'source_url'     => $url,
					'source_title'   => $title,
					'source_content' => $content,
					'content_hash'   => $content_hash,
				]);

				if ( $registry_id ) {
					// 5. Enqueue background processing job via Action Scheduler.
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						as_enqueue_async_action(
							'cgm_process_news_item',
							[ 'registry_id' => $registry_id ],
							'cgm-news-group'
						);
						$queued_count++;
					} else {
						// Fallback if Action Scheduler is missing: process synchronously.
						$this->logger->warning( $symbol, 'scheduler_missing', 'as_enqueue_async_action is not available, processing synchronously.' );
						$this->process_news_item( $registry_id );
					}
				}
			}

			$this->logger->info(
				$symbol,
				'cron_fetch_complete',
				sprintf( 'Finished ticker fetch. Queued %d new articles for background processing.', $queued_count )
			);
		}
	}

	/**
	 * Processes a single news registry item.
	 * Runs: OpenAI Rewrite -> Fact Verification -> Translation -> Publishing.
	 *
	 * @param int $registry_id
	 * @return bool True if published successfully, false otherwise
	 */
	public function process_news_item( int $registry_id ): bool {
		$item = $this->news_repo->get( $registry_id );

		if ( ! $item ) {
			$this->logger->error( null, 'process_job_error', sprintf( 'Registry item ID %d not found.', $registry_id ) );
			return false;
		}

		if ( 'pending' !== $item['status'] ) {
			$this->logger->warning( $item['ticker'], 'process_job_skip', sprintf( 'Registry item ID %d already processed (Status: %s).', $registry_id, $item['status'] ) );
			return false;
		}

		$symbol = $item['ticker'];

		// Double-check limit before running heavy AI/Translation tasks.
		$news_limit = 3;
		$tickers = $this->settings->get_tickers();
		foreach ( $tickers as $t ) {
			if ( strcasecmp( $t['symbol'], $symbol ) === 0 ) {
				$news_limit = $t['news_limit'] ?? 3;
				break;
			}
		}

		$today_published = $this->news_repo->get_today_published_count( $symbol );
		if ( $today_published >= $news_limit ) {
			$err_msg = sprintf( 'Daily limit of %d articles reached for ticker %s. Post skipped.', $news_limit, $symbol );
			$this->news_repo->update_status( $registry_id, 'failed', null, $err_msg );
			$this->logger->warning( $symbol, 'process_job_limit_exceeded', $err_msg );
			return false;
		}

		// Mark status as processing (could introduce a custom status, but keeping simple).
		$this->logger->info( $symbol, 'process_job_start', sprintf( 'Starting AI Editorial Workflow for registry item ID %d.', $registry_id ) );

		// Step 1: AI Rewrite & Enrichment
		$rewritten = $this->openai->rewrite_article( $symbol, $item['source_title'], $item['source_content'] );
		if ( ! is_array( $rewritten ) || empty( $rewritten['title'] ) || empty( $rewritten['content'] ) ) {
			$err_msg = 'OpenAI rewrite API returned an empty or invalid response.';
			$this->news_repo->update_status( $registry_id, 'failed', null, $err_msg );
			return false;
		}

		// Step 2: Determine Relevance threshold
		$min_relevance = $this->settings->get_all()['min_relevance'] ?? 5;
		$article_relevance = intval( $rewritten['relevance'] ?? 0 );
		if ( $article_relevance < $min_relevance ) {
			$skip_msg = sprintf( 'Article skipped. Relevance score (%d) is below configured threshold (%d).', $article_relevance, $min_relevance );
			$this->news_repo->update_status( $registry_id, 'skipped_irrelevant' );
			$this->logger->info( $symbol, 'process_job_skipped', $skip_msg, [ 'ai_response' => $rewritten ] );
			return true;
		}

		// Step 3: Factual Verification Audit
		$original_facts = $rewritten['extracted_facts'] ?? [];
		$verification = $this->openai->verify_facts( $symbol, $original_facts, $rewritten['title'], $rewritten['content'] );
		if ( ! is_array( $verification ) || empty( $verification['isValid'] ) ) {
			$errors_found = $verification['errors'] ?? [ 'Failed factual check' ];
			$err_msg = 'Factual consistency audit failed: ' . implode( '; ', $errors_found );
			$this->news_repo->update_status( $registry_id, 'failed', null, $err_msg );
			$this->logger->warning( $symbol, 'fact_check_fail', $err_msg, [ 'verification' => $verification ] );
			return false;
		}

		$final_title   = $rewritten['title'];
		$final_content = $rewritten['content'];

		// Step 4: Optional Translation
		$translate_enabled = $this->settings->get_all()['translation_enabled'] ?? false;
		$target_lang       = $this->settings->get_all()['translation_lang'] ?? 'de';

		if ( $translate_enabled && ! empty( $target_lang ) ) {
			$translated = $this->translator->translate( $symbol, $final_title, $final_content, $target_lang );
			if ( is_array( $translated ) && ! empty( $translated['title'] ) && ! empty( $translated['content'] ) ) {
				$final_title   = $translated['title'];
				$final_content = $translated['content'];
			} else {
				$err_msg = sprintf( 'Translation to language "%s" failed. Aborting publishing.', $target_lang );
				$this->news_repo->update_status( $registry_id, 'failed', null, $err_msg );
				return false;
			}
		}

		// Step 5: Convert content to WordPress Block Editor format.
		$final_content = $this->convert_to_wp_block_editor( $final_content );

		// Step 6: Publish Article to WordPress CPT
		$publishing_status = $this->settings->get_all()['publishing_status'] ?? 'publish';

		$post_data = [
			'post_title'   => sanitize_text_field( html_entity_decode( $final_title, ENT_QUOTES, 'UTF-8' ) ),
			'post_content' => wp_kses_post( $final_content ),
			'post_status'  => $publishing_status,
			'post_type'    => 'cgm_news',
			'post_author'  => 1, // Default to admin user
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			$err_msg = 'Failed to insert WP Post: ' . $post_id->get_error_message();
			$this->news_repo->update_status( $registry_id, 'failed', null, $err_msg );
			return false;
		}

		// 7. Associate with Ticker Custom Taxonomy.
		wp_set_object_terms( $post_id, $symbol, 'cgm_ticker', false );

		// 8. Store meta details for rendering/auditing.
		update_post_meta( $post_id, '_cgm_original_id', sanitize_text_field( $item['source_id'] ) );
		update_post_meta( $post_id, '_cgm_original_url', esc_url_raw( $item['source_url'] ) );
		update_post_meta( $post_id, '_cgm_source_hash', sanitize_text_field( $item['content_hash'] ) );
		update_post_meta( $post_id, '_cgm_importance', intval( $rewritten['relevance'] ?? 0 ) );
		update_post_meta( $post_id, '_cgm_sentiment', sanitize_text_field( $rewritten['sentiment'] ?? 'Neutral' ) );
		update_post_meta( $post_id, '_cgm_original_title', sanitize_text_field( $item['source_title'] ) );
		update_post_meta( $post_id, '_cgm_original_content', wp_kses_post( $item['source_content'] ) );

		// 9. Update registry status.
		$this->news_repo->update_status( $registry_id, 'processed', $post_id );

		$this->logger->info(
			$symbol,
			'process_job_success',
			sprintf( 'Successfully published article "%s" (Post ID %d, Status: %s).', $final_title, $post_id, $publishing_status )
		);

		return true;
	}

	/**
	 * Convert Markdown or plain HTML content to WordPress Block Editor format.
	 * Handles: headings, paragraphs, lists, bold, italic, links, inline code, hr.
	 *
	 * @param string $content Raw content (Markdown or HTML)
	 * @return string Content wrapped in WordPress block comments
	 */
	private function convert_to_wp_block_editor( string $content ): string {
		$content = trim( $content );

		if ( empty( $content ) ) {
			return $content;
		}

		// Already in block editor format — return as-is.
		if ( str_contains( $content, '<!-- wp:' ) ) {
			return $content;
		}

		// If it contains HTML block-level tags, just wrap them in blocks.
		if ( preg_match( '/<(p|h[1-6]|ul|ol|li|blockquote|pre|hr|table)[\s>]/i', $content ) ) {
			return $this->wrap_html_in_blocks( $content );
		}

		// Otherwise treat as Markdown and convert.
		return $this->markdown_to_wp_blocks( $content );
	}

	/**
	 * Wrap raw HTML block elements in WordPress block comments.
	 */
	private function wrap_html_in_blocks( string $html ): string {
		$blocks = [];

		// Normalise line endings, then split on block-level tags (keep the tags).
		$html  = preg_replace( '/\r\n?/', "\n", $html );
		$parts = preg_split(
			'/(<(?:p|h[1-6]|ul|ol|li|blockquote|pre|hr|table)[^>]*>)/i',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);

		$buffer = '';
		$in_tag = false;

		foreach ( $parts as $part ) {
			if ( preg_match( '/^<(p|h[1-6]|ul|ol|blockquote|pre)(\s[^>]*)?>$/i', $part, $m ) ) {
				if ( $buffer !== '' ) {
					$blocks[] = '<!-- wp:paragraph --><p>' . trim( $buffer ) . '</p><!-- /wp:paragraph -->';
					$buffer   = '';
				}
				$blocks[] = '<!-- wp:' . strtolower( $m[1] ) . ' -->';
				$blocks[] = $part;
				$in_tag   = strtolower( $m[1] );
			} elseif ( $in_tag && preg_match( '/^<\/(p|h[1-6]|ul|ol|blockquote|pre)>$/i', $part, $m ) && strtolower( $m[1] ) === $in_tag ) {
				$blocks[] = $part;
				$blocks[] = '<!-- /wp:' . $in_tag . ' -->';
				$in_tag   = false;
			} elseif ( preg_match( '/^<(hr|li)(\s[^>]*)?(\/?)>$/i', $part, $m ) ) {
				$tag = strtolower( $m[1] );
				if ( $tag === 'hr' ) {
					$blocks[] = '<!-- wp:separator --><hr class="wp-block-separator" /><!-- /wp:separator -->';
				} elseif ( $tag === 'li' ) {
					$blocks[] = $part; // Handled inside ul/ol blocks
				}
			} else {
				$buffer .= $part;
			}
		}

		if ( $buffer !== '' ) {
			$blocks[] = '<!-- wp:paragraph --><p>' . trim( $buffer ) . '</p><!-- /wp:paragraph -->';
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Convert Markdown text to WordPress block editor format.
	 */
	private function markdown_to_wp_blocks( string $md ): string {
		$lines  = preg_split( '/\r\n|\r|\n/', $md );
		$blocks = [];
		$i      = 0;
		$count  = count( $lines );
		$buffer = ''; // For collecting paragraph text

		$flush_paragraph = function () use ( &$buffer, &$blocks ) {
			if ( $buffer !== '' ) {
				$text     = $this->inline_markdown_to_html( trim( $buffer ) );
				$blocks[] = '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
				$buffer   = '';
			}
		};

		while ( $i < $count ) {
			$line    = $lines[ $i ];
			$trimmed = trim( $line );

			// Empty line → paragraph boundary.
			if ( $trimmed === '' ) {
				$flush_paragraph();
				$i++;
				continue;
			}

			// Thematic break: ---, ***, ___
			if ( preg_match( '/^(?:[-*_]\s*){3,}$/', $trimmed ) ) {
				$flush_paragraph();
				$blocks[] = '<!-- wp:separator --><hr class="wp-block-separator" /><!-- /wp:separator -->';
				$i++;
				continue;
			}

			// Heading: ## text
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trimmed, $m ) ) {
				$flush_paragraph();
				$level = strlen( $m[1] );
				$text  = $this->inline_markdown_to_html( $m[2] );
				$blocks[] = '<!-- wp:heading {"level":' . $level . '} --><h' . $level . '>' . $text . '</h' . $level . '><!-- /wp:heading -->';
				$i++;
				continue;
			}

			// Unordered list: - item or * item
			if ( preg_match( '/^[\*\-+]\s+(.+)$/', $trimmed, $m ) ) {
				$flush_paragraph();
				$items = [];
				while ( $i < $count ) {
					$tl = trim( $lines[ $i ] );
					if ( preg_match( '/^[\*\-+]\s+(.+)$/', $tl, $im ) ) {
						$items[] = '<li>' . $this->inline_markdown_to_html( $im[1] ) . '</li>';
						$i++;
					} elseif ( $tl === '' ) {
						$i++;
						break;
					} else {
						break;
					}
				}
				if ( $items ) {
					$blocks[] = '<!-- wp:list --><ul>' . implode( '', $items ) . '</ul><!-- /wp:list -->';
				}
				continue;
			}

			// Ordered list: 1. item
			if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $m ) ) {
				$flush_paragraph();
				$items = [];
				while ( $i < $count ) {
					$tl = trim( $lines[ $i ] );
					if ( preg_match( '/^\d+\.\s+(.+)$/', $tl, $im ) ) {
						$items[] = '<li>' . $this->inline_markdown_to_html( $im[1] ) . '</li>';
						$i++;
					} elseif ( $tl === '' ) {
						$i++;
						break;
					} else {
						break;
					}
				}
				if ( $items ) {
					$blocks[] = '<!-- wp:list {"ordered":true} --><ol>' . implode( '', $items ) . '</ol><!-- /wp:list -->';
				}
				continue;
			}

			// Regular paragraph line — accumulate into buffer.
			$buffer .= ( $buffer !== '' ? ' ' : '' ) . $line;
			$i++;
		}

		$flush_paragraph();

		return implode( "\n", $blocks );
	}

	/**
	 * Convert inline Markdown formatting to HTML.
	 */
	private function inline_markdown_to_html( string $text ): string {
		// Bold: **text** or __text__
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/', '<strong>$1</strong>', $text );

		// Italic: *text* or _text_ (but not inside words like some_text_here)
		$text = preg_replace( '/(?<!\w)\*(?!\s)(.+?)(?<!\s)\*(?!\w)/', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/', '<em>$1</em>', $text );

		// Inline code: `code`
		$text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );

		// Links: [text](url)
		$text = preg_replace( '/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text );

		return $text;
	}
}
