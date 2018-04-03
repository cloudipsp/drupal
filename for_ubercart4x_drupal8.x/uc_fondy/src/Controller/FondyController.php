<?php

namespace Drupal\uc_fondy\Controller;

use Drupal\uc_fondy\Plugin\Ubercart\PaymentMethod\Fondy;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_cart\CartManagerInterface;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_payment\Plugin\PaymentMethodManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller routines for uc_Fondy.
 */
class FondyController extends ControllerBase {

	/**
	 * The cart manager.
	 *
	 * @var \Drupal\uc_cart\CartManager
	 */
	protected $cartManager;
	/**
	 * @var
	 */
	protected $session;

	/**
	 * Constructs a FondyController.
	 *
	 * @param \Drupal\uc_cart\CartManagerInterface $cart_manager
	 *   The cart manager.
	 */
	public function __construct( CartManagerInterface $cart_manager ) {
		$this->cartManager = $cart_manager;
	}

	/**
	 * @param ContainerInterface $container
	 *
	 * @return static
	 */
	public static function create( ContainerInterface $container ) {
		return new static(
			$container->get( 'uc_cart.manager' )
		);
	}

	/**
	 * Final redirec status Fondy
	 *
	 * @param int $cart_id
	 * @param Request $request
	 *
	 * @return array
	 */
	public function complete( $cart_id = 0, Request $request ) {

		\Drupal::logger( 'uc_fondy' )->notice( 'Receiving new order notification for order @order_id.', [ '@order_id' => Html::escape( $request->request->get( 'order_id' ) ) ] );
		if ( ! $request->request->get( 'order_id' ) ) {
			throw new AccessDeniedHttpException();
		}

		list( $orderId, ) = explode( Fondy::$order_separator, $request->request->get( 'order_id' ) );
		$order = Order::load( $orderId );

		if ( ! $order || $order->getStateId() != 'in_checkout' ) {
			return [ '#plain_text' => $this->t( 'An error has occurred during payment. Please contact us to ensure your order has submitted.' ) ];
		}

		$plugin = \Drupal::service( 'plugin.manager.uc_payment.method' )->createFromOrder( $order );

		if ( $plugin->getPluginId() != 'fondy' ) {
			throw new AccessDeniedHttpException();
		}

		$configuration = $plugin->getConfiguration();
		$data          = array();
		foreach ( $request->request as $key => $value ) {
			$data[ $key ] = $value;
		}
		$valid = $this->isPaymentValid( $configuration, $data );

		if ( $valid == false ) {
			uc_order_comment_save( $order->id(), 0, $this->t( 'Attempted unverified Fondy completion for this order.' ), 'admin' );
			throw new AccessDeniedHttpException();
		}


		$address = $order->getAddress( 'billing' );
		$order->setAddress( 'billing', $address );
		$order->save();

		if ( Unicode::strtolower( $request->request->get( 'sender_email' ) ) !== Unicode::strtolower( $order->getEmail() ) ) {
			uc_order_comment_save( $order->id(), 0, $this->t( 'Customer used a different e-mail address during payment: @email', [ '@email' => Html::escape( $request->request->get( 'email' ) ) ] ), 'admin' );
		}

		if ( $request->request->get( 'order_status' ) == 'approved' && is_numeric( $request->request->get( 'amount' ) ) ) {
			$comment = $this->t( 'Paid by @type, fondy.eu order #@order.', [
				'@type'  => $this->t( 'Credit card' ),
				'@order' => Html::escape( $request->request->get( 'payment_id' ) )
			] );
			uc_payment_enter( $order->id(), 'fondy', $request->request->get( 'amount' ) / 100, $order->getOwnerId(), null, $comment );
			$order->setStatusId('payment_received')->save();
		} else {
			drupal_set_message( $this->t( 'Your order will be processed as soon as your payment clears at fondy.eu.' ) );
			uc_order_comment_save( $order->id(), 0, $this->t( '@type payment is pending approval at fondy.eu.', [ '@type' => $this->t( 'Credit card' ) ] ), 'admin' );
		}
		// Add a comment to let sales team know this came in through the site.
		uc_order_comment_save( $order->id(), 0, $this->t( 'Order created through website.' ), 'admin' );

		return $this->cartManager->completeSale( $order );
	}

	/**
	 * React on messages from Fondy.
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 *   The request of the page.
	 */
	public function notification( Request $request ) {
		$values = $request->request;
		\Drupal::logger( 'uc_fondy' )->notice( 'Received Fondy notification with following data: @data', [ '@data' => print_r( $values->all(), true ) ] );

		if ( $values->has( 'order_status' ) && $values->has( 'order_id' ) && $values->has( 'payment_id' ) ) {
			list( $orderId, ) = explode( Fondy::$order_separator, $values->get( 'order_id' ) );
			$order         = Order::load( $orderId );
			$plugin        = \Drupal::service( 'plugin.manager.uc_payment.method' )->createFromOrder( $order );
			$configuration = $plugin->getConfiguration();
			$valid         = $this->isPaymentValid( $configuration, $values->all() );

			if ( $valid == false ) {
				\Drupal::logger( 'uc_fondy' )->notice( 'Fondy notification #@num had a wrong Signs.', [ '@num' => $values->get( 'message_id' ) ] );
				die( 'Sign Incorrect' );
			}

			switch ( $values->get( 'order_status' ) ) {
				case 'approved':
					if($order->getStateId() != 'payment_received') {
						$comment = $this->t( 'Fondy transaction ID: @payment_id', [ '@payment_id' => $values->get( 'order_id' ) ] );
						uc_payment_enter( $orderId, 'fondy', $values->get( 'amount' ) / 100, $order->getOwnerId(), null, $comment );
						uc_order_comment_save( $orderId, 0, $this->t( 'Fondy reported a payment of @amount @currency.', [
							'@amount'   => uc_currency_format( $values->get( 'amount' ) / 100, false ),
							'@currency' => $values->get( 'currency' )
						] ) );
					}
					break;
				case 'processing':
					break;
				case 'declined':
					$order->setStatusId( 'canceled' )->save();
					uc_order_comment_save( $orderId, 0, $this->t( 'Order have not passed Fondy declined.' ) );
					die( 'canceled' );
					break;

				case 'expired':
					$order->setStatusId( 'canceled' )->save();
					uc_order_comment_save( $orderId, 0, $this->t( 'Order have not passed Fondy expired.' ) );
					die( 'canceled' );
					break;
			}
		}
		die( 'ok' );
	}

	private function isPaymentValid( $settings, $response ) {

		if ( $settings['merchant_id'] != $response['merchant_id'] ) {
			return false;
		}

		$responseSignature = $response['signature'];
		if ( isset( $response['response_signature_string'] ) ) {
			unset( $response['response_signature_string'] );
		}
		if ( isset( $response['signature'] ) ) {
			unset( $response['signature'] );
		}
		if ( Fondy::getSignature( $response, $settings['secret_key'] ) != $responseSignature ) {
			return false;
		}

		return true;
	}

}
