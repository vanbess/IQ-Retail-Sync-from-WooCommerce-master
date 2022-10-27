<?php

/**
 * Update stock from IQ to Woo (Auto and Manual major syncs)
 * 
 * Used by:
 * /functions/fetch/auto-major-sync.php
 * /functions/fetch/manual-major-sync.php
 * 
 * @var $last_fetch_date: last date on which stock was fetched from IQ in YYYY-MM-DD format
 */
function iq_update_stock($last_fetch_date = '', $is_manual = false) {

    // 1. Fetch stock master (uses $last_fetch_date)
    require_once __DIR__ . '/inc/fetch-stock-master.php';

    // 2. Fetch stock weights
    require_once __DIR__ . '/inc/fetch-stock-weight-data.php';

    // 3. Combine stock master and weight datasets
    require_once __DIR__ . '/inc/combine-stock-master-weights.php';

    // 4. Update/insert products
    require_once __DIR__ . '/inc/update-insert-stock.php';
}
