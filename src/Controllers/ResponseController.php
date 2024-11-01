<?php

namespace Transbank\WooCommerce\Webpay\Controllers;

use DateTime;
use Transbank\WooCommerce\Webpay\Helpers\RedirectorHelper;
use Transbank\WooCommerce\Webpay\TransbankWebpayOrders;
use TransbankSdkWebpay;
use WC_Order;

class ResponseController
{
    /**
     * @var array
     */
    protected $pluginConfig;
    /**
     * ResponseController constructor.
     *
     * @param array $pluginConfig
     */
    public function __construct(array $pluginConfig)
    {
        $this->pluginConfig = $pluginConfig;
    }
    
    public function response($postData)
    {
        $token_ws = $this->getTokenWs($postData);
        $webpayTransaction = TransbankWebpayOrders::getByToken($token_ws);
        $wooCommerceOrder = $this->getWooCommerceOrderById($webpayTransaction->order_id);
        
        if ($webpayTransaction->status != TransbankWebpayOrders::STATUS_INITIALIZED) {
            wc_add_notice(__('Estimado cliente, le informamos que esta transacción ya ha sido pagada o rechazada.',
                'woocommerce'), 'error');
            RedirectorHelper::redirect($wooCommerceOrder->get_checkout_order_received_url(), ['token_ws' => $token_ws]);
        }
        $transbankSdkWebpay = new TransbankSdkWebpay($this->pluginConfig);
        $result = $transbankSdkWebpay->commitTransaction($token_ws);
        
        if ($this->transactionIsApproved($result) && $this->validateTransactionDetails($result, $webpayTransaction)) {
            $this->completeWooCommerceOrder($wooCommerceOrder, $result, $webpayTransaction);
            RedirectorHelper::redirect($result->urlRedirection, ["token_ws" => $token_ws]);
        }
        
        $this->setWooCommerceOrderAsFailed($wooCommerceOrder, $webpayTransaction, $result);
        RedirectorHelper::redirect($wooCommerceOrder->get_checkout_order_received_url(), ['token_ws' => $token_ws]);
    }
    
    /**
     * @param $data
     * @return |null
     */
    protected function getTokenWs($data)
    {
        $token_ws = isset($data["token_ws"]) ? $data["token_ws"] : null;
        
        if (!isset($token_ws)) {
            $this->throwError('No se encontro el token');
        }
        
        return $token_ws;
    }
    /**
     * @param $orderId
     * @return WC_Order
     */
    protected function getWooCommerceOrderById($orderId)
    {
        $wooCommerceOrder = new WC_Order($orderId);
        
        return $wooCommerceOrder;
    }
    
    protected function throwError($msg)
    {
        $error_message = "Estimado cliente, le informamos que su orden termin&oacute; de forma inesperada: <br />" . $msg;
        wc_add_notice(__('ERROR: ', 'woocommerce') . $error_message, 'error');
        die();
    }
    
