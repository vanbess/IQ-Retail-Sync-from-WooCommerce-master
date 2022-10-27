<?php

/**
 * Add metabox to product edit screen to manually sync order
 */

add_action('add_meta_boxes', function () {
    add_meta_box(
        'iq-sync-single-order',
        'Sync Order to IQ',
        'iq_sync_single_order',
        'shop_order',
        'side',
        'high'
    );
});


/**
 * Render metabox html
 */
function iq_sync_single_order() {

    global $post;
    $order_id = $post->ID;

    // uncomment below after testing
    $current_user_id = get_current_user_id();
    if ($current_user_id !== 3) :
?>
        <p style="color: red;"><i><b>***Currently Testing***</b></i></p>
    <?php
        return;
    endif;

    if (get_post_meta($order_id, '_iq_doc_number', true)) : ?>

        <!-- iq doc number -->
        <p><b><i>IQ document number for this order:</i></b></p>

        <h3><i><?php echo get_post_meta($order_id, '_iq_doc_number', true); ?></i></h3>

        <!-- sync order to iq -->
        <p><b><i>Click to resync this order to IQ (NOTE: will create duplicate order on IQ):</i></b></p>

        <button id="iq-sync-order" class="button button-primary button-large" data-nonce="<?php echo wp_create_nonce('iq sync woo order to iq'); ?>" style="width: 100%; margin-bottom: 0;">Sync Order To IQ</button>

    <?php else : ?>
        <!-- check order iq status -->
        <p><b><i>Check this order's IQ status:</i></b></p>

        <button id="iq-check-status" class="button button-primary button-large" data-nonce="<?php echo wp_create_nonce('iq check woo order status'); ?>" style="width: 100%;">Check IQ Status</button>

        <!-- sync order to iq -->
        <p><b><i>Click to sync this order to IQ:</i></b></p>

        <button id="iq-sync-order" class="button button-primary button-large" data-nonce="<?php echo wp_create_nonce('iq sync woo order to iq'); ?>" style="width: 100%; margin-bottom: 0;">Sync Order To IQ</button>
    <?php endif; ?>

    <!-- sync user to iq -->
    <p><b><i>Click to sync this customer to IQ:</i></b></p>

    <button id="iq-sync-user" class="button button-primary button-large" data-nonce="<?php echo wp_create_nonce('iq sync woo user to iq'); ?>" style="width: 100%; margin-bottom: 0;">Sync Customer To IQ</button>

    <!-- css -->
    <style>
        #iq-sync-single-order>div.inside {
            margin-bottom: 10px;
        }
    </style>

    <!-- js -->
    <script>
        'use strict';

        $ = jQuery;

        $(document).ready(function() {

            // **********************
            // check order iq status
            // **********************
            $('#iq-check-status').click(function(e) {
                e.preventDefault();

                $(this).text('Checking...');

                var data = {
                    'action': 'iq_check_order_status',
                    '_ajax_nonce': $(this).data('nonce'),
                    'order_id': '<?php echo $order_id; ?>'
                };

                $.post(ajaxurl, data, function(response) {

                    $('#iq-check-status').text('Check IQ Status');

                    // console.log(response);
                    alert(response);

                });

            });

            // *****************
            // sync order to iq
            // *****************
            $('#iq-sync-order').click(function(e) {
                e.preventDefault();

                $(this).text('Attempting sync...');

                var data = {
                    'action': 'iq_sync_order',
                    '_ajax_nonce': $(this).data('nonce'),
                    'order_id': '<?php echo $order_id; ?>'
                };

                $.post(ajaxurl, data, function(response) {

                    if (response.success === false) {
                        alert(response.data);
                    } else {
                        alert(response);
                        location.reload();
                    }

                    $('#iq-sync-order').text('Sync Order To IQ');
                });

            });

            // ****************
            // sync user to iq
            // ****************
            $('#iq-sync-user').click(function(e) {
                e.preventDefault();

                $(this).text('Attempting sync...');

                var data = {
                    'action': 'iq_sync_single_user',
                    '_ajax_nonce': $(this).data('nonce'),
                    'order_id': '<?php echo $order_id; ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    
                    if (response.success === false) {
                        alert(response.data);
                    } else {
                        alert(response);
                        location.reload();
                    }

                    $('#iq-sync-user').text('Sync Customer to IQ');
                });

            });
        });
    </script>

<?php }


/**
 * Check order status AJAX
 */
require_once __DIR__ . '/inc-mbox/ajax-check-order-iq-status.php';

/**
 * Sync order to IQ via AJAX
 */
require_once __DIR__ . '/inc-mbox/ajax-sync-order-to-iq.php';

/**
 * Sync user to IQ via AJAX
 */
require_once __DIR__ . '/inc-mbox/ajax-sync-user-to-iq.php';
