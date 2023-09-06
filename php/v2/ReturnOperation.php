<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @return array
     * @throws \Exception
     */

    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');

        $data =  $this->cleanData($data);

        $notificationType = (int)$data['notificationType'];
        if (empty($notificationType)) {
            throw new \Exception('Empty notificationType', 400);
        }

        $resellerId = (int)$data['resellerId'];
        if (empty($resellerId)) {
            throw new \Exception('Empty resellerId', 400);
        }

        $templateData = $this->prepareTemplateData($data);

        $result = array();
        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                        0 => [
                            'emailFrom' => $emailFrom,
                            'emailTo' => $email,
                            'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                            'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                        ],
                    ],
                    $resellerId,
                    NotificationEvents::CHANGE_RETURN_STATUS
                );
            }
            $result['notificationEmployeeByEmail'] = true;
        }

        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($templateData['email'])) {
                MessagesClient::sendMessage([
                        0 => [
                            'emailFrom' => $emailFrom,
                            'emailTo' => $templateData['email'],
                            'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                            'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                        ],
                    ],
                    $resellerId,
                    $templateData['CLIENT_ID'],
                    NotificationEvents::CHANGE_RETURN_STATUS,
                    (int)$data['differences']['to']
                );
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($templateData['CLIENT_MOBILE'])) {
                $res = NotificationManager::send(
                    $resellerId,
                    $templateData['CLIENT_ID'],
                    NotificationEvents::CHANGE_RETURN_STATUS,
                    (int)$data['differences']['to'],
                    $templateData
                );
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }
        return $this->prepareResulData($result);
    }

    /**
     * @param array $data
     * @return array
     * @throws \Exception
     */

    public function prepareTemplateData(array $data): array
    {
        $reseller = Seller::getById((int)$data['resellerId']);
        if (empty($reseller)) {
            throw new \Exception('Seller not found!', 400);
        }

        $cr = Employee::getById((int)$data['creatorId']);
        if (empty($cr)) {
            throw new \Exception('Creator not found!', 400);
        }

        $et = Employee::getById((int)$data['expertId']);
        if (empty($et)) {
            throw new \Exception('Expert not found!', 400);
        }

        $client = Contractor::getById((int)$data['clientId']);
        if (empty($client)) {
            throw new \Exception('client not found!', 400);
        }

        $templateData = [
            'COMPLAINT_ID' => (int)$data['complaintId'],
            'COMPLAINT_NUMBER' => (string)$data['complaintNumber'],
            'CREATOR_ID' => (int)$data['creatorId'],
            'CREATOR_NAME' => $cr->getFullName(),
            'EXPERT_ID' => (int)$data['expertId'],
            'EXPERT_NAME' => $et->getFullName(),
            'CLIENT_ID' => (int)$data['clientId'],
            'CONSUMPTION_ID' => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string)$data['agreementNumber'],
            'DATE' => (string)$data['date']
        ];

        foreach ($templateData as $key => $value) {
            if (empty($value)) {
                throw new \Exception("Template Data ({$key}) is empty!", 400);
            }
        }

        if (!empty($data['differences']['from']) && !empty($data['differences']['to'])) {
            $templateData['DIFFERENCES'] = $this->getDifferences($data['notificationType'], $data['resellerId'], (int)$data['differences']['from'], (int)$data['differences']['to']);
        } else {
            $templateData['DIFFERENCES'] = '';
        }
        $templateData['CLIENT_NAME'] = !empty($client->getFullName()) ? $client->getFullName() : $client->name;
        $templateData['CLIENT_EMAIL'] = !empty($client->email) ? $client->email : '';
        $templateData['CLIENT_MOBILE'] = !empty($client->mobile) ? $client->mobile : '';


        return $templateData;
    }

    /**
     * @param array $result
     * @return array
     */

    public function prepareResulData(array $result): array
    {
        $notificationEmployeeByEmail = !empty($result['notificationEmployeeByEmail']) ? $result['notificationEmployeeByEmail'] : false;
        $notificationClientByEmail = !empty($result['notificationClientByEmail']) ? $result['notificationClientByEmail'] : false;
        $isSent = !empty($result['notificationClientBySms']['isSent']) ? $result['notificationClientBySms']['isSent'] : false;
        $message = !empty($result['notificationClientBySms']['message']) ? $result['notificationClientBySms']['message'] : '';

        return [
            'notificationEmployeeByEmail' => $notificationEmployeeByEmail,
            'notificationClientByEmail' => $notificationClientByEmail,
            'notificationClientBySms' => [
                'isSent' => $isSent,
                'message' => $message
            ],
        ];
    }

    /**
     * @param int $notificationType
     * @param int $resellerId
     * @param int $differences_from
     * @param int $differences_to
     * @return string
     */

    public function getDifferences(int $notificationType,int $resellerId, int $differences_from, int $differences_to): string
    {
        switch ($notificationType) {
            case self::TYPE_NEW:
                $differences = __('NewPositionAdded', null, $resellerId);
                break;
            case self::TYPE_CHANGE:
                $differences = __('PositionStatusHasChanged', [
                    'FROM' => Status::getName($differences_from),
                    'TO' => Status::getName($differences_to),
                ], $resellerId);
                break;
            default:
                $differences = '';
        }
        return $differences;
    }

    /**
     * @param array $data
     * @return array
     */

    public function cleanData(array $data): array
    {
        $data = array_map('trim', $data);
        $data = array_map('htmlspecialchars', $data);
        return array_map('strip_tags', $data);
    }
}
