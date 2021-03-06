<?php

namespace Crm\PaymentsModule\Api;

use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\PaymentsModule\Gateways\GoPayRecurrent;
use Nette\Http\Response;

/**
 * Class GoPayNotificationHandler
 *
 * API Handler that GoPay call after payment status change.
 * Url for this API is send from crm when creating payment in go pay
 *   - https://doc.gopay.com/en/#callback
 *
 * @package Crm\PaymentsModule\Api
 */
class GoPayNotificationHandler extends ApiHandler
{
    private $gopay;

    public function __construct(GoPayRecurrent $gopay)
    {
        $this->gopay = $gopay;
    }

    public function params()
    {
        return [
            new InputParam(InputParam::TYPE_GET, 'id', InputParam::REQUIRED),
        ];
    }

    /**
     * @param ApiAuthorizationInterface $authorization
     * @return \Nette\Application\IResponse
     */
    public function handle(ApiAuthorizationInterface $authorization)
    {
        $paramsProcessor = new ParamsProcessor($this->params());
        if ($paramsProcessor->isError()) {
            $response = new JsonResponse(['status' => 'error', 'message' => 'Missing id parameter']);
            $response->setHttpCode(Response::S400_BAD_REQUEST);
            return $response;
        }
        $params = $paramsProcessor->getValues();

        $result = $this->gopay->notification($params['id']);
        if (!$result) {
            $response = new JsonResponse(['status' => 'error', 'message' => 'payment not found']);
            $response->setHttpCode(Response::S404_NOT_FOUND);
            return $response;
        }

        $response = new JsonResponse(['status' => 'ok']);
        $response->setHttpCode(Response::S200_OK);

        return $response;
    }
}
