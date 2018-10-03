<?php

  namespace Drupal\commerce_fondy\PluginForm\OffsiteRedirect;

  use Drupal\commerce_order\Entity\Order;
  use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Url;
  use Drupal\Core\Language\LanguageInterface;

  class FondyOffsiteForm extends BasePaymentOffsiteForm {
    /**
     * @var string pay url
     */
    private $url = 'https://api.fondy.eu/api/checkout/redirect/';
    /**
     * additional cons
     */
    const ORDER_SEPARATOR = '#';
    const SIGNATURE_SEPARATOR = '|';

    /**
     * @param array              $form
     * @param FormStateInterface $form_state
     *
     * @return mixed
     */
    public function buildConfigurationForm(array $form,
                                           FormStateInterface $form_state) {

      $form = parent::buildConfigurationForm($form, $form_state);

      $payment = $this->entity;
      $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

      $configuration = $payment_gateway_plugin->getConfiguration();

      $redirect_method = 'post';
      $redirect_url = $this->url;
      $mid = $configuration['merchant_id'];
      $order_id = \Drupal::routeMatch()->getParameter('commerce_order')->id();
      $amount = round(number_format($payment->getAmount()
          ->getNumber(), 2, '.', '') * 100);
      $currency_code = $payment->getAmount()->getCurrencyCode();
      if ($payment->getOrder()->getCustomer()->isAnonymous() === FALSE) {
        $description = t('Customer: ') . $payment->getOrder()
            ->getCustomer()
            ->getAccountName() . '. ' . t('Order #: ') . $order_id;
        $subscriber_id = $payment->getOrder()->getCustomerId();
      }
      else {
        $description = t('Customer: anonymous');
        $subscriber_id = '';
      }
      $callbackurl = $payment_gateway_plugin->getNotifyUrl()->toString();
      $responseurl = Url::FromRoute('commerce_payment.checkout.return', [
        'commerce_order' => $order_id,
        'step' => 'payment'
      ], ['absolute' => TRUE])->toString();

      $order = Order::load($order_id);
      $address = $order->getBillingProfile()->address->first();
      // Get the language of credit card form.
      $language = $configuration['language'];
      if ($language == LanguageInterface::LANGCODE_NOT_SPECIFIED &&
        $customer = $order->getCustomer()
      ) {
        // Use account preferred language.
        $language = $customer->getPreferredLangcode();
      }
      $f_data = [
        'merchant_id' => $mid,
        'order_id' => $order_id . self::ORDER_SEPARATOR . time(),
        'order_desc' => $description,
        'amount' => $amount,
        'merchant_data' => json_encode([
          'subscriber_id' => $subscriber_id,
          'custom_field_customer_name' => $address->getGivenName(),
          'custom_field_customer_address' => $address->getAddressLine1(),
          'custom_field_customer_city' => $address->getLocality(),
          'custom_field_customer_country' => $address->getAdministrativeArea(),
          'custom_field_customer_state' => $address->getCountryCode(),
          'custom_field_customer_zip' => $address->getPostalCode(),
          'custom_field_sender_email' => $order->getEmail()
        ]),
        'currency' => $currency_code,
        'response_url' => $responseurl,
        'server_callback_url' => $callbackurl,
        'sender_email' => $order->getEmail(),
        'preauth' => $configuration['preauth'] ? 'Y' : 'N',
        'lang' => strtolower($language)
      ];

      $f_data['signature'] = self::getSignature($f_data,
        $configuration['secret_key']);

      return $this->buildRedirectForm($form, $form_state, $redirect_url,
        $f_data, $redirect_method);
    }

    /**
     * Signature generator
     * @param      $data
     * @param      $password
     * @param bool $encoded
     * @return string
     *
     *
     */
    public static function getSignature($data, $password, $encoded = TRUE) {
      $data = array_filter($data, function ($var) {
        return $var !== '' && $var !== NULL;
      });
      ksort($data);
      $str = $password;
      foreach ($data as $v) {
        $str .= self::SIGNATURE_SEPARATOR . $v;
      }
      if ($encoded) {
        return sha1($str);
      }
      else {
        return $str;
      }
    }
  }
