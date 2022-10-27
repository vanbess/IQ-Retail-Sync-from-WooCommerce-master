<?php

/**
 * SCHEDULE AUTO MINOR SYNC (Woo to IQ)
 */

//  Schedule minor sync via Action Scheduler
add_action('init', function () {

    // check when minor sync was last run, else set last run time to now if not present
    $last_run = get_option('iq_last_auto_sync_orders') ? get_option('iq_last_auto_sync_orders') : strtotime('now');

    // get run interval
    $iq_settings       = maybe_unserialize(get_option('iq_settings'));
    $sync_run_interval = $iq_settings['minor-sync-interval'];

    // if $sync_run_interval empty, bail
    if ($sync_run_interval == '') :
        return;
    endif;

    // if $time_now > $last_run + $sync_run_interval, and run not scheduled, schedule run
    if ($last_run && false === as_has_scheduled_action('iq_auto_sync_orders')) :

        // schedule action
        as_schedule_single_action(strtotime('now') + $sync_run_interval, 'iq_auto_sync_orders', [], 'iq_api_sync');

    endif;
});

// Function to run to do minor sync
add_action('iq_auto_sync_orders', function () {

    // add to log run time start
    $time_start = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'functions/push/logs/auto-minor-sync-log.txt', 'Minor auto order sync run start: ' . date('j F Y @ h:i:s', $time_start) . PHP_EOL, FILE_APPEND);

    // update code gets executed from here


    // add to log run time end
    $time_end = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'functions/push/logs/auto-minor-sync-log.txt', 'Minor auto order sync run start: ' . date('j F Y @ h:i:s', $time_end) . PHP_EOL, FILE_APPEND);

    // calculate total run time and add to log
    $total_run_time = ($time_start - $time_end) / 60;
    file_put_contents(IQ_RETAIL_PATH . 'functions/push/logs/auto-minor-sync-log.txt', 'Minor auto order sync run start: ' . $total_run_time . PHP_EOL, FILE_APPEND);

    // update last minor runtime
    update_option('iq_last_auto_sync_orders', $time_end);
});
