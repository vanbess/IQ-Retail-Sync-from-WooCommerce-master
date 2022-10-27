<?php

/*******************************
 * 2. FETCH PRODUCT WEIGHT DATA
 *******************************/

// retrieve IQ settings
$settings = maybe_unserialize(get_option('iq_settings'));

// setup payload
$payload = [
    'IQ_API' => [
        'IQ_API_Request_GenericSQL' => [
            'IQ_Company_Number'     => $settings['company-no'],
            'IQ_Terminal_Number'    => $settings['terminal-no'],
            'IQ_User_Number'        => $settings['user-no'],
            'IQ_User_Password'      => $settings['user-pass-api-key'],
            'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
            "IQ_SQL_Text"           => "select code, weight from stocuser where weight is not null order by code;"
        ]
    ]
];

// setup request URL
$request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

// setup basic auth
$basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
$basic_auth     = base64_encode($basic_auth_raw);
$auth_string    = 'Basic ' . $basic_auth;

// setup test request
$curl = curl_init();

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

$result = curl_exec($curl);

// if request fails, log to error log and bail
if ($result === false) :

    if ($is_manual == true) :
        error_log('Could not execute stock retrieval from IQ. Request error returned: ' . curl_error($curl) . '.' . PHP_EOL, 3, IQ_RETAIL_PATH . 'functions/fetch/logs/manual-sync/fetch_errors.log');
    else :
        error_log('Could not execute stock retrieval from IQ. Request error returned: ' . curl_error($curl) . '.' . PHP_EOL, 3, IQ_RETAIL_PATH . 'functions/fetch/logs/auto-sync/fetch_errors.log');
    endif;

else :

    // delete previous result file if exists
    if (file_exists(IQ_RETAIL_PATH . 'inc/fetch/files/stock-weight.json')) :
        unlink(IQ_RETAIL_PATH . 'inc/fetch/files/stock-weight.json');
    endif;

    // write result to file for later ref
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/files/stock-weight.json', $result);

    // logging
    $time_now = strtotime('now');

    if ($is_manual == true) :
        file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-sync/stock_weight_fetch.log', date('j F, Y @ h:i:s', $time_now) . ' - IQ stock weight fetch successful.' . PHP_EOL, FILE_APPEND);
    else :
        file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/auto-sync/stock_weight_fetch.log', date('j F, Y @ h:i:s', $time_now) . ' - IQ stock weight fetch successful.' . PHP_EOL, FILE_APPEND);
    endif;

endif;

curl_close($curl);
