<?php
namespace CGM\FinancialNews\Core;

/**
 * Handles frontend shortcodes, widgets, and ticker auto-detection.
 */
class Frontend {

	private Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register actions and shortcodes.
	 */
	public function hooks() {
		// Register [cgm_news] shortcode
		add_shortcode( 'cgm_news', [ $this, 'render_news_shortcode' ] );

		// Enqueue frontend scripts & styles
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue stylesheet.
	 */
	public function enqueue_assets() {
		wp_register_style(
			'cgm-news-frontend-css',
			plugins_url( 'assets/css/frontend.css', CGM_FN_FILE ),
			[],
			CGM_FN_VERSION
		);
		wp_enqueue_style( 'cgm-news-frontend-css' );
	}

	/**
	 * Auto-detect ticker symbol based on the current page context.
	 *
	 * @return string|null Detected ticker symbol (e.g. 'DSEAF') or null.
	 */
	public function auto_detect_ticker(): ?string {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_post();
		if ( ! $post ) {
			return null;
		}

		// 1. Check if the post itself is associated with a cgm_ticker taxonomy.
		$terms = wp_get_object_terms( $post->ID, 'cgm_ticker' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			return strtoupper( $terms[0]->name );
		}

		// 2. Check common custom fields.
		$meta_keys = [ 'ticker', 'stock_ticker', 'symbol', '_stock_ticker', '_ticker' ];
		foreach ( $meta_keys as $key ) {
			$meta_val = get_post_meta( $post->ID, $key, true );
			if ( ! empty( $meta_val ) ) {
				return strtoupper( trim( $meta_val ) );
			}
		}

		// 3. Scan title and content for configured tickers and their aliases.
		$tickers = $this->settings->get_tickers();
		$title   = $post->post_title;
		$content = $post->post_content;

		foreach ( $tickers as $ticker ) {
			$symbol = strtoupper( $ticker['symbol'] );
			$alias  = strtoupper( $ticker['alias'] ?? $symbol );

			// Check symbol match in title or content (surrounded by word boundaries).
			$pattern_sym = '/\b' . preg_quote( $symbol, '/' ) . '\b/i';
			$pattern_ali = '/\b' . preg_quote( $alias, '/' ) . '\b/i';

			if ( preg_match( $pattern_sym, $title ) || preg_match( $pattern_ali, $title ) ) {
				return $symbol;
			}

			if ( preg_match( $pattern_sym, $content ) || preg_match( $pattern_ali, $content ) ) {
				return $symbol;
			}
		}

		return null;
	}

	/**
	 * Shortcode callback: [cgm_news ticker="DSEAF" limit="5" layout="list"]
	 *
	 * @param array $atts
	 * @return string Renders HTML output.
	 */
	public function render_news_shortcode( $atts ): string {
		$args = shortcode_atts(
			[
				'ticker' => '',
				'limit'  => 5,
				'layout' => 'grid', // 'list' or 'grid'
			],
			$atts,
			'cgm_news'
		);

		$ticker = strtoupper( trim( $args['ticker'] ) );
		$limit  = intval( $args['limit'] );
		$layout = sanitize_key( $args['layout'] );

		// Run auto-detection if ticker attribute is omitted or blank.
		if ( empty( $ticker ) ) {
			$detected = $this->auto_detect_ticker();
			if ( $detected ) {
				$ticker = $detected;
			} else {
				// Try to map alias if ticker isn't configured directly.
				// E.g., if page slug matches a ticker alias.
				$slug = strtoupper( get_post_field( 'post_name', get_post() ) );
				$tickers = $this->settings->get_tickers();
				foreach ( $tickers as $t ) {
					if ( strtoupper( $t['alias'] ) === $slug || strtoupper( $t['symbol'] ) === $slug ) {
						$ticker = $t['symbol'];
						break;
					}
				}
			}
		}

		// Resolve alias to primary ticker symbol if necessary.
		// E.g. if the user asked for SEAS, map it to DSEAF.
		if ( ! empty( $ticker ) ) {
			$tickers = $this->settings->get_tickers();
			foreach ( $tickers as $t ) {
				if ( strtoupper( $t['alias'] ) === $ticker ) {
					$ticker = $t['symbol'];
					break;
				}
			}
		}

		// If still empty, display error or placeholder.
		if ( empty( $ticker ) ) {
			return sprintf(
				'<div class="cgm-news-empty">%s</div>',
				esc_html__( 'No stock ticker specified or auto-detected.', 'cgm-financial-news' )
			);
		}

		// Query CPT matching the ticker taxonomy
		$query_args = [
			'post_type'      => 'cgm_news',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'tax_query'      => [
				[
					'taxonomy' => 'cgm_ticker',
					'field'    => 'name',
					'terms'    => $ticker,
				],
			],
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$query = new \WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			return sprintf(
				'<div class="cgm-news-empty">%s %s</div>',
				esc_html__( 'No news available for ticker:', 'cgm-financial-news' ),
				esc_html( $ticker )
			);
		}

		ob_start();
		?>
		<div class="cgm-news-container cgm-layout-<?php echo esc_attr( $layout ); ?>">
			<h3 class="cgm-news-ticker-header">
				<?php
				printf(
					/* translators: %s: Ticker Symbol */
					esc_html__( 'Latest News: %s', 'cgm-financial-news' ),
					esc_html( $ticker )
				);
				?>
			</h3>
			<div class="cgm-news-wrapper">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					$post_id   = get_the_ID();
					$sentiment = get_post_meta( $post_id, '_cgm_sentiment', true ) ?: 'Neutral';
					$sentiment_class = 'cgm-sentiment-' . strtolower( $sentiment );
					$importance = get_post_meta( $post_id, '_cgm_importance', true ) ?: 0;
					$orig_url  = get_post_meta( $post_id, '_cgm_original_url', true );
					?>
					<article class="cgm-news-card">
						<div class="cgm-news-card-meta">
							<span class="cgm-news-date"><?php echo esc_html( get_the_date() ); ?></span>
							<span class="cgm-news-badge <?php echo esc_attr( $sentiment_class ); ?>">
								<?php echo esc_html( $sentiment ); ?>
							</span>
							<?php if ( $importance > 0 ) : ?>
								<span class="cgm-news-importance" title="<?php esc_attr_e( 'Importance Score', 'cgm-financial-news' ); ?>">
									🔥 <?php echo esc_html( $importance ); ?>/10
								</span>
							<?php endif; ?>
						</div>

						<h4 class="cgm-news-title">
							<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						</h4>

						<div class="cgm-news-excerpt">
							<?php
							if ( has_excerpt() ) {
								the_excerpt();
							} else {
								echo esc_html( wp_trim_words( get_the_excerpt(), 25 ) );
							}
							?>
						</div>

						<div class="cgm-news-card-footer">
							<a href="<?php the_permalink(); ?>" class="cgm-read-more">
								<?php esc_html_e( 'Read Article', 'cgm-financial-news' ); ?> &rarr;
							</a>
							<?php if ( ! empty( $orig_url ) ) : ?>
								<a href="<?php echo esc_url( $orig_url ); ?>" target="_blank" rel="noopener noreferrer" class="cgm-source-link">
									<?php esc_html_e( 'Original Source', 'cgm-financial-news' ); ?> &#8599;
								</a>
							<?php endif; ?>
						</div>
					</article>
					<?php
				}
				wp_reset_postdata();
				?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
