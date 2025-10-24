<?php
/**
 * @wordpress-plugin
 * Plugin Name: WooCommerce Faktura z NIP Field Toggle
 * Plugin URI:        https://ecomsystem.pl/
 * Description:       Dodaje pole NIP z checkboxem do faktury firmowej. Działa tylko z klasycznym checkoutem WooCommerce. Automatycznie wyłącza checkout blokowy Gutenberg.
 * Version:           1.2
 * Author:            EcomSystem
 * Author URI:        https://ecomsystem.pl
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins:  woocommerce
 */


defined('ABSPATH') or die('Direct access not allowed');

// Wymuś klasyczny checkout - plugin nie jest kompatybilny z WooCommerce Blocks (Gutenberg)
add_filter('woocommerce_blocks_is_checkout_block_enabled', '__return_false');

// Dodaj JavaScript do strony checkout
add_action('wp_footer', 'add_company_toggle_script');
function add_company_toggle_script() {
    if (!is_checkout()) return;
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Poczekaj aż formularz się załaduje
        setTimeout(function() {
            // Dodaj checkbox bezpośrednio przed polem nazwa firmy
            var checkbox = '<h3 id="company_checkbox_field" style="padding-top:0px;">' +
                '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">' +
                '<input id="company-checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" type="checkbox" name="is_company" value="1">' +
                ' <span style="text-transform: none; font-weight: 400;">Chcę fakturę z NIP na Firmę</span>' +
                '</label>' +
                '</h3>';
            
            // Wstaw checkbox po polu nazwisko (przed nazwa firmy)
            $('#billing_last_name_field').after(checkbox);
            
            // Ukryj pola firmowe na start
            $('#billing_company_field, #billing_nip_field').hide();
            
            // Toggle pól
            $('#company-checkbox').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#billing_company_field, #billing_nip_field').slideDown();
                } else {
                    $('#billing_company_field, #billing_nip_field').slideUp();
                    $('#billing_company, #billing_nip').val('');
                }
            });
            
            // Sprawdź czy pola mają wartości
            if ($('#billing_company').val() || $('#billing_nip').val()) {
                $('#company-checkbox').prop('checked', true).trigger('change');
            }
        }, 100);
    });
    </script>
    <?php
}

// Dodaj pole NIP do checkout
add_filter('woocommerce_checkout_fields', 'add_nip_billing_field');
function add_nip_billing_field($fields) {
    // Ustaw priorytet dla pola nazwa firmy
    $fields['billing']['billing_company']['priority'] = 30;
    
    // Dodaj pole NIP zaraz po nazwie firmy
    $fields['billing']['billing_nip'] = [
        'label'       => __('NIP', 'woocommerce'),
        'placeholder' => __('Numer Identyfikacji Podatkowej', 'woocommerce'),
        'required'    => false,
        'class'       => ['form-row-wide'],
        'clear'       => true,
        'priority'    => 31  // Zaraz po polu nazwa firmy
    ];
    return $fields;
}

// Funkcja walidująca NIP
function validate_nip($nip) {
    $nip = str_replace(['-', ' '], '', $nip);
    if (strlen($nip) !== 10 || !is_numeric($nip)) {
        return false;
    }
    $digits = array_map('intval', str_split($nip));
    $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $checksum = 0;
    for ($i = 0; $i < 9; $i++) {
        $checksum += $weights[$i] * $digits[$i];
    }
    $checksum = $checksum % 11;
    return $digits[9] == $checksum;
}

// Walidacja NIP przy checkout
add_action('woocommerce_checkout_process', 'validate_nip_field');
function validate_nip_field() {
    if (isset($_POST['billing_nip']) && !empty($_POST['billing_nip'])) {
        $nip = $_POST['billing_nip'];
        if (!validate_nip($nip)) {
            wc_add_notice(__('Proszę podać poprawny NIP', 'woocommerce'), 'error');
        }
    }
}

// Zapisz NIP przy tworzeniu zamówienia
add_action('woocommerce_checkout_update_order_meta', 'save_nip_field');
function save_nip_field($order_id) {
    if (isset($_POST['billing_nip'])) {
        $nip = sanitize_text_field($_POST['billing_nip']);
        if (!empty($nip) && validate_nip($nip)) {
            update_post_meta($order_id, '_billing_nip', $nip);
            
            // Zapisz również w profilu użytkownika
            $order = wc_get_order($order_id);
            $user_id = $order->get_user_id();
            if ($user_id) {
                update_user_meta($user_id, 'billing_nip', $nip);
            }
        }
    }
}

// Wyświetl edytowalne pole NIP w panelu admina
add_action('woocommerce_admin_order_data_after_billing_address', 'add_editable_nip_to_billing_info');
function add_editable_nip_to_billing_info($order) {
    $nip = get_post_meta($order->get_id(), '_billing_nip', true);
    ?>
    <div class="edit_address">
        <?php
        woocommerce_wp_text_input([
            'id'            => '_billing_nip',
            'label'         => __('NIP:', 'woocommerce'),
            'wrapper_class' => 'form-field-wide',
            'value'         => $nip,
            'placeholder'   => __('Numer Identyfikacji Podatkowej', 'woocommerce')
        ]);
        ?>
    </div>
    <?php
}

// Zapisz edytowany NIP w panelu admina
add_action('woocommerce_process_shop_order_meta', 'save_admin_nip_field');
function save_admin_nip_field($order_id) {
    if (isset($_POST['_billing_nip'])) {
        $nip = sanitize_text_field($_POST['_billing_nip']);
        if (!empty($nip)) {
            if (validate_nip($nip)) {
                update_post_meta($order_id, '_billing_nip', $nip);
            } else {
                // Opcjonalnie: dodaj błąd walidacji
                // WC_Admin_Meta_Boxes::add_error(__('Podano nieprawidłowy NIP', 'woocommerce'));
            }
        } else {
            delete_post_meta($order_id, '_billing_nip');
        }
    }
}

// Dodaj pole NIP do profilu użytkownika
add_filter('woocommerce_customer_meta_fields', 'add_nip_to_customer_profile');
function add_nip_to_customer_profile($fields) {
    $fields['billing']['fields']['billing_nip'] = [
        'label'       => __('NIP', 'woocommerce'),
        'description' => __('Numer Identyfikacji Podatkowej', 'woocommerce')
    ];
    return $fields;
}

// Walidacja NIP w profilu użytkownika
add_action('woocommerce_save_account_details_errors', 'validate_user_nip_field', 10, 1);
function validate_user_nip_field($args) {
    if (isset($_POST['billing_nip']) && !empty($_POST['billing_nip'])) {
        $nip = $_POST['billing_nip'];
        if (!validate_nip($nip)) {
            $args->add('error', __('Proszę podać poprawny NIP', 'woocommerce'));
        }
    }
}

// Automatyczne wypełnianie NIP z profilu użytkownika przy checkout
add_filter('woocommerce_checkout_get_value', 'populate_nip_from_user_profile', 10, 2);
function populate_nip_from_user_profile($value, $input) {
    if ($input === 'billing_nip' && is_user_logged_in()) {
        $user_id = get_current_user_id();
        $saved_nip = get_user_meta($user_id, 'billing_nip', true);
        if (!empty($saved_nip)) {
            return $saved_nip;
        }
    }
    return $value;
}

// Dodaj NIP do emaili
add_filter('woocommerce_email_order_meta_keys', 'add_nip_to_order_email');
function add_nip_to_order_email($keys) {
    $keys['NIP'] = '_billing_nip';
    return $keys;
}

