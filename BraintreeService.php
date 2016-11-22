<?php

namespace yii2mod\braintree;

use Braintree\Plan as BraintreePlan;
use Exception;

/**
 * Class BraintreeService
 *
 * @package yii2mod\braintree
 */
class BraintreeService
{
    /**
     * Get the Braintree plan that has the given ID.
     *
     * @param string $id
     *
     * @return BraintreePlan
     *
     * @throws Exception
     */
    public static function findPlan($id)
    {
        $plans = BraintreePlan::all();

        foreach ($plans as $plan) {
            if ($plan->id === $id) {
                return $plan;
            }
        }

        throw new Exception("Unable to find Braintree plan with ID [{$id}].");
    }
}
