<?php

namespace Crm\PaymentsModule\Repository;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Graphs\Criteria;
use Crm\ApplicationModule\Graphs\GraphData;
use Crm\ApplicationModule\Graphs\GraphDataItem;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Repository;
use Crm\ApplicationModule\Repository\AuditLogRepository;
use Crm\ApplicationModule\Request;
use Crm\PaymentsModule\Events\NewPaymentEvent;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\VariableSymbolVariant;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use DateTime;
use League\Event\Emitter;
use Nette\Database\Context;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\IRow;
use Nette\Database\Table\Selection;
use Nette\Localization\ITranslator;
use Tracy\Debugger;

class PaymentsRepository extends Repository
{
    const STATUS_FORM = 'form';
    const STATUS_PAID = 'paid';
    const STATUS_FAIL = 'fail';
    const STATUS_TIMEOUT = 'timeout';
    const STATUS_REFUND = 'refund';
    const STATUS_IMPORTED = 'imported';
    const STATUS_PREPAID = 'prepaid';

    protected $tableName = 'payments';

    private $variableSymbol;

    private $subscriptionTypesRepository;

    private $subscriptionsRepository;

    private $paymentGatewaysRepository;

    private $recurrentPaymentsRepository;

    private $paymentItemsRepository;

    private $emitter;

    private $hermesEmitter;

    private $paymentMetaRepository;

    private $translator;

    private $donationVatRate;

    private $cacheRepository;

    private $graphData;

    public function __construct(
        $donationVatRate,
        Context $database,
        VariableSymbol $variableSymbol,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        SubscriptionsRepository $subscriptionsRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentItemsRepository $paymentItemsRepository,
        Emitter $emitter,
        AuditLogRepository $auditLogRepository,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        PaymentMetaRepository $paymentMetaRepository,
        ITranslator $translator,
        CacheRepository $cacheRepository,
        GraphData $graphData
    ) {
        parent::__construct($database);
        $this->variableSymbol = $variableSymbol;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentItemsRepository = $paymentItemsRepository;
        $this->emitter = $emitter;
        $this->auditLogRepository = $auditLogRepository;
        $this->hermesEmitter = $hermesEmitter;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->translator = $translator;
        $this->donationVatRate = $donationVatRate;
        $this->cacheRepository = $cacheRepository;
        $this->graphData = $graphData;
    }

    public function add(
        ActiveRow $subscriptionType = null,
        ActiveRow $paymentGateway,
        ActiveRow $user,
        $referer = null,
        $amount = null,
        DateTime $subscriptionStartAt = null,
        DateTime $subscriptionEndAt = null,
        $note = null,
        $additionalAmount = 0,
        $additionalType = null,
        $variableSymbol = null,
        IRow $address = null,
        $recurrentCharge = false,
        $paymentItems = [],
        $invoiceable = true
    ) {
        $data = [
            'user_id' => $user->id,
            'payment_gateway_id' => $paymentGateway->id,
            'status' => self::STATUS_FORM,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
            'variable_symbol' => $variableSymbol ? $variableSymbol : $this->variableSymbol->getNew(),
            'ip' => Request::getIp(),
            'user_agent' => Request::getUserAgent(),
            'referer' => $referer,
            'subscription_start_at' => $subscriptionStartAt,
            'subscription_end_at' => $subscriptionEndAt,
            'note' => $note,
            'additional_type' => $additionalType,
            'additional_amount' => $additionalAmount == null ? 0 : $additionalAmount,
            'address_id' => $address ? $address->id : null,
            'recurrent_charge' => $recurrentCharge,
            'invoiceable' => $invoiceable,
        ];
        if ($subscriptionType) {
            $data['amount'] = $subscriptionType->price;
            $data['subscription_type_id'] = $subscriptionType->id;
        }
        if ($amount) {
            $data['amount'] = $amount;
        }
        if (isset($data['amount']) && $additionalAmount) {
            $data['amount'] += $additionalAmount;
        }
        /** @var ActiveRow $payment */
        $payment = $this->insert($data);

        // subscriptions
        if (!empty($paymentItems)) {
            foreach ($paymentItems as $item) {
                $this->paymentItemsRepository->add(
                    $payment,
                    $item['name'],
                    $item['amount'],
                    $item['vat'],
                    $payment->subscription_type_id
                );
            }
        } elseif ($payment->subscription_type_id) {
            $subscriptionTypeItems = $payment->subscription_type->related('subscription_type_items')->order('sorting');
            foreach ($subscriptionTypeItems as $subscriptionTypeItem) {
                $this->paymentItemsRepository->add($payment, $subscriptionTypeItem->name, $subscriptionTypeItem->amount, $subscriptionTypeItem->vat, $payment->subscription_type_id);
            }
        }

        // donations
        if ($payment->additional_amount) {
            $this->paymentItemsRepository->add($payment, $this->translator->translate('payments.admin.donation'), $payment->additional_amount, $this->donationVatRate, null);
        }

        $this->emitter->emit(new NewPaymentEvent($payment));
        return $payment;
    }

