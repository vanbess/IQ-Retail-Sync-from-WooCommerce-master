<?php

/************************************
 * 2. DIFF IQ ORDERS WITH WOO ORDERS
 ************************************/

// fetch Woo orders
$woo_orders = wc_get_orders(
    [
        'status' => 'wc-processing',
        'return' => 'ids',
        'limit' => -1
    ]
);

// retrieve iq order numbers
$iq_order_numbers = json_decode(file_get_contents(IQ_RETAIL_PATH . 'inc/push/files/orders/order-master.json'), true);
$iq_order_numbers = $iq_order_numbers['iq_api_result_data']['records'];

// array which holds iq order ids only
$iq_order_nos_only = [];

// loop to extract IQ order id and push $iq_order_nos_only
if (is_array($iq_order_numbers) && !empty($iq_order_numbers)) :
    foreach ($iq_order_numbers as $key => $data) :
        $iq_order_nos_only[] = $data['ordernum'];
    endforeach;
elseif (empty($iq_order_numbers)) :
    return;
endif;

// orders not synced array
$orders_not_synced = [];

// loop to determine if orders are synced
foreach ($woo_orders as $order_id) :

    // if $order_id not in $iq_order_nos_only, push to $orders_not_synced
    if (!in_array($order_id, $iq_order_nos_only)) :
        $orders_not_synced[] = $order_id;
    endif;

endforeach;

file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/manual-sync/orders/not-synced.log', print_r($orders_not_synced, true));

// bail with log message if $orders_not_synced empty
if (empty($orders_not_synced)) :

    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/manual-sync/orders/orders-to-iq.log', date('j F Y @ h:i:s', strtotime('now')) . ' - No orders synced to IQ. Reason: all current orders already synced.', FILE_APPEND);
    return;

endif;
