<?php

/*****************************
 * Manually sync orders to IQ
 *****************************/

function iq_manual_sync_orders_to_iq() {

    // 1. Retrieve existing orders from IQ
    require_once __DIR__ . '/inc/retrieve-ext-orders-iq.php';

    // 2. Diff IQ order with Woo orders
    require_once __DIR__ . '/inc/diff-woo-iq-orders.php';

    // 3. Build request payload
    require_once __DIR__ . '/inc/build-request-payload.php';

    // 4. Push diffed Woo orders to IQ
    require_once __DIR__ . '/inc/push-diffed-orders-iq.php';
}
