<?php

use Transbank\WooCommerce\Webpay\Controllers\ResponseController;
use Transbank\WooCommerce\Webpay\Controllers\ThanksPageController;
use Transbank\WooCommerce\Webpay\Exceptions\TokenNotFoundOnDatabaseException;
use Transbank\WooCommerce\Webpay\Helpers\RedirectorHelper;
use Transbank\WooCommerce\Webpay\TransbankWebpayOrders;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
} // Exit if accessed directly

/**
 * Plugin Name: Integración de Webpay Plus de Transbank para WooCommerce
 * Plugin URI: https://andres.reyes.dev
 * Description: Recibe pagos en línea con Tarjetas de Crédito y Redcompra en tu WooCommerce a través de Webpay Plus.
 * Version: 20.12.28
 * Author: AndresReyesDev
 * Author URI: https://andres.reyes.dev
 *
 * Requires at least: 4.0
 * Tested up to: 5.6
 * Requires PHP: 5.6+
 *
 * WC requires at least: 2.5
 * WC tested up to: 4.8
 */

add_action( 'plugins_loaded', 'woocommerce_transbank_init', 0 );

require_once plugin_dir_path( __FILE__ ) . "vendor/autoload.php";
require_once plugin_dir_path( __FILE__ ) . "vendor/persist-admin-notices-dismissal/persist-admin-notices-dismissal.php";
require_once plugin_dir_path( __FILE__ ) . "libwebpay/TransbankSdkWebpay.php";

register_activation_hook( __FILE__, 'on_webpay_plugin_activation' );
add_action( 'admin_init', 'on_transbank_webpay_plugins_loaded' );
add_action( 'admin_init', array( 'PAnD', 'init' ) );
add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_transbank_gateway' );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'add_action_links' );

