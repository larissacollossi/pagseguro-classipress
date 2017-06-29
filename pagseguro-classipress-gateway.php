<?php

class PagseguroGateway extends APP_Gateway {

	public function __construct() {
		parent::__construct( 'pagseguro', array(
			'dropdown' => __( 'PagSeguro', 'ps_cp' ),
			'admin'    => __( 'PagSeguro', 'ps_cp' ),
		) );

	}

	/**
	 * Returns an array representing the form to output for admin configuration
	 *
	 * @return array scbForms style form array
	 */
	public function form() {
		$form_values = array(

			array(
				'title' => __( 'Email', 'ps_cp' ),
				'type'  => 'text',
				'name'  => 'user_email',
			),
			array(
				'title' => __( 'Token', 'ps_cp' ),
				'type'  => 'text',
				'name'  => 'user_token',
			),
			array(
				'title' => __( 'Usar Sandbox?', 'ps_cp' ),
				'type'  => 'checkbox',
				'name'  => 'use_sandbox',
			),
			array(
				'title' => __( 'Sandbox Token', 'ps_cp' ),
				'type'  => 'text',
				'name'  => 'user_token_sandbox',
			),
		);

		$return_array = array(
			"title"  => __( 'General Information', APP_TD ),
			"fields" => $form_values
		);

		return $return_array;
	}

	/**
	 * Processes an order payment
	 *
	 * @param  APP_Order $order   The order to be processed
	 * @param  array     $options An array of user-entered options
	 *                            corresponding to the values provided in form()
	 *
	 * @return void
	 */
	public function process( $order, $options ) {

		$url         = $options[ 'use_sandbox' ] ? 'https://ws.sandbox.pagseguro.uol.com.br/v2/checkout' : 'https://ws.pagseguro.uol.com.br/v2/checkout';

		$description = 'Compra no site ' . get_bloginfo('url');

		$payment_request[ 'email' ]    = $options[ 'user_email' ];
		$payment_request[ 'token' ]    = $options[ 'use_sandbox' ] ? $options[ 'user_token_sandbox' ] : $options[ 'user_token' ];
		$payment_request[ 'currency' ] = 'BRL';


		$payment_request[ 'itemId1' ]          = $order->get_id();
		$payment_request[ 'itemDescription1' ] = $description;
		$payment_request[ 'itemAmount1' ]      = $order->get_total();
		$payment_request[ 'itemQuantity1' ]    = 1;

		$payment_request[ 'redirectURL' ]     = get_bloginfo( 'url' );
		$payment_request[ 'notificationURL' ] = get_bloginfo( 'url' );
		$payment_request[ 'reference' ]       = $order->get_id();

		$buyer                            = get_userdata( $order->get_author() );
		$payment_request[ 'senderEmail' ] = $buyer->user_email;

		$payment_request = http_build_query( $payment_request );

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $payment_request );
		$xml = curl_exec( $ch );
		curl_close( $ch );


		if ( $xml == 'Unauthorized' ) {
			echo 'Houve um erro ao comunicar com o servidor do PagSeguro <br>';
			pagseguro_log( 'status => UNAUTHORIZED' );
			exit;
		}

		$xml = simplexml_load_string( $xml );

		if ( count( $xml->error ) > 0 ) {
			echo 'Falha na requisição.<br>';
			foreach ( $xml->error as $error ) {
				echo $error . '<br>';
			}
			exit;
		}

		$payment_url = $options[ 'use_sandbox' ] ? 'https://sandbox.pagseguro.uol.com.br/v2/checkout/payment.html' : 'https://pagseguro.uol.com.br/v2/checkout/payment.html';
		echo '<p>Total do pedido: ' . $order->get_total() . '</p>';
		echo '<a class="obtn btn_orange" href=' . $payment_url . '?code='.$xml->code.'>Concluir pagamento agora</a>';
	}
}
appthemes_register_gateway( 'PagseguroGateway' );

add_action( 'wp_footer', 'pagseguro_create_payment_listener' );
function pagseguro_create_payment_listener() {
	$code = ( isset( $_POST[ 'notificationCode' ] ) && trim( $_POST[ 'notificationCode' ] ) !== "" ?
		trim( $_POST[ 'notificationCode' ] ) : null );
	$type = ( isset( $_POST[ 'notificationType' ] ) && trim( $_POST[ 'notificationType' ] ) !== "" ?
		trim( $_POST[ 'notificationType' ] ) : null );

	if ( $code && $type ) {
		pagseguro_log( '::::: Notificação PagSeguro recebida :::::' );

		$options = APP_Gateway_Registry::get_gateway_options( 'pagseguro' );
		$email   = $options[ 'user_email' ];
		$token   = $options[ 'use_sandbox' ] ? $options[ 'user_token_sandbox' ] : $options[ 'user_token' ];

		$url = $options[ 'use_sandbox' ] ? 'https://ws.sandbox.pagseguro.uol.com.br/v2/transactions/notifications/' : 'https://ws.pagseguro.uol.com.br/v2/transactions/notifications/';

		$url = $url . $code . '?email=' . $email . '&token=' . $token;

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$transaction = curl_exec( $ch );

		if ( $transaction == 'Unauthorized' ) {
			pagseguro_log( 'Transação não autorizada. Token: ' . $token );
			exit;
		}
		curl_close( $ch );

		$transaction = simplexml_load_string( $transaction );
		$status      = $transaction->status;

		$order = APP_Order_Factory::retrieve( intval( $transaction->reference ) );

		if ( false === $order ) {
			pagseguro_log( 'ERRO: Não foi encontrado pedido com ID_TRANSACAO == ' . $transaction->reference );

			return;
		}

		switch ( $status ) {
			case 3:
				if ( $order->get_status() == 'tr_activated' || $order->get_status() == 'tr_completed' ) {
					pagseguro_log( 'Notificação repetida para ' . $transaction->reference . '. Ignorando...' );

					return;
				}

                                global $cp_options;
                                if ( $cp_options->moderate_ads ) {
                                        $order->complete();
                                }else{
                                        $order->activate();
                                }

				pagseguro_log( 'Pedido ' . $transaction->reference . ' foi ativado');
				break;
			case 7:
				if ( $order->get_status() == 'tr_failed' ) {
					pagseguro_log( 'Notificação repetida para ' . $transaction->reference . '. Ignorando...' );
				}
				$order->failed();
				pagseguro_log( 'Pedido ' . $transaction->reference . ' foi cancelado');
		}

	}
}

/**
 * Writes to a log file.
 *
 * @param $message
 */
function pagseguro_log( $message ) {
	file_put_contents( 'payment.log', date( "d-m-Y H:i:s" ) . ' ::::: ' . $message . PHP_EOL, FILE_APPEND );
}
