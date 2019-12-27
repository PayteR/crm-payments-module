<?php

namespace Crm\PaymentsModule\Commands;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\Events\RecurrentPaymentFailEvent;
use Crm\PaymentsModule\Events\RecurrentPaymentFailTryEvent;
use Crm\PaymentsModule\GatewayFactory;
use Crm\PaymentsModule\GatewayFail;
use Crm\PaymentsModule\PaymentItem\DonationPaymentItem;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\RecurrentPaymentFailStop;
use Crm\PaymentsModule\RecurrentPaymentFailTry;
use Crm\PaymentsModule\RecurrentPaymentFastCharge;
use Crm\PaymentsModule\RecurrentPaymentsResolver;
use Crm\PaymentsModule\Repository\PaymentLogsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use League\Event\Emitter;
use Nette\Localization\ITranslator;
use Nette\Utils\DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tracy\Debugger;

class RecurrentPaymentsChargeCommand extends Command
{
    private $recurrentPaymentsRepository;

    private $paymentsRepository;

    private $gatewayFactory;

    private $paymentLogsRepository;

    private $emitter;

    private $applicationConfig;

    private $recurrentPaymentsResolver;

    private $translator;

    public function __construct(
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentsRepository $paymentsRepository,
        GatewayFactory $gatewayFactory,
        PaymentLogsRepository $paymentLogsRepository,
        Emitter $emitter,
        ApplicationConfig $applicationConfig,
        RecurrentPaymentsResolver $recurrentPaymentsResolver,
        ITranslator $translator
    ) {
        parent::__construct();
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->gatewayFactory = $gatewayFactory;
        $this->paymentLogsRepository = $paymentLogsRepository;
        $this->emitter = $emitter;
        $this->applicationConfig = $applicationConfig;
        $this->recurrentPaymentsResolver = $recurrentPaymentsResolver;
        $this->translator = $translator;
    }

