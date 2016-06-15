<?php

namespace yii2mod\braintree\controllers;

use yii2mod\braintree\models\SubscriptionModel;
use Exception;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\Inflector;
use yii\web\Controller;
use Braintree\WebhookNotification;
use yii\web\Response;

/**
 * Class WebhookController
 * @package yii2mod\braintree\controllers
 */
class WebhookController extends Controller
{
    /**
     * @var bool whether to enable CSRF validation for the actions in this controller.
     */
    public $enableCsrfValidation = false;

    /**
     * Returns a list of behaviors that this component should behave as.
     *
     * Child classes may override this method to specify the behaviors they want to behave as.
     * @return array
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'handle-webhook' => ['post'],
                ]
            ]
        ];
    }

    /**
     * Handle a Braintree webhook call.
     *
     * @return mixed|void
     */
    public function actionHandleWebhook()
    {
        try {
            $webhook = $this->parseBraintreeNotification();
        } catch (Exception $e) {
            return;
        }

        $method = 'handle' . Inflector::camelize(str_replace('.', '_', $webhook->kind));

        if (method_exists($this, $method)) {
            return $this->{$method}($webhook);
        } else {
            return $this->missingMethod();
        }
    }

    /**
     * Parse the given Braintree webhook notification request.
     *
     * @return WebhookNotification
     */
    protected function parseBraintreeNotification()
    {
        return WebhookNotification::parse(Yii::$app->request->post('bt_signature'), Yii::$app->request->post('bt_payload'));
    }

    /**
     * Handle a subscription cancellation notification from Braintree.
     *
     * @param WebhookNotification $webhook
     * @return Response
     */
    protected function handleSubscriptionCanceled($webhook)
    {
        return $this->cancelSubscription($webhook->subscription->id);
    }

    /**
     * Handle a subscription expiration notification from Braintree.
     *
     * @param WebhookNotification $webhook
     * @return Response
     */
    protected function handleSubscriptionExpired($webhook)
    {
        return $this->cancelSubscription($webhook->subscription->id);
    }

    /**
     * Handle a subscription cancellation notification from Braintree.
     *
     * @param string $subscriptionId
     * @return Response
     */
    protected function cancelSubscription($subscriptionId)
    {
        $subscription = $this->getSubscriptionById($subscriptionId);

        if ($subscription && !$subscription->cancelled()) {
            $subscription->markAsCancelled();
        }

        return new Response([
            'statusCode' => 200,
            'statusText' => 'Webhook Handled'
        ]);
    }

    /**
     * Get the user for the given subscription ID.
     *
     * @param string $subscriptionId
     * @return null|SubscriptionModel
     */
    protected function getSubscriptionById($subscriptionId)
    {
        return SubscriptionModel::find()->where(['braintreeId' => $subscriptionId])->one();
    }

    /**
     * Handle calls to missing methods on the controller.
     *
     * @return mixed
     */
    public function missingMethod()
    {
        return new Response([
            'statusCode' => 200
        ]);
    }
}
