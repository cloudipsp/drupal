<?php

class Fondy {
	const ORDER_APPROVED = 'approved';
	const ORDER_DECLINED = 'declined';

	const ORDER_SEPARATOR = '#';

	const SIGNATURE_SEPARATOR = '|';

	const URL = "https://api.fondy.eu/api/checkout/redirect/";

	public static function getSignature( $data, $password, $encoded = true ) {
		$data = array_filter( $data, function ( $var ) {
			return $var !== '' && $var !== null;
		} );
		ksort( $data );

		$str = $password;
		foreach ( $data as $k => $v ) {
			$str .= self::SIGNATURE_SEPARATOR . $v;
		}

		if ( $encoded ) {
			return sha1( $str );
		} else {
			return $str;
		}
	}

	public static function isPaymentValid( $oplataSettings, $response ) {
		list( $orderId, ) = explode( self::ORDER_SEPARATOR, $response['order_id'] );
		$order = uc_order_load( $orderId );

		if ( $order === false || uc_order_status_data( $order->order_status, 'state' ) != 'in_checkout' ) {
			return t( 'An error has occurred during payment. Please contact us to ensure your order has submitted.' );
		}

		if ( $oplataSettings->merchant_id != $response['merchant_id'] ) {
			return t( 'An error has occurred during payment. Merchant data is incorrect.' );
		}


		$responseSignature = $response['signature'];
		if ( isset( $response['response_signature_string'] ) ) {
			unset( $response['response_signature_string'] );
		}
		if ( isset( $response['signature'] ) ) {
			unset( $response['signature'] );
		}
		if ( self::getSignature( $response, $oplataSettings['secret_key'] ) != $responseSignature ) {
			return false;
		}

		if ( drupal_strtolower( $response['sender_email'] ) !== drupal_strtolower( $order->primary_email ) ) {
			uc_order_comment_save( $order->order_id, 0, t( 'Customer used a different e-mail address during payment: !email', array( '!email' => check_plain( $response['sender_email'] ) ) ), 'admin' );
		}

		uc_order_comment_save( $order->order_id, 0, "Order status: {$response['order_status']}", 'admin' );

		return true;
	}
}