    protected function configure()
    {
        $this->setName('payments:charge')
            ->setDescription("Charges recurrent payments ready to be charged. It's highly recommended to use flock or similar tool to prevent multiple instances of this command running.")
            ->addArgument(
                'charge',
                null,
                InputOption::VALUE_OPTIONAL,
                'Set to test charge'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start = microtime(true);

        $output->writeln('');
        $output->writeln('<info>***** Recurrent Payment *****</info>');
        $output->writeln('');

        $chargeableRecurrentPayments = $this->recurrentPaymentsRepository->getChargeablePayments();

        $output->writeln('Charging: <info>' . $chargeableRecurrentPayments->count('*') . '</info> payments');
        $output->writeln('');

        foreach ($chargeableRecurrentPayments as $recurrentPayment) {
            try {
                $this->validateRecurrentPayment($recurrentPayment);
            } catch (RecurrentPaymentFastCharge $exception) {
                $msg = 'RecurringPayment_id: ' . $recurrentPayment->id . ' Card_id: ' . $recurrentPayment->cid . ' User_id: ' . $recurrentPayment->user_id . ' Error: Fast charge';
                Debugger::log($msg, Debugger::EXCEPTION);
                $output->writeln('<error>' . $msg . '</error>');
                continue;
            }

            $subscriptionType = $this->recurrentPaymentsResolver->resolveSubscriptionType($recurrentPayment);
            $customChargeAmount = $this->recurrentPaymentsResolver->resolveCustomChargeAmount($recurrentPayment);

            if (isset($recurrentPayment->payment_id) && $recurrentPayment->payment_id != null) {
                $payment = $this->paymentsRepository->find($recurrentPayment->payment_id);
            } else {
                $additionalAmount = 0;
                $additionalType = null;
                $parentPayment = $recurrentPayment->parent_payment;
                if ($parentPayment && $parentPayment->additional_type == 'recurrent') {
                    $additionalType = 'recurrent';
                    $additionalAmount = $parentPayment->additional_amount;
                }

                $paymentItemContainer = new PaymentItemContainer();

                // we want to load previous payment items only if new subscription has same subscription type
                // and it isn't upgraded recurrent payment
                if ($subscriptionType->id === $parentPayment->subscription_type_id && $subscriptionType->id === $recurrentPayment->subscription_type_id && !$customChargeAmount) {
                    foreach ($this->paymentsRepository->getPaymentItems($parentPayment) as $key => $item) {
                        // TODO: unset donation payment item without relying on the name of payment item
                        // remove donation from items, it will be added by PaymentsRepository->add().
                        //
                        // Possible solution should be to add `recurrent` field to payment_items
                        // and copy only this items with recurrent flag
                        // for now this should be ok because we are not selling recurring products
                        if ($item['name'] == $this->translator->translate('payments.admin.donation')
                            && $item['amount'] === $parentPayment->additional_amount) {
                            continue;
                        }

                        $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
                            $subscriptionType->id,
                            $item['name'],
                            $item['amount'],
                            $item['vat'],
                            $item['count']
                        ));
                    }
                } elseif (!$customChargeAmount) {
                    // if subscription type changed, load the items from new subscription type
                    $paymentItemContainer->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));
                    // TODO: what about other types of payment items? (e.g. student donation?); seems we're losing them here
                } else {
                    // we're charging custom amount for subscription
                    $items = SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType);
                    if (count($items) === 1) {
                        // custom amount is handable only for single-item payments; how would we split the custom amount otherwise?
                        if ($customChargeAmount) {
                            $items[0]->forcePrice($customChargeAmount);
                        }
                        $paymentItemContainer->addItems($items);
                    } else {
                        $msg = 'RecurringPayment_id: ' . $recurrentPayment->id . ' Card_id: ' . $recurrentPayment->cid . ' User_id: ' . $recurrentPayment->user_id . ' Error: Unchargeable custom amount';
                        Debugger::log($msg, Debugger::EXCEPTION);
                        $output->writeln('<error>' . $msg . '</error>');
                        continue;
                    }
                }

                if ($additionalType == 'recurrent' && $additionalAmount) {
                    $donationPaymentVat = $this->applicationConfig->get('donation_vat_rate');
                    if ($donationPaymentVat === null) {
                        throw new \Exception("Config 'donation_vat_rate' is not set");
                    }
                    $paymentItemContainer->addItem(
                        new DonationPaymentItem(
                            $this->translator->translate('payments.admin.donation'),
                            $additionalAmount,
                            $donationPaymentVat
                        )
                    );
                }

                $payment = $this->paymentsRepository->add(
                    $subscriptionType,
                    $recurrentPayment->payment_gateway,
                    $recurrentPayment->user,
                    $paymentItemContainer,
                    null,
                    $customChargeAmount,
                    null,
                    null,
                    null,
                    $additionalAmount,
                    $additionalType,
                    null,
                    null,
                    true
                );
            }

            $this->recurrentPaymentsRepository->update($recurrentPayment, [
                'payment_id' => $payment->id,
            ]);

            $gateway = $this->gatewayFactory->getGateway($payment->payment_gateway->code);
            try {
                $gateway->charge($payment, $recurrentPayment->cid);

                $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID);
                $payment = $this->paymentsRepository->find($payment->id);

                $retries = explode(', ', $this->applicationConfig->get('recurrent_payment_charges'));
                $retries = count((array)$retries);

                $this->recurrentPaymentsRepository->add(
                    $recurrentPayment->cid,
                    $payment,
                    $this->recurrentPaymentsRepository->calculateChargeAt($payment),
                    $customChargeAmount,
                    --$retries
                );

                $this->recurrentPaymentsRepository->setCharged($recurrentPayment, $payment, $gateway->getResultCode(), $gateway->getResultMessage());
            } catch (RecurrentPaymentFailTry $exception) {
                $charges = explode(', ', $this->applicationConfig->get('recurrent_payment_charges'));
                $charges = array_reverse((array)$charges);

                $next = new \DateInterval(end($charges));
                if (isset($charges[$recurrentPayment->retries])) {
                    $next = new \DateInterval($charges[$recurrentPayment->retries]);
                }

                $nextCharge = new DateTime();
                $nextCharge->add($next);

                $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_FAIL);
                $this->recurrentPaymentsRepository->add(
                    $recurrentPayment->cid,
                    $payment,
                    $nextCharge,
                    $customChargeAmount,
                    $recurrentPayment->retries - 1
                );

                $this->recurrentPaymentsRepository->update($recurrentPayment, [
                    'state' => RecurrentPaymentsRepository::STATE_CHARGE_FAILED,
                    'status' => $gateway->getResultCode(),
                    'approval' => $gateway->getResultMessage(),
                ]);

                $this->emitter->emit(new RecurrentPaymentFailTryEvent($recurrentPayment));
            } catch (RecurrentPaymentFailStop $exception) {
                $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_FAIL);
                $this->recurrentPaymentsRepository->update($recurrentPayment, [
                    'state' => RecurrentPaymentsRepository::STATE_SYSTEM_STOP,
                    'status' => $gateway->getResultCode(),
                    'approval' => $gateway->getResultMessage(),
                ]);

                $this->emitter->emit(new RecurrentPaymentFailEvent($recurrentPayment));
            } catch (GatewayFail $exception) {
                $next = new \DateInterval($this->applicationConfig->get('recurrent_payment_gateway_fail_delay'));
                $nextCharge = new DateTime();
                $nextCharge->add($next);

                $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_FAIL);
                $this->recurrentPaymentsRepository->add(
                    $recurrentPayment->cid,
                    $payment,
                    $nextCharge,
                    $customChargeAmount,
                    $recurrentPayment->retries
                );

                $this->recurrentPaymentsRepository->update($recurrentPayment, [
                    'state' => RecurrentPaymentsRepository::STATE_CHARGE_FAILED,
                    'status' => $exception->getCode(),
                    'approval' => $exception->getMessage(),
                ]);

                $this->emitter->emit(new RecurrentPaymentFailTryEvent($recurrentPayment));
            }

            $this->paymentLogsRepository->add(
                $gateway->isSuccessful() ? 'OK' : 'ERROR',
                json_encode($gateway->getResponseData()),
                'recurring-payment-automatic-charge',
                $payment->id
            );

            $output->writeln("<info>Recurrent payment: #{$recurrentPayment->id} Token: {$recurrentPayment->cid} User: #{$recurrentPayment->user_id} Status: {$gateway->getResultCode()}</info>");
        }

        $end = microtime(true);
        $duration = $end - $start;

        $output->writeln('');
        $output->writeln('<info>All done. Took ' . round($duration, 2) . ' sec.</info>');
        $output->writeln('');

        return 0;
    }

    private function validateRecurrentPayment($recurrentPayment)
    {
        $parentRecurrentPayment = $this->recurrentPaymentsRepository->getLastWithState($recurrentPayment, RecurrentPaymentsRepository::STATE_CHARGED);
        if (!$parentRecurrentPayment) {
            return;
        }

        $diff = $parentRecurrentPayment->charge_at->diff(new DateTime());

        if ($diff->days == 0 || $parentRecurrentPayment->charge_at === $recurrentPayment->charge_at) {
            $this->recurrentPaymentsRepository->update($recurrentPayment, [
                'state' => RecurrentPaymentsRepository::STATE_SYSTEM_STOP,
                'note' => 'Fast charge',
            ]);

            throw new RecurrentPaymentFastCharge();
        }
    }
}
