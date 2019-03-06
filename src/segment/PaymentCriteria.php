<?php

namespace Crm\PaymentsModule\Segment;

use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ApplicationModule\Criteria\CriteriaInterface;
use Crm\SegmentModule\Criteria\Fields;
use Crm\SegmentModule\Params\BooleanParam;
use Crm\SegmentModule\Params\DateTimeParam;
use Crm\SegmentModule\Params\ParamsBag;
use Crm\SegmentModule\Params\StringArrayParam;

class PaymentCriteria implements CriteriaInterface
{
    private $paymentsRepository;

    public function __construct(
        PaymentsRepository $paymentsRepository
    ) {
        $this->paymentsRepository = $paymentsRepository;
    }

    public function label(): string
    {
        return "Payment";
    }

    public function category(): string
    {
        return "Payment";
    }

    public function params(): array
    {
        return [
            new BooleanParam(
                "additional_amount",
                "Donation",
                "Filters users with payments with / without donation"
            ),
            new DateTimeParam(
                "created",
                "Created",
                "Filters users with payments created within selected period"
            ),
            new StringArrayParam(
                "status",
                "Status",
                "Filters users with payments with specific status",
                false,
                [PaymentsRepository::STATUS_PAID],
                null,
                array_keys($this->paymentsRepository->getStatusPairs())
            ),
        ];
    }

    public function join(ParamsBag $paramBag): string
    {
        $where = [];

        if ($paramBag->has('additional_amount')) {
            if ($paramBag->boolean('additional_amount')->isTrue()) {
                $where[] = ' payments.additional_amount > 0 AND payments.additional_type IS NOT NULL ';
            } elseif ($paramBag->boolean('additional_amount')->isFalse()) {
                $where[] = ' payments.additional_amount = 0 AND payments.additional_type IS NULL ';
            }
        }

        if ($paramBag->has('status')) {
            $where[] = " payments.status IN ({$paramBag->stringArray('status')->escapedString()}) ";
        }

        if ($paramBag->has('created')) {
            $where += $paramBag->datetime('created')->escapedConditions('payments.created_at');
        }

        return "SELECT DISTINCT(payments.user_id) AS id, " . Fields::formatSql($this->fields()) . "
          FROM payments
          WHERE " . implode(" AND ", $where);
    }

    public function title(ParamsBag $paramBag): string
    {
        $result = '';
        if ($paramBag->has('additional_amount') || $paramBag->has('status') || $paramBag->has('created')) {
            if ($paramBag->has('status')) {
                $result .= " with {$paramBag->stringArray('status')->escapedString()} payment";
            } else {
                $result .= ' with payment';
            }

            if ($paramBag->has('additional_amount')) {
                if ($paramBag->boolean('additional_amount')->isTrue()) {
                    $result .= ' with additional amount';
                } elseif ($paramBag->boolean('additional_amount')->isFalse()) {
                    $result .= ' without additional amount';
                }
            }

            if ($paramBag->has('created')) {
                $result .= " created{$paramBag->datetime('created')->title('payments.created_at')}";
            }
        }
        return $result;
    }

    public function fields(): array
    {
        return [
            'payments.id' => 'payment_id',
            'payments.status' => 'payment_status',
            'payments.variable_symbol' => 'variable_symbol',
            'payments.amount' => 'amount',
            'payments.additional_amount' => 'additional_amount',
            'payments.additional_type' => 'additional_type',
            'payments.created_at' => 'created_at',
            'payments.paid_at' => 'paid_at',
            'payments.recurrent_charge' => 'recurrent_charge',
        ];
    }
}
