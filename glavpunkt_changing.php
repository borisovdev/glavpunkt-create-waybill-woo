<?php
/**
 * Убираем поля, добавленные плагином
 */
 remove_filter('woocommerce_checkout_fields', 'glavpunkt_custom_override_checkout_fields');

 
remove_action('woocommerce_review_order_before_cart_contents', 'glavpunkt_validate_order', 10);
remove_action('woocommerce_after_checkout_validation', 'glavpunkt_validate_order', 10);

add_action('woocommerce_review_order_before_cart_contents', 'glavpunkt_lustra_validate_order', 10);
add_action('woocommerce_after_checkout_validation', 'glavpunkt_lustra_validate_order', 10);

/**
 * Валидация полей для дальнейшего вывода способа доставки
 *
 * @param array $posted
 */
function glavpunkt_lustra_validate_order()
{

    $packages = WC()->shipping->get_packages(); // Берем содержимое доставки
    $chosen_method = WC()->session->get('chosen_shipping_methods')[0];

    // Начинаем сессию для добавления веса заказа
    session_name('wilmax_weight');
    session_start();
    // Присваиваем вес заказа глобально, для передачи в glavpunkt api
    $_SESSION['wilmax_cart_weight'] = WC()->cart->cart_contents_weight;
    session_write_close(); // Записываем сессию

    //  Перебираем методы доставки
    foreach ($packages as $i => $package) {

        // Если выбранный метод доставки не соответствует методам доставки glavpunkt, выходим из цикла
        if (
            $chosen_method !== 'glavpunkt_courier' &&
            $chosen_method !== 'glavpunkt_post' &&
            $chosen_method !== 'glavpunkt_pickup'
        ) {
            continue;
        }

        $weight = 0;

        // Перебираем товары, которые должны быть доставлены и присваиваем новый вес
        foreach ($package['contents'] as $itemId => $item) {
            $_product = $item['data'];
            $weight = $weight + abs($_product->get_weight()) * $item['quantity'];
        }

        // Выводим предупреждение, если превышено весовое ограничение glavpunkt
        if ($weight > 20) {
            $message = 'Вес заказа превышает 20 кг, для уточнения способа доставки, с Вами свяжется наш менеджер. Вес заказа: ' . $weight . ' kg';
            $messageType = "notice";
            if (!wc_has_notice($message, $messageType)) {
                wc_add_notice($message, $messageType);
            }
        }

        // Проверяем заполненные поля для glavpunkt
        $glavpunktShippingMethod = new WC_glavpunkt_Shipping_Method();

        if ($package['destination']['city'] === '') {
            $message = sprintf(
                'Не указан город, для расчёта стоимости доставки %s',
                $glavpunktShippingMethod->title
            );
            $messageType = "error";
            if (!wc_has_notice($message, $messageType)) {
                wc_add_notice($message, $messageType);
            }
        }

        if ($package['destination']['postcode'] === '') {
            $message = sprintf(
                'Не указан индекс или адрес, для расчёта стоимости доставки %s',
                $glavpunktShippingMethod->title
            );
            $messageType = "error";
            if (!wc_has_notice($message, $messageType)) {
                wc_add_notice($message, $messageType);
            }
        }
    }

}

/**
 * Посылаем накладную с собранными данными в систему Glavpunkt
 */
require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

if ( is_plugin_active('glavpunkt/glavpunkt.php') ) {

    // Отправляем api запрос к glavpunkt
    add_action('woocommerce_thankyou', 'sendGlavpunktDataAfterSubmit');
    function sendGlavpunktDataAfterSubmit($order_id) {
        session_name('wilmax_weight');
        session_start();
        // var_dump($_SESSION['wilmax_cart_weight']);

        $request = new GlavpunktCreateWaybill($order_id, $_SESSION['wilmax_cart_weight']);
        $request->createWaybill();

        ( $request->DEV_MODE ) ? "\n\t" . print_r($request->waybill) : false; //Смотрим передаваемый на сервер массив

        // Очищаем значение переменной, отвечающей за вес заказа
        unset($_SESSION['wilmax_cart_weight']);
    }    

    /**
     * Обновление полей заказа на ходу
     */

    // Добавляем часть формы к фрагменту
    add_filter( 'woocommerce_update_order_review_fragments', 'awoohc_add_update_form_billing', 99 );
    function awoohc_add_update_form_billing( $fragments ) {

        $checkout = WC()->checkout();
        ob_start();

        echo '<div class="woocommerce-billing-fields__field-wrapper">';

        $fields = $checkout->get_checkout_fields( 'billing' );
        foreach ( $fields as $key => $field ) {
            if ( isset( $field['country_field'], $fields[ $field['country_field'] ] ) ) {
                $field['country'] = $checkout->get_value( $field['country_field'] );
            }
            woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
        }

        echo '</div>';

        $art_add_update_form_billing              = ob_get_clean();
        $fragments['.woocommerce-billing-fields'] = $art_add_update_form_billing;

        return $fragments;
    }

    // Изменяем отображение полей при выборе метода доставки
    add_filter( 'woocommerce_checkout_fields', 'chosen_method_override_fields' );
    function chosen_method_override_fields( $fields ) {

        $chosen_method = WC()->session->get('chosen_shipping_methods')[0];

        if( $chosen_method === 'glavpunkt_courier' ) {
         $fields['billing']['shipping_date']['required'] = true;
         unset($fields['billing']['pvz_id']);
        }
        elseif( $chosen_method === 'glavpunkt_pickup' ) {
            $fields['billing']['pvz_id']['required'] = true;
            unset($fields['billing']['shipping_date']);
        }
        elseif( $chosen_method === 'glavpunkt_post' ) {
            unset($fields['billing']['pvz_id']);
            unset($fields['billing']['shipping_date']);
        }

        return $fields;
    }

    // Добавляем обновление полей заказа на ходу при выборе метода доставки
    add_action( 'wp_footer', 'air_update_on_shipping_change' );
    function air_update_on_shipping_change() {

        if( is_checkout() ) {
        ?>

        <script type="text/javascript">
            jQuery(document).ready(function($) {

                $(document.body).on('updated_checkout updated_shipping_method', function(event, xhr, data) {
                    $('input[name^="shipping_method"]').on('change', function () {
                        $('.woocommerce-billing-fields__field-wrapper').block({
                            message: null,
                            overlayCSS: {
                                background: '#ffffff',
                                'z-index': 1000000,
                                opacuty: 0.3
                            }
                        })
                    });
                    let first_name = $('#billing_first_name').val(),
                        last_name = $('#billing_last_name').val(),
                        phone = $('#billing_phone').val(),
                        email = $('#billing_email').val(),
                        punkt_id = $('#pvz_id').val(),
                        city = $('#billing_city').val(),
                        state = $('#billing_state').val(),
                        shipping_date = $('#shipping_date').val(),
                        postcode = $('#billing_postcode').val();
                    $('.woocommerce-billing-fields__field-wrapper').html(xhr.fragments['.woocommerce-billing-fields']);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="billing_first_name"]').val(first_name);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="billing_last_name"]').val(last_name);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="billing_phone"]').val(phone);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="billing_email"]').val(email);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="pvz_id"]').val(punkt_id);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="shipping_date"]').val(shipping_date);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="billing_postcode"]').val(postcode);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="billing_city"]').val(city);
                    $('.woocommerce-billing-fields__field-wrapper').find('input[name="billing_state"]').val(state);
                    $('.woocommerce-billing-fields__field-wrapper').unblock();
                });

            });
        </script>

        <?php
        }
    }

}
    
?>
