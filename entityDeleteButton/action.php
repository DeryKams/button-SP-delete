<?php

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Crm\Service\Container;

/*
    Код поля сделки => entityTypeId смарт-процесса
*/
const SMART_PROCESS_FIELD_MAP = [
    'UF_CRM_1768381090' => 1042,
    'UF_CRM_1773808972' => 1068,

];

header('Content-Type: application/json; charset=UTF-8');

function sendJson(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    die();
}

function normalizeToArray($value): array
{
    if ($value === null || $value === '' || $value === false)
    {
        return [];
    }

    if (is_array($value))
    {
        return $value;
    }

    return [$value];
}

function getSmartProcessTitle(int $entityTypeId): string
{
    $type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);

    if ($type && method_exists($type, 'getTitle'))
    {
        $title = trim((string)$type->getTitle());

        if ($title !== '')
        {
            return $title;
        }
    }

    return 'Смарт-процесс ' . $entityTypeId;
}

function getSmartProcessItemTitle(int $entityTypeId, int $itemId): string
{
    $factory = Container::getInstance()->getFactory($entityTypeId);

    if (!$factory)
    {
        return '';
    }

    $item = $factory->getItem($itemId);

    if (!$item)
    {
        return '';
    }

    if (method_exists($item, 'getTitle'))
    {
        $title = trim((string)$item->getTitle());

        if ($title !== '')
        {
            return $title;
        }
    }

    if (method_exists($item, 'getCompatibleData'))
    {
        $data = (array)$item->getCompatibleData();

        if (!empty($data['TITLE']))
        {
            return trim((string)$data['TITLE']);
        }

        if (!empty($data['NAME']))
        {
            return trim((string)$data['NAME']);
        }
    }

    return '';
}

function extractFieldValues(string $fieldCode, $rawValue): array
{
    /*
        Этот обработчик работает только для полей,
        которые явно описаны в маппинге.
    */
    if (!isset(SMART_PROCESS_FIELD_MAP[$fieldCode]))
    {
        return [];
    }

    $values = normalizeToArray($rawValue);

    if (!$values)
    {
        return [];
    }

    $entityTypeId = (int)SMART_PROCESS_FIELD_MAP[$fieldCode];
    $result = [];

    foreach ($values as $value)
    {
        $itemId = (int)$value;

        if ($itemId <= 0)
        {
            continue;
        }

        $entityTitle = getSmartProcessTitle($entityTypeId);
        $itemTitle = 'Элемент не найден';

        $resolvedTitle = getSmartProcessItemTitle($entityTypeId, $itemId);
        if ($resolvedTitle !== '')
        {
            $itemTitle = $resolvedTitle;
        }

        $result[] = [
            'id' => (string)$itemId,
            'title' => $entityTitle . ': ' . $itemTitle . ' [ID: ' . $itemId . ']',
            'entityTypeId' => $entityTypeId,
            'entityTitle' => $entityTitle,
            'itemTitle' => $itemTitle,
        ];
    }

    return $result;
}

global $USER, $USER_FIELD_MANAGER;

if (!$USER || !$USER->IsAuthorized())
{
    sendJson([
        'status' => 'error',
        'message' => 'Пользователь не авторизован',
    ]);
}

if (!check_bitrix_sessid())
{
    sendJson([
        'status' => 'error',
        'message' => 'Некорректная сессия',
    ]);
}

if (!Loader::includeModule('crm'))
{
    sendJson([
        'status' => 'error',
        'message' => 'Модуль crm не подключился',
    ]);
}

$dealId = (int)($_POST['dealId'] ?? 0);

if ($dealId <= 0)
{
    sendJson([
        'status' => 'error',
        'message' => 'Не передан ID сделки',
    ]);
}

$fieldConfigs = json_decode((string)($_POST['fields'] ?? '[]'), true);

if (!is_array($fieldConfigs))
{
    sendJson([
        'status' => 'error',
        'message' => 'Некорректный список полей',
    ]);
}

$deal = \CCrmDeal::GetByID($dealId, false);

if (!$deal || !is_array($deal))
{
    sendJson([
        'status' => 'error',
        'message' => 'Сделка не найдена',
    ]);
}

$userFields = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', $dealId);

$resultItems = [];

foreach ($fieldConfigs as $fieldConfig)
{
    $code = strtoupper(trim((string)($fieldConfig['code'] ?? '')));
    $label = trim((string)($fieldConfig['label'] ?? $code));

    if ($code === '' || !preg_match('/^[A-Z0-9_]+$/', $code))
    {
        continue;
    }

    if (isset($userFields[$code]))
    {
        $rawValue = $userFields[$code]['VALUE'] ?? null;
    }
    else
    {
        $rawValue = $deal[$code] ?? null;
    }

    $resultItems[] = [
        'code' => $code,
        'label' => $label,
        'values' => extractFieldValues($code, $rawValue),
    ];
}

sendJson([
    'status' => 'success',
    'dealId' => $dealId,
    'items' => $resultItems,
]);