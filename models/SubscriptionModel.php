<?php

namespace yii2mod\braintree\models;

use Carbon\Carbon;
use LogicException;
use Yii;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii2mod\behaviors\CarbonBehavior;
use Braintree\Subscription as BraintreeSubscription;
use Braintree\Plan as BraintreePlan;
use yii2mod\braintree\BraintreeService;
use yii2mod\collection\Collection;

/**
 * This is the model class for table "Subscription".
 *
 * @property integer $id
 * @property integer $userId
 * @property string $name
 * @property string $braintreeId
 * @property string $braintreePlan
 * @property integer $quantity
 * @property Carbon $trialEndAt
 * @property Carbon $endAt
 * @property integer $createdAt
 * @property integer $updatedAt
 *
 * @property \yii\db\ActiveRecord $user
 */
class SubscriptionModel extends ActiveRecord
{

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'subscription';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['userId', 'name', 'braintreeId', 'braintreePlan'], 'required'],
            [['userId', 'quantity'], 'integer'],
            ['quantity', 'default', 'value' => 1],
            [['name', 'braintreeId', 'braintreePlan'], 'string', 'max' => 255],
            [['trialEndAt', 'endAt'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'userId' => Yii::t('app', 'User ID'),
            'name' => Yii::t('app', 'Name'),
            'braintreeId' => Yii::t('app', 'Braintree ID'),
            'braintreePlan' => Yii::t('app', 'Braintree Plan'),
            'quantity' => Yii::t('app', 'Quantity'),
            'trialEndAt' => Yii::t('app', 'Trial End At'),
            'endAt' => Yii::t('app', 'End At'),
            'createdAt' => Yii::t('app', 'Created At'),
            'updatedAt' => Yii::t('app', 'Updated At'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => 'yii\behaviors\TimestampBehavior',
                'createdAtAttribute' => 'createdAt',
                'updatedAtAttribute' => 'updatedAt',
                'value' => function () {
                    $currentDateExpression = Yii::$app->db->getDriverName() === 'sqlite' ? "DATETIME('now')" : 'NOW()';

                    return new Expression($currentDateExpression);
                }
            ],
            'carbon' => [
                'class' => CarbonBehavior::className(),
                'attributes' => [
                    'trialEndAt',
                    'endAt'
                ],
            ],
        ];
    }

    /**
     * User relation
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(Yii::$app->user->identityClass, ['id' => 'userId']);
    }

    /**
     * Determine if the subscription is active, on trial, or within its grace period.
     *
     * @return bool
     */
    public function valid()
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active()
    {
        return is_null($this->endAt) || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return !is_null($this->endAt);
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (!is_null($this->trialEndAt)) {
            return Carbon::today()->lt($this->trialEndAt);
        } else {
            return false;
        }
    }


    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        if (!is_null($endAt = $this->endAt)) {
            return Carbon::now()->lt(Carbon::instance($endAt));
        } else {
            return false;
        }
    }

    /**
     * Swap the subscription to a new Braintree plan.
     *
     * @param string $plan
     * @return $this
     * @throws Exception
     */
    public function swap($plan)
    {
        if ($this->onGracePeriod() && $this->braintreePlan === $plan) {
            return $this->resume();
        }

        if (!$this->active()) {
            return $this->user->newSubscription($this->name, $plan)
                ->skipTrial()->create();
        }

        $plan = BraintreeService::findPlan($plan);

        if ($this->wouldChangeBillingFrequency($plan)) {
            return $this->swapAcrossFrequencies($plan);
        }

        $subscription = $this->asBraintreeSubscription();
        $response = BraintreeSubscription::update($subscription->id, [
            'planId' => $plan->id,
            'price' => $plan->price * (1 + ($this->user->taxPercentage() / 100)),
            'neverExpires' => true,
            'numberOfBillingCycles' => null,
            'options' => [
                'prorateCharges' => true,
            ],
        ]);

        if ($response->success) {
            $this->name = $plan->name;
            $this->braintreePlan = $plan->id;
            $this->endAt = null;
            $this->trialEndAt = null;
            $this->save();
        } else {
            throw new Exception('Braintree failed to swap plans: ' . $response->message);
        }

        return $this;
    }

    /**
     * Determine if the given plan would alter the billing frequency.
     *
     * @param  string $plan
     * @return bool
     */
    protected function wouldChangeBillingFrequency($plan)
    {
        return $plan->billingFrequency !==
        BraintreeService::findPlan($this->braintreePlan)->billingFrequency;
    }

    /**
     * Swap the subscription to a new Braintree plan with a different frequency.
     *
     * @param string $plan
     * @return $this
     */
    protected function swapAcrossFrequencies($plan)
    {
        $currentPlan = BraintreeService::findPlan($this->braintreePlan);

        $discount = $this->switchingToMonthlyPlan($currentPlan, $plan)
            ? $this->getDiscountForSwitchToMonthly($currentPlan, $plan)
            : $this->getDiscountForSwitchToYearly();

        $options = [];

        if ($discount->amount > 0 && $discount->numberOfBillingCycles > 0) {
            $options = ['discounts' => ['add' => [
                [
                    'inheritedFromId' => 'plan-credit',
                    'amount' => (float)$discount->amount,
                    'numberOfBillingCycles' => $discount->numberOfBillingCycles,
                ],
            ]]];
        }

        $this->cancelNow();

        return $this->user->newSubscription($this->name, $plan->id)
            ->skipTrial()->create(null, [], $options);
    }

    /**
     * Determine if the user is switching form yearly to monthly billing.
     *
     * @param  BraintreePlan $currentPlan
     * @param  BraintreePlan $plan
     * @return bool
     */
    protected function switchingToMonthlyPlan($currentPlan, $plan)
    {
        return $currentPlan->billingFrequency == 12 && $plan->billingFrequency == 1;
    }

    /**
     * Get the discount to apply when switching to a monthly plan.
     *
     * @param  BraintreePlan $currentPlan
     * @param  BraintreePlan $plan
     * @return object
     */
    protected function getDiscountForSwitchToMonthly($currentPlan, $plan)
    {
        return (object)[
            'amount' => $plan->price,
            'numberOfBillingCycles' => floor(
                $this->moneyRemainingOnYearlyPlan($currentPlan) / $plan->price
            ),
        ];
    }

    /**
     * Calculate the amount of discount to apply to a swap to monthly billing.
     *
     * @param BraintreePlan $plan
     * @return float
     */
    protected function moneyRemainingOnYearlyPlan($plan)
    {
        return ($plan->price / 365) * Carbon::today()->diffInDays(Carbon::instance(
            $this->asBraintreeSubscription()->billingPeriodEndDate
        ), false);
    }

    /**
     * Get the discount to apply when switching to a yearly plan.
     *
     * @return object
     */
    protected function getDiscountForSwitchToYearly()
    {
        $amount = 0;
        foreach ($this->asBraintreeSubscription()->discounts as $discount) {
            if ($discount->id == 'plan-credit') {
                $amount += (float)$discount->amount * $discount->numberOfBillingCycles;
            }
        }
        return (object)[
            'amount' => $amount,
            'numberOfBillingCycles' => 1,
        ];
    }

    /**
     * Apply a coupon to the subscription.
     *
     * @param string $coupon
     * @param bool $removeOthers
     * @return void
     */
    public function applyCoupon($coupon, $removeOthers = false)
    {
        if (!$this->active()) {
            throw new \InvalidArgumentException("Unable to apply coupon. Subscription not active.");
        }
        BraintreeSubscription::update($this->braintreeId, [
            'discounts' => [
                'add' => [[
                    'inheritedFromId' => $coupon,
                ]],
                'remove' => $removeOthers ? $this->currentDiscounts() : [],
            ],
        ]);
    }

    /**
     * Get the current discounts for the subscription.
     *
     * @return array
     */
    protected function currentDiscounts()
    {
        return Collection::make($this->asBraintreeSubscription()->discounts)->map(function ($discount) {
            return $discount->id;
        })->all();
    }

    /**
     * Cancel the subscription.
     *
     * @return $this
     */
    public function cancel()
    {
        $subscription = $this->asBraintreeSubscription();

        if ($this->onTrial()) {
            BraintreeSubscription::cancel($subscription->id);
            $this->markAsCancelled();
        } else {
            BraintreeSubscription::update($subscription->id, [
                'numberOfBillingCycles' => $subscription->currentBillingCycle,
            ]);
            $this->endAt = $subscription->billingPeriodEndDate->format('Y-m-d H:i:s');
            $this->save();
        }

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        $subscription = $this->asBraintreeSubscription();

        BraintreeSubscription::cancel($subscription->id);

        $this->markAsCancelled();

        return $this;
    }

    /**
     * Mark the subscription as cancelled.
     *
     * @return void
     */
    public function markAsCancelled()
    {
        $this->endAt = Carbon::now();
        $this->save();
    }

    /**
     * Resume the cancelled subscription.
     *
     * @return $this
     *
     * @throws \LogicException
     */
    public function resume()
    {
        if (!$this->onGracePeriod()) {
            throw new LogicException('Unable to resume subscription that is not within grace period.');
        }
        $subscription = $this->asBraintreeSubscription();

        BraintreeSubscription::update($subscription->id, [
            'neverExpires' => true,
            'numberOfBillingCycles' => null,
        ]);

        $this->endAt = null;
        $this->save();

        return $this;
    }

    /**
     * Get the subscription as a Braintree subscription object.
     *
     * @return \Braintree\Subscription
     */
    public function asBraintreeSubscription()
    {
        return BraintreeSubscription::find($this->braintreeId);
    }
}