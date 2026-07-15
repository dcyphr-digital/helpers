<?php

namespace DcyphrDigital\Helpers\Support;

trait PaymentStatusFormatter
{
    public const STATUS = [
        'Credit Card'  => ['mastercard', 'visa', 'card'],
        'Amex'         => ['amex'],
        'PayPal'       => ['paypal'],
        'Afterpay'     => ['afterpay'],
        'Google Pay'   => ['googlepay'],
        'Apple Pay'    => ['applepay'],
        'Link'         => ['link'],
        'Gift Voucher' => ['gift voucher'],
        'Other'        => ['other'],
    ];

    public static function formatPaymentStatus(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = strtolower($value);

        foreach (self::STATUS as $key => $values) {
            if (in_array(strtolower($value), $values)) {
                return $key;
            }
        }

        return $value;
    }
}
