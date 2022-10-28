<?php

/**
 * SCHEDULE MANUAL MINOR SYNC (Woo to IQ)
 */

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
    iq_logger('order_sync_times', 'Order sync started', strtotime('now'));

    // update code gets executed from here
    iq_sync_orders();

    // add to log run time end
    iq_logger('order_sync_times', 'Order sync ended', strtotime('now'));
});
