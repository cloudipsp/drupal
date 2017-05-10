<?php

class Fondy
{
    const ORDER_APPROVED = 'approved';
    const ORDER_DECLINED = 'declined';

    const ORDER_SEPARATOR = '#';

    const SIGNATURE_SEPARATOR = '|';

    const URL = "https://api.fondy.eu/api/checkout/redirect/";

    protected static $responseFields = array('rrn',
                                             'masked_card',
                                             'sender_cell_phone',
                                             'response_status',
                                             'currency',
                                             'fee',
                                             'reversal_amount',
                                             'settlement_amount',
                                             'actual_amount',
                                             'order_status',
                                             'response_description',
                                             'order_time',
                                             'actual_currency',
                                             'order_id',
                                             'tran_type',
                                             'eci',
                                             'settlement_date',
                                             'payment_system',
                                             'approval_code',
                                             'merchant_id',
                                             'settlement_currency',
                                             'payment_id',
                                             'sender_account',
                                             'card_bin',
                                             'response_code',
                                             'card_type',
                                             'amount',
                                             'sender_email');

    public static function getSignature($data, $password, $encoded = true)
    {
        $data = array_filter($data, function($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);

        $str = $password;
        foreach ($data as $k => $v) {
            $str .= self::SIGNATURE_SEPARATOR . $v;
        }

        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }

    public static function isPaymentValid($oplataSettings, $response)
    {
        list($orderId,) = explode(self::ORDER_SEPARATOR, $response['order_id']);
        $order = uc_order_load($orderId);

        if ($order === FALSE || uc_order_status_data($order->order_status, 'state') != 'in_checkout') {
            return t('An error has occurred during payment. Please contact us to ensure your order has submitted.');
        }

        if ($oplataSettings->merchant_id != $response['merchant_id']) {
            return t('An error has occurred during payment. Merchant data is incorrect.');
        }

        $originalResponse = $response;
        foreach ($response as $k => $v) {
            if (!in_array($k, self::$responseFields)) {
                unset($response[$k]);
            }
        }

        if (self::getSignature($response, $oplataSettings->secret_key) != $originalResponse['signature']) {
            return t('An error has occurred during payment. Signature is not valid.');
        }

        if (drupal_strtolower($originalResponse['sender_email']) !== drupal_strtolower($order->primary_email)) {
            uc_order_comment_save($order->order_id, 0, t('Customer used a different e-mail address during payment: !email', array('!email' => check_plain($originalResponse['sender_email']))), 'admin');
        }

        uc_order_comment_save($order->order_id, 0, "Order status: {$response['order_status']}", 'admin');

        return true;
    }
}
