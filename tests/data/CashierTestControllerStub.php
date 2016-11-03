<?php

namespace yii2mod\braintree\tests\data;

use Yii;
use yii2mod\braintree\controllers\WebhookController;

class CashierTestControllerStub extends WebhookController
{
    /**
     * @inheritdoc
     */
    protected function parseBraintreeNotification()
    {
        $requestParams = new \stdClass;
        $requestParams->kind = Yii::$app->request->getBodyParam('kind');
        $requestParams->subscription = new \stdClass;
        $requestParams->subscription->id = Yii::$app->request->getBodyParam('subscription')['id'];

        return $requestParams;
    }
}