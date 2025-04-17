<?php

date_default_timezone_set('Asia/Kolkata');
define('LISTINGS_ENTITY_TYPE_ID', 1084);
define('DEFAULT_RESPONSIBLE_PERSON', 1593);

function logData($message, $file)
{
    $timestamp = date("Y-m-d H:i:s");
    $formattedMessage = "[$timestamp] $message" . PHP_EOL . PHP_EOL;
    file_put_contents(__DIR__ . "/logs/$file", $formattedMessage, FILE_APPEND);
}

function createBitrixLead($fields)
{
    return CRest::call('crm.deal.add', [
        'fields' => $fields
    ])['result'];
}

function respondWithError($statusCode, $message)
{
    http_response_code($statusCode);
    echo json_encode(["status" => "error", "message" => $message]);
    exit;
}

function respondWithSuccess($message)
{
    http_response_code(200);
    echo json_encode(["status" => "success", "message" => $message]);
    exit;
}

function getResponsiblePerson(string $searchValue, string $searchType): ?int
{
    if ($searchType === 'reference') {
        $response = CRest::call('crm.item.list', [
            'entityTypeId' => LISTINGS_ENTITY_TYPE_ID,
            'filter' => ['ufCrm37ReferenceNumber' => $searchValue],
            'select' => ['ufCrm37ReferenceNumber', 'ufCrm37AgentEmail', 'ufCrm37ListingOwner', 'ufCrm37OwnerId'],
        ]);

        if (!empty($response['error'])) {
            error_log(
                'Error getting CRM item: ' . $response['error_description']
            );
            return null;
        }

        if (
            empty($response['result']['items']) ||
            !is_array($response['result']['items'])
        ) {
            error_log(
                'No listing found with reference number: ' . $searchValue
            );
            return null;
        }

        $listing = $response['result']['items'][0];

        $ownerId = $listing['ufCrm37OwnerId'] ?? null;
        if ($ownerId && is_numeric($ownerId)) {
            return (int)$ownerId;
        }

        $ownerName = $listing['ufCrm37ListingOwner'] ?? null;

        if ($ownerName) {
            $nameParts = explode(' ', trim($ownerName));
            $combinations = [];

            for ($i = 1; $i < count($nameParts); $i++) {
                $first = implode(' ', array_slice($nameParts, 0, $i));
                $last = implode(' ', array_slice($nameParts, $i));
                $combinations[] = ['%NAME' => $first, '%LAST_NAME' => $last];
            }

            foreach ($combinations as $filter) {
                $filter['!ID'] = [3, 268, 1945];
                $user = getUserId($filter);
                if ($user) return $user;
            }

            return getUserId([
                '%FIND' => $ownerName,
                '!ID' => [3, 268, 1945]
            ]);
        }

        $agentEmail = $listing['ufCrm37AgentEmail'] ?? null;
        if ($agentEmail) {
            return getUserId([
                'EMAIL' => $agentEmail,
                '!ID' => [3, 268, 1945]
            ]);
        } else {
            error_log(
                'No agent email found for reference number: ' . $searchValue
            );
            return DEFAULT_RESPONSIBLE_PERSON;
        }
    } else if ($searchType === 'phone') {
        return getUserId([
            '%PERSONAL_MOBILE' => $searchValue,
            '!ID' => [3, 268, 1945]
        ]);
    }

    return DEFAULT_RESPONSIBLE_PERSON;
}

function getUserId(array $filter): ?int
{
    $response = CRest::call('user.get', [
        'filter' => array_merge($filter, ['ACTIVE' => 'Y']),
    ]);

    if (!empty($response['error'])) {
        error_log('Error getting user: ' . $response['error_description']);
        return null;
    }

    if (empty($response['result'])) {
        return null;
    }

    if (empty($response['result'][0]['ID'])) {
        return null;
    }

    return (int)$response['result'][0]['ID'];
}

function createContact($fields)
{
    $response = CRest::call('crm.contact.add', [
        'fields' => $fields
    ]);

    return $response['result'];
}

function getPropertyPrice($propertyReference)
{
    $response = CRest::call('crm.item.list', [
        'entityTypeId' => LISTINGS_ENTITY_TYPE_ID,
        'filter' => ['ufCrm37ReferenceNumber' => $propertyReference],
        'select' => ['ufCrm37Price'],
    ]);

    return $response['result']['items'][0]['ufCrm37Price'] ?? null;
}