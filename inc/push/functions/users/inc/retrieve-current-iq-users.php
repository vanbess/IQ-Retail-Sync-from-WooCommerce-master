<?php

/*******************************
 * 1. RETRIEVE CURRENT USER SET
 *******************************/

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
            "IQ_SQL_Text"           => "SELECT account FROM debtors WHERE account LIKE '%WWW%' order by account;"
        ]
    ]
];

// setup basic auth
$basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
$basic_auth     = base64_encode($basic_auth_raw);
$auth_string    = 'Basic ' . $basic_auth;

// curl request
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

$response = curl_exec($curl);

if ($response === false) :
    error_log('Could not execute user retrieval from IQ. Request error returned: ' . curl_error($curl) . '.' . PHP_EOL, 3, IQ_RETAIL_PATH . 'inc/push/logs/users/fetch_errors.log');
else :

    // delete previous result file if exists
    if (file_exists(IQ_RETAIL_PATH . 'inc/push/files/users/user-master.json')) :
        unlink(IQ_RETAIL_PATH . 'inc/push/files/users/user-master.json');
    endif;

    // write result to file
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/files/users/user-master.json', $response);

    // logging
    $time_now = strtotime('now');

    // log
    file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/users/users-fetch.log', date('j F, Y @ h:i:s', $time_now) . ' - IQ user fetch successful.' . PHP_EOL, FILE_APPEND);

endif;

// close curl
curl_close($curl);
