<?php

use yii2mod\cashier\Cashier;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/* @var $user \yii\db\ActiveRecord */
/* @var $invoice \yii2mod\braintree\Invoice */
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <title>Invoice</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background: #fff;
            background-image: none;
            font-size: 12px;
        }

        address {
            margin-top: 15px;
        }

        h2 {
            font-size: 28px;
            color: #cccccc;
        }

        .container {
            padding-top: 30px;
        }

        .invoice-head td {
            padding: 0 8px;
        }

        .invoice-body {
            background-color: transparent;
        }

        .logo {
            padding-bottom: 10px;
        }

        .table th {
            vertical-align: bottom;
            font-weight: bold;
            padding: 8px;
            line-height: 20px;
            text-align: left;
        }

        .table td {
            padding: 8px;
            line-height: 20px;
            text-align: left;
            vertical-align: top;
            border-top: 1px solid #dddddd;
        }

        .well {
            margin-top: 15px;
        }
    </style>
</head>

<body>
<div class="container">
    <table style="margin-left: auto; margin-right: auto" width="550">
        <tr>
            <td width="160">
                &nbsp;
            </td>

            <!-- Organization Name / Image -->
            <td align="right">
                <strong><?php echo isset($header) ? $header : $vendor; ?></strong>
            </td>
        </tr>
        <tr valign="top">
            <td style="font-size:28px;color:#cccccc;">
                Receipt
            </td>

            <!-- Organization Name / Date -->
            <td>
                <br><br>
                <strong>To:</strong> <?php echo ArrayHelper::getValue($user, 'email', 'name'); ?>
                <br>
                <strong>Date:</strong> <?php echo $invoice->date()->toFormattedDateString(); ?>
            </td>
        </tr>
        <tr valign="top">
            <!-- Organization Details -->
            <td style="font-size:9px;">
                <?php echo $vendor; ?><br>
                <?php if (isset($street)): ?>
                    <?php echo $street; ?><br>
                <?php endif; ?>
                <?php if (isset($location)): ?>
                    <?php echo $location; ?><br>
                <?php endif; ?>
                <?php if (isset($phone)): ?>
                    <strong>T</strong> <?php echo $phone; ?><br>
                <?php endif; ?>
                <?php if (isset($url)): ?>
                    <?php echo Html::a($url, $url); ?>
                <?php endif; ?>
            </td>
            <td>
                <!-- Invoice Info -->
                <p>
                    <strong>Product:</strong> <?php echo $product; ?><br>
                    <strong>Invoice Number:</strong> <?php echo $invoice->id; ?><br>
                </p>

                <!-- Extra / VAT Information -->
                <?php if (isset($vat)): ?>
                    <p>
                        <?php echo $vat; ?>
                    </p>
                <?php endif; ?>

                <br><br>

                <!-- Invoice Table -->
                <table width="100%" class="table" border="0">
                    <tr>
                        <th align="left">Description</th>
                        <th align="right">Amount</th>
                    </tr>

                    <!-- Display The Invoice Charges -->
                    <tr>
                        <td>
                            <?php if ($invoice->planId): ?>
                                Subscription To "<?php echo $invoice->planId; ?>"
                            <?php elseif (isset($invoice->customFields['description'])): ?>
                                <?php echo $invoice->customFields['description']; ?>
                            <?php else: ?>
                                Charge
                            <?php endif; ?>
                        </td>

                        <td><?php echo $invoice->subtotal(); ?></td>
                    </tr>

                    <!-- Display The Add-Ons -->
                    <?php if ($invoice->hasAddOn()): ?>
                        <tr>
                            <td>Add-Ons <?php echo '(' . implode(', ', $invoice->addOns()) . ')' ?></td>
                            <td><?php echo $invoice->addOn(); ?></td>
                        </tr>
                    <?php endif; ?>

                    <!-- Display The Discount -->
                    <?php if ($invoice->hasDiscount()): ?>
                        <tr>
                            <td>Discounts <?php echo '(' . implode(', ', $invoice->coupons()) . ')' ?></td>
                            <td>-<?php echo $invoice->discount(); ?></td>
                        </tr>
                    <?php endif; ?>

                    <!-- Display The Final Total -->
                    <tr style="border-top:2px solid #000;">
                        <td style="text-align: right;"><strong>Total</strong></td>
                        <td><strong><?php echo $invoice->total(); ?></strong></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
