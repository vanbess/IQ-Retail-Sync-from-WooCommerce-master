<?php

/******************************
 * 4. SEND FINAL REQUEST TO IQ
 ******************************/
$curl = curl_init();

// execute request
curl_setopt_array($curl, array(
    CURLOPT_URL            => $request_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => '',
    CURLOPT_MAXREDIRS      => 10,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => array(
        "Authorization: $auth_string",
        'Content-Type: application/json'
    ),
));

$response = curl_exec($curl);

// if request fails, log error and bail
if ($response == false) :

    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/manual-sync/orders/sync-request-error.log', date('j F Y @ h:i:s', strtotime('now')) . PHP_EOL . print_r($response, true), FILE_APPEND);

    return;

// if request successful, write response to file
else :

    if (file_exists(IQ_RETAIL_PATH . 'inc/push/files/orders/order-push-iq-response.json')) {
        unlink(IQ_RETAIL_PATH . 'inc/push/files/orders/order-push-iq-response.json');
    }

    file_put_contents(IQ_RETAIL_PATH . 'inc/push/files/orders/order-push-iq-response.json', $response);

endif;
curl_close($curl);
