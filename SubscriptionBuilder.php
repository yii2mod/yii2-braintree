<?php

namespace yii2mod\braintree;

use Carbon\Carbon;
use yii\base\Exception;
use yii2mod\braintree\models\SubscriptionModel;
use Braintree\Subscription as BraintreeSubscription;

/**
 * Class SubscriptionBuilder
 * @package yii2mod\braintree
 */
class SubscriptionBuilder
{
    /**
     * The user model that is subscribing.
     *
     * @var \yii\db\ActiveRecord
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * Create a new subscription builder instance.
     *
     * @param mixed $user
     * @param string $name
     * @param string $plan
     */
    public function __construct($user, $name, $plan)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param int $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;
        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;
        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param string $coupon
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;
        return $this;
    }

    /**
     * Add a new Braintree subscription to the user.
     *
     * @param array $options
     * @return SubscriptionModel
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Braintree subscription.
     *
     * @param string|null $token
     * @param array $customerOptions
     * @param array $subscriptionOptions
     * @return SubscriptionModel
     * @throws Exception
     */
    public function create($token = null, array $customerOptions = [], array $subscriptionOptions = [])
    {
        $payload = $this->getSubscriptionPayload(
            $this->getBraintreeCustomer($token, $customerOptions), $subscriptionOptions
        );

        if ($this->coupon) {
            $payload = $this->addCouponToPayload($payload);
        }

        $response = BraintreeSubscription::create($payload);

        if (!$response->success) {
            throw new Exception('Braintree failed to create subscription: ' . $response->message);
        }

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }

        $subscriptionModel = new SubscriptionModel([
            'userId' => $this->user->id,
            'name' => $this->name,
            'braintreeId' => $response->subscription->id,
            'braintreePlan' => $this->plan,
            'trialEndAt' => $trialEndsAt,
            'endAt' => null
        ]);

        if ($subscriptionModel->save()) {
            return $subscriptionModel;
        } else {
            throw new Exception('Subscription was not saved.');
        }
    }

    /**
     * Get the base subscription payload for Braintree.
     *
     * @param  \Braintree\Customer $customer
     * @param  array $options
     * @return array
     */
    protected function getSubscriptionPayload($customer, array $options = [])
    {
        $plan = BraintreeService::findPlan($this->plan);

        if ($this->skipTrial) {
            $trialDuration = 0;
        } else {
            $trialDuration = $this->trialDays ?: 0;
        }

        return array_merge([
            'planId' => $this->plan,
            'price' => $plan->price * (1 + ($this->user->taxPercentage() / 100)),
            'paymentMethodToken' => $customer->paymentMethods[0]->token,
            'trialPeriod' => $this->trialDays && !$this->skipTrial ? true : false,
            'trialDurationUnit' => 'day',
            'trialDuration' => $trialDuration,
        ], $options);
    }

    /**
     * Add the coupon discount to the Braintree payload.
     *
     * @param array $payload
     * @return array
     */
    protected function addCouponToPayload(array $payload)
    {
        if (!isset($payload['discounts']['add'])) {
            $payload['discounts']['add'] = [];
        }

        $payload['discounts']['add'][] = [
            'inheritedFromId' => $this->coupon,
        ];

        return $payload;
    }

    /**
     * Get the Braintree customer instance for the current user and token.
     *
     * @param string|null $token
     * @param array $options
     * @return \Braintree\Customer
     */
    protected function getBraintreeCustomer($token = null, array $options = [])
    {
        if (!$this->user->braintreeId) {
            $customer = $this->user->createAsBraintreeCustomer($token, $options);
        } else {
            $customer = $this->user->asBraintreeCustomer();
            if ($token) {
                $this->user->updateCard($token);
            }
        }

        return $customer;
    }
}
