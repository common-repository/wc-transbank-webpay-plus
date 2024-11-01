<?php
if (!defined('ABSPATH')) {
    exit;
}


require_once(plugin_dir_path(__DIR__) . "vendor/autoload.php");

use Transbank\Webpay\Configuration;
use Transbank\Webpay\Webpay;

class TransbankSdkWebpay
{
    const ENVIRONMENT_INTEGRATION = 'INTEGRACION';

    var $transaction;

    function __construct($config)
    {
        if (isset($config)) {
            $environment = isset($config["MODO"]) ? $config["MODO"] : static::ENVIRONMENT_INTEGRATION;
            $configuration = Configuration::forTestingWebpayPlusNormal();
            $configuration->setWebpayCert(Webpay::defaultCert($environment));

            if ($environment != static::ENVIRONMENT_INTEGRATION) {
                $configuration->setEnvironment($environment);
                $configuration->setCommerceCode($config["COMMERCE_CODE"]);
                $configuration->setPrivateKey($config["PRIVATE_KEY"]);
                $configuration->setPublicCert($config["PUBLIC_CERT"]);
            }

            $this->transaction = (new Webpay($configuration))->getNormalTransaction();
        }
    }

    public function getWebPayCertDefault()
    {
        return Webpay::defaultCert(static::ENVIRONMENT_INTEGRATION);
    }

    public function initTransaction($amount, $sessionId, $buyOrder, $returnUrl, $finalUrl)
    {
        $result = array();
        try {
            $txDate = date('d-m-Y');
            $txTime = date('H:i:s');
            $initResult = $this->transaction->initTransaction($amount, $buyOrder, $sessionId, $returnUrl, $finalUrl);
            if (isset($initResult) && isset($initResult->url) && isset($initResult->token)) {
                $result = array(
                    "url" => $initResult->url,
                    "token_ws" => $initResult->token
                );
            } else {
                throw new Exception('No se ha creado la transacción para, amount: ' . $amount . ', sessionId: ' . $sessionId . ', buyOrder: ' . $buyOrder);
            }
        } catch (Exception $e) {
            $result = array(
                "error" => 'Error al crear la transacción',
                "detail" => $e->getMessage()
            );
        }
        return $result;
    }

    public function commitTransaction($tokenWs)
    {
        $result = array();
        try {
            if ($tokenWs == null) {
                throw new Exception("El token webpay es requerido");
            }
            return $this->transaction->getTransactionResult($tokenWs);
        } catch (Exception $e) {
            $result = array(
                "error" => 'Error al confirmar la transacción',
                "detail" => $e->getMessage()
            );
        }
        return $result;
    }
}
