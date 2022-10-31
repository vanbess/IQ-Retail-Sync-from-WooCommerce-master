<?php

/**
 * Check order status AJAX
 */
add_action('wp_ajax_nopriv_iq_check_order_status', 'iq_check_order_status');
add_action('wp_ajax_iq_check_order_status', 'iq_check_order_status');

function iq_check_order_status() {

    check_ajax_referer('iq check woo order status');

    // retrieve order id
    $order_id = $_POST['order_id'];

    // retrieve iq settings
    $settings = maybe_unserialize(get_option('iq_settings'));

    // setup request url
    $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

    // setup payload
    $payload = [
        'IQ_API' => [
            'IQ_API_Request_GenericSQL' => [
                'IQ_Company_Number'     => $settings['company-no'],
                'IQ_Terminal_Number'    => $settings['terminal-no'],
                'IQ_User_Number'        => $settings['user-no'],
                'IQ_User_Password'      => $settings['user-pass-api-key'],
                'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
                "IQ_SQL_Text"           => "SELECT ordernum, document FROM sorders WHERE ordernum = '$order_id';"
            ]
        ]
    ];

    // setup basic auth
    $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
    $basic_auth     = base64_encode($basic_auth_raw);
    $auth_string    = 'Basic ' . $basic_auth;

    // init curl
    $curl = curl_init();

    // init curl options
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

    // execute curl
    $response = curl_exec($curl);

    // if request successful
    if (false !== $response) :

        // decode response
        $response = json_decode($response, true);

        // if iq did not return an error
        if ($response['iq_api_error']['iq_error_code'] == 0) :

            // retrieve records
            $records = $response['iq_api_result_data']['records'];

            // if no records
            if (empty($records)) :
                wp_send_json('No records found for this order on IQ. Use the Sync to IQ button to sync it to IQ.');

            // if records
            elseif (!empty($records)) :

                iq_logger('order_sync', 'Order ID ' . $order_id . ' already present on IQ. Skipping...', strtotime('now'));

                update_post_meta($order_id, '_iq_doc_number', $records['document']);
                
                wp_send_json('This order is already present on IQ. IQ document number ' . $records['document'] . ' has been added to order meta.');

            endif;

        // if IQ error returned
        elseif ($response['iq_api_error']['iq_error_code'] != 0) :
            wp_send_json('The request to IQ returned an error with the following error code: ' . $response['iq_api_error']['iq_error_code'] . '. Please reload the page and try again.');
        endif;

    // if curl request failed for some reason
    else :
        $error = curl_error($curl);
        wp_send_json("Request to IQ failed with the following error: $error. Please double check you IQ connection settings, save them and try to send the request again.");
    endif;

    // close curl
    curl_close($curl);

    wp_die();
}
