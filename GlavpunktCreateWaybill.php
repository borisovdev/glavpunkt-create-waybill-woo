<?php
/**
 * Send order from your woocommerece website to Glavpunkt.ru
 * author: Alexandr Borisov <burninghills@yandex.ru>
 */

require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

if ( is_plugin_active('glavpunkt/glavpunkt.php') ) {

    class GlavpunktCreateWaybill {
        
        public $DEV_MODE = false;
        private $host = 'glavpunkt.ru';
        private $order;
        public $waybill;
        private $from_punkt_ID = 'Sklad-SPB';
        
        public function __construct($order_id, $order_weight) {
            // Данные владельца для передачи информации
            $this->waybill = [
                'login' => 'login',
                'token' => 'YOUR_GLAVPUNKT_TOKEN'
            ];
            // Основные данные заказа
            $this->order = wc_get_order($order_id);
            $this->order_data = $this->order->get_data(); // Получаем массив с данными о заказе
            $this->order_weight = $order_weight;
            $this->DEV_MODE ? var_dump($this->order_data) : false; // Смотрим полный объект заказа на странице thankyou
            //Собираем поля с данными о заказе
            $this->buyer_firstname = $this->order_data['billing']['first_name']; //Имя
            $this->buyer_lastname = $this->order_data['billing']['last_name']; //Фамилия
            $this->buyer_fullname = sprintf( '%1$s %2$s', $this->buyer_firstname, $this->buyer_lastname ); // Полное имя
            $this->buyer_phone = $this->order_data['billing']['phone']; // Номер телефона
            $this->buyer_email = $this->order_data['billing']['email']; // Email
            $this->total_price = $this->order_data['total']; // Полная стоймость заказа
            $this->total_weight = $this->order_weight; // Полный вес заказа
            $this->insurance_price = $this->order_data['total'] - $this->order_data['shipping_total'];
            $this->payment_method = $this->order_data['payment_method_title']; // Тип платежа
            $this->cityFrom = get_option('woocommerce_store_city');// Город, в котором расположен магазин
            $this->cityTo = $this->order_data['billing']['city']; // Город, куда поедет заказ
            $this->postcode = $this->order_data['billing']['postcode']; // Индекс
            $this->buyer_address = $this->order_data['billing']['address_1']; // Адрес доставки
            $this->post_address = sprintf( '%1$s, %2$s', $this->postcode, $this->buyer_address );
            //Собираем кастомные поля
            $this->order_id = $this->order->get_id(); // Получаем ID заказа
            $this->chosen_method = WC()->session->get('chosen_shipping_methods')[0]; //Выбранный метод доставки
            $this->pvz_id = get_post_meta( $order_id, 'pvz_id', true ); // ID указанного клиентом пункта выдачи
            $this->delivery_date = get_post_meta( $order_id, 'shipping_date', true ); // Время доставки (при курьерской доставке)            
        }

        /**
         * Формируем параметры отправки
         * @return array
         */
        private function createShipmentOptions() {
            $shipment_options = [];
            $shipment_options += [
                'skip_existed' => 1, //Если заказ создан, не создаем новый
                'method' => 'self_delivery', //Самопривоз заказа в пункт
                'punkt_id' => $this->from_punkt_ID
            ];

            return $shipment_options;
        }

        /**
         * Формируем данные о заказе
         * @return array
         */
        private function createOrderFields() {

            $order_fields = []; //Будет передаваться в orders
        
            //Если курьерская доставка
            if ( $this->chosen_method == 'glavpunkt_courier' ) {

                $order_fields += [
                    [
                        'serv' => 'курьерская доставка',
                        'sku' => 'COURIER-'.$this->order_data['id'], //string 3-35 symbols
                        'price' => $this->total_price,
                        'insurance_val' => $this->insurance_price,
                        'buyer_phone' => $this->buyer_phone,
                        'buyer_fio' => $this->buyer_fullname,
                        'buyer_email' => $this->buyer_email,
                        'payment_method' => $this->payment_method,
                        'weight' => $this->total_weight,
                        'delivery' => [
                            'city' => $this->cityTo,
                            'address' => $this->buyer_address,
                            'date' => $this->delivery_date,
                        ]                    
                    ]
                ];
            }
            //Если доставка почтой
            elseif ( $this->chosen_method == 'glavpunkt_post' ) {
                $order_fields += [
                    [
                        'serv' => 'почта',
                        'sku' => 'POST-'.$this->order_data['id'],
                        'price' => $this->total_price,
                        'insurance_val' => $this->insurance_price,
                        'buyer_fio' => $this->buyer_fullname,
                        'buyer_phone' => $this->buyer_phone,
                        'buyer_email' => $this->buyer_email,
                        'payment_method' => $this->payment_method,
                        'weight' => $this->total_weight,
                        'pochta' => [
                            'address' => $this->post_address,
                            'index' => $this->postcode
                        ]                    
                    ]
                ];
            }
            //Если выдача на пункте самовывоза
            elseif( $this->chosen_method == 'glavpunkt_pickup' ) {
                $order_fields += [
                    [
                        'serv' => 'выдача',
                        'sku' => 'PICKUP-'.$this->order_data['id'],
                        'pvz_id' => $this->pvz_id,
                        'price' => $this->total_price,
                        'insurance_val' => $this->insurance_price,
                        'buyer_fio' => $this->buyer_fullname,
                        'buyer_phone' => $this->buyer_phone,
                        'buyer_email' => $this->buyer_email,
                        'payment_method' => $this->payment_method,   
                        'weight' => $this->total_weight                  
                    ]
                ];
            }

            return $order_fields;
        }

        /**
         * Формируем данные о товарах
         * @return array
         */
        private function createOrderParts() {
            $goods = [];

            $order_items = $this->order->get_items();

            foreach( $order_items as $item_id => $item ) {
                $item_data = $item->get_data();
                /// var_dump($item);
                $goods[] = [
                    'name' => $item_data['name'] . ' x' . $item_data['quantity'],
                    'price' => $item_data['total'],
                    'insurance_val' => $item_data['total']
                    // 'num' => $item_data['quantity']
                ];
            }

            // Добавляем стоимость доставке к номенклатуре заказа
            $goods[] = [
                'name' => 'Стоимость доставки',
                'price' => $this->order_data['shipping_total'],
                'insurance_val' => 0
            ];

            return $goods;
        }

        /**
         * Вывод сообщения об успехе
         */
        private function successMessage() {
            $user_docnum = $this->order_data['id'];
            $success_msg = 'Заказ успешно направлен в службу доставки Glavpunkt. Номер вашего заказа: '. $user_docnum . '';

            $notificator = '<div id="lustra_notificator" class="lustra-notificator-success">';
            $notificator .= $success_msg;
            $notificator .= '</div>';

            echo $notificator;            
        }

        /**
         * Вывод сообщения об ошибке
         */
        private function errorMessage() {
            $error_msg = sprintf( '%1$s %2$s', 'Не удалось передать заказ в службу доставки Glavpunkt.',   '<a href="'. esc_url( home_url( '/контактная-информация' ) ) .'">Свяжитесь с нами для уточнения деталей по доставке</a>'  );

            $notificator = '<div id="lustra_notificator" class="lustra-notificator-error">';
            $notificator .= $error_msg;
            $notificator .= '</div>';

            echo $notificator;
        }

        /**
         * Создаем накладную
         */
        public function createWaybill() {

            $this->waybill['shipment_options'] = $this->createShipmentOptions();
            $this->waybill['orders'] = $this->createOrderFields();
            $this->waybill['orders'][0]['parts'] = $this->createOrderParts();
        
            $send_waybill = $this->createShipment($this->waybill);

            //Выводим оповещение для пользователя
            $send_waybill['result'] == 'ok' ? $this->successMessage() : $this->errorMessage();

            //Смотрим ответ с сервера, если режим разработки включен
            // ( $this->DEV_MODE ) ? "\n\t" . print_r($send_waybill) : false;
        }

        /**
         * Создание поставки с заказами.
         */
        public function createShipment($data) {
            $res = $this->postJSON('/api/create_shipment', $data);

            return $res;
        }

        /**
         * Отправка HTTP-запроса POST к API Glavpunkt.ru
         */
        private function postJSON($url, $data = null) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'http://' . $this->host . $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            if (isset($data)) {
                $post_body = json_encode($data);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            }

            $out = curl_exec($curl);
            curl_close($curl);
            $res = json_decode($out, true);
            if (is_null($res)) {
                throw new Exception("Неверный JSON ответ: " . $out);
            }

            return $res;
        }

    }

}

?>