<?php

/**********************************
 * Schedule manual user sync to IQ
 **********************************/

// function to process manual user sync to IQ
include IQ_RETAIL_PATH . 'inc/push/functions/users/fnc_iq_manual_sync_users.php';

/**
 * AJAX function to schedule user sync to IQ
 */
add_action('wp_ajax_nopriv_iq_schedule_manual_sync_users', 'iq_schedule_manual_sync_users');
add_action('wp_ajax_iq_schedule_manual_sync_users', 'iq_schedule_manual_sync_users');

function iq_schedule_manual_sync_users() {

    check_ajax_referer('schedule manual sync users');

    $scheduled_id = '';

    // if run not scheduled, schedule run immediately
    if (false === as_has_scheduled_action('iq_manual_sync_users')) :

        // schedule action
        $scheduled_id = as_schedule_single_action(strtotime('now'), 'iq_manual_sync_users', [], 'iq_api_sync');

    endif;

    // send appropriate response
    if (is_int($scheduled_id)) :
        wp_send_json('Manual user sync to IQ scheduled to run as soon as possible.');
    else :
        wp_send_json('Count not schedule manual user sync to IQ. Please reload the page and try again.');
    endif;

    wp_die();
}

/**
 * Function to run to do manual user sync
 */
add_action('iq_manual_sync_users', function () {

    // add to log run time start
    $time_start = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/manual-sync/users/sync.log', 'Manual user sync to IQ run start: ' . date('j F Y @ h:i:s', $time_start) . PHP_EOL, FILE_APPEND);

    // update code gets executed from here
    iq_manual_sync_new_users();

    // add to log run time end
    $time_end = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/manual-sync/users/sync.log', 'Manual user sync to IQ run end: ' . date('j F Y @ h:i:s', $time_end) . PHP_EOL, FILE_APPEND);

    // calculate total run time and add to log
    $total_run_time = ($time_end - $time_start) / 60;
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/manual-sync/users/sync.log', 'Total manual user sync to IQ run time in minutes: ' . $total_run_time . PHP_EOL, FILE_APPEND);
});