    /**
     * @param WC_Order $wooCommerceOrder
     * @param array $result
     * @param $webpayTransaction
     */
    protected function completeWooCommerceOrder(WC_Order $wooCommerceOrder, $result, $webpayTransaction)
    {
        $wooCommerceOrder->add_order_note(__('Pago con WEBPAY PLUS', 'woocommerce'));

        /** CORREO */

        $tbk_invoice_buyOrder = $result->buyOrder;

        $to = get_bloginfo('admin_email');
        $subject = 'Comprobante de Pago Webpay Plus — Orden #' . $tbk_invoice_buyOrder;

        $tbk_invoice_authorizationCode = $result->detailOutput->authorizationCode;

        $date_accepted = new DateTime($result->transactionDate);
        $tbk_invoice_transactionDate = $date_accepted->format('d-m-Y H:i:s');

        $tbk_invoice_cardNumber = $result->cardDetail->cardNumber;

        $paymentTypeCode = $result->detailOutput->paymentTypeCode;

        switch ($paymentTypeCode) {
            case "VD":
                $tbk_invoice_paymenCodeResult = "Venta Deb&iacute;to";
                break;
            case "VN":
                $tbk_invoice_paymenCodeResult = "Venta Normal";
                break;
            case "VC":
                $tbk_invoice_paymenCodeResult = "Venta en cuotas";
                break;
            case "SI":
                $tbk_invoice_paymenCodeResult = "3 cuotas sin inter&eacute;s";
                break;
            case "S2":
                $tbk_invoice_paymenCodeResult = "2 cuotas sin inter&eacute;s";
                break;
            case "NC":
                $tbk_invoice_paymenCodeResult = "N cuotas sin inter&eacute;s";
                break;
            default:
                $tbk_invoice_paymenCodeResult = "—";
                break;
        }

        $tbk_invoice_amount = number_format($result->detailOutput->amount, 0, ',', '.');
        $tbk_invoice_sharesNumber = $result->detailOutput->sharesNumber;

        //Datos Cliente
        $tbk_invoice_nombre = $wooCommerceOrder->get_billing_first_name() . ' ' . $wooCommerceOrder->get_billing_last_name();
        $tbk_invoice_correo = $wooCommerceOrder->get_billing_email();

        $formato = '<ul><li><strong>Respuesta de la Transacción</strong>: ACEPTADO</li><li><strong>Orden de Compra:</strong> %s</li><li><strong>Codigo de Autorización:</strong> %s</li><li><strong>Fecha y Hora de la Transacción:</strong> %s</li><li><strong>Tarjeta de Crédito:</strong> ···· ···· ···· %s</li><li><strong>Tipo de Pago:</strong> %s</li><li><strong>Monto Compra: </strong>$%s</li><li><strong>Número de Cuotas:</strong> %s</li></ul>';

        $wooCommerceOrder->add_order_note(sprintf($formato, $tbk_invoice_buyOrder, $tbk_invoice_authorizationCode, $tbk_invoice_transactionDate, $tbk_invoice_cardNumber, $tbk_invoice_paymenCodeResult, $tbk_invoice_amount, $tbk_invoice_sharesNumber));

        $body = <<<EOT
						<!DOCTYPE html>
						<html lang="en">

						<head>
						    <meta charset="UTF-8">
						    <meta name="viewport" content="width=device-width, initial-scale=1.0">
						    <meta http-equiv="X-UA-Compatible" content="ie=edge">
						    <title>Comprobante Webpay Plus</title>
						</head>

						<body style="padding: 30px 15% 0; font-family: Arial, Helvetica, sans-serif; font-size: 0.85rem;">

						    <div style="width: 100%; text-align: center;">
						        <img src="https://payment.swo.cl/host/mail" width="250px" />
						    </div>
						    <div>
						        <h1 style="font-size: 25px; text-transform: uppercase; text-align: center;">Notificación de Pago</h1>
						        <p>Estimado usuario, se ha realizado un pago con los siguientes datos:</p>
						        <hr />
						        <h3>Detalle de Transacción</h3>
						        <table style="width: 100%;" border="1">
						            <tbody>
						                <tr>
						                    <td style="width: 50%"><strong>Respuesta de la Transacción</strong></td>
						                    <td style="width: 50%"><strong>ACEPTADO</strong></td>
						                </tr>
						                <tr>
						                    <td>Orden de Compra</td>
						                    <td>$tbk_invoice_buyOrder</td>
						                </tr>
						                <tr>
						                    <td>Codigo de Autorización</td>
						                    <td>$tbk_invoice_authorizationCode</td>
						                </tr>
						                <tr>
						                    <td>Fecha y Hora de la Transacción</td>
						                    <td>$tbk_invoice_transactionDate</td>
						                </tr>
						                <tr>
						                    <td>Tarjeta de Crédito</td>
						                    <td>···· ···· ···· $tbk_invoice_cardNumber</td>
						                </tr>
						                <tr>
						                    <td>Tipo de Pago</td>
						                    <td>$tbk_invoice_paymenCodeResult</td>
						                </tr>
						                <tr>
						                    <td>Monto Compra</td>
						                    <td>$$tbk_invoice_amount</td>
						                </tr>
						                <tr>
						                    <td>Número de Cuotas</td>
						                    <td>$tbk_invoice_sharesNumber</td>
						                </tr>
						            </tbody>
						        </table>
						        <hr />
						        <h3>Detalle de Orden</h3>
						        <table style="width: 100%;" border="1">
						            <tbody>
						                <tr>
						                    <td style="width: 50%">Nombre de Cliente:</td>
						                    <td style="width: 50%">$tbk_invoice_nombre</td>
						                </tr>
						                <tr>
						                    <td>Correo Electrónico</td>
						                    <td>$tbk_invoice_correo</td>
						                </tr>
						            </tbody>
						        </table>
						        <p>La información contenida en este correo electrónico es informativa y ha sido enviada como respaldo de la transacción
						            cursada con tarjeta de crédito o Redcompra. El siguiente pago ha sido consignado directamente en la cuenta del
						            usuario realizando las actualizaciones correspondientes a la orden de compra indicada.
						        </p>
                            </div>
                            
                            <div style="border: dashed 1px #9c9c26; padding: 10px; background: #ffffb3; text-align: center">
                            <p style="font-size: 17px;">Recuerda que para seguir vendiendo con Transbank debes actualizar tu servicio ya que este dejará de funcionar pronto.</p>
                            <p>Para que esto no ocurra podemos realizar la migración por ti con garantía de funcionamiento y sin perder ninguna venta. Para más información sobre costos y tiempos de ejecución puedes comunicarte con nuestro equipo vía WhatsApp haciendo <a href="https://link.reyes.dev/webpay-plus-woocommerce?text=Hola, necesito migrar Webpay Plus SOAP a Webpay Plus REST">clic aquí</a></p>
                            </div>
						</body>

						</html>
EOT;

        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail( $to, $subject, $body, $headers );

        /** END CORREO */

        $wooCommerceOrder->payment_complete();
        $final_status = $this->pluginConfig['STATUS_AFTER_PAYMENT'];
        $wooCommerceOrder->update_status($final_status);
        
        // Todo: eliminar esto, ya que $wooCommerceOrder->payment_complete() lo hace.
        wc_reduce_stock_levels($wooCommerceOrder->get_id());
        wc_empty_cart();
        
        list($authorizationCode, $amount, $sharesNumber, $transactionResponse, $paymentCodeResult, $date_accepted) = $this->getTransactionDetails($result);
        
        update_post_meta($wooCommerceOrder->get_id(), 'transactionResponse', $transactionResponse);
        update_post_meta($wooCommerceOrder->get_id(), 'buyOrder', $result->buyOrder);
        update_post_meta($wooCommerceOrder->get_id(), 'authorizationCode', $authorizationCode);
        update_post_meta($wooCommerceOrder->get_id(), 'cardNumber', $result->cardDetail->cardNumber);
        update_post_meta($wooCommerceOrder->get_id(), 'paymentCodeResult', $paymentCodeResult);
        update_post_meta($wooCommerceOrder->get_id(), 'amount', $amount);
        update_post_meta($wooCommerceOrder->get_id(), 'shares', $sharesNumber);
        update_post_meta($wooCommerceOrder->get_id(), 'transactionDate', $date_accepted->format('d-m-Y / H:i:s'));
        
        wc_add_notice(__('Pago recibido satisfactoriamente', 'woocommerce'));
        TransbankWebpayOrders::update($webpayTransaction->id,
            ['status' => TransbankWebpayOrders::STATUS_APPROVED, 'transbank_response' => json_encode($result)]);
    }
    /**
     * @param WC_Order $wooCommerceOrder
     * @param array $result
     * @param $webpayTransaction
     */
    protected function setWooCommerceOrderAsFailed(WC_Order $wooCommerceOrder, $webpayTransaction, $result = null)
    {
        $wooCommerceOrder->add_order_note(__('Pago rechazado', 'woocommerce'));
        $wooCommerceOrder->update_status('failed');
        if ($result !== null) {
            $wooCommerceOrder->add_order_note(json_encode($result, JSON_PRETTY_PRINT));
        }
        
        $error_message = "Estimado cliente, le informamos que su pago no ha sido efectuado correctamente";
        wc_add_notice(__($error_message, 'woocommerce'), 'error');
        
        TransbankWebpayOrders::update($webpayTransaction->id,
            ['status' => TransbankWebpayOrders::STATUS_FAILED, 'transbank_response' => json_encode($result)]);
    }
    
