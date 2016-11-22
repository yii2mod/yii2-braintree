<?php

namespace yii2mod\braintree\tests;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * This is the base class for all yii framework unit tests.
 */
class TestCase extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->mockApplication();
        $this->setupTestDbData();
    }

    protected function tearDown()
    {
        $this->destroyApplication();
    }

    /**
     * Populates Yii::$app with a new application
     * The application will be destroyed on tearDown() automatically.
     *
     * @param array $config The application configuration, if needed
     * @param string $appClass name of the application class to create
     */
    protected function mockApplication($config = [], $appClass = '\yii\web\Application')
    {
        new $appClass(ArrayHelper::merge([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => $this->getVendorPath(),
            'bootstrap' => [
                [
                    'class' => 'yii2mod\braintree\BraintreeBootstrap',
                    'environment' => 'sandbox',
                    'merchantId' => getenv('MERCHANT_ID'),
                    'publicKey' => getenv('PUBLIC_KEY'),
                    'privateKey' => getenv('PRIVATE_KEY'),
                ],
            ],
            'components' => [
                'db' => [
                    'class' => 'yii\db\Connection',
                    'dsn' => 'sqlite::memory:',
                ],
                'request' => [
                    'hostInfo' => 'http://domain.com',
                    'scriptUrl' => 'index.php',
                ],
                'user' => [
                    'identityClass' => 'yii2mod\braintree\tests\data\User',
                ],
            ],
        ], $config));
    }

    /**
     * @return string vendor path
     */
    protected function getVendorPath()
    {
        return dirname(__DIR__) . '/vendor';
    }

    /**
     * Destroys application in Yii::$app by setting it to null.
     */
    protected function destroyApplication()
    {
        Yii::$app = null;
    }

    /**
     * Setup tables for test ActiveRecord
     */
    protected function setupTestDbData()
    {
        $db = Yii::$app->getDb();

        // Structure :

        $db->createCommand()->createTable('subscription', [
            'id' => 'pk',
            'userId' => 'integer not null',
            'name' => 'string not null',
            'braintreeId' => 'string not null',
            'braintreePlan' => 'string not null',
            'quantity' => 'integer not null',
            'trialEndAt' => 'timestamp null default null',
            'endAt' => 'timestamp null default null',
            'createdAt' => 'timestamp null default null',
            'updatedAt' => 'timestamp null default null',
        ])->execute();

        $db->createCommand()->createTable('user', [
            'id' => 'pk',
            'username' => 'string',
            'email' => 'string',
            'braintreeId' => 'string',
            'paypalEmail' => 'string',
            'cardBrand' => 'string',
            'cardLastFour' => 'string',
            'trialEndAt' => 'timestamp null default null',
        ])->execute();

        $db->createCommand()->insert('user', [
            'username' => 'John Doe',
            'email' => 'johndoe@domain.com',
        ])->execute();
    }
}