function woocommerce_transbank_init() {
	if ( ! class_exists( "WC_Payment_Gateway" ) ) {
		return;
	}

	class WC_Gateway_Transbank extends WC_Payment_Gateway {
		private static $URL_RETURN;
		private static $URL_FINAL;

		var $notify_url;
		var $plugin_url;

		public function __construct() {

			self::$URL_RETURN = home_url( '/' ) . '?wc-api=WC_Gateway_transbank';
			self::$URL_FINAL  = '_URL_';

			$this->id                 = 'transbank';
			$this->icon               = "https://payment.swo.cl/host/logo";
			$this->method_title       = __( 'Webpay Plus de Transbank' );
			$this->method_description = __( 'Paga con las Tarjetas de Crédito y Redcompra de Transbank' );
			$this->notify_url         = add_query_arg( 'wc-api', 'WC_Gateway_' . $this->id, home_url( '/' ) );
			$this->title              = 'Tarjetas de Crédito o Débito (Redcompra)';
			$this->description        = 'Paga usando tu Tarjeta de Cr&eacute;dito o Débito (Redcompra) a trav&eacute;s de Webpay Plus de Transbank';
			$this->plugin_url         = plugins_url( '/', __FILE__ );


			$certificates         = include 'libwebpay/certificates.php';
			$webpay_commerce_code = $certificates['commerce_code'];
			$webpay_private_key   = $certificates['private_key'];
			$webpay_public_cert   = $certificates['public_cert'];
			$webpay_webpay_cert   = ( new TransbankSdkWebpay( null ) )->getWebPayCertDefault();

			$this->config = [
				"MODO"                 => trim( $this->get_option( 'webpay_test_mode', 'INTEGRACION' ) ),
				"COMMERCE_CODE"        => trim( $this->get_option( 'webpay_commerce_code', $webpay_commerce_code ) ),
				"PRIVATE_KEY"          => trim( str_replace( "<br/>", "\n",
					$this->get_option( 'webpay_private_key', $webpay_private_key ) ) ),
				"PUBLIC_CERT"          => trim( str_replace( "<br/>", "\n",
					$this->get_option( 'webpay_public_cert', $webpay_public_cert ) ) ),
				"WEBPAY_CERT"          => trim( str_replace( "<br/>", "\n",
					$this->get_option( 'webpay_webpay_cert', $webpay_webpay_cert ) ) ),
				"URL_RETURN"           => home_url( '/' ) . '?wc-api=WC_Gateway_' . $this->id,
				"URL_FINAL"            => "_URL_",
				"ECOMMERCE"            => 'woocommerce',
				"VENTA_DESC"           => [
					"VD" => "Venta Deb&iacute;to",
					"VN" => "Venta Normal",
					"VC" => "Venta en cuotas",
					"SI" => "3 cuotas sin inter&eacute;s",
					"S2" => "2 cuotas sin inter&eacute;s",
					"NC" => "N cuotas sin inter&eacute;s"
				],
				"STATUS_AFTER_PAYMENT" => $this->get_option( 'after_payment_order_status' )
			];


			/**
			 * Carga configuración y variables de inicio
			 **/

			$this->init_form_fields();
			$this->init_settings();

			add_action( 'woocommerce_thankyou', [ new ThanksPageController( $this->config ), 'show' ], 1 );
			add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
			add_action( 'woocommerce_api_wc_gateway_' . $this->id, [ $this, 'check_ipn_response' ] );
			add_action( 'woocommerce_sections_checkout', [$this, 'wc_transbank_message'], 1);

			if ( ! $this->is_valid_for_use() ) {
				$this->enabled = false;
			}
		}

		function is_valid_for_use() {
			if ( ! in_array( get_woocommerce_currency(),
				apply_filters( 'woocommerce_' . $this->id . '_supported_currencies', [ 'CLP' ] ) ) ) {
				return false;
			}

			return true;
		}

		function init_form_fields() {
			$this->form_fields = [
				'enabled'                    => [
					'title'   => __( 'Activar/Desactivar', 'woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'yes'
				],
				'webpay_test_mode'           => [
					'title'   => __( 'Ambiente', 'woocommerce' ),
					'type'    => 'select',
					'options' => [
						'INTEGRACION' => 'Integraci&oacute;n',
						'PRODUCCION'  => 'Producci&oacute;n'
					],
					'default' => 'INTEGRACION'
				],
				'webpay_commerce_code'       => [
					'title'   => __( 'C&oacute;digo de Comercio', 'woocommerce' ),
					'type'    => 'text',
					'default' => __( $this->config['COMMERCE_CODE'], 'woocommerce' )
				],
				'webpay_private_key'         => [
					'title'   => __( 'Llave Privada', 'woocommerce' ),
					'type'    => 'textarea',
					'default' => __( str_replace( "<br/>", "\n", $this->config['PRIVATE_KEY'] ), 'woocommerce' ),
					'css'     => 'font-family: monospace'
				],
				'webpay_public_cert'         => [
					'title'   => __( 'Certificado', 'woocommerce' ),
					'type'    => 'textarea',
					'default' => __( str_replace( "<br/>", "\n", $this->config['PUBLIC_CERT'] ), 'woocommerce' ),
					'css'     => 'font-family: monospace'
				],
				'after_payment_order_status' => [
					'title'   => __( 'Estado de pedido después del pago' ),
					'type'    => 'select',
					'options' => [
						'wc-pending'    => 'Pendiente',
						'wc-processing' => 'Procesando',
						'wc-on-hold'    => 'Retenido',
						'wc-completed'  => 'Completado',
						'wc-cancelled'  => 'Cancelado',
						'wc-refunded'   => 'Reembolsado',
						'wc-failed'     => 'Fallido'
					],
					'default' => 'wc-processing'
				]
			];
		}

		/**
		 * Pagina Receptora
		 **/
		function receipt_page( $order_id ) {
			$order     = new WC_Order( $order_id );
			$amount    = (int) number_format( $order->get_total(), 0, ',', '' );
			$sessionId = uniqid();
			$buyOrder  = $order_id;
			$returnUrl = self::$URL_RETURN;
			$finalUrl  = str_replace( "_URL_",
				add_query_arg( 'key', $order->get_order_key(), $order->get_checkout_order_received_url() ),
				self::$URL_FINAL );

			$transbankSdkWebpay = new TransbankSdkWebpay( $this->config );
			$result             = $transbankSdkWebpay->initTransaction( $amount, $sessionId, $buyOrder, $returnUrl, $finalUrl );

			if ( ! isset( $result["token_ws"] ) ) {
				wc_add_notice( __( 'ERROR: ',
						'woocommerce' ) . 'Ocurrió un error al intentar conectar con WebPay Plus. Por favor intenta mas tarde.<br/>',
					'error' );

				return;
			}

			$url      = $result["url"];
			$token_ws = $result["token_ws"];

			TransbankWebpayOrders::createTransaction( [
				'order_id'   => $order_id,
				'buy_order'  => $buyOrder,
				'amount'     => $amount,
				'token'      => $token_ws,
				'session_id' => $sessionId,
				'status'     => TransbankWebpayOrders::STATUS_INITIALIZED
			] );

			RedirectorHelper::redirect( $url, [ "token_ws" => $token_ws ] );
		}

		/**
		 * Obtiene respuesta IPN (Instant Payment Notification)
		 **/
		function check_ipn_response() {
			@ob_clean();
			if ( isset( $_POST ) ) {
				header( 'HTTP/1.1 200 OK' );

				return ( new ResponseController( $this->config ) )->response( $_POST );
			} else {
				echo "Ocurrio un error al procesar su compra";
			}
		}

		/**
		 * Procesar pago y retornar resultado
		 **/
		function process_payment( $order_id ) {
			$order = new WC_Order( $order_id );

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true )
			];
		}

		function wc_transbank_message() {
			$modo            = $this->get_option( 'webpay_test_mode' );
			if ($modo == 'INTEGRACION') {
				$current_section = ( isset( $_GET['section'] ) && ! empty( $_GET['section'] ) ) ? $_GET['section'] : '';
				if ( $current_section == 'transbank' ) {
					$bg     = plugin_dir_url( __FILE__ ) . 'assets/img/back.png';
					$domain = get_home_url();

					$message = <<<EOL
<div class="transbank-postbox" style="background-image: url('$bg'); border-radius: 5px">
<div class="inside" style="text-align: center; color: #ffffff; font-weight: 300; padding: 10px 50px;">
<p style="font-size: 25px; font-weight: 300;">¡Vende ya con Tarjetas de Crédito y Débito!</p>
<p style="font-size: 15px; font-weight: 300;">Vamos al grano: Para vender con tarjetas no sólo basta con instalar el plugin, sino hay que realizar un procedimiento comercial y técnico que puede llevar un par de semanas. Yo realizo ese proceso en máximo <strong>5 días hábiles</strong>.</p>
<p style="font-size: 15px; font-weight: 300;">Soy <a href="https://andres.reyes.dev" style="color: white"><strong>Andrés Reyes Galgani</strong></a>, desarrollador del plugin, principal integrador de Transbank y estoy aquí para ayudarte en dicho proceso.</p>
<p style="text-align: center;"><a class="button" style="padding: 5px 10px; margin-top: 10px; background-color: #11a94a; color: white; font-size: 15px; font-weight: 500;" href="https://link.reyes.dev/webpay-plus-woocommerce?text=Hola, necesito integrar Webpay Plus en el dominio $domain" target="_blank">¿Dudas? ¡Háblame Ahora Directo a WhatsApp!</a> <!--a class="button" style="padding: 1px 5px; background-color: #986c93; color: white; font-size: 11px; font-weight: 300; line-height: 26px; border: 1px solid #793e6e;" href="" target="_blank">No necesito ayuda</a--></p>
</div>
</div>
EOL;
					echo $message;
				}
			}
		}
	}

	/**
	 * Añadir Transbank Plus a Woocommerce
	 **/
	function woocommerce_add_transbank_gateway( $methods ) {
		$methods[] = 'WC_Gateway_transbank';

		return $methods;
	}

	/**
	 * Muestra detalle de pago a Cliente a finalizar compra
	 **/
	function pay_transbank_webpay_content( $orderId ) {

	}
    function transbank_rest_first_warning__warning() {
        if ( ! PAnD::is_admin_notice_active( 'transbank_rest_first_warning' ) ) {
            return;
        }

        ?>
        <div data-dismissible="transbank_rest_first_warning" class="updated notice notice-error is-dismissible">
            <img width="300px" src="<?php echo plugin_dir_url( __FILE__ ) . 'assets/img/logo-migracion.png' ?>"  />
            <h3 style="text-transform: uppercase">Aviso Importante sobre Webpay Plus de Transbank</h2>
			<p>Hola 👋, soy Andrés Reyes Galgani, desarrollador del plugin de Webpay y tengo que informarte algo: <strong>Transbank</Strong> necesita que actualices <u>OBLIGATORIAMENTE</u> tu servicio a la brevedad ya que <strong>este plugin dejará de funcionar</strong>.</p>
			<p>La razón se debe a una <strong>actualización tecnológica</strong> que <strong>trae mejoras en el servicio como reversas de compras</strong>. Para realizar dicho cambio recomiendo realizar una revisión de tu web para garantizar que esta funcione sin problemas.</p>
            <p>Yo puedo realizar dicho procedimiento de actualización y verificación de funcionalidad por un valor único de <strong><u>$20 mil pesos</u></strong> <small>(válido hasta el 10 de enero de 2021)</small>. Tus clientes no notarán el cambio y no perderás ventas.</p>
			<p>Ya son más de <strong>1000 clientes que han migrado</strong> al nuevo servicio. <strong>No esperes</strong> hasta último momento para actualizar tu web y así <strong>no perderás ventas</strong>.</p>
            <a href="https://link.reyes.dev/webpay-plus-woocommerce?text=Hola, necesito migrar Webpay Plus SOAP a Webpay Plus REST en el dominio <?php echo get_home_url() ?>" target="_blank"><img width="150px" src="<?php echo plugin_dir_url( __FILE__ ) . 'assets/img/hablar_whatsapp.png' ?>" /></a>
        </div>
        <?php
    }

    //add_action( 'admin_notices', 'transbank_rest_first_warning__warning' );

}

function add_action_links( $links ) {
	$newLinks = [
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=transbank' ) . '">💳 Configuración de Medio de Pago</a>',
	];

	return array_merge( $links, $newLinks );
}

function on_webpay_plugin_activation() {
	woocommerce_transbank_init();
}

function on_transbank_webpay_plugins_loaded() {
	TransbankWebpayOrders::createTableIfNeeded();
}

function transbank_remove_database() {
	TransbankWebpayOrders::deleteTable();
}

register_uninstall_hook( __FILE__, 'transbank_remove_database' );
