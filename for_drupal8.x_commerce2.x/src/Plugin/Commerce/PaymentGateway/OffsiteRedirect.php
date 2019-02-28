<?php

  namespace Drupal\commerce_fondy\Plugin\Commerce\PaymentGateway;

  use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\commerce_fondy\PluginForm\OffsiteRedirect\FondyOffsiteForm;
  use Drupal\Core\Language\LanguageInterface;
  use Drupal\commerce_order\Entity\OrderInterface;
  use Symfony\Component\HttpFoundation\Request;
  use Drupal\commerce_order\Entity\Order;

  /**
   * Provides the Fondy offsite Checkout payment gateway.
   *
   * @CommercePaymentGateway(
   *   id = "fondy_redirect",
   *   label = @Translation("Fondy (Redirect to payment page)"),
   *   display_label = @Translation("Fondy"),
   *    forms = {
   *     "offsite-payment" = "Drupal\commerce_fondy\PluginForm\OffsiteRedirect\FondyOffsiteForm",
   *   },
   * )
   */
  class OffsiteRedirect extends OffsitePaymentGatewayBase {

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
    public function buildConfigurationForm(array $form,
                                           FormStateInterface $form_state) {
      $form = parent::buildConfigurationForm($form, $form_state);

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

      $languages = $this->getFondyLanguages()
        + [LanguageInterface::LANGCODE_NOT_SPECIFIED => $this->t('Language of the user')];
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
        'ua' => $this->t('Ukranian'),
        'en' => $this->t('English'),
        'pl' => $this->t('Polish'),
        'lv' => $this->t('Latvian'),
      ];
    }

    /**
     * @param OrderInterface $order
     * @param Request $request
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    public function onReturn(OrderInterface $order, Request $request) {

      $settings = [
        'secret_key' => $this->configuration['secret_key'],
        'merchant_id' => $this->configuration['merchant_id']
      ];
      $data = $request->request->all();
      list($orderId,) = explode(FondyOffsiteForm::ORDER_SEPARATOR, $data['order_id']);
      if ($this->isPaymentValid($settings, $data, $order) !== TRUE) {
        $this->messenger()->addMessage($this->t('Invalid Transaction. Please try again'), 'error');
        return $this->onCancel($order, $request);
      }
      else {
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->create([
          'state' => 'completed',
          'amount' => $order->getTotalPrice(),
          'payment_gateway' => $this->entityId,
          'order_id' => $orderId,
          'remote_id' => $data['payment_id'],
          'remote_state' => $data['order_status'],
        ]);
        $payment->save();
        $this->messenger()->addMessage(
          $this->t('Your payment was successful with Order id : @orderid and Transaction id : @payment_id',
            [
              '@orderid' => $order->id(),
              '@payment_id' => $data['payment_id']
            ]
          ));
      }
    }

    /**
     * @param Request $request
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    public function onNotify(Request $request) {
      $settings = [
        'secret_key' => $this->configuration['secret_key'],
        'merchant_id' => $this->configuration['merchant_id']
      ];
      $data = $request->request->all();
      if (!$data)
        $data = $request->getContent();
      list($orderId,) = explode(FondyOffsiteForm::ORDER_SEPARATOR, $data['order_id']);
      $order = Order::load($orderId);
      if ($this->isPaymentValid($settings, $data, $order) !== TRUE) {
        die($this->t('Invalid Transaction. Please try again'));
      }
      else {
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        if ($data['order_status'] == 'expired' or $data['order_status'] == 'declined') {
          $order->set('state', 'cancelled');
          $order->save();
        }
        $last = $payment_storage->loadByProperties([
          'payment_gateway' => $this->entityId,
          'order_id' => $orderId,
          'remote_id' => $data['payment_id']
        ]);
        if (!empty($last)) {
          $payment_storage->delete($last);
        }
        $payment = $payment_storage->create([
          'state' => 'completed',
          'amount' => $order->getTotalPrice(),
          'payment_gateway' => $this->entityId,
          'order_id' => $orderId,
          'remote_id' => $data['payment_id'],
          'remote_state' => $data['order_status'],
        ]);
        $payment->save();
        die('Ok');
      }
    }

    /**
     * @param $settings
     * @param $response
     *
     * @return bool
     */
    public function isPaymentValid($settings, $response, $order) {
      if (!$response) {
        return FALSE;
      }
      if ($settings['merchant_id'] != $response['merchant_id']) {
        return FALSE;
      }
      $transaction_currency = $response['currency'];
      $transaction_amount = $response['amount'] / 100;
      $order_currency = $order->getTotalPrice()->getCurrencyCode();
      $order_amount = $order->getTotalPrice()->getNumber();

      if (!$this->validateSum($transaction_currency, $order_currency,
        $transaction_amount, $order_amount)
      ) {
        return FALSE;
      }
      $responseSignature = $response['signature'];
      if (isset($response['response_signature_string'])) {
        unset($response['response_signature_string']);
      }
      if (isset($response['signature'])) {
        unset($response['signature']);
      }
      if (FondyOffsiteForm::getSignature($response, $settings['secret_key']) != $responseSignature) {
        return FALSE;
      }

      return TRUE;
    }

    /**
     * @param $transaction_currency
     * @param $order_currency
     * @param $transaction_amount
     * @param $order_amount
     *
     * @return bool
     */
    protected function validateSum($transaction_currency, $order_currency,
                                   $transaction_amount, $order_amount) {
      if ($transaction_currency != $order_currency) {
        return FALSE;
      }
      if ($transaction_amount != $order_amount) {
        return FALSE;
      }

      return TRUE;
    }

  }
