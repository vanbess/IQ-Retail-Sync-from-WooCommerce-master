<?php

/**
 * Function to manually push new users to IQ
 */
function iq_manual_sync_new_users() {

    // 1. Retrieve current IQ user set
    require_once __DIR__ . '/inc/retrieve-current-iq-users.php';

    // 2. Retrieve Woo user list and check against IQ user list
    require_once __DIR__ . '/inc/retrieve-current-woo-users.php';

    // 3. Push filtered list of users to IQ, i.e. users which are present on Woo, but not in IQ
    require_once __DIR__ . '/inc/push-woo-users-to-iq.php';
}
