<?php

namespace Drupal\uc_fondy\Plugin\Ubercart\PaymentMethod;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\uc_order\OrderInterface;
use Drupal\uc_payment\OffsitePaymentMethodPluginInterface;
use Drupal\uc_payment\PaymentMethodPluginBase;

/**
 * Defines the fondy payment method.
 *
 * @UbercartPaymentMethod(
 *   id = "fondy",
 *   name = @Translation("fondy"),
 *   redirect = "\Drupal\uc_fondy\Form\fondyForm",
 * )
 */
class Fondy extends PaymentMethodPluginBase implements OffsitePaymentMethodPluginInterface {

	/**
	 * @var string payment url
	 */
	protected $url = 'https://api.fondy.eu/api/checkout/redirect/';
	/**
	 * @var string
	 */
	public static $signature_separator = '|';
	/**
	 * @var string
	 */
	public static $order_separator = '#';

	/**
	 * @param string $label
	 *
	 * @return mixed
	 */
	public function getDisplayLabel( $label ) {
		$build['label'] = [
			'#prefix'     => '<div class="uc-fondy">',
			'#plain_text' => $label,
			'#suffix'     => '</div>',
		];
		$build['image'] = [
			'#theme'      => 'image',
			'#uri'        => drupal_get_path( 'module', 'uc_fondy' ) . '/images/logo.png',
			'#alt'        => $this->t( 'fondy' ),
			'#attributes' => array( 'class' => array( 'uc-fondy-logo' ) )
		];

		return $build;
	}

	/**
	 * @return array
	 */
	public function defaultConfiguration() {
		return [
			'currency'    => 'EUR',
			'preauth'     => false,
			'language'    => 'en',
			'back_url'    => '',
			'secret_key'  => 'test',
			'merchant_id' => ''
		];
	}