    public function addMeta($payment, $data)
    {
        if (empty($data)) {
            return null;
        }
        $added = [];
        foreach ($data as $key => $value) {
            if (!$this->paymentMetaRepository->add($payment, $key, $value)) {
                return false;
            }
        }
        return $added;
    }

    public function copyPayment(ActiveRow $payment)
    {
        $newPayment = $this->insert([
            'amount' => $payment->amount,
            'user_id' => $payment->user_id,
            'subscription_type_id' => $payment->subscription_type_id,
            'payment_gateway_id' => $payment->payment_gateway_id,
            'status' => self::STATUS_FORM,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
            'variable_symbol' => $payment->variable_symbol,
            'invoiceable' => $payment->invoiceable,
            'ip' => '',
            'user_agent' => '',
            'referer' => '',
        ]);

        foreach ($payment->related('payment_items') as $paymentItem) {
            $this->paymentItemsRepository->add(
                $newPayment,
                $paymentItem->name,
                $paymentItem->amount,
                $paymentItem->vat,
                $paymentItem->subscription_type_id
            );
        }

        return $newPayment;
    }

    public function getPaymentItems(ActiveRow $payment): array
    {
        $items = [];
        foreach ($payment->related('payment_items') as $paymentItem) {
            $items[] = [
                'name' => $paymentItem->name,
                'amount' => $paymentItem->amount,
                'vat' => $paymentItem->vat,
            ];
        }
        return $items;
    }

    public function update(IRow &$row, $data, $paymentItems = [])
    {
        $paymentId = $row->id;
        if (!empty($paymentItems)) {
            $this->paymentItemsRepository->deleteForPaymentId($paymentId);

            foreach ($paymentItems as $item) {
                $this->paymentItemsRepository->add(
                    $row,
                    $item['name'],
                    $item['amount'],
                    $item['vat'],
                    $row->subscription_type_id
                );
            }
        }

        $values['modified_at'] = new DateTime();
        return parent::update($row, $data);
    }

    public function updateStatus(ActiveRow $payment, $status, $sendEmail = false, $note = null, $errorMessage = null, $salesFunnelId = null)
    {
        $data = [
            'status' => $status,
            'modified_at' => new DateTime()
        ];
        if ($status == self::STATUS_PAID && !$payment->paid_at) {
            $data['paid_at'] = new DateTime();
        }
        if ($note) {
            $data['note'] = $note;
        }
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        if ($payment->status == static::STATUS_PAID && $data['status'] == static::STATUS_FAIL) {
            Debugger::log("attempt to make change payment status from [paid] to [fail]");
            return false;
        }

        parent::update($payment, $data);

        $this->emitter->emit(new PaymentChangeStatusEvent($payment, $sendEmail));
        $this->hermesEmitter->emit(new HermesMessage('payment-status-change', [
            'payment_id' => $payment->id,
            'sales_funnel_id' => $payment->sales_funnel_id ?? $salesFunnelId, // pass explicit sales_funnel_id if payment doesn't contain one
        ]));

        return $payment;
    }

    /**
     * @param $variableSymbol
     * @return ActiveRow
     */
    public function findByVs($variableSymbol)
    {
        return $this->findBy('variable_symbol', $this->variableSymbolVariants($variableSymbol));
    }

    private function variableSymbolVariants($variableSymbol)
    {
        $variableSymbolVariant = new VariableSymbolVariant();
        return $variableSymbolVariant->variableSymbolVariants($variableSymbol);
    }

    public function findLastByVS(string $variableSymbol)
    {
        return $this->findAllByVS($variableSymbol)->order('created_at DESC')->limit(1)->fetch();
    }

    public function findAllByVS(string $variableSymbol)
    {
        return $this->getTable()->where(
            'variable_symbol',
            $this->variableSymbolVariants($variableSymbol)
        );
    }

    public function addSubscriptionToPayment(IRow $subscription, IRow $payment)
    {
        return parent::update($payment, ['subscription_id' => $subscription->id]);
    }

