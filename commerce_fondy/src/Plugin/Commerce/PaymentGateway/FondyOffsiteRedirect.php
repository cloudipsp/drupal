<?php

namespace Drupal\commerce_fondy\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_fondy\PluginForm\OffsiteRedirect\FondyOffsiteForm;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
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
      '#description' => $this->t('The URL of the merchant page to which the customer will be redirected in the browser after completing the payment. Example: <em>/thank-you</em> </br> If left blank, the url will be set as the default from the Fondy API.'),
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
  protected function getFondyLanguages() {
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
  public function onReturn(OrderInterface $order, Request $request) {
    $settings = [
      'secret_key' => $this->configuration['secret_key'],
      'merchant_id' => $this->configuration['merchant_id'],
    ];

    // Get the request data.
    $data = $request->request->all();
    // Get order id.
    [$order_id] = explode(FondyOffsiteForm::ORDER_SEPARATOR, $data['order_id']);

    // Payment validation check.
    if ($this->isPaymentValid($settings, $data, $order) !== TRUE) {
      $this->messenger()->addMessage($this->t('Invalid Transaction. Please try again'), 'error');

      return $this->onCancel($order, $request);
    }
    else {
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment_storage->create([
        'state' => 'completed',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->parentEntity->id(),
        'order_id' => $order_id,
        'remote_id' => $data['payment_id'],
        'remote_state' => $data['order_status'],
      ])->save();

      // Successful order.
      $this->messenger()->addMessage(
        $this->t('Your payment was successful with Order id : @orderid and Transaction id : @payment_id',
          [
            '@orderid' => $order->id(),
            '@payment_id' => $data['payment_id'],
          ]
        )
      );
    }
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
    $data = $_POST;
    if (!empty($data)) {
      // Get order id.
      [$order_id] = explode(FondyOffsiteForm::ORDER_SEPARATOR, $data['order_id']);
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $order_storage = $this->entityTypeManager->getStorage('commerce_order');
      /** @var \Drupal\commerce_order\Entity\Order $order */
      $order = $order_storage->load($order_id);

      // Check payment valid.
      if ($this->isPaymentValid($settings, $data, $order) !== TRUE) {
        die($this->t('Invalid Transaction. Please try again'));
      }
      else {
        /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        if ($data['order_status'] == 'expired' || $data['order_status'] == 'declined') {
          $order->set('state', 'cancelled');
          $order->save();
        }

        $last = $payment_storage->loadByProperties([
          'payment_gateway' => $this->parentEntity->id(),
          'order_id' => $order_id,
          'remote_id' => $data['payment_id'],
        ]);

        if (!empty($last)) {
          $payment_storage->delete($last);
        }

        $payment_storage->create([
          'state' => 'completed',
          'amount' => $order->getTotalPrice(),
          'payment_gateway' => $this->parentEntity->id(),
          'order_id' => $order_id,
          'remote_id' => $data['payment_id'],
          'remote_state' => $data['order_status'],
        ])->save();
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
  protected function validateSum($transaction_currency, $order_currency, $transaction_amount, $order_amount) {
    if ($transaction_currency != $order_currency) {
      return FALSE;
    }
    if ($transaction_amount != $order_amount) {
      return FALSE;
    }

    return TRUE;
  }

}
