<?php

namespace yii2mod\braintree\tests;

use Carbon\Carbon;
use Yii;
use yii2mod\braintree\tests\data\CashierTestControllerStub;
use yii2mod\braintree\tests\data\User;

class BraintreeTest extends TestCase
{
    protected function getTestToken()
    {
        return 'fake-valid-nonce';
    }

    // Tests:

    public function testSubscriptionsCanBeCreated()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintreeId);
        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());

        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->endAt;
        $subscription->updateAttributes(['endAt' => Carbon::now()->subDays(5)]);

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        $subscription->updateAttributes(['endAt' => $oldGracePeriod]);

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Swap Plan
        $subscription->swap('monthly-10-2');

        $this->assertEquals('monthly-10-2', $subscription->braintreePlan);

        // Invoice Tests
        $invoice = $user->invoicesIncludingPending()[0];
        $foundInvoice = $user->findInvoice($invoice->id);
        $this->assertEquals($invoice->id, $foundInvoice->id);
        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertEquals(0, count($invoice->coupons()));
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    public function testCreatingSubscriptionWithCoupons()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->withCoupon('coupon-1')->create($this->getTestToken());

        $subscription = $user->subscription('main');
        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Invoice Tests
        $invoice = $user->invoicesIncludingPending()[0];
        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$5.00', $invoice->total());
        $this->assertEquals('$5.00', $invoice->amountOff());
    }

    public function testCreatingSubscriptionWithTrial()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->trialDays(7)->create($this->getTestToken());
        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trialEndAt->day);

        // Cancel Subscription
        $subscription->cancel();

        // Braintree trials are just cancelled out right since we have no good way to cancel them
        // and then later resume them
        $this->assertFalse($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
    }

    public function testApplyingCouponsToExistingCustomers()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());
        $user->applyCoupon('coupon-1', 'main');
        $subscription = $user->subscription('main')->asBraintreeSubscription();
        foreach ($subscription->discounts as $discount) {
            if ($discount->id === 'coupon-1') {
                return;
            }
        }

        $this->fail('Coupon was not applied to existing customer.');
    }

    public function testYearlyToMonthlyProperlyProrates()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'yearly-100-1')->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintreeId);

        // Swap To Monthly
        $user->subscription('main')->swap('monthly-10-1');
        $user->refresh();

        $this->assertEquals(2, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintreeId);
        $this->assertEquals('monthly-10-1', $user->subscription('main')->braintreePlan);

        $braintreeSubscription = $user->subscription('main')->asBraintreeSubscription();
        foreach ($braintreeSubscription->discounts as $discount) {
            if ($discount->id === 'plan-credit') {
                $this->assertEquals('10.00', $discount->amount);
                $this->assertEquals(9, $discount->numberOfBillingCycles);
                return;
            }
        }

        $this->fail('Proration when switching to yearly was not done properly.');
    }

    public function testMonthlyToYearlyProperlyProrates()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'yearly-100-1')->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintreeId);

        // Swap To Monthly
        $user->subscription('main')->swap('monthly-10-1');
        $user->refresh();

        // Swap Back To Yearly
        $user->subscription('main')->swap('yearly-100-1');
        $user->refresh();

        $this->assertEquals(3, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintreeId);
        $this->assertEquals('yearly-100-1', $user->subscription('main')->braintreePlan);

        $braintreeSubscription = $user->subscription('main')->asBraintreeSubscription();
        foreach ($braintreeSubscription->discounts as $discount) {
            if ($discount->id === 'plan-credit') {
                $this->assertEquals('90.00', $discount->amount);
                $this->assertEquals(1, $discount->numberOfBillingCycles);
                return;
            }
        }
        $this->fail('Proration when switching to yearly was not done properly.');
    }

    public function testMarkingAsCancelledFromWebhook()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $subscription = $user->subscription('main');

        Yii::$app->request->bodyParams = [
            'kind' => 'SubscriptionCanceled',
            'subscription' => [
                'id' => $subscription->braintreeId,
            ]
        ];

        $controller = new CashierTestControllerStub('webhook', Yii::$app);
        $response = $controller->actionHandleWebhook();

        $this->assertEquals(200, $response->getStatusCode());

        $user->refresh();

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->cancelled());
    }

    public function testMarkingSubscriptionCancelledOnGracePeriodAsCancelledNowFromWebhook()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $subscription = $user->subscription('main');
        $subscription->cancel();
        $this->assertTrue($subscription->onGracePeriod());

        Yii::$app->request->bodyParams = [
            'kind' => 'SubscriptionCanceled',
            'subscription' => [
                'id' => $subscription->braintreeId,
            ]
        ];

        $controller = new CashierTestControllerStub('webhook', Yii::$app);
        $response = $controller->actionHandleWebhook();

        $this->assertEquals(200, $response->getStatusCode());

        $user->refresh();

        $subscription = $user->subscription('main');

        $this->assertFalse($subscription->onGracePeriod());
    }
}