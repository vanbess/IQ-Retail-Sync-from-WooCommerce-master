<?php

/**
 * SCHEDULE MANUAL MAJOR SYNC (IQ to Woo)
 */

add_action('wp_ajax_nopriv_iq_schedule_major_manual_sync', 'iq_schedule_major_manual_sync');
add_action('wp_ajax_iq_schedule_major_manual_sync', 'iq_schedule_major_manual_sync');

function iq_schedule_major_manual_sync() {

    check_ajax_referer('schedule manual major sync');

    // major sync action scheduled? (empty:int)
    $major_manual_scheduled = '';

    // if $time_now > $last_run + $sync_run_interval, and run not scheduled, schedule run
    if (false === as_has_scheduled_action('iq_manual_major_sync') && function_exists('as_has_scheduled_action')) :

        // schedule action
        $major_manual_scheduled = as_schedule_single_action(strtotime('now'), 'iq_manual_major_sync', [], 'iq_api_sync');

    endif;

    // send success/error message
    if (is_int($major_manual_scheduled)) :
        wp_send_json('Manual major IQ => Woo sync successfully scheduled to run as soon as possible.');
    else :
        wp_send_json('Failed to schedule major manual IQ => Woo sync. Please check all settings on this page is correct, update settings and try again.');
    endif;

    wp_die();
}

/**
 * Function to run when AS action is run
 * HAS to be outside AJAX to work
 */
add_action('iq_manual_major_sync', function () {

    // add to log run time start
    $time_start = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-sync/sync.log', 'Sync from IQ to Woo run start: ' . date('j F Y @ h:i:s', $time_start) . PHP_EOL, FILE_APPEND);

    // sync products to Woo
    try {
        iq_update_stock('', true);
    } catch (\Throwable $th) {

        // log time
        $log_time = strtotime('now');

        // log
        file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-sync/sync.log', date('j F Y @ h:i:s', $log_time) . ': Sync from IQ to Woo failed. Error message returned: ' . $th->getMessage() . PHP_EOL, FILE_APPEND);
    }

    // add to log run time end
    $time_end = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-sync/sync.log', 'Sync from IQ to Woo run end: ' . date('j F Y @ h:i:s', $time_end) . PHP_EOL, FILE_APPEND);

    // calculate total run time and add to log
    $total_run_time = ($time_end - $time_start) / 60;
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-sync/sync.log', 'Sync run time in minutes: ' . $total_run_time . PHP_EOL, FILE_APPEND);
});