    public function subscriptionPayment(IRow $subscription)
    {
        return $this->getTable()->where(['subscription_id' => $subscription->id])->select('*')->limit(1)->fetch();
    }

    /**
     * @param int $userId
     * @return \Nette\Database\Table\Selection
     */
    public function userPayments($userId)
    {
        return $this->getTable()->where(['user_id' => $userId])->order('created_at DESC');
    }

    public function userPaymentsWithRecurrent($userId)
    {
        return $this->getTable()->where(['payments.user_id' => $userId])->order('created_at DESC');
    }

    /**
     * @param string $text
     * @param int $payment_gateway
     * @param int $subscription_type
     * @param string $status
     * @param string|\DateTime $start
     * @param string|\DateTime $end
     * @param int $sales_funnel
     * @param bool $donation
     * @param bool $recurrentCharge
     * @return Selection
     */
    public function all($text = '', $payment_gateway = null, $subscription_type = null, $status = null, $start = null, $end = null, $sales_funnel = null, $donation = null, $recurrentCharge = null)
    {
        $where = [];
        if ($text != '') {
            $where['variable_symbol LIKE ? OR note LIKE ?'] = ["%{$text}%", "%{$text}%"];
        }
        if ($payment_gateway) {
            $where['payment_gateway_id'] = $payment_gateway;
        }
        if ($subscription_type) {
            $where['subscription_type_id'] = $subscription_type;
        }
        if ($status) {
            $where['status'] = $status;
        }
        if ($sales_funnel) {
            $where['sales_funnel_id'] = $sales_funnel;
        }
        if ($donation !== null) {
            if ($donation) {
                $where[] = 'additional_amount > 0';
            } else {
                $where[] = 'additional_amount = 0';
            }
        }
        if ($start) {
            $where['(paid_at IS NOT NULL AND paid_at >= ?) OR (paid_at IS NULL AND modified_at >= ?)'] = [$start, $start];
        }
        if ($end) {
            $where['(paid_at IS NOT NULL AND paid_at < ?) OR (paid_at IS NULL AND modified_at < ?)'] = [$end, $end];
        }
        if ($recurrentCharge !== null) {
            $where['recurrent_charge'] = $recurrentCharge;
        }
        return $this->getTable()->where($where);
    }

    public function allWithoutOrder()
    {
        return $this->getTable();
    }

    public function totalAmountSum($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->getTable()->where(['status' => self::STATUS_PAID])->sum('amount');
        };

        if ($allowCached) {
            return $this->cacheRepository->loadByKeyAndUpdate(
                'payments_paid_sum',
                $callable,
                \Nette\Utils\DateTime::from(CacheRepository::DEFAULT_REFRESH_TIME),
                $forceCacheUpdate
            );
        }

