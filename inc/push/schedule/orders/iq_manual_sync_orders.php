<?php

/**
 * SCHEDULE MANUAL MINOR SYNC (Woo to IQ)
 */

// include function to sync orders to IQ manually
include IQ_RETAIL_PATH . 'inc/push/functions/orders/fnc_iq_manual_sync_orders.php';

/**
 * Schedule manual order sync via AJAX
 */
add_action('wp_ajax_nopriv_iq_schedule_manual_sync_orders', 'iq_schedule_manual_sync_orders');
add_action('wp_ajax_iq_schedule_manual_sync_orders', 'iq_schedule_manual_sync_orders');

function iq_schedule_manual_sync_orders() {

    check_ajax_referer('schedule manual sync orders');

    // AS action ID
    $action_id = [];

    // if $time_now > $last_run + $sync_run_interval, and run not scheduled, schedule run
    if (false === as_has_scheduled_action('iq_manual_sync_orders')) :

        // schedule action
        $action_id = as_schedule_single_action(strtotime('now'), 'iq_manual_sync_orders', [], 'iq_api_sync');

    endif;

    if (is_int($action_id)) :
        wp_send_json('Manual sync of orders successfully scheduled and will process as soon as possible.');
    else :
        wp_send_json('Could not schedule manual sync of orders. Please reload the page and try again.');
    endif;

    wp_die();
}

/**
 * Function to run to do minor syn
 */
// Function to run to do minor sync
add_action('iq_manual_sync_orders', function () {

    // add to log run time start
    $time_start = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'functions/push/logs/manual-sync/orders/orders.log', 'Order sync run start: ' . date('j F Y @ h:i:s', $time_start) . PHP_EOL, FILE_APPEND);

    // update code gets executed from here
    iq_manual_sync_orders_to_iq();

    // add to log run time end
    $time_end = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'functions/push/logs/manual-sync/orders/orders.log', 'Order sync run end: ' . date('j F Y @ h:i:s', $time_end) . PHP_EOL, FILE_APPEND);

    // calculate total run time and add to log
    $total_run_time = ($time_end - $time_start) / 60;
    file_put_contents(IQ_RETAIL_PATH . 'functions/push/logs/manual-sync/orders/orders.log', 'Order sync run time in minutes: ' . $total_run_time . PHP_EOL, FILE_APPEND);
});
