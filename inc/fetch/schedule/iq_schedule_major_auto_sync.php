<?php

/**
 * SCHEDULE AUTO MAJOR SYNC (IQ to Woo)
 */

// check when major sync was last run; if not defined, set last run time as now
$last_run = get_option('iq_last_major_auto_sync') ? get_option('iq_last_major_auto_sync') : strtotime('now');

// get sync interval
$iq_settings       = maybe_unserialize(get_option('iq_settings'));
$sync_run_interval = $iq_settings['major-sync-interval'];

// if $sync_run_interval empty, bail
if ($sync_run_interval == '') :
    return;
endif;

// is auto major sync disabled, bail
if ($iq_settings['enable-major-sync'] != 'yes') :
    return;
endif;

// check time now
$time_now = strtotime('now');

// if $last_run time present, and run not scheduled, schedule run
// if ($last_run && false === as_has_scheduled_action('iq_auto_major_sync') && function_exists('as_has_scheduled_action')) :
if (false === as_has_scheduled_action('iq_auto_major_sync') && function_exists('as_has_scheduled_action')) :

    // schedule action
    as_schedule_recurring_action($time_now, $sync_run_interval, 'iq_auto_major_sync', [], 'iq_api_auto_sync');

    // as_schedule_single_action($last_run + $sync_run_interval, 'iq_auto_major_sync', [], 'iq_api_sync');

endif;

// Function to run to do major sync
add_action('iq_auto_major_sync', function () {

    // add to log run time start
    $time_start = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-major-sync.log', 'Major sync run start: ' . date('j F Y @ h:i:s', $time_start) . PHP_EOL, FILE_APPEND);

    // sync products to Woo
    try {

        // retrieve product mod date (used to fetch/return only products which was modified on or after this date);
        $last_fetch_date = get_option('iq_prods_mod_date') ? get_option('iq_prods_mod_date') : strtotime('now');
        $last_fetch_date = date('Y-m-d', $last_fetch_date);

        // run through product retrieval and update process using $last_fetch_date
        iq_update_stock($last_fetch_date, false);
    } catch (\Throwable $th) {

        // log time
        $log_time = strtotime('now');

        // log
        file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-major-sync.log', date('j F Y @ h:i:s', $log_time) . ': Major manual sync failed. Error message returned: ' . $th->getMessage() . PHP_EOL, FILE_APPEND);
    }

    // add to log run time end
    $time_end = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-major-sync.log', 'Major sync run end: ' . date('j F Y @ h:i:s', $time_end) . PHP_EOL, FILE_APPEND);

    // calculate total run time and add to log
    $total_run_time = ($time_end - $time_start) / 60;
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-major-sync.log', 'Total sync run time in minutes: ' . $total_run_time . PHP_EOL, FILE_APPEND);

    // update last major runtime
    update_option('iq_last_major_auto_sync', $time_end);

    // update product modified time
    update_option('iq_prods_mod_date', $time_end);
});
