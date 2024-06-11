<?php

namespace Drupal\commerce_fondy\PluginForm\OffsiteRedirect;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides an offsite-payment form "Fondy (redirect to payment page)".
 */
class FondyOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * The redirect methods.
   */
  const REDIRECT_GET = 'get';
  const REDIRECT_POST = 'post';

  /**
   * The Fondy endpoint redirect.
   *
   * @var string
   */
  const FONDY_ENDPOINT = 'https://pay.fondy.eu/api/checkout/redirect/';

  /**
   * The order separator.
   */
  const ORDER_SEPARATOR = '#';

  /**
   * The signature separator.
   */
  const SIGNATURE_SEPARATOR = '|';

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $configuration = $payment_gateway_plugin->getConfiguration();

    // Payment gateway configurations.
    $mid = str_replace(' ', '', $configuration['merchant_id']);
    $secret_key = $configuration['secret_key'];
    $order_id = $payment->getOrderId();
    $response_url = $this->generateResponseUrl($configuration['response_url'], $order_id);
    $preauth = $configuration['preauth'];

    // Payment data.
    $amount = round(
      number_format(
        $payment
          ->getAmount()
          ->getNumber(),
        2,
        '.',
        ''
      ) * 100
    );
    $currency_code = $payment
      ->getAmount()
      ->getCurrencyCode();
    $order_id = $payment->getOrderId();

    // Order and billing address.
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $payment->getOrder();
    $billing_address = $order->getBillingProfile()->get('address')->first();

    // Url values.
    $redirect_url = self::FONDY_ENDPOINT;
    $callback_url = $payment_gateway_plugin->getNotifyUrl()->toString();

    // Build order description and subscriber id.
    $description = sprintf(
      "%s%s",
      $this->t('Order #'),
      $order_id);
    $subscriber_id = $order->getCustomerId();

    // Build the data array.
    $f_data = [
      'merchant_id' => $mid,
      'order_id' => $order_id . self::ORDER_SEPARATOR . time(),
      'order_desc' => $description,
      'amount' => $amount,
      'merchant_data' => json_encode([
        'subscriber_id' => $subscriber_id,
        'custom_field_customer_name' => $billing_address->getGivenName(),
        'custom_field_customer_address' => $billing_address->getAddressLine1(),
        'custom_field_customer_city' => $billing_address->getLocality(),
        'custom_field_customer_country' => $billing_address->getAdministrativeArea(),
        'custom_field_customer_state' => $billing_address->getCountryCode(),
        'custom_field_customer_zip' => $billing_address->getPostalCode(),
        'custom_field_sender_email' => $order->getEmail(),
      ]),
      'currency' => $currency_code,
      'response_url' => $response_url,
      'server_callback_url' => $callback_url,
      'sender_email' => '',
      'preauth' => $preauth ? 'Y' : 'N',
      'reservation_data' => $this->getReservationData($order),
    ];

    // Build signature.
    $f_data['signature'] = self::getSignature($f_data, $secret_key);

    return $this->buildRedirectForm(
      $form,
      $form_state,
      $redirect_url,
      $f_data,
      self::REDIRECT_POST
    );
  }

  /**
   * Build the anti-fraud parameters.
   *
   * @param \Drupal\commerce_order\Entity\Order $order
   *   Commerce order of entity.
   *
   * @return string
   *   Return the encoded reservation data.
   */
  public function getReservationData(Order $order): string {
    /** @var \Drupal\profile\Entity\Profile $order_billing_data */
    $order_billing_profile = $order->getBillingProfile();

    $reservation_data = [
      'cms_name' => 'Drupal',
      'cms_version' => \Drupal::VERSION,
      'shop_domain' => Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(),
      'path' => $_SERVER['HTTP_REFERER'] ?? '',
      'products' => $this->getReservationDataProducts($order->getItems()),
      'account' => $order->getEmail(),
      'customer_name' => sprintf(
        "%s %s",
        $order_billing_profile->get('address')->first()->get('given_name')->getValue(),
        $order_billing_profile->get('address')->first()->get('family_name')->getValue()
      ),
      'customer_address' => sprintf(
        '%s %s',
        $order_billing_profile->get('address')->first()->get('address_line1')->getValue(),
        $order_billing_profile->get('address')->first()->get('locality')->getValue()
      ),
      'customer_state' => $order_billing_profile->get('address')->first()->get('dependent_locality')->getValue() ?? '',
      'customer_country' => $order_billing_profile->get('address')->first()->get('country_code')->getValue(),
    ];

    return base64_encode(json_encode($reservation_data));
  }

  /**
   * Build reservation data products.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_items
   *   The order items.
   *
   * @return array
   *   Return the products array.
   */
  public function getReservationDataProducts($order_items): array {
    $reservation_data_products = [];

    try {
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      foreach ($order_items as $order_item) {
        /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation */
        $product_variation = $order_item->getPurchasedEntity();

        // Build reservation products data.
        $reservation_data_products[] = [
          'id' => $product_variation->getProductId(),
          'name' => $product_variation->getTitle(),
          'price' => number_format($product_variation->getPrice()->getNumber(), 2, '.', ''),
          'total_amount' => number_format($order_item->getTotalPrice()->getNumber(), 2, '.', ''),
          'quantity' => $order_item->getQuantity(),
        ];
      }
    }
    catch (\Exception $e) {
      $reservation_data_products['error'] = $e->getMessage();
      \Drupal::logger('commerce_fondy')->error($e->getMessage());
    }

    return $reservation_data_products;
  }

  /**
   * Signature generator.
   */
  public static function getSignature($data, $password, bool $encoded = TRUE): string {
    // Filter data.
    $data = array_filter($data, 'strlen');

    // Rebuild data.
    ksort($data);
    $data = array_values($data);
    if (!empty($password)) {
      array_unshift($data, $password);
    }

    // Create signature string.
    $data = implode(self::SIGNATURE_SEPARATOR, $data);

    // Calculate the sha1 hash of a string.
    if ($encoded) {
      return sha1($data);
    }
    else {
      return $data;
    }
  }

  /**
   * Generate the response url.
   */
  public static function generateResponseUrl($response_url, $order_id) {
    if (empty($response_url)) {
      return Url::FromRoute('commerce_payment.checkout.return', [
        'commerce_order' => $order_id,
        'step' => 'payment'
      ], ['absolute' => TRUE])->toString();
    }

    if (str_starts_with($response_url, '/')) {
      $base_path = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
      $base_path = rtrim($base_path, '/');

      return $base_path . $response_url;
    }

    if (str_starts_with($response_url, 'http')) {
      return $response_url;
    }

    return Url::FromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order_id,
      'step' => 'payment'
    ], ['absolute' => TRUE])->toString();
  }

}