        return $callable();
    }

    public function totalUserAmountSum($userId)
    {
        return $this->getTable()->where(['user_id' => $userId, 'status' => self::STATUS_PAID])->sum('amount');
    }

    public function totalUserAmounts()
    {
        return $this->getDatabase()->query("SELECT user_id,email,SUM(amount) AS total FROM payments INNER JOIN users ON users.id=payments.user_id WHERE payments.status='paid' GROUP BY user_id ORDER BY total DESC");
    }

    public function getStatusPairs()
    {
        return [
            self::STATUS_FORM => self::STATUS_FORM,
            self::STATUS_FAIL => self::STATUS_FAIL,
            self::STATUS_PAID => self::STATUS_PAID,
            self::STATUS_TIMEOUT => self::STATUS_TIMEOUT,
            self::STATUS_REFUND => self::STATUS_REFUND,
            self::STATUS_IMPORTED => self::STATUS_IMPORTED,
            self::STATUS_PREPAID => self::STATUS_PREPAID,
        ];
    }

    public function getPaymentsWithNotes()
    {
        return $this->getTable()->where(['NOT note' => null])->order('created_at DESC');
    }

    public function unbundleProducts(ActiveRow $payment, callable $callback)
    {
        foreach ($payment->related('payment_products') as $paymentProduct) {
            $product = $paymentProduct->product;
            if ($product->bundle) {
                foreach ($product->related('product_bundles') as $productBundle) {
                    $callback($productBundle->item, $paymentProduct->count, $paymentProduct->price);
                }
            } else {
                $callback($product, $paymentProduct->count, $paymentProduct->price);
            }
        }
    }

    public function totalCount($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return parent::totalCount();
        };
        if ($allowCached) {
            return $this->cacheRepository->loadByKeyAndUpdate(
                'payments_count',
                $callable,
                \Nette\Utils\DateTime::from(CacheRepository::DEFAULT_REFRESH_TIME),
                $forceCacheUpdate
            );
        }
        return $callable();
    }

    public function paidSubscribersCount($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->paidSubscribers()
                ->select('COUNT(DISTINCT(subscriptions.user_id)) AS total')
                ->fetch()->total;
        };

        if ($allowCached) {
            return $this->cacheRepository->loadByKeyAndUpdate(
                'paid_subscribers_count',
                $callable,
                \Nette\Utils\DateTime::from('-1 hour'),
                $forceCacheUpdate
            );
        }

        return $callable();
    }

    public function paidSubscribers()
    {
        return $this->database->table('subscriptions')
            ->where('start_time < ?', $this->database::literal('NOW()'))
            ->where('end_time > ?', $this->database::literal('NOW()'))
            ->where('user.active = 1')
            ->where(':payments.id IS NOT NULL OR type IN (?)', ['upgrade', 'prepaid', 'gift']);
    }

    public function freeSubscribersCount($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->freeSubscribers()
                ->select('COUNT(DISTINCT(subscriptions.user_id)) AS total')
                ->fetch()->total;
        };

        if ($allowCached) {
            return $this->cacheRepository->loadByKeyAndUpdate(
                'free_subscribers_count',
                $callable,
                \Nette\Utils\DateTime::from('-1 hour'),
                $forceCacheUpdate
            );
        }

        return $callable();
    }

    public function freeSubscribers()
    {
        $freeSubscribers = $this->database->table('subscriptions')
            ->where('start_time < ?', $this->database::literal('NOW()'))
            ->where('end_time > ?', $this->database::literal('NOW()'))
            ->where('user.active = 1')
            ->where(':payments.id IS NULL AND type NOT IN (?)', ['upgrade', 'prepaid', 'gift']);

        $paidSubscribers = $this->paidSubscribers()->select('subscriptions.user_id');
        if ($paidSubscribers->fetchAll()) {
            $freeSubscribers->where('subscriptions.user_id NOT IN (?)', $paidSubscribers);
        }

        return $freeSubscribers;
    }

    /**
     * @param DateTime $from
     * @param DateTime $to
     * @return \Crm\ApplicationModule\Selection
     */
    public function paidBetween(DateTime $from, DateTime $to)
    {
        return $this->getTable()->where([
            'status' => self::STATUS_PAID,
            'paid_at > ?' => $from,
            'paid_at < ?' => $to,
        ]);
    }

    public function subscriptionsWithActiveUnchargedRecurrentEndingNextTwoWeeksCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithActiveUnchargedRecurrentEndingBetween(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+14 days 23:59:59')
            )->count('*');
        };

        return $this->cacheRepository->loadByKeyAndUpdate(
            'subscriptions_with_active_uncharged_recurrent_ending_next_two_weeks_count',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::DEFAULT_REFRESH_TIME),
            $forceCacheUpdate
        );
    }

    public function subscriptionsWithActiveUnchargedRecurrentEndingNextMonthCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithActiveUnchargedRecurrentEndingBetween(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+31 days 23:59:59')
            )->count('*');
        };

        return $this->cacheRepository->loadByKeyAndUpdate(
            'subscriptions_with_active_uncharged_recurrent_ending_next_month_count',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::DEFAULT_REFRESH_TIME),
            $forceCacheUpdate
        );
    }

    /**
     * @param DateTime $startTime
     * @param DateTime $endTime
     * @return mixed|Selection
     */
    public function subscriptionsWithActiveUnchargedRecurrentEndingBetween(DateTime $startTime, DateTime $endTime)
    {
        return $this->database->table('subscriptions')
            ->where(':payments.id IS NOT NULL')
            ->where(':payments:recurrent_payments(parent_payment_id).status IS NULL')
            ->where(':payments:recurrent_payments(parent_payment_id).retries > ?', 3)
            ->where(':payments:recurrent_payments(parent_payment_id).state = ?', 'active')
            ->where('next_subscription_id IS NULL')
            ->where('end_time >= ?', $startTime)
            ->where('end_time <= ?', $endTime);
    }


    /**
     * Cached value since computation of the value for next two weeks interval may be slow
     *
     * @param bool $forceCacheUpdate
     *
     * @return int
     */
    public function subscriptionsWithoutExtensionEndingNextTwoWeeksCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithoutExtensionEndingBetweenCount(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+14 days 23:59:59')
            );
        };
        return $this->cacheRepository->loadByKeyAndUpdate(
            'subscriptions_without_extension_ending_next_two_weeks_count',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::DEFAULT_REFRESH_TIME),
            $forceCacheUpdate
        );
    }

    /**
     * Cached value since computation of the value for next month interval may be slow
     *
     * @param bool $forceCacheUpdate
     *
     * @return int
     */
    public function subscriptionsWithoutExtensionEndingNextMonthCount($forceCacheUpdate = false)
    {
        $callable = function () {
            return $this->subscriptionsWithoutExtensionEndingBetweenCount(
                \Nette\Utils\DateTime::from('today 00:00'),
                \Nette\Utils\DateTime::from('+31 days 23:59:59')
            );
        };
        return $this->cacheRepository->loadByKeyAndUpdate(
            'subscriptions_without_extension_ending_next_month_count',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::DEFAULT_REFRESH_TIME),
            $forceCacheUpdate
        );
    }

    public function subscriptionsWithoutExtensionEndingBetweenCount(DateTime $startTime, DateTime $endTime)
    {
        $s = $startTime;
        $e = $endTime;

        $renewedSubscriptionsEndingBetweenSql = <<<SQL
SELECT subscriptions.id 
FROM subscriptions 
LEFT JOIN payments ON subscriptions.id = payments.subscription_id 
LEFT JOIN recurrent_payments ON payments.id = recurrent_payments.parent_payment_id 
WHERE payments.id IS NOT NULL AND 
recurrent_payments.status IS NULL AND
recurrent_payments.retries > 3 AND 
recurrent_payments.state = 'active' AND
next_subscription_id IS NULL AND 
end_time >= ? AND 
end_time <= ?
SQL;

        $q = <<<SQL
        
SELECT COUNT(id) as total FROM subscriptions 
WHERE id IS NOT NULL AND end_time >= ? AND end_time <= ? AND id NOT IN (
  SELECT id FROM subscriptions WHERE end_time >= ? AND end_time <= ? AND next_subscription_id IS NOT NULL
  UNION 
  ($renewedSubscriptionsEndingBetweenSql)
)
SQL;
        return $this->getDatabase()->fetch($q, $s, $e, $s, $e, $s, $e)->total;
    }

    /**
     * WARNING: slow for wide intervals
     * @param DateTime $startTime
     * @param DateTime $endTime
     * @return Selection
     */
    public function subscriptionsWithoutExtensionEndingBetween(DateTime $startTime, DateTime $endTime)
    {
        $endingSubscriptions = $this->subscriptionsRepository->subscriptionsEndingBetween($startTime, $endTime)->select('subscriptions.id')->fetchAll();
        $renewedSubscriptions = $this->subscriptionsRepository->renewedSubscriptionsEndingBetween($startTime, $endTime)->select('subscriptions.id')->fetchAll();
        $activeUnchargedSubscriptions = $this->subscriptionsWithActiveUnchargedRecurrentEndingBetween($startTime, $endTime)->select('subscriptions.id')->fetchAll();

        $ids = array_diff($endingSubscriptions, $renewedSubscriptions, $activeUnchargedSubscriptions);

        return $this->database
            ->table('subscriptions')
            ->where('id IN (?)', $ids);
    }

    /**
     * @param DateTime $createdAt
     * @return Selection
     */
    public function unconfirmedPayments(DateTime $from)
    {
        return $this->getTable()
            ->where('payments.status = ?', self::STATUS_FORM)
            ->where('payments.created_at >= ?', $from)
            ->order('payments.created_at DESC');
    }

    public function paymentsLastMonthDailyHistogram($status, $forceCacheUpdate = true)
    {
        $cacheKey = "payments_status_{$status}_last_month_daily_histogram";

        $callable = function () use ($status) {
            $graphDataItem = new GraphDataItem();
            $graphDataItem->setCriteria((new Criteria())
                ->setTableName('payments')
                ->setWhere("AND payments.status = '$status'"));

            $this->graphData->clear();
            $this->graphData->addGraphDataItem($graphDataItem);
            $this->graphData->setScaleRange('day')->setStart('-31 days');

            $data = $this->graphData->getData();
            return json_encode($data);
        };

        return json_decode($this->cacheRepository->loadByKeyAndUpdate(
            $cacheKey,
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::DEFAULT_REFRESH_TIME),
            $forceCacheUpdate
        ), true);
    }
}
