<?php
/**
 * Whop Plan Explorer — Admin tool to list all products/plans and their cashier URLs.
 * Access: /wp-admin/admin.php?page=whop-plan-explorer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Whop_Plan_Explorer {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'woocommerce',
			'Whop Plans Explorer',
			'Whop Plans Explorer',
			'manage_woocommerce',
			'whop-plan-explorer',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Fetch all pages from a Whop v1 list endpoint.
	 * Tries numeric page/per first, then page_info cursor-based if available.
	 *
	 * @param string $base_endpoint  e.g. '/plans' or '/access_passes'
	 * @param array  $extra_params   Additional query params (e.g. company_id)
	 * @param int    $per_page       Items per page (max Whop allows)
	 * @return array  Flat array of all items
	 */
	private static function fetch_all( $base_endpoint, $extra_params = array(), $per_page = 50 ) {
		$all_items = array();

		// Build base query string from extra params
		$qs_extra = '';
		foreach ( $extra_params as $k => $v ) {
			$qs_extra .= '&' . urlencode( $k ) . '=' . urlencode( $v );
		}

		$page       = 1;
		$after      = null; // cursor for beta-style pagination
		$max_pages  = 30;   // safety cap

		do {
			// Build endpoint — try cursor first if we have one, else numeric page
			if ( $after ) {
				$endpoint = $base_endpoint . '?per=' . $per_page . '&after=' . urlencode( $after ) . $qs_extra;
			} else {
				$endpoint = $base_endpoint . '?per=' . $per_page . '&page=' . $page . $qs_extra;
			}

			$response = Whop_API::request( $endpoint, 'GET' );

			if ( is_wp_error( $response ) ) {
				return array( 'error' => $response->get_error_message(), 'items' => $all_items );
			}

			$batch = isset( $response['data'] ) ? $response['data'] : array();

			if ( ! empty( $batch ) && is_array( $batch ) ) {
				$all_items = array_merge( $all_items, $batch );
			}

			$fetched = count( $batch );

			// Check for cursor-based pagination (beta API)
			$has_next  = isset( $response['page_info']['has_next_page'] ) ? (bool) $response['page_info']['has_next_page'] : false;
			$after     = $has_next && isset( $response['page_info']['end_cursor'] ) ? $response['page_info']['end_cursor'] : null;

			// If cursor-based has_next is explicitly false, we're done
			if ( $has_next === false && ! empty( $response ) && array_key_exists( 'page_info', $response ) ) {
				break;
			}

			// Numeric fallback: stop when we get fewer items than requested
			if ( ! $after && $fetched < $per_page ) {
				break;
			}

			$page++;

		} while ( $page <= $max_pages || $after );

		return array( 'error' => null, 'items' => $all_items );
	}

	public static function render_page() {
		$settings   = get_option( 'woocommerce_whop_settings', array() );
		$company_id = isset( $settings['company_id'] ) ? trim( $settings['company_id'] ) : '';
		$api_key    = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

		// Allow ?debug=1 to dump raw API response for diagnosis
		$debug = isset( $_GET['debug'] ) && '1' === $_GET['debug'];

		echo '<div class="wrap">';
		echo '<h1>Whop Plans &amp; Direct Checkout Links</h1>';
		echo '<p style="color:#666;">Debug raw response: <a href="' . esc_url( add_query_arg( 'debug', '1' ) ) . '">?debug=1</a></p>';

		if ( empty( $api_key ) ) {
			echo '<div class="notice notice-error"><p>Please configure your Whop API Key in <strong>WooCommerce &rarr; Settings &rarr; Payments &rarr; Whop</strong> first.</p></div></div>';
			return;
		}

		// ------------------------------------------------------------------
		// Strategy: Fetch access_passes (products) — Whop v1 uses this term.
		// Each access pass has plans nested inside it. Fall back to /plans if needed.
		// ------------------------------------------------------------------
		$extra = array();
		if ( ! empty( $company_id ) ) {
			$extra['company_id'] = $company_id;
		}

		// Try /access_passes first (v1 product endpoint)
		$result = self::fetch_all( '/access_passes', $extra );

		if ( $debug ) {
			echo '<h3>Raw /access_passes response (first page only):</h3>';
			$raw_ep = '/access_passes?per=2' . ( $company_id ? '&company_id=' . urlencode( $company_id ) : '' );
			$raw    = Whop_API::request( $raw_ep, 'GET' );
			echo '<pre style="background:#222;color:#0f0;padding:15px;overflow:auto;max-height:400px;">' . esc_html( wp_json_encode( $raw, JSON_PRETTY_PRINT ) ) . '</pre>';

			echo '<h3>Raw /plans response (first page only):</h3>';
			$raw_ep2 = '/plans?per=2' . ( $company_id ? '&company_id=' . urlencode( $company_id ) . '&account_id=' . urlencode( $company_id ) : '' );
			$raw2    = Whop_API::request( $raw_ep2, 'GET' );
			echo '<pre style="background:#222;color:#0f0;padding:15px;overflow:auto;max-height:400px;">' . esc_html( wp_json_encode( $raw2, JSON_PRETTY_PRINT ) ) . '</pre>';
		}

		if ( ! empty( $result['error'] ) ) {
			echo '<div class="notice notice-warning"><p>/access_passes error: ' . esc_html( $result['error'] ) . '. Trying /plans directly...</p></div>';
			// Fallback: try /plans endpoint directly with account_id
			$plan_extra = $extra;
			$plan_extra['account_id'] = $company_id;
			$result2 = self::fetch_all( '/plans', $plan_extra );

			if ( ! empty( $result2['error'] ) ) {
				echo '<div class="notice notice-error"><p>Both endpoints failed. /plans error: ' . esc_html( $result2['error'] ) . '</p></div></div>';
				return;
			}

			// Render plans directly (no product grouping)
			self::render_flat_plans( $result2['items'] );
			echo '</div>';
			return;
		}

		$access_passes = $result['items'];

		if ( empty( $access_passes ) ) {
			echo '<p>No access passes/products found. <a href="' . esc_url( add_query_arg( 'debug', '1' ) ) . '">Enable debug mode</a> to see raw API response.</p></div>';
			return;
		}

		// ------------------------------------------------------------------
		// Build rows: each access pass may have plans[] embedded,
		// or we fall back to fetching /plans?access_pass_id=...
		// ------------------------------------------------------------------
		$rows = array();

		foreach ( $access_passes as $ap ) {
			$ap_id   = isset( $ap['id'] ) ? $ap['id'] : '';

			// Try every possible name field Whop v1 might use
			$ap_name = '';
			foreach ( array( 'name', 'title', 'internal_name', 'label' ) as $f ) {
				if ( ! empty( $ap[ $f ] ) ) {
					$ap_name = $ap[ $f ];
					break;
				}
			}
			if ( empty( $ap_name ) ) {
				$ap_name = $ap_id ? 'ID: ' . $ap_id : 'Unknown';
			}

			// Plans may be embedded
			$plans = array();
			foreach ( array( 'plans', 'access_pass_plans', 'pricing_plans' ) as $pf ) {
				if ( ! empty( $ap[ $pf ] ) && is_array( $ap[ $pf ] ) ) {
					$plans = $ap[ $pf ];
					break;
				}
			}

			// If not embedded, fetch from /plans with access_pass_id filter
			if ( empty( $plans ) && ! empty( $ap_id ) ) {
				$plan_extra                  = $extra;
				$plan_extra['account_id']    = $company_id;
				$plan_extra['access_pass_id'] = $ap_id;
				$plan_result = self::fetch_all( '/plans', $plan_extra );
				$plans       = $plan_result['items'];
			}

			foreach ( $plans as $plan ) {
				$plan_id = isset( $plan['id'] ) ? $plan['id'] : '';
				if ( empty( $plan_id ) ) {
					continue;
				}

				$price = isset( $plan['renewal_price'] )
					? (float) $plan['renewal_price']
					: ( isset( $plan['initial_price'] ) ? (float) $plan['initial_price'] : 0 );

				$period_days = isset( $plan['billing_period_days'] ) ? (int) $plan['billing_period_days'] : 0;
				if ( $period_days === 7 ) {
					$interval = 'week';
				} elseif ( $period_days === 30 || $period_days === 31 ) {
					$interval = 'month';
				} elseif ( $period_days === 365 ) {
					$interval = 'year';
				} elseif ( $period_days > 0 ) {
					$interval = $period_days . ' days';
				} else {
					$interval = isset( $plan['billing_period'] ) ? $plan['billing_period'] : '';
				}

				$trial = ! empty( $plan['trial_period_days'] ) ? intval( $plan['trial_period_days'] ) . '-day trial' : '';

				$rows[] = array(
					'product_name' => $ap_name,
					'plan_id'      => $plan_id,
					'price'        => $price,
					'interval'     => $interval,
					'trial'        => $trial,
					'link'         => 'https://whop.com/checkout/' . $plan_id,
				);
			}
		}

		if ( empty( $rows ) ) {
			echo '<p>Found <strong>' . count( $access_passes ) . '</strong> products but no plans inside them. '
				. '<a href="' . esc_url( add_query_arg( 'debug', '1' ) ) . '">Enable debug mode</a> to inspect the raw API response.</p></div>';
			return;
		}

		usort( $rows, function ( $a, $b ) {
			$cmp = strcmp( $a['product_name'], $b['product_name'] );
			return $cmp !== 0 ? $cmp : ( $a['price'] <=> $b['price'] );
		} );

		echo '<p><strong>' . count( $rows ) . ' plans</strong> across <strong>' . count( $access_passes ) . ' products</strong> loaded.</p>';

		self::render_table( $rows );
		echo '</div>';
	}

	private static function render_flat_plans( $plans ) {
		$rows = array();
		foreach ( $plans as $plan ) {
			$plan_id = isset( $plan['id'] ) ? $plan['id'] : '';
			if ( empty( $plan_id ) ) continue;

			$price = isset( $plan['renewal_price'] ) ? (float) $plan['renewal_price'] : (float)( $plan['initial_price'] ?? 0 );
			$period_days = isset( $plan['billing_period_days'] ) ? (int) $plan['billing_period_days'] : 0;
			if ( $period_days === 7 )           $interval = 'week';
			elseif ( $period_days >= 28 && $period_days <= 31 ) $interval = 'month';
			elseif ( $period_days === 365 )     $interval = 'year';
			elseif ( $period_days > 0 )         $interval = $period_days . ' days';
			else $interval = isset( $plan['billing_period'] ) ? $plan['billing_period'] : '';

			$prod_name = '';
			if ( ! empty( $plan['access_pass']['name'] ) ) $prod_name = $plan['access_pass']['name'];
			elseif ( ! empty( $plan['product']['name'] ) ) $prod_name = $plan['product']['name'];
			elseif ( ! empty( $plan['access_pass_id'] ) )  $prod_name = $plan['access_pass_id'];
			else $prod_name = 'Uncategorised';

			$rows[] = array(
				'product_name' => $prod_name,
				'plan_id'      => $plan_id,
				'price'        => $price,
				'interval'     => $interval,
				'trial'        => ! empty( $plan['trial_period_days'] ) ? $plan['trial_period_days'] . '-day trial' : '',
				'link'         => 'https://whop.com/checkout/' . $plan_id,
			);
		}

		usort( $rows, function ( $a, $b ) {
			$cmp = strcmp( $a['product_name'], $b['product_name'] );
			return $cmp !== 0 ? $cmp : ( $a['price'] <=> $b['price'] );
		} );

		echo '<p><strong>' . count( $rows ) . ' plans</strong> loaded via /plans endpoint.</p>';
		self::render_table( $rows );
	}

	private static function render_table( $rows ) {
		echo '<table class="widefat striped" style="border-collapse:collapse;">';
		echo '<thead><tr style="background:#1d2327;color:#fff;">';
		echo '<th style="padding:10px 12px;width:230px;">Product Name</th>';
		echo '<th style="padding:10px 12px;width:160px;">Price / Interval</th>';
		echo '<th style="padding:10px 12px;">Plan ID</th>';
		echo '<th style="padding:10px 12px;width:160px;">Cashier Link</th>';
		echo '</tr></thead><tbody>';

		$last = null;
		foreach ( $rows as $row ) {
			$border = ( $row['product_name'] !== $last && $last !== null ) ? 'border-top:3px solid #2271b1;' : '';
			$last   = $row['product_name'];

			echo '<tr style="' . $border . '">';
			echo '<td style="padding:10px 12px;"><strong>' . esc_html( $row['product_name'] ) . '</strong></td>';
			echo '<td style="padding:10px 12px;">$' . number_format( $row['price'], 2 ) . ' / ' . esc_html( $row['interval'] );
			if ( ! empty( $row['trial'] ) ) {
				echo ' <span style="background:#d0ecff;color:#0073aa;font-size:11px;padding:1px 5px;border-radius:3px;">' . esc_html( $row['trial'] ) . '</span>';
			}
			echo '</td>';
			echo '<td style="padding:10px 12px;">'
				. '<input type="text" readonly value="' . esc_attr( $row['plan_id'] ) . '" '
				. 'style="width:100%;font-family:monospace;font-size:12px;background:#f6f6f6;border:1px solid #ddd;padding:4px 6px;" '
				. 'onclick="this.select();document.execCommand(\'copy\');this.style.background=\'#d4edda\';var el=this;setTimeout(function(){el.style.background=\'#f6f6f6\';},1500);">'
				. '</td>';
			echo '<td style="padding:10px 12px;">'
				. '<a href="' . esc_url( $row['link'] ) . '" target="_blank" class="button button-primary">Open Cashier &rarr;</a>'
				. '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}

Whop_Plan_Explorer::init();
