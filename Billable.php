<?php

namespace yii2mod\braintree;

use Carbon\Carbon;
use Exception;
use InvalidArgumentException;
use Yii;
use yii\helpers\ArrayHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use Braintree\PaymentMethod;
use Braintree\PaypalAccount;
use Braintree\Customer as BraintreeCustomer;
use Braintree\TransactionSearch;
use Braintree\Transaction as BraintreeTransaction;
use Braintree\Subscription as BraintreeSubscription;
use yii2mod\braintree\models\SubscriptionModel;
use yii2mod\collection\Collection;

/**
 * Class Billable
 * @package yii2mod\braintree
 */
trait Billable
{
    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param int $amount
     * @param array $options
     * @return \Braintree\Transaction
     * @throws Exception
     */
    public function charge($amount, array $options = [])
    {
        $customer = $this->asBraintreeCustomer();

        $response = BraintreeTransaction::sale(array_merge([
            'amount' => $amount * (1 + ($this->taxPercentage() / 100)),
            'paymentMethodToken' => $customer->paymentMethods[0]->token,
            'options' => [
                'submitForSettlement' => true,
            ],
            'recurring' => true,
        ], $options));

        if (!$response->success) {
            throw new Exception('Braintree was unable to perform a charge: ' . $response->message);
        }

        return $response;
    }

    /**
     * Invoice the customer for the given amount.
     *
     * @param string $description
     * @param int $amount
     * @param array $options
     * @return \Braintree\Transaction
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        return $this->charge($amount, array_merge($options, [
            'customFields' => [
                'description' => $description,
            ],
        ]));
    }

    /**
     * Begin creating a new subscription.
     *
     * @param string $subscription
     * @param string $plan
     * @return SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the user is on trial.
     *
     * @param string $subscription
     * @param string|null $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
        $subscription->braintreePlan === $plan;
    }

    /**
     * Determine if the user is on a "generic" trial at the user level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trialEndAt && Carbon::now()->lt(Carbon::createFromFormat('Y-m-d H:i:s', $this->trialEndAt));
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param string $subscription
     * @param string|null $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
        $subscription->braintreePlan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param string $subscription
     * @return SubscriptionModel|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->getSubscriptions()->where(['name' => $subscription])->one();
    }

    /**
     * @return mixed
     */
    public function getSubscriptions()
    {
        return $this->hasMany(SubscriptionModel::className(), ['userId' => 'id'])->orderBy(['createdAt' => SORT_DESC]);
    }

