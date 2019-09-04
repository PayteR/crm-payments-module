<?php

namespace Crm\PaymentsModule\Components;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\PaymentsModule\Report\NoRecurrentChargeReport;
use Crm\PaymentsModule\Report\PaidNextSubscriptionReport;
use Crm\PaymentsModule\Report\RecurrentWithoutProfileReport;
use Crm\PaymentsModule\Report\StoppedOnFirstSubscriptionReport;
use Crm\PaymentsModule\Report\TotalPaidSubscriptionsReport;
use Crm\PaymentsModule\Report\TotalRecurrentSubscriptionsReport;
use Crm\SubscriptionsModule\Report\ReportGroup;
use Crm\SubscriptionsModule\Report\ReportTable;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Kdyby\Translation\Translator;

class SubscriptionTypeReports extends BaseWidget
{
    private $templateName = 'subscription_type_reports.latte';

    private $subscriptionTypesRepository;

    private $translator;

    public function __construct(
        WidgetManager $widgetManager,
        Translator $translator,
        SubscriptionTypesRepository $subscriptionTypesRepository
    ) {
        parent::__construct($widgetManager);
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->translator = $translator;
    }

    public function identifier()
    {
        return 'subscriptiontypesreports';
    }

    public function render($subscriptionTypeId)
    {
        $reportTable = new ReportTable(
            ['subscription_type_id' => $subscriptionTypeId],
            $this->subscriptionTypesRepository->getDatabase(),
            new ReportGroup('users.source')
        );
        $reportTable
            ->addReport(new TotalPaidSubscriptionsReport('', $this->translator))
            ->addReport(new TotalRecurrentSubscriptionsReport('', $this->translator))
            ->addReport(new NoRecurrentChargeReport('', $this->translator), [\Crm\PaymentsModule\Report\TotalRecurrentSubscriptionsReport::class])
            ->addReport(new StoppedOnFirstSubscriptionReport('', $this->translator), [\Crm\PaymentsModule\Report\TotalRecurrentSubscriptionsReport::class])
            ->addReport(new PaidNextSubscriptionReport('', $this->translator, 1), [\Crm\PaymentsModule\Report\TotalRecurrentSubscriptionsReport::class])
            ->addReport(new PaidNextSubscriptionReport('', $this->translator, 2))
            ->addReport(new PaidNextSubscriptionReport('', $this->translator, 3))
        ;
        $this->template->reportTables = [
            $this->translator->translate('payments.admin.component.subscription_type_reports.title') => $reportTable->getData(),
        ];

        $this->template->setFile(__DIR__ . DIRECTORY_SEPARATOR . $this->templateName);
        $this->template->render();
    }
}
