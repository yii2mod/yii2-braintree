<?php

namespace yii2mod\braintree;

use Braintree\Configuration;
use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Class BraintreeBootstrap
 *
 * @package yii2mod\braintree
 */
class BraintreeBootstrap implements BootstrapInterface
{
    /**
     * @var string braintree environment
     */
    public $environment;

    /**
     * @var string braintree merchantId
     */
    public $merchantId;

    /**
     * @var string braintree publicKey
     */
    public $publicKey;

    /**
     * @var string braintree privateKey
     */
    public $privateKey;

    /**
     * Bootstrap method to be called during application bootstrap stage.
     *
     * @param Application $app the application currently running
     */
    public function bootstrap($app)
    {
        Configuration::environment($this->environment);
        Configuration::merchantId($this->merchantId);
        Configuration::publicKey($this->publicKey);
        Configuration::privateKey($this->privateKey);
    }
}
