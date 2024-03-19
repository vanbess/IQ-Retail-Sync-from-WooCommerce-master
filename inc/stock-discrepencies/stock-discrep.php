<?php

defined('ABSPATH') ?: exit();

/**
 * Schedule stock check
 */
add_action('wp_ajax_nopriv_iq_schedule_stock_check', 'iq_schedule_stock_check');
add_action('wp_ajax_iq_schedule_stock_check', 'iq_schedule_stock_check');

function iq_schedule_stock_check() {

    check_ajax_referer('iq schedule stock check');

    // stock check action scheduled? (empty:int)
    $stock_check_scheduled = '';

    // if not schedule, schedule
    if (false === as_has_scheduled_action('iq_manual_major_sync') && function_exists('as_has_scheduled_action')) :

        // schedule action
        $stock_check_scheduled = as_schedule_single_action(strtotime('now'), 'iq_execute_stock_check', [], 'iq_execute_stock_check');

    endif;

    // send success/error message
    if (is_int($stock_check_scheduled)) :
        wp_send_json('Stock discrepancy check scheduled. Once completed successfully, you can find the stock discrepancy log under WooCommerce -> Status -> Logs.');
    else :
        wp_send_json('Failed to schedule stock discrepancy check. Please try again.');
    endif;

    wp_die();
}

add_action('iq_execute_stock_check', function () {

    // Start monitoring execution time and memory usage
    $start_time         = microtime(true);
    $start_memory_usage = memory_get_usage();

    // init WC logger
    $logger  = wc_get_logger();
    $context = array('source' => 'IQ-missing-products');

    $logger->info('Setting up stock request parameters', $context);

    // fetch condensed stock list from IQ
    $sql_string = "select code, descript from Stock where webitem = true order by code;";

    // retrieve IQ settings
    $settings = maybe_unserialize(get_option('iq_settings'));

    // setup payload
    $payload = [
        'IQ_API' => [
            'IQ_API_Request_GenericSQL' => [
                'IQ_Company_Number'     => $settings['company-no'],
                'IQ_Terminal_Number'    => $settings['terminal-no'],
                'IQ_User_Number'        => $settings['user-no'],
                'IQ_User_Password'      => $settings['user-pass-api-key'],
                'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
                "IQ_SQL_Text"           => $sql_string
            ]
        ]
    ];

    // setup request URL
    $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

    $logger->info('Setting up authorization', $context);

    // setup basic auth
    $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
    $basic_auth     = base64_encode($basic_auth_raw);
    $auth_string    = 'Basic ' . $basic_auth;

    $logger->info('Initializing cURL request', $context);

    // setup test request
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL            => $request_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array(
            "Authorization: $auth_string",
            'Content-Type: application/json'
        ),
    ));

    $logger->info('Executing request', $context);

    $result = curl_exec($curl);

    // $logger->info('cURL request successfully executed, result: '.$result, $context);


    // if request fails, log to error log and bail
    if ($result === false) :

        $logger->info('Could not fetch products from IQ for stock discrepancy check. Error message: ' . curl_error($curl), $context);
        $logger->info('Stopping execution and bailing.', $context);

        return false;

    else :

        $logger->info('Checking for existence of previous stock comparison file', $context);

        // delete previous result file if exists
        if (file_exists(IQ_RETAIL_PATH . 'inc/stock-discrepencies/stock-compare.json')) :
            $logger->info('File found, deleting', $context);
            unlink(IQ_RETAIL_PATH . 'inc/stock-discrepencies/stock-compare.json');
        endif;

        // write result to file
        $logger->info('Writing retrieved stock data to stock-compare.json', $context);

        file_put_contents(IQ_RETAIL_PATH . 'inc/stock-discrepencies/stock-compare.json', $result);

    endif;

    $logger->info('Closing cURL...', $context);

    curl_close($curl);

    $logger->info('Done.', $context);

    /**
     * Stock comparison
     */
    $logger->info('Starting local => remote product stock comparison procedure', $context);

    // fetch remote products from file
    $remoteProducts = file_get_contents(IQ_RETAIL_PATH . 'inc/stock-discrepencies/stock-compare.json');

    // bail if file not found
    if (!$remoteProducts) :
        $logger->info('Could not find stock JSON file, stopping execution.', $context);
        return false;
    endif;

    $logger->info('Locally saved stock file successfully fetched, decoding', $context);

    // target correct key
    $remoteProducts = json_decode($remoteProducts, true);
    $remoteProducts = $remoteProducts['iq_api_result_data']['records'];

    $remoteProductsArray = [];

    $logger->info('Loop to reformat stock file starting', $context);

    // loop to properly format $remoteProducts and push to $remoteProductsArray for comparison
    foreach ($remoteProducts as $prod_data) :
        $remoteProductsArray[$prod_data['code']] = $prod_data['descript'];
    endforeach;

    $remote_prods_count = count($remoteProductsArray);

    $logger->info("Loop to reformat stock file ended - {$remote_prods_count} products found, fetching WC products from database to compare", $context);

    global $wpdb;

    // Fetch WooCommerce products
    $wcProductsQuery = "SELECT p.ID, p.post_title, pm.meta_value AS sku
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE p.post_type = 'product'
    -- AND p.post_status = 'publish'
    AND pm.meta_key = '_sku'";

    // get query results
    $wcProducts = $wpdb->get_results($wcProductsQuery);

    // count WC products
    $wcProductsCount = count($wcProducts);

    // calc difference in product count
    $prod_count_diff = $remote_prods_count - $wcProductsCount;

    $logger->info("WC product fetch complete - {$wcProductsCount} simple products found, looping through results and formatting", $context);
    $logger->info("There is a difference of {$prod_count_diff} between remote and WC products (NOTE: a negative number here implies there are more products present on WC than in your IQ stock database; a positive number implies the opposite)", $context);

    // Create an array of WooCommerce products
    $wcProductsArray = [];

    foreach ($wcProducts as $product) {
        $wcProductsArray[$product->sku] = $product->post_title;
    }

    $logger->info('Loop complete, extracting missing products', $context);

    // Find missing products
    $missingProducts = array_diff_key($wcProductsArray, $remoteProductsArray );

    // Count missing products
    $missing_count = count($missingProducts);

    // continue if missing products found, else bail 
    if ($missing_count > 0) :
        $logger->info("Found {$missing_count} products missing from IQ, starting output", $context);
    else :
        $logger->info("No missing products found. Yay! Stopping function execution.", $context);

        // Calculate execution time and memory usage
        $execution_time    = microtime(true) - $start_time;
        $peak_memory_usage = memory_get_peak_usage() - $start_memory_usage;

        // Log execution time and memory usage
        $logger->info("Execution time: {$execution_time} seconds", $context);
        $logger->info("Peak memory usage: " . number_format($peak_memory_usage / 1024, 2) . " KB", $context);

        return false;
    endif;

    // Log missing products
    foreach ($missingProducts as $sku => $title) {
        $logger->info("SKU: {$sku} - Product Title: {$title}", $context);
    }

    // Calculate execution time and memory usage
    $execution_time    = microtime(true) - $start_time;
    $peak_memory_usage = memory_get_peak_usage() - $start_memory_usage;

    // Log execution time and memory usage
    $logger->info("Execution time: {$execution_time} seconds", $context);
    $logger->info("Peak memory usage: " . number_format($peak_memory_usage / 1024, 2) . " KB", $context);
});
