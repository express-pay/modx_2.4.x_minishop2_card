<?php

if (!class_exists('msPaymentInterface')) {
    require_once dirname(dirname(dirname(__FILE__))) . '/model/minishop2/mspaymenthandler.class.php';
}

class Card extends msPaymentHandler implements msPaymentInterface
{
    public $modx;

    const CURRENCY = 933;

    function __construct(xPDOObject $object, $config = array())
    {
        $this->modx = &$object->xpdo;
    }

    //Метод принятия заказа
    public function send(msOrder $order)
    {
        $miniShop2 = $this->modx->getService('minishop2');

        $miniShop2->loadCustomClasses('payment');

        $id = $order->get('id');

        $id_resource = $this->modx->getOption('EXPRESS_PAY_RESOURCE_ID');

        $baseUrl = "https://api.express-pay.by/v1/";

        if ($this->modx->getOption('EXPRESS_PAY_TEST_MODE'))
            $baseUrl = "https://sandbox-api.express-pay.by/v1/";

        $url = $baseUrl . "web_cardinvoices";

        $request_params = $this->getInvoiceParam($order);

        $response = $this->sendRequestPOST($url, $request_params);

        $response = json_decode($response, true);

        $this->log_info('Response', print_r($response, 1));

        $home_url =$this->modx->getOption('site_url');

        if ($response['Errors']) {
            $output_error =
                '<br />
            <h3>Ваш номер заказа: ##ORDER_ID##</h3>
            <p>При выполнении запроса произошла непредвиденная ошибка. Пожалуйста, повторите запрос позже или обратитесь в службу технической поддержки магазина</p>
            <input type="button" value="Продолжить" onClick=\'location.href="##HOME_URL##"\'>';

            $output_error = str_replace('##ORDER_ID##', $id,  $output_error);

            $output_error = str_replace('##HOME_URL##', $home_url,  $output_error);

            $res = $this->modx->getObject('modResource', $id_resource);

            $res->setContent($output_error);

            $res->save();

            $key = $res->getCacheKey();
            $cache = $this->modx->cacheManager->getCacheProvider($this->modx->getOption('cache_resource_key', null, 'resource'));
            $cache->delete($key, array('deleteTop' => true));
            $cache->delete($key);

            $url = $this->modx->makeUrl($id_resource);

            $miniShop2->changeOrderStatus($id, 4);

            return $this->success('', array('redirect' => $url));
        } else {
            return $this->success('', array('redirect' => $response['FormUrl']));
        }
    }

    // Отправка POST запроса
    public function sendRequestPOST($url, $params)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    //Получение данных для JSON
    public function getInvoiceParam(msOrder $order)
    {
        $id = $order->get('id');
        $amount = number_format($order->get('cost'), 2, ',', '');

        $request = array(
            "ServiceId"          => $this->modx->getOption('EXPRESS_PAY_SERVICE_ID_CARD'),
            "AccountNo"          => $id,
            "Expiration"         => '',
            "Amount"             => $amount,
            "Currency"           => self::CURRENCY,
            "Info"               => "Покупка в магазине",
            "ReturnUrl"          => '',
            "FailUrl"            => '',
            "Language"           => 'ru',
            "SessionTimeoutSecs" => 1200,
            "ReturnType"         => 'json'
        );

        $request['Signature'] = $this->compute_signature($request, $this->modx->getOption('EXPRESS_PAY_SECRET_WORD_CARD'));

        return $request;
    }

    //Вычисление цифровой подписи
    public function compute_signature($request_params, $secret_word)
    {
        $secret_word = trim($secret_word);
        $normalized_params = array_change_key_case($request_params, CASE_LOWER);
        $api_method = array(
            "serviceid",
            "accountno",
            "expiration",
            "amount",
            "currency",
            "info",
            "returnurl",
            "failurl",
            "language",
            "sessiontimeoutsecs",
            "expirationdate",
            "returntype"
        );

        $this->log_info('normalized_params', print_r($secret_word, 1));

        $result = $this->modx->getOption('EXPRESS_PAY_TOKEN_CARD');

        foreach ($api_method as $item)
            $result .= (isset($normalized_params[$item])) ? $normalized_params[$item] : '';

        $this->log_info('compute_signature', 'RESULT - ' . $result);

        $hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

        return $hash;
    }


    private function log_info($name, $message)
    {
        $this->log($name, "INFO", $message);
    }

    private function log($name, $type, $message)
    {
        $log_url = dirname(__FILE__) . '/log';

        if (!file_exists($log_url)) {
            $is_created = mkdir($log_url, 0777);

            if (!$is_created)
                return;
        }

        $log_url .= '/express-pay-' . date('Y.m.d') . '.log';

        file_put_contents($log_url, $type . " - IP - " . $_SERVER['REMOTE_ADDR'] . "; DATETIME - " . date("Y-m-d H:i:s") . "; USER AGENT - " . $_SERVER['HTTP_USER_AGENT'] . "; FUNCTION - " . $name . "; MESSAGE - " . $message . ';' . PHP_EOL, FILE_APPEND);
    }
}
