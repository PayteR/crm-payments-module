<?php

namespace Crm\PaymentsModule\Presenters;

use Crm\ApplicationModule\Components\Graphs\SmallBarGraphControlFactoryInterface;
use Crm\ApplicationModule\Components\VisualPaginator;
use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\PaymentsModule\Components\ChangePaymentStatusFactoryInterface;
use Crm\PaymentsModule\Components\GiftCouponsFactoryInterface;
use Crm\PaymentsModule\DataProvider\AdminFilterFormDataProviderInterface;
use Crm\PaymentsModule\Forms\PaymentFormFactory;
use Crm\PaymentsModule\PaymentsHistogramFactory;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\PaymentItem\PostalFeePaymentItem;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\UsersModule\Repository\UsersRepository;
use DateTime;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Form;
use Tomaj\Form\Renderer\BootstrapInlineRenderer;

class PaymentsAdminPresenter extends AdminPresenter
{
    /** @var PaymentsRepository @inject */
    public $paymentsRepository;

    /** @var PaymentGatewaysRepository @inject */
    public $paymentGatewaysRepository;

    /** @var SubscriptionTypesRepository @inject */
    public $subscriptionTypesRepository;

    /** @var UsersRepository @inject */
    public $usersRepository;

    /** @var PaymentFormFactory @inject */
    public $factory;

    /** @var DataProviderManager @inject */
    public $dataProviderManager;

    /** @var PaymentsHistogramFactory @inject */
    public $paymentsHistogramFactory;

    /** @persistent */
    public $payment_gateway;

    /** @persistent */
    public $subscription_type;

    /** @persistent */
    public $status;

    /** @persistent */
    public $donation;

    /** @persistent */
    public $month;

    /** @persistent */
    public $recurrent_charge = 'all';

    public function startup()
    {
        parent::startup();
        $this->month = isset($this->params['month']) ? $this->params['month'] : '';
    }

    public function renderDefault()
    {
        $payments = $this->filteredPayments();
        $filteredCount = $payments->count('*');

        $vp = new VisualPaginator();
        $this->addComponent($vp, 'vp');
        $paginator = $vp->getPaginator();
        $paginator->setItemCount($filteredCount);
        $paginator->setItemsPerPage($this->onPage);
        $this->template->vp = $vp;
        $this->template->filteredCount = $filteredCount;
        $this->template->payments = $payments->limit($paginator->getLength(), $paginator->getOffset());
        $this->template->totalPayments = $this->paymentsRepository->totalCount(true);
    }

