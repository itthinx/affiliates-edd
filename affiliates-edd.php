<?php
/**
 * Plugin Name: Affiliates Integration for EDD (unsupported)
 * Description: Affiliates Integration for EDD - we do not provide support for this integration, it is provided as a courtesy for developers and existing users who wish to use or extend it for their deployments. We recommend to use an alternative integration with an officially supported e-commerce system, see <a href="http://www.itthinx.com/shop/affiliates-pro/">Affiliates Pro</a> or <a href="http://www.itthinx.com/shop/affiliates-enterprise/">Affiliates Enterprise</a>.
 * Version: 1.0.0 
 * Author: itthinx
 * Author URI: http://www.itthinx.com
 */

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integration.
 */
class Affiliates_EDD {

	/**
	 * Adds actions.
	 */
	public static function init() {
		add_action( 'edd_insert_payment', array( __CLASS__, 'edd_insert_payment' ), 10, 2 );
		add_action( 'edd_update_payment_status', array( __CLASS__, 'edd_update_payment_status' ), 10, 3 );
	}

	/**
	 * Payment insertion hook.
	 * @param int $payment_id
	 * @param array $payment_data
	 */
	public static function edd_insert_payment( $payment_id, $payment_data ) {
		if ( $payment_id instanceof WP_Error ) {
			return;
		}
		if ( !class_exists( 'Affiliates_Referral_WordPress' ) ) {
			return;
		}
		$payment_meta = edd_get_payment_meta( $payment_id );
		// Net order amount used to calculate the commission.
		$order_subtotal = edd_get_payment_subtotal( $payment_id );
		$currency       = $payment_meta['currency'];
		$user_info      = maybe_unserialize( $payment_meta['user_info'] );
		$order_link     = '<a href="' . admin_url( 'edit.php?post_type=download&page=edd-payment-history&purchase_id=' . $payment_id . '&edd-action=edit-payment' ) . '">';
		$order_link     .= sprintf( __( 'Order #%s', 'edd' ), $payment_id );
		$order_link     .= "</a>";
		$data           = array(
			'order_id' => array(
				'title' => 'Order #',
				'domain' => 'edd',
				'value' => esc_sql( $payment_id )
			),
			'order_total' => array(
				'title' => 'Total',
				'domain' =>  'edd',
				'value' => esc_sql( $order_subtotal )
			),
			'order_currency' => array(
				'title' => 'Currency',
				'domain' =>  'edd',
				'value' => esc_sql( $currency )
			),
			'order_link' => array(
				'title' => 'Purchase',
				'domain' => 'edd',
				'value' => esc_sql( $order_link )
			)
		);
		$coupon_affiliate_ids = array();
		if ( isset( $user_info['discount'] ) && $user_info['discount'] != 'none' ) {
			$coupon = trim( $user_info['discount'] );
			$affiliate_id = Affiliates_Attributes_WordPress::get_affiliate_for_coupon( $coupon );
			if ( ( $affiliate_id !== null ) && !in_array( $affiliate_id, $coupon_affiliate_ids ) ) {
				$coupon_affiliate_ids[] = $affiliate_id;
			}
		}
		$r = new Affiliates_Referral_WordPress();
		$description = sprintf( 'Order #%s', $payment_id );
		if ( count( $coupon_affiliate_ids ) > 0 ) {
			bcscale( AFFILIATES_REFERRAL_AMOUNT_DECIMALS );
			$split_order_subtotal = bcdiv( $order_subtotal, count( $coupon_affiliate_ids ) );
			$r->add_referrals( $coupon_affiliate_ids, 0, $description, $data, $split_order_subtotal, null, $currency, null, 'sale', $payment_id );
		} else {
			$r->evaluate( 0, $description, $data, $order_subtotal, null, $currency, null, 'sale', $payment_id );
		}
	}

	/**
	 * Mark referrals as accepted on appropriate payment status.
	 * @param unknown $payment_id
	 * @param string $new_status
	 * @param string $old_status
	 */
	public static function edd_update_payment_status( $payment_id, $new_status = '', $old_status = '' ) {
		global $affiliates_db;
		if ( !class_exists( 'Affiliates_Referral_WordPress' ) ) {
			return;
		}
		if ( $new_status != $old_status ) {
			$status = null;
			switch ( $new_status ) {
				case 'publish' :
				case 'complete' :
					$status = AFFILIATES_REFERRAL_STATUS_ACCEPTED;
					break;
				case 'pending' :
					$status = AFFILIATES_REFERRAL_STATUS_PENDING;
					break;
				case 'refunded' :
				case 'failed' :
				case 'abandoned' :
				case 'revoked' :
					$status = AFFILIATES_REFERRAL_STATUS_REJECTED;
					break;
			}
			if ( $status !== null ) {
				$referrals_table = $affiliates_db->get_tablename( "referrals" );
				$referrals = $affiliates_db->get_objects(
					"SELECT referral_id FROM $referrals_table WHERE reference = %d AND status != %s AND status != %s",
					intval( $payment_id ),
					$status,
					AFFILIATES_REFERRAL_STATUS_CLOSED
				);
				if ( $referrals ) {
					foreach( $referrals as $referral ) {
						try {
							$r = new Affiliates_Referral_WordPress( $referral->referral_id );
							$r->update( array( 'status' => $status ) );
						} catch ( Exception $ex ) {
						}
					}
				}
			}
		}
	}
}
Affiliates_EDD::init();
