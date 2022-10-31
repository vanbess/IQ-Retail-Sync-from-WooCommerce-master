<?php

/****************************
 * SCHEDULE AUTO ORDER SYNC 
 ***************************/

// check when minor sync was last run, else set last run time to now if not present
$last_run = get_option('iq_last_auto_sync_orders') ? get_option('iq_last_auto_sync_orders') : strtotime('now');

// get run interval
$iq_settings       = maybe_unserialize(get_option('iq_settings'));
$sync_run_interval = $iq_settings['minor-sync-interval'];

// if $sync_run_interval empty, bail
if ($sync_run_interval == '') :
    return;
endif;

// is auto order sync disabled, bail
if ($iq_settings['enable-order-sync'] != 'yes') :
    return;
endif;

// if $time_now > $last_run + $sync_run_interval, and run not scheduled, schedule run
if ($last_run && false === as_has_scheduled_action('iq_auto_sync_orders') && function_exists('as_has_scheduled_action')) :

    // schedule action
    as_schedule_single_action($last_run + $sync_run_interval, 'iq_auto_sync_orders', [], 'iq_api_sync');

endif;

/**
 * Function to run to do minor sync
 */
add_action('iq_auto_sync_orders', function () {

    // add to log run time start
    iq_logger('order_sync', 'Order sync started', strtotime('now'));

    // sync orders
    iq_sync_orders();

    // add to log run time end
    iq_logger('order_sync_times', 'Order sync ended', strtotime('now'));

    // update last minor runtime
    update_option('iq_last_auto_sync_orders', strtotime('now'));
});
