<?php

namespace Drupal\commerce_fondy\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_fondy\PluginForm\OffsiteRedirect\FondyOffsiteForm;
use Drupal\commerce_log\LogStorageInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Fondy offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "fondy_redirect",
 *   label = @Translation("Fondy (redirect to payment page)"),
 *   display_label = @Translation("Fondy"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_fondy\PluginForm\OffsiteRedirect\FondyOffsiteForm",
 *   },
 * )
 */
class FondyOffsiteRedirect extends OffsitePaymentGatewayBase {

  /**
   * The log storage.
   */
  protected LogStorageInterface $logStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->logStorage = $container->get('entity_type.manager')->getStorage('commerce_log');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'merchant_id' => '',
        'secret_key' => '',
        'language' => 'en',
        'preauth' => FALSE,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Set mode to live by default.
    $form['mode']['#default_value'] = 'live';
    // Hide the mode configuration field by default.
    $form['mode']['#access'] = FALSE;

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#description' => $this->t('This is the Merchant ID from the Fondy portal.'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];

    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret key'),
      '#description' => $this->t('This is the private key from the Fondy portal.'),
      '#default_value' => $this->configuration['secret_key'],
      '#required' => TRUE,
    ];

    $form['response_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Response URL'),
      '#description' => $this->t('The URL of the merchant page to which the customer will be redirected in the browser after completing the payment. Example: <em>/thank-you</em> </br> If left blank, the url will be set as the default from Commerce Checkout Return.'),
      '#default_value' => $this->configuration['response_url'],
    ];

    // Build language selection.
    $languages = $this->getFondyLanguages() + [LanguageInterface::LANGCODE_NOT_SPECIFIED => $this->t('Language of the user')];
    $form['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#description' => $this->t('The language for the credit card form.'),
      '#options' => $languages,
      '#default_value' => $this->configuration['language'],
    ];

    $form['preauth'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('preauth'),
      '#description' => $this->t('Enable preauth transactions.'),
      '#default_value' => $this->configuration['preauth'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['response_url'] = $values['response_url'];
      $this->configuration['secret_key'] = $values['secret_key'];
      $this->configuration['language'] = $values['language'];
      $this->configuration['preauth'] = $values['preauth'];
    }
  }

  /**
   * Returns an array of languages supported by Fondy.
   *
   * @return array
   *   Array with key being language codes, and value being names.
   */
  public function getFondyLanguages() {
    return [
      'ru' => $this->t('Russian'),
      'uk' => $this->t('Ukrainian'),
      'en' => $this->t('English'),
      'pl' => $this->t('Polish'),
      'lv' => $this->t('Latvian'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $settings = [
      'secret_key' => $this->configuration['secret_key'],
      'merchant_id' => $this->configuration['merchant_id'],
    ];

    // Get the request data.
    if ($request->request->all()) {
      $data = $request->request->all();
    }

    if (!empty($data)) {
      $data = $_POST;
    }

    if (!empty($data)) {
      // Get order id.
      [$order_id] = explode(FondyOffsiteForm::ORDER_SEPARATOR, $data['order_id']);
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $order_storage = $this->entityTypeManager->getStorage('commerce_order');
      /** @var \Drupal\commerce_order\Entity\Order $order */
      $order = $order_storage->load($order_id);

      // Check payment valid.
      if ($this->isPaymentValid($settings, $data, $order) !== TRUE) {
        \Drupal::logger('commerce_fondy')->warning('Order: #@order_id. Payment is not valid', ['@order_id' => $order_id]);
        die($this->t('Invalid Transaction. Please try again'));
      }
      else {
        /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment_array = $payment_storage->loadByProperties(['order_id' => $order->id()]);
        /** @var \Drupal\commerce_payment\Entity\Payment $payment */
        $payment = reset($payment_array);
        // Get the capture status.
        $data_additional_info = json_decode($data['additional_info'], true);
        $capture_status = $data_additional_info['capture_status'] ?? '';

        // Stop the process if the order at the Fondy checkout is in process or expired.
        $order_status = $data['order_status'] ?? '';
        if (($order_status == 'processing') || ($order_status == 'expired')) {
          return;
        }

        if (!empty($payment)) {
          switch ($data['order_status']) {
            case 'reversed':
              // Reformat amount.
              // Remove 2 last zero from the reversal_amount.
              $reversal_amount = substr($data['reversal_amount'], 0, -2);
              // Refund.
              $this->refundPaymentProcess($payment, new Price($reversal_amount, $data['currency']));
              break;

            case 'approved':
              // Get the order payment state value.
              // Check if the state order is authorization.
              $payment_state = $payment->getState();
              $payment_state_value = $payment_state->getValue()['value'] ?? '';

              if ($payment_state_value == 'authorization') {
                // Partially refund process.
                if (!empty($data['reversal_amount']) && empty($capture_status)) {
                  // Reformat amount.
                  // Remove 2 last zero from the reversal_amount.
                  $reversal_amount = substr($data['reversal_amount'], 0, -2);
                  // Refund.
                  $this->refundPaymentProcess($payment, new Price($reversal_amount, $data['currency']));
                }

                // Capture payment process.
                if (empty($capture_status)) {
                  return;
                }

                // Get the capture amount.
                $capture_amount = '';
                if (!empty($data_additional_info['capture_amount'])) {
                  $capture_amount = $data_additional_info['capture_amount'];
                }

                // Capture process.
                // Go to the capture amount.
                if ($capture_status == 'captured') {
                  $this->capturePaymentProcess($payment, new Price($capture_amount, $data['currency']));
                }
              }
              break;
          }
        }
        else {
          // Create payment.
          $this->paymentCreate($order, $data);

          // Transition order to state `completed`.
          $order->set('state', 'completed');
          $order->save();
        }
      }
    }
  }

  /**
   * Is payment valid.
   */
  public function isPaymentValid($settings, $response, Order $order) {
    if (!$response) {
      \Drupal::logger('commerce_fondy')->warning('Response is empty');
      return FALSE;
    }
    if ($settings['merchant_id'] != $response['merchant_id']) {
      \Drupal::logger('commerce_fondy')->warning('merchant_id do not match');
      return FALSE;
    }

    $transaction_currency = $response['currency'];
    $transaction_amount = $response['amount'] / 100;
    $order_currency = $order->getTotalPrice()->getCurrencyCode();
    $order_amount = $order->getTotalPrice()->getNumber();

    if (!$this->validateSum($transaction_currency, $order_currency,
      $transaction_amount, $order_amount)
    ) {
      \Drupal::logger('commerce_fondy')->notice('Sum do not match');
      return FALSE;
    }

    $response_signature = $response['signature'];

    if (isset($response['response_signature_string'])) {
      unset($response['response_signature_string']);
    }

    if (isset($response['signature'])) {
      unset($response['signature']);
    }

    if (FondyOffsiteForm::getSignature($response, $settings['secret_key']) != $response_signature) {
      \Drupal::logger('commerce_fondy')->notice('Signatures do not match');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validate sum.
   */
  public function validateSum($transaction_currency, $order_currency, $transaction_amount, $order_amount) {
    if ($transaction_currency != $order_currency) {
      return FALSE;
    }
    if ($transaction_amount != $order_amount) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * The payment refund process.
   */
  public function refundPaymentProcess(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
      $order = $payment->getOrder();
      $order->set('state', 'canceled');
      $order->save();
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * The payment capture process.
   */
  public function capturePaymentProcess(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * Creation of payment according to capture status.
   */
  public function paymentCreate($order, $data) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    $payment_storage->create([
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $data['payment_id'],
      'remote_state' => $data['order_status'],
    ])->save();
  }

}
