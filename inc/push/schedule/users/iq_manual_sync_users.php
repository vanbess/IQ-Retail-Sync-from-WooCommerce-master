<?php

/**********************************
 * Schedule manual user sync to IQ
 **********************************/

/**
 * AJAX function to schedule user sync to IQ
 */
add_action('wp_ajax_nopriv_iq_schedule_manual_sync_users', 'iq_schedule_manual_sync_users');
add_action('wp_ajax_iq_schedule_manual_sync_users', 'iq_schedule_manual_sync_users');

function iq_schedule_manual_sync_users() {

    check_ajax_referer('schedule manual sync users');

    $scheduled_id = '';

    // if run not scheduled, schedule run immediately
    if (false === as_has_scheduled_action('iq_manual_sync_users') && function_exists('as_has_scheduled_action')) :

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
    iq_logger('user_sync_times', 'User sync started', strtotime('now'));

    // sync users
    iq_sync_users();

    // add to log run time end
    iq_logger('user_sync_times', 'User sync ended', strtotime('now'));
});