    private function filteredPayments()
    {
        $recurrentChargeValues = [
            'all' => null,
            'recurrent' => true,
            'manual' => false,
        ];

        $payments = $this->paymentsRepository->all(
            $this->text,
            $this->payment_gateway,
            $this->subscription_type,
            $this->status,
            null,
            null,
            null,
            $this->donation,
            $recurrentChargeValues[$this->recurrent_charge] ?? null
        );

        $payments->order('created_at DESC')->order('id DESC');
        /** @var AdminFilterFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('payments.dataprovider.list_filter_form', AdminFilterFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $payments = $provider->filter($payments, $this->request);
        }

        return $payments;
    }

    private function filteredPaymentsForExports()
    {
        $start = DateTime::createFromFormat('Y-m', $this->month);
        $end = DateTime::createFromFormat('Y-m', $this->month);
        $end->modify('+1 month');
        return $this->paymentsRepository->all(
            '',
            $this->payment_gateway,
            $this->subscription_type,
            $this->status,
            $start->format('Y-m-01 00:00:00'),
            $end->format('Y-m-01 00:00:00')
        )->order('created_at DESC')->order('id DESC');
    }

    private function filteredProductStatsForExports()
    {
        $start = DateTime::createFromFormat('Y-m', $this->month);
        $end = DateTime::createFromFormat('Y-m', $this->month);
        $end->modify('+1 month');

        $payments = $this->paymentsRepository->all(
            '',
            null,
            null,
            $this->status,
            $start->format('Y-m-01 00:00:00'),
            $end->format('Y-m-01 00:00:00')
        )
            ->where(':payment_items.type = ?', ProductPaymentItem::TYPE)
            ->order('created_at DESC')->order('id DESC');

        $stats = [];
        $total = 0;

        $badPayments = [];

        foreach ($payments as $payment) {
            $paymentSum = 0;

            // we're intentionally not using $this->paymentsRepository->unBundleProducts,
            // because the pricing of individual products doesn't need to match price of the bundle
            foreach ($payment->related('payment_items')->where('type = ?', ProductPaymentItem::TYPE) as $paymentItem) {
                if (!$paymentItem->amount) {
                    continue;
                }

                $product = $paymentItem->product;
                if (!isset($stats['product_sums'][$product->id])) {
                    $stats['product_sums'][$product->id] = [];
                }
                if (!isset($stats['product_sums'][$product->id][strval($paymentItem->amount)])) {
                    $stats['product_sums'][$product->id][strval($paymentItem->amount)] = 0;
                }
                $stats['products'][$product->id] = $product;
                $stats['product_sums'][$product->id][strval($paymentItem->amount)] += $paymentItem->count * $paymentItem->amount;
                $paymentSum += $paymentItem->count * $paymentItem->amount;
                $total += $paymentItem->count * $paymentItem->amount;
            }

            foreach ($payment->related('payment_items')->where('type = ?', PostalFeePaymentItem::TYPE) as $paymentItem) {
                if (!isset($stats['postal_fee_sums'][$paymentItem->postal_fee_id])) {
                    $stats['postal_fee_sums'][$paymentItem->postal_fee_id] = [];
                }
                if (!isset($stats['postal_fee_sums'][$paymentItem->postal_fee_id][strval($paymentItem->amount)])) {
                    $stats['postal_fee_sums'][$paymentItem->postal_fee_id][strval($paymentItem->amount)] = 0.0;
                }
                $stats['postal_fees'][$paymentItem->postal_fee_id] = $paymentItem->postal_fee;
                $stats['postal_fee_sums'][$paymentItem->postal_fee_id][strval($paymentItem->amount)] += floatval($paymentItem->amount * $paymentItem->count);
                $total += $paymentItem->amount * $paymentItem->count;
                $paymentSum += $paymentItem->amount * $paymentItem->count;
            }
            if (round($paymentSum, 2) != $payment->amount) {
                $badPayments[] = [
                    'id' => $payment->id,
                    'calculated' => $paymentSum,
                    'expected' => $payment->amount,
                ];
                throw new \Exception("products sum and postal fee doesn't match with payment amount for payment {$payment->id}: calculated {$paymentSum}, expected {$payment->amount}");
            }
        }
        return [$stats, $total];
    }

    public function createComponentAdminFilterForm()
    {
        $form = new Form;
        $form->setRenderer(new BootstrapInlineRenderer());
        $form->addText('text', 'VS:')
            ->setAttribute('autofocus');

        $paymentGateways = $this->paymentGatewaysRepository->getAllActive()->fetchPairs('id', 'name');
        $form->addSelect('payment_gateway', 'Platobná brána', $paymentGateways)->setPrompt('--');

        $statuses = $this->paymentsRepository->getStatusPairs();
        $form->addSelect('status', 'Stav platby', $statuses)->setPrompt('--');

        $donations = [
            true => 'S darom',
            false => 'Bez daru',
        ];
        $form->addSelect('donation', 'Dar', $donations)->setPrompt('--');

        $form->addSelect('recurrent_charge', 'Automatické obnovenie', [
            'all' => 'Všetky',
            'recurrent' => 'Iba automaticky obnovené',
            'manual' => 'Iba manuálne',
         ]);

        $subscriptionTypes = $this->subscriptionTypesRepository->getAllActive()->fetchPairs('id', 'name');
        $form->addSelect('subscription_type', 'Typ predplatného', $subscriptionTypes)->setPrompt('--');

        /** @var AdminFilterFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('payments.dataprovider.list_filter_form', AdminFilterFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form, 'request' => $this->request]);
        }

        $form->addSubmit('send', 'Filter')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-filter"></i> Filter');
        $presenter = $this;
        $form->addSubmit('cancel', 'Zruš filter')->onClick[] = function () use ($presenter) {
            $presenter->redirect('PaymentsAdmin:Default', ['text' => '']);
        };

        $form->onSuccess[] = [$this, 'adminFilterSubmited'];
        $form->setDefaults([
            'text' => $this->text,
            'payment_gateway' => $this->payment_gateway,
            'subscription_type' => $this->subscription_type,
            'status' => $this->status,
            'donation' => $this->donation,
            'recurrent_charge' => $this->recurrent_charge,
        ]);
        return $form;
    }

    public function adminFilterSubmited($form, $values)
    {
        $this->redirect($this->action, array_filter((array)$values));
    }

    public function actionChangeStatus()
    {
        $payment = $this->paymentsRepository->find($this->params['payment']);
        $this->paymentsRepository->updateStatus($payment, $this->params['status']);
        $this->flashMessage('Stav platby bol zmenený');
        $this->redirect(':Users:UsersAdmin:Show', $payment->user_id);
    }

    public function renderExport()
    {
        $this->getHttpResponse()->addHeader('Content-Type', 'application/csv');
        $this->getHttpResponse()->addHeader('Content-Disposition', 'attachment; filename=export.csv');
        $this->template->payments = $this->filteredPayments();
    }

    public function renderEdit($id, $userId)
    {
        $payment = $this->paymentsRepository->find($id);
        if (!$payment) {
            throw new BadRequestException();
        }
        $this->template->payment = $payment;
        $this->template->user = $payment->user;
    }

    public function renderNew($userId)
    {
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new BadRequestException();
        }
        $this->template->user = $user;
    }

    public function renderSupporterPayments()
    {
        $payments = $this->paymentsRepository->getPaymentsWithNotes();

        $vp = new VisualPaginator();
        $this->addComponent($vp, 'vp');
        $paginator = $vp->getPaginator();
        $paginator->setItemCount($payments->count('*'));
        $paginator->setItemsPerPage($this->onPage);
        $this->template->vp = $vp;
        $this->template->payments = $payments->limit($paginator->getLength(), $paginator->getOffset());
        $this->template->totalPayments = $this->paymentsRepository->totalCount(true);
    }

    public function createComponentPaymentForm()
    {
        $id = null;
        if (isset($this->params['id'])) {
            $id = $this->params['id'];
            $user = $this->paymentsRepository->find($id)->user;
        } else {
            $user = $this->usersRepository->find($this->params['userId']);
            if (!$user) {
                throw new BadRequestException();
            }
        }

        $form = $this->factory->create($id, $user);
        $this->factory->onSave = function ($form, $payment) {
            if (isset($form->values['display_order']) && $form->values['display_order']) {
                $this->redirect(':Products:OrdersAdmin:New', ['paymentId' => $payment->id]);
            }
            $this->flashMessage('Platba bola vytvorená.');
            $this->redirect(':Users:UsersAdmin:Show', $payment->user->id);
        };
        $this->factory->onUpdate = function ($form, $payment) {
            if (isset($form->values['display_order']) && $form->values['display_order']) {
                $this->redirect(':Products:OrdersAdmin:New', ['paymentId' => $payment->id]);
            }
            $this->flashMessage('Platba bola aktualizovaná.');
            $this->redirect(':Users:UsersAdmin:Show', $payment->user->id);
        };
        return $form;
    }

    protected function createComponentFormPaymentsSmallBarGraph(SmallBarGraphControlFactoryInterface $factory)
    {
        return $this->generateSmallBarGraphComponent(PaymentsRepository::STATUS_FORM, 'Form', $factory);
    }

    protected function createComponentPaidPaymentsSmallBarGraph(SmallBarGraphControlFactoryInterface $factory)
    {
        return $this->generateSmallBarGraphComponent(PaymentsRepository::STATUS_PAID, 'Paid', $factory);
    }

    protected function createComponentFailPaymentsSmallBarGraph(SmallBarGraphControlFactoryInterface $factory)
    {
        return $this->generateSmallBarGraphComponent(PaymentsRepository::STATUS_FAIL, 'Fail', $factory);
    }

    protected function createComponentTimeoutPaymentsSmallBarGraph(SmallBarGraphControlFactoryInterface $factory)
    {
        return $this->generateSmallBarGraphComponent(PaymentsRepository::STATUS_TIMEOUT, 'Timeout', $factory);
    }

    protected function createComponentRefundedPaymentsSmallBarGraph(SmallBarGraphControlFactoryInterface $factory)
    {
        return $this->generateSmallBarGraphComponent(PaymentsRepository::STATUS_REFUND, 'Refunded', $factory);
    }

    private function generateSmallBarGraphComponent($status, $title, SmallBarGraphControlFactoryInterface $factory)
    {
        $data = $this->paymentsHistogramFactory->paymentsLastMonthDailyHistogram($status);

        $control = $factory->create();
        $control->setGraphTitle($title)->addSerie($data);

        return $control;
    }

    protected function createComponentChangePaymentStatus(ChangePaymentStatusFactoryInterface $factory)
    {
        return $factory->create();
    }

    protected function createComponentGiftCoupons(GiftCouponsFactoryInterface $factory)
    {
        return $factory->create();
    }
}