	/**
	 * @param array $form
	 * @param FormStateInterface $form_state
	 *
	 * @return array
	 */
	public function buildConfigurationForm( array $form, FormStateInterface $form_state ) {
		$form['merchant_id'] = array(
			'#type'          => 'textfield',
			'#title'         => $this->t( 'Your Merchant ID' ),
			'#description'   => $this->t( 'Your mid from portal.' ),
			'#default_value' => $this->configuration['merchant_id'],
			'#size'          => 16,
		);
		$form['secret_key']  = array(
			'#type'          => 'textfield',
			'#title'         => $this->t( 'Secret word for order verification' ),
			'#description'   => $this->t( 'The secret word entered in your fondy settings page.' ),
			'#default_value' => $this->configuration['secret_key'],
			'#size'          => 256,
		);
		$form['preauth']     = array(
			'#type'          => 'checkbox',
			'#title'         => $this->t( 'Enable preauth.' ),
			'#default_value' => $this->configuration['preauth'],
		);
		$form['currency']    = array(
			'#type'          => 'select',
			'#title'         => $this->t( 'Currency preference' ),
			'#description'   => $this->t( 'Type of currency. Leave empty to shop currency.' ),
			'#options'       => array(
				'UAH' => $this->t( 'Ukrainian Hryvnia' ),
				'RUB' => $this->t( 'Russian Rouble' ),
				'USD' => $this->t( 'US Dollar' ),
				'EUR' => $this->t( 'Euro' ),
				'GBP' => $this->t( 'Pound sterling mandatory' ),
				''    => $this->t( 'Shop Currency' )
			),
			'#default_value' => $this->configuration['currency'],
		);
		$form['language']    = array(
			'#type'          => 'select',
			'#title'         => $this->t( 'Language preference' ),
			'#description'   => $this->t( 'Adjust language on fondy pages.' ),
			'#options'       => array(
				'en' => $this->t( 'English' ),
				'ru' => $this->t( 'Russian' ),
				'ua' => $this->t( 'Ukranian' ),
				'sp' => $this->t( 'Spanish' ),
				'lv' => $this->t( 'Latvian' ),
				'fr' => $this->t( 'French' )
			),
			'#default_value' => $this->configuration['language'],
		);
		$form['back_url']    = array(
			'#type'          => 'url',
			'#title'         => $this->t( 'Instant notification settings URL' ),
			'#description'   => $this->t( 'Back/notify url. Example (http://{your_site}/fondy/back_url)' ),
			'#default_value' => Url::fromRoute( 'uc_fondy.notification', [], [ 'absolute' => true ] )->toString(),
			'#attributes'    => array( 'readonly' => 'readony' ),
		);

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitConfigurationForm( array &$form, FormStateInterface $form_state ) {
		$this->configuration['preauth']       = $form_state->getValue( 'preauth' );
		$this->configuration['checkout_type'] = $form_state->getValue( 'checkout_type' );
		$this->configuration['currency']      = $form_state->getValue( 'currency' );
		$this->configuration['language']      = $form_state->getValue( 'language' );
		$this->configuration['back_url']      = $form_state->getValue( 'back_url' );
		$this->configuration['secret_key']    = $form_state->getValue( 'secret_key' );
		$this->configuration['merchant_id']   = $form_state->getValue( 'merchant_id' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function cartProcess( OrderInterface $order, array $form, FormStateInterface $form_state ) {
		$session = \Drupal::service( 'session' );
		if ( null != $form_state->getValue( [ 'panes', 'payment', 'details', 'pay_method' ] ) ) {
			$session->set( 'pay_method', $form_state->getValue( [ 'panes', 'payment', 'details', 'pay_method' ] ) );
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function cartReviewTitle() {
		return $this->t( 'Credit card Fondy' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildRedirectForm( array $form, FormStateInterface $form_state, OrderInterface $order = null ) {

		$fondySettings = $this->configuration;
		$amount        = round( uc_currency_format( $order->getTotal(), false, false, '.' ) * 100 );

		$dataSettings = [
			'order_id'            => $order->id() . self::$order_separator . time(),
			'merchant_id'         => $fondySettings['merchant_id'],
			'order_desc'          => $this->t( 'Order Pay #' ) . $order->id(),
			'amount'              => $amount,
			'preauth'             => $fondySettings['preauth'] ? 'Y' : 'N',
			'currency'            => $fondySettings['currency'] != '' ? $fondySettings['currency'] : $order->getCurrency(),
			'server_callback_url' => $fondySettings['back_url'] == '' ? Url::fromRoute( 'uc_fondy.notification', [], [ 'absolute' => true ] )->toString() : $fondySettings['back_url'],
			'response_url'        => Url::fromRoute( 'uc_fondy.complete', [], [ 'absolute' => true ] )->toString(),
			'lang'                => $fondySettings['language'],
			'sender_email'        => Unicode::substr( $order->getEmail(), 0, 64 )
		];

		$dataSettings['signature'] = self::getSignature( $dataSettings, $fondySettings['secret_key'] );

		return $this->generateForm( $dataSettings, $this->url );
	}

	/**
	 * @param $data
	 * @param string $url
	 *
	 * @return mixed
	 */
	public function generateForm( $data, $url ) {
		$form['#action'] = $url;
		foreach ( $data as $k => $v ) {
			if ( ! is_array( $v ) ) {
				$form[ $k ] = array(
					'#type'  => 'hidden',
					'#value' => $v
				);
			} else {
				$i = 0;
				foreach ( $v as $val ) {
					$form[ $k . '[' . $i ++ . ']' ] = array(
						'#type'  => 'hidden',
						'#value' => $val
					);
				}
			}
		}
		$form['actions']           = [ '#type' => 'actions' ];
		$form['actions']['submit'] = [
			'#type'  => 'submit',
			'#value' => $this->t( 'Submit order' ),
		];

		return $form;
	}

	/**
	 * @param $data
	 * @param $password
	 * @param bool $encoded
	 *
	 * @return string
	 */
	public static function getSignature( $data, $password, $encoded = true ) {
		$data = array_filter( $data, function ( $var ) {
			return $var !== '' && $var !== null;
		} );
		ksort( $data );

		$str = $password;
		foreach ( $data as $k => $v ) {
			$str .= self::$signature_separator . $v;
		}

		if ( $encoded ) {
			return sha1( $str );
		} else {
			return $str;
		}
	}
}
