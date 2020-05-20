<?php

namespace App;


class PaymentSignature
{
    private $requiredFields = [
        'order_id',
        'amount',
        'order_desc',
        'merchant_id',
    ];

    public function getSignature(string $password , $params = []): string
    {
        $params = array_filter($params,'strlen');

        $this->checkRequiredFields($params);

        ksort($params);
        $params = array_values($params);
        array_unshift( $params , $password );
        $params = join('|',$params);

        return (sha1($params));
    }

    private function checkRequiredFields(array $params): void
    {
        foreach ($this->requiredFields as $field) {
            if (empty($params[$field])) {
                throw new \InvalidArgumentException('Field "' . $field . '" is required');
            }
        }
    }
}
