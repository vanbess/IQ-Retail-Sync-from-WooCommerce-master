<?php

/*****************************************
 * Schedule periodic auto user sync to IQ
 *****************************************/

//  function to run
include IQ_RETAIL_PATH . 'inc/push/functions/users/fnc_iq_auto_sync_users.php';

// check when minor sync was last run, else set last run time to now if not present
$last_run = get_option('iq_last_auto_sync_users') ? get_option('iq_last_auto_sync_users') : strtotime('now');

// get run interval
$iq_settings       = maybe_unserialize(get_option('iq_settings'));
$sync_run_interval = $iq_settings['minor-sync-interval'];

// if $sync_run_interval empty, bail
if ($sync_run_interval == '') :
    return;
endif;

// is auto user sync disabled, bail
if ($iq_settings['enable-user-sync'] != 'yes') :
    return;
endif;

// if $last_run, and run not scheduled, schedule run
if ($last_run && false === as_has_scheduled_action('iq_auto_sync_users')) :

    // schedule action
    $auto_sync_users = as_schedule_single_action($last_run + $sync_run_interval, 'iq_auto_sync_users', [], 'iq_api_sync');

endif;

/**
 * Function to run to do minor sync
 */
add_action('iq_auto_sync_users', function () {

    // add to log run time start
    $time_start = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/auto-sync/users/sync.log', 'User sync to IQ run start: ' . date('j F Y @ h:i:s', $time_start) . PHP_EOL, FILE_APPEND);

    // sync users
    iq_auto_sync_new_users();

    // add to log run time end
    $time_end = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/auto-sync/users/sync.log', 'User sync to IQ run end: ' . date('j F Y @ h:i:s', $time_end) . PHP_EOL, FILE_APPEND);

    // calculate total run time and add to log
    $total_run_time = ($time_end - $time_start) / 60;
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/auto-sync/users/sync.log', 'User sync to IQ run time: ' . $total_run_time . PHP_EOL, FILE_APPEND);

    // update last minor runtime
    update_option('iq_last_auto_sync_users', $time_end);
});