    /**
     * Find an invoice by ID.
     *
     * @param string $id
     * @return Invoice|null
     */
    public function findInvoice($id)
    {
        try {
            $invoice = BraintreeTransaction::find($id);
            if ($invoice->customerDetails->id != $this->braintreeId) {
                return;
            }
            return new Invoice($this, $invoice);
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param string $id
     * @return Invoice
     * @throws NotFoundHttpException
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param string $id
     * @param array $data
     * @return Response
     */
    public function downloadInvoice($id, array $data)
    {
        return $this->findInvoiceOrFail($id)->download($data);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param bool $includePending
     * @param array $parameters
     * @return Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = [];
        $customer = $this->asBraintreeCustomer();

        $parameters = array_merge([
            TransactionSearch::customerId()->is($customer->id),
            TransactionSearch::createdAt()->between(
                Carbon::today()->subYears(2)->format('m/d/Y H:i'),
                Carbon::tomorrow()->format('m/d/Y H:i')
            ),
        ], $parameters);

        $transactions = BraintreeTransaction::search($parameters);

        // Here we will loop through the Braintree invoices and create our own custom Invoice
        // instance that gets more helper methods and is generally more convenient to work
        // work than the plain Braintree objects are. Then, we'll return the full array.
        if (!is_null($transactions)) {
            foreach ($transactions as $transaction) {
                if ($transaction->status == BraintreeTransaction::SETTLED || $includePending) {
                    $invoices[] = new Invoice($this, $transaction);
                }
            }
        }
        return Collection::make($invoices);
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param array $parameters
     * @return Collection
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Update customer's credit card.
     *
     * @param  string $token
     * @throws Exception
     */
    public function updateCard($token)
    {
        $customer = $this->asBraintreeCustomer();

        $response = PaymentMethod::create([
            'customerId' => $customer->id,
            'paymentMethodNonce' => $token,
            'options' => [
                'makeDefault' => true,
                'verifyCard' => true,
            ],
        ]);

        if (!$response->success) {
            throw new Exception('Braintree was unable to create a payment method: ' . $response->message);
        }

        $paypalAccount = $response->paymentMethod instanceof PaypalAccount;

        $this->paypalEmail = $paypalAccount ? $response->paymentMethod->email : null;
        $this->cardBrand = $paypalAccount ? null : $response->paymentMethod->cardType;
        $this->cardLastFour = $paypalAccount ? null : $response->paymentMethod->last4;

        $this->save();

        $this->updateSubscriptionsToPaymentMethod($response->paymentMethod->token);
    }

    /**
     * Update the payment method token for all of the user's subscriptions.
     *
     * @param  string $token
     * @return void
     */
    protected function updateSubscriptionsToPaymentMethod($token)
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->active()) {
                BraintreeSubscription::update($subscription->braintreeId, [
                    'paymentMethodToken' => $token,
                ]);
            }
        }
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param string $coupon
     * @param string $subscription
     * @param bool $removeOthers
     * @return void
     */
    public function applyCoupon($coupon, $subscription = 'default', $removeOthers = false)
    {
        $subscription = $this->subscription($subscription);

        if (!$subscription) {
            throw new InvalidArgumentException("Unable to apply coupon. Subscription does not exist.");
        }

        $subscription->applyCoupon($coupon, $removeOthers);
    }

    /**
     * Determine if the user is actively subscribed to one of the given plans.
     *
     * @param  array|string $plans
     * @param  string $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (!$subscription || !$subscription->valid()) {
            return false;
        }

        foreach ((array)$plans as $plan) {
            if ($subscription->braintreePlan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return !is_null($this->getSubscriptions()->where(['braintreePlan' => $plan])->one());
    }

    /**
     * Create a Braintree customer for the given user.
     *
     * @param string $token
     * @param array $options
     * @return BraintreeCustomer
     * @throws Exception
     */
    public function createAsBraintreeCustomer($token, array $options = [])
    {
        $response = BraintreeCustomer::create(
            array_replace_recursive([
                'firstName' => ArrayHelper::getValue(explode(' ', $this->username), 0),
                'lastName' => ArrayHelper::getValue(explode(' ', $this->username), 1),
                'email' => $this->email,
                'paymentMethodNonce' => $token,
                'creditCard' => [
                    'options' => [
                        'verifyCard' => true,
                    ],
                ],
            ], $options)
        );

        if (!$response->success) {
            throw new Exception('Unable to create Braintree customer: ' . $response->message);
        }

        $paymentMethod = $response->customer->paymentMethods[0];
        $paypalAccount = $paymentMethod instanceof PaypalAccount;

        //Update user braintree info
        $this->braintreeId = $response->customer->id;
        $this->paypalEmail = $paypalAccount ? $paymentMethod->email : null;
        $this->cardBrand = !$paypalAccount ? $paymentMethod->cardType : null;
        $this->cardLastFour = !$paypalAccount ? $paymentMethod->last4 : null;

        $this->save();

        return $response->customer;
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Get the Braintree customer for the user.
     *
     * @return \Braintree\Customer
     */
    public function asBraintreeCustomer()
    {
        return BraintreeCustomer::find($this->braintreeId);
    }

    /**
     * Determine if the entity has a Braintree customer ID.
     *
     * @return bool
     */
    public function hasBraintreeId()
    {
        return !is_null($this->braintreeId);
    }
}
