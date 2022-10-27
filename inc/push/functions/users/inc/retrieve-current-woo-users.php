<?php

/****************************************************************
 * 2. RETRIEVE USER LIST AND CHECK AGAINST EXISTING LIST FROM IQ
 ****************************************************************/

// Retrieve WC customers
$wc_customers = get_users([
    'role'    => 'customer',
    'orderby' => 'ID'
]);

// formatted WC user id array, with IDs prefixed with WWW
$wc_user_ids = [];

if (is_object($wc_customers) || is_array($wc_customers) && !empty($wc_customers)) :
    foreach ($wc_customers as $customer) :
        $wc_user_ids[] = 'WWW' . $customer->ID;
    endforeach;
endif;

// retrieve IQ user list, loop and push IQ user ids to array
$iq_users = file_get_contents(IQ_RETAIL_PATH . 'inc/push/files/users/user-master.json');
$iq_users = json_decode($iq_users, true);
$iq_users = $iq_users['iq_api_result_data']['records'];

// setup array which contains on IQ user ids
$iq_user_ids = [];

// loop to extract IQ user ids
if (is_array($iq_users) && !empty($iq_users)) :
    foreach ($iq_users as $user) :
        $iq_user_ids[] = $user['account'];
    endforeach;
endif;

// array of user ids for which to retrieve data and push to IQ
$user_ids_to_push = [];

// loop to check if wc user id exists in $iq_user_ids; if not, push to $user_ids_to_push
if (!empty($iq_user_ids)) :
    foreach ($wc_user_ids as $user_id) :
        if (!in_array($user_id, $iq_user_ids)) :
            $user_ids_to_push[] = $user_id;
        endif;
    endforeach;
endif;

// if $user_ids_to_push is empty, log and return
if (empty($user_ids_to_push)) :
    $time_now = strtotime('now');
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/users/user-push.log', date('j F Y @ h:i:s', $time_now) . ' - No new users to push to IQ.' . PHP_EOL, FILE_APPEND);
    return;
endif;