    /**
     * @param array $result
     * @return bool
     */
    protected function transactionIsApproved($result)
    {
        if (!isset($result->detailOutput->responseCode)) {
            return false;
        }
        
        return $result->detailOutput->responseCode == 0;
    }
    /**
     * @param array $result
     * @param $webpayTransaction
     * @return bool
     */
    protected function validateTransactionDetails($result, $webpayTransaction)
    {
        if (!isset($result->detailOutput->responseCode)) {
            return false;
        }
        
        return $result->detailOutput->buyOrder == $webpayTransaction->buy_order && $result->sessionId == $webpayTransaction->session_id && $result->detailOutput->amount == $webpayTransaction->amount;
    }
    /**
     * @param array $result
     * @return array
     * @throws \Exception
     */
    protected function getTransactionDetails($result)
    {
        $detailOutput = $result->detailOutput;
        $paymentTypeCode = isset($detailOutput->paymentTypeCode) ? $detailOutput->paymentTypeCode : null;
        $authorizationCode = isset($detailOutput->authorizationCode) ? $detailOutput->authorizationCode : null;
        $amount = isset($detailOutput->amount) ? $detailOutput->amount : null;
        $sharesNumber = isset($detailOutput->sharesNumber) ? $detailOutput->sharesNumber : null;
        $responseCode = isset($detailOutput->responseCode) ? $detailOutput->responseCode : null;
        if ($responseCode == 0) {
            $transactionResponse = "Transacción Aprobada";
        } else {
            $transactionResponse = "Transacción Rechazada";
        }
        $paymentCodeResult = "Sin cuotas";
        if ($this->pluginConfig) {
            if (array_key_exists('VENTA_DESC', $this->pluginConfig)) {
                if (array_key_exists($paymentTypeCode, $this->pluginConfig['VENTA_DESC'])) {
                    $paymentCodeResult = $this->pluginConfig['VENTA_DESC'][$paymentTypeCode];
                }
            }
        }
        
        $transactionDate = isset($result->transactionDate) ? $result->transactionDate : null;
        $date_accepted = new DateTime($transactionDate);
        
        return [$authorizationCode, $amount, $sharesNumber, $transactionResponse, $paymentCodeResult, $date_accepted];
    }
}
