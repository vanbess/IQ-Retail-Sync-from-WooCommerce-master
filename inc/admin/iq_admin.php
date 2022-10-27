<?php

/**
 * Register and render admin page
 */
add_action('admin_menu', function () {
    add_submenu_page('tools.php', 'IQ Retail REST API sync', 'IQ Retail', 'manage_options', 'iq-retail-rest', 'iq_retail_rest', 20);
});

function iq_retail_rest() {
    global $title;

    // retrieve settings
    $settings = get_option('iq_settings');
    $settings = unserialize($settings);

?>


    <div>

        <h1 style="background: white; padding: 15px 20px; margin-top: 0; margin-left: -19px !important; box-shadow: 0px 2px 4px lightgrey;">

            <?php echo $title; ?>

            <!-- <span style="color:red; font-weight: bold;">[STILL IN FINAL TESTING PHASE!!]</span> -->

            <?php if (!$settings) : ?>

                <span id="iq-schedule-links" style="float: right; position: relative; bottom: 6px;">

                    <!-- test connectivity -->
                    <a href="" id="iq-test-connectivity" style="text-transform: uppercase;" class="button button-primary button-medium disabled" title="please fill in all settings below first">
                        Test IQ <=> Woo connectivity
                    </a>

                    <!-- schedule major sync -->
                    <a href="" id="iq-schedule-major-sync" style="text-transform: uppercase;" class="button button-primary button-medium disabled" title="please fill in all settings below first">
                        schedule major sync (IQ to Woo)
                    </a>

                    <!-- schedule user sync -->
                    <a href="" id="iq-schedule-user-sync" style="text-transform: uppercase;" class="button button-primary button-medium disabled" title="please fill in all settings below first">
                        schedule user sync to IQ
                    </a>

                    <!-- schedule order sync -->
                    <a href="" id="iq-schedule-order-sync" style="text-transform: uppercase;" class="button button-primary button-medium disabled" title="please fill in all settings below first">
                        schedule order sync to IQ
                    </a>

                </span>
            <?php else : ?>

                <span id="iq-schedule-links" style="float: right; position: relative; bottom: 6px;">

                    <!-- test connectivity -->
                    <a href="" id="iq-test-connectivity" style="text-transform: uppercase;" class="button button-primary button-medium" title="click to test connectivity to IQ server">
                        Test IQ <=> Woo connectivity
                    </a>

                    <!-- schedule major sync -->
                    <a href="" id="iq-schedule-major-sync" style="text-transform: uppercase;" class="button button-primary button-medium" title="click to manually run a major synchronization from IQ to WooCommerce as soon as possible">
                        schedule major sync (IQ to Woo)
                    </a>

                    <!-- schedule user sync -->
                    <a href="" id="iq-schedule-user-sync" style="text-transform: uppercase;" class="button button-primary button-medium" title="click to manually sync new users to IQ">
                        schedule user sync to IQ
                    </a>

                    <!-- schedule order sync -->
                    <a href="" id="iq-schedule-order-sync" style="text-transform: uppercase;" class="button button-primary button-medium" title="click to manually sync new orders to IQ">
                        schedule order sync to IQ
                    </a>

                </span>

            <?php endif; ?>

        </h1>

        <?php
        // save settings
        if (isset($_POST['iq-save-settings'])) :

            // save settings
            $updated = update_option('iq_settings', maybe_serialize($_POST['iq-settings']));

            // display success message
            if (true === $updated) : ?>
                <div class="notice notice-success is-dismissible" style="left: -15px;">
                    <p>Settings saved successfully.</p>
                </div>

        <?php endif;

            // retrieve settings
            $settings = get_option('iq_settings');
            $settings = unserialize($settings);

        endif; ?>

        <p style="margin-bottom: -15px;">
            <b>
                <i>
                    Add your IQ Retail credentials below and synchronization settings below. All fields are required for the connection between IQ and WooCommerce to work successfully.
                </i>
            </b>
        </p>

        <form action="" method="post">

            <h4 style="width: 400px;background: lightgrey; padding: 10px; font-size: 15px; box-sizing: border-box; margin-top: 35px; box-shadow: 0px 2px 2px grey;">
                Rest API connection settings
            </h4>

            <!-- company number -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="company-no">
                    <i><b>IQ Company Number: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[company-no]" id="company-no" required value="<?php echo $settings['company-no']; ?>">
            </p>

            <!-- terminal number -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="terminal-no">
                    <i><b>IQ Terminal Number: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[terminal-no]" id="terminal-no" required value="<?php echo $settings['terminal-no']; ?>">
            </p>

            <!-- user number -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="user-no">
                    <i><b>IQ User Number: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[user-no]" id="user-no" required value="<?php echo $settings['user-no']; ?>">
            </p>

            <!-- user password -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="user-pass">
                    <i><b>IQ User Password: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[user-pass]" id="user-pass" required value="<?php echo $settings['user-pass']; ?>">
            </p>

            <!-- user password API key-->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="user-pass">
                    <i><b>IQ User Password API Key: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[user-pass-api-key]" id="user-pass-api-key" required value="<?php echo $settings['user-pass-api-key']; ?>">
            </p>

            <!-- passphrase -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="passphrase">
                    <i><b>IQ Partner Passphrase (optional):</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[passphrase]" id="passphrase" value="<?php echo $settings['passphrase']; ?>">
            </p>

            <!-- API port number -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="port-no">
                    <i><b>Rest API Port Number: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[port-no]" id="port-no" required value="<?php echo $settings['port-no']; ?>">
            </p>

            <!-- API host URL -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="host-url">
                    <i><b>Rest API Host URL: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="url" name="iq-settings[host-url]" id="host-url" required value="<?php echo $settings['host-url']; ?>">
            </p>

            <!-- API version number -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="api-version">
                    <i><b>Rest API Version Number: *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <input style="width: 400px;" type="text" name="iq-settings[api-version]" id="api-version" required value="<?php echo $settings['api-version']; ?>">
            </p>

            <h4 style="width: 400px; background: lightgrey; padding: 10px; font-size: 15px; box-sizing: border-box; margin-top: 35px; box-shadow: 0px 2px 2px grey;">
                Syncronisation settings
            </h4>

            <!-- enable auto major sync -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="enable-major-sync">
                    <i><b>Enable automatic periodic sync from IQ to Woo? *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <select style="width: 400px;" name="iq-settings[enable-major-sync]" id="enable-major-sync" required data-current="<?php echo $settings['enable-major-sync']; ?>">
                    <option value="">please select...</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                </select>
            </p>

            <!-- enable auto user sync -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="enable-user-sync">
                    <i><b>Enable automatic periodic user sync from Woo to IQ? *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <select style="width: 400px;" name="iq-settings[enable-user-sync]" id="enable-user-sync" required data-current="<?php echo $settings['enable-user-sync']; ?>">
                    <option value="">please select...</option>
                    <option value="yes">Yes</option>
                    <option selected value="no">No</option>
                </select>
            </p>

            <!-- enable auto order sync -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="enable-order-sync">
                    <i><b>Enable automatic periodic order sync from Woo to IQ? *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <select style="width: 400px;" name="iq-settings[enable-order-sync]" id="enable-order-sync" required data-current="<?php echo $settings['enable-order-sync']; ?>">
                    <option value="">please select...</option>
                    <option value="yes">Yes</option>
                    <option selected value="no">No</option>
                </select>
            </p>

            <!-- major sync interval -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="major-sync-interval">
                    <i><b>Major sync interval (IQ to Woo): *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <select style="width: 400px;" name="iq-settings[major-sync-interval]" id="major-sync-interval" required data-current="<?php echo $settings['major-sync-interval']; ?>">
                    <option value="">please select...</option>
                    <option value="21600">Every 6 hours</option>
                    <option value="43200">Every 12 hours</option>
                    <option value="86400">Every 24 hours</option>
                    <option value="172800">Every 48 hours</option>
                </select>
            </p>

            <!-- minor sync interval -->
            <p style="margin-bottom: 5px;">
                <label style="padding-left: 3px;" for="minor-sync-interval">
                    <i><b>Minor sync interval (Woo to IQ): *</b></i>
                </label>
            </p>
            <p style="margin-top: 0px;">
                <select style="width: 400px;" name="iq-settings[minor-sync-interval]" id="minor-sync-interval" required data-current="<?php echo $settings['minor-sync-interval']; ?>">
                    <option value="">please select...</option>
                    <option value="900">Every 15 minutes</option>
                    <option value="1800">Every 30 minutes</option>
                    <option value="2700">Every 45 minutes</option>
                    <option value="3600">Every 60 minutes</option>
                </select>
            </p>

            <p></p>

            <!-- save -->
            <p>
                <input style="width: 400px; margin-top: 15px;" type="submit" name="iq-save-settings" class="button button-primary button-large" value="Save IQ Settings">
            </p>

        </form>

    </div>

    <script>
        jQuery(document).ready(function($) {

            // set dropdown values if defined
            $('#major-sync-interval').val($('#major-sync-interval').data('current'));
            $('#minor-sync-interval').val($('#minor-sync-interval').data('current'));
            $('#enable-major-sync').val($('#enable-major-sync').data('current'));
            $('#enable-user-sync').val($('#enable-user-sync').data('current'));
            $('#enable-order-sync').val($('#enable-order-sync').data('current'));

            // manually schedule major sync (IQ -> Woo)
            $('#iq-schedule-major-sync').click(function(e) {
                e.preventDefault();

                var data = {
                    'action': 'iq_schedule_major_manual_sync',
                    '_ajax_nonce': '<?php echo wp_create_nonce('schedule manual major sync') ?>'
                };
                $.post(ajaxurl, data, function(response) {
                    alert(response);
                });

            });

            // manually schedule user sync (Woo -> IQ)
            $('#iq-schedule-user-sync').click(function(e) {
                e.preventDefault();

                var data = {
                    'action': 'iq_schedule_manual_sync_users',
                    '_ajax_nonce': '<?php echo wp_create_nonce('schedule manual sync users') ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    alert(response);
                });

            });

            // manually schedule order sync (Woo -> IQ)
            $('#iq-schedule-order-sync').click(function(e) {
                e.preventDefault();

                var data = {
                    'action': 'iq_schedule_manual_sync_orders',
                    '_ajax_nonce': '<?php echo wp_create_nonce('schedule manual sync orders') ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    alert(response);
                });

            });

            // test iq connectivity
            $('#iq-test-connectivity').click(function(e) {
                e.preventDefault();

                var data = {
                    'action': 'iq_test_connect',
                    '_ajax_nonce': '<?php echo wp_create_nonce('test iq connectivity') ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    alert(response);
                });

            });
        });
    </script>

<?php }
