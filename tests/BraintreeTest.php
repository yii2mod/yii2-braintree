<?php

namespace yii2mod\braintree\tests;

use Carbon\Carbon;
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
        $user->newSubscription('main', 'monthly-10-1')
            ->trialDays(7)->create($this->getTestToken());

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
}