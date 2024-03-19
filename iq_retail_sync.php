<?php

/**
 * Plugin Name:       IQ Retail API
 * Description:       Connects to IQ Retail Rest API to sync order, product and customer data between IQ and WooCommerce
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Caxton Books
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       iq-retail-api
 */

defined('ABSPATH') || exit();

add_action('init', function () {

    // constants
    define('IQ_RETAIL_PATH', plugin_dir_path(__FILE__));
    define('IQ_RETAIL_URL', plugin_dir_url(__FILE__));

    // fetch function
    include IQ_RETAIL_PATH . 'inc/fetch/functions/iq_update_stock.php';

    // sync users
    include IQ_RETAIL_PATH . 'inc/push/functions/sync-users.php';

    // sync orders
    include IQ_RETAIL_PATH . 'inc/push/functions/sync-orders.php';

    // admin page
    include IQ_RETAIL_PATH . 'inc/admin/iq_admin.php';

    // connectivity test
    include IQ_RETAIL_PATH . 'inc/tests/iq_test_connect.php';

    // product sync to iq metabox
    include IQ_RETAIL_PATH . 'inc/admin/single-order-mbox.php';

    // major updates (IQ to Woo)
    include IQ_RETAIL_PATH . 'inc/fetch/schedule/iq_schedule_major_auto_sync.php';
    include IQ_RETAIL_PATH . 'inc/fetch/schedule/iq_schedule_major_manual_sync.php';

    // minor updates (Woo to IQ)
    include IQ_RETAIL_PATH . 'inc/push/schedule/orders/iq_auto_sync_orders.php';
    include IQ_RETAIL_PATH . 'inc/push/schedule/orders/iq_manual_sync_orders.php';
    include IQ_RETAIL_PATH . 'inc/push/schedule/users/iq_auto_sync_users.php';
    include IQ_RETAIL_PATH . 'inc/push/schedule/users/iq_manual_sync_users.php';
    
    // stock discrepancy test (added 17 March 2023)
    include IQ_RETAIL_PATH . 'inc/stock-discrepencies/stock-discrep.php';

    /**
     * Logger - logs sync progress
     *
     * @param string $message - message to log
     * @param int $time_stamp - timestamp to log at (defaults to 'now')
     * @param boolean $debug - whether or not to stop code execution for debugging purposes once file has been logged
     * @return void
     */
    function iq_logger($file_name, $message, $time_stamp, $debug = false) {

        if(filesize(__DIR__ . '/logs-files/' . $file_name . '.log') > 52428800):
            unlink(__DIR__ . '/logs-files/' . $file_name . '.log');
        endif;

        // setup date and time
        $date_time = date('j F Y @ h:i:s', $time_stamp);

        // setup message
        $msg = $date_time . ' - ' . $message . PHP_EOL;

        // write to file
        file_put_contents(__DIR__ . '/logs-files/' . $file_name . '.log', $msg, FILE_APPEND);

        // if $debug == true, stop any additional code execution
        if ($debug === true) :
            return;
        endif;
    }

    /**
     * Filer - writes JSON data to file for later ref
     *
     * @param string $file_name - file name to use
     * @param string $json_data - JSON data to write to file
     * @param boolean $debug - whether or not to stop code execution for debugging purposes once file has been logged
     * @return void
     */
    function iq_filer($file_name, $json_data, $debug = false) {

        if(filesize(__DIR__ . '/logs-files/' . $file_name . '.json') > 52428800):
            unlink(__DIR__ . '/logs-files/' . $file_name . '.json');
        endif;

        // write to file
        file_put_contents(__DIR__ . '/logs-files/' . $file_name . '.json', $json_data);

        // if $debug == true, stop any additional code execution
        if ($debug === true) :
            return;
        endif;
    }
});
