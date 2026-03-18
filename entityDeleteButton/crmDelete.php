<?php

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Crm\Service\Container;

header('Content-Type: application/json; charset=UTF-8');

function sendJson(array $data, int $httpStatus = 200): void
{
    /*
        На случай, если до этого уже что-то вывелось в буфер,
        полностью очищаем буферы, чтобы не сломать JSON-ответ.
    */
    while (ob_get_level() > 0)
    {
        ob_end_clean();
    }

    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

global $USER;

if (!$USER || !$USER->IsAuthorized()) {
    sendJson([
        'status' => 'error',
        'message' => 'Пользователь не авторизован',
    ]);
}

if (!check_bitrix_sessid()) {
    sendJson([
        'status' => 'error',
        'message' => 'Некорректная сессия',
    ]);
}

if (!Loader::includeModule('crm')) {
    sendJson([
        'status' => 'error',
        'message' => 'Модуль crm не подключился',
    ]);
}
try
{
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    $dealId = (int)($_POST['dealId'] ?? 0);

    if ($dealId <= 0 && is_array($items) && !empty($items))
    {
        foreach ($items as $item)
        {
            $candidateDealId = (int)($item['dealId'] ?? 0);

            if ($candidateDealId > 0)
            {
                $dealId = $candidateDealId;
                break;
            }
        }
    }

    if ($dealId <= 0)
    {
        sendJson([
            'status' => 'error',
            'message' => 'Не передан ID сделки',
        ], 400);
    }

    if (!is_array($items) || !$items)
    {
        sendJson([
            'status' => 'error',
            'message' => 'Не переданы элементы для удаления',
        ], 400);
    }

    $results = [];
    $fieldsToCleanup = [];

    foreach ($items as $item)
    {
        $itemId = (int)($item['itemId'] ?? 0);
        $entityTypeId = (int)($item['entityTypeId'] ?? 0);
        $fieldCode = strtoupper(trim((string)($item['fieldCode'] ?? '')));

        if ($itemId <= 0 || $entityTypeId <= 0 || $fieldCode === '')
        {
            $results[] = [
                'itemId' => $itemId,
                'entityTypeId' => $entityTypeId,
                'fieldCode' => $fieldCode,
                'status' => 'error',
                'message' => 'Некорректные параметры удаления',
            ];
            continue;
        }

        /*
            Даже если удаление не сработает,
            это поле всё равно потом перечитаем и очистим.
        */
        $fieldsToCleanup[$fieldCode . '_' . $entityTypeId] = [
            'fieldCode' => $fieldCode,
            'entityTypeId' => $entityTypeId,
        ];

        $factory = Container::getInstance()->getFactory($entityTypeId);

        if (!$factory)
        {
            $results[] = [
                'itemId' => $itemId,
                'entityTypeId' => $entityTypeId,
                'fieldCode' => $fieldCode,
                'status' => 'error',
                'message' => 'Factory не найден',
            ];
            continue;
        }

        $crmItem = $factory->getItem($itemId);

        if (!$crmItem)
        {
            /*
                Для операции удаления отсутствие элемента -это не критическая ошибка.
                Нужного элемента уже нет, значит конечное состояние уже достигнуто.
            */
            $results[] = [
                'itemId' => $itemId,
                'entityTypeId' => $entityTypeId,
                'fieldCode' => $fieldCode,
                'status' => 'skipped',
                'message' => 'Элемент уже отсутствует',
            ];
            continue;
        }

        $operation = $factory->getDeleteOperation($crmItem);
        $operationResult = $operation->launch();

        if (!$operationResult->isSuccess())
        {
            $results[] = [
                'itemId' => $itemId,
                'entityTypeId' => $entityTypeId,
                'fieldCode' => $fieldCode,
                'status' => 'error',
                'message' => implode('; ', $operationResult->getErrorMessages()),
            ];
            continue;
        }

        $results[] = [
            'itemId' => $itemId,
            'entityTypeId' => $entityTypeId,
            'fieldCode' => $fieldCode,
            'status' => 'success',
            'message' => 'Элемент удалён',
        ];
    }

    $cleanupResults = [];

    foreach ($fieldsToCleanup as $cleanupData)
    {
        $cleanupResults[] = [
            'fieldCode' => $cleanupData['fieldCode'],
            'entityTypeId' => $cleanupData['entityTypeId'],
            'cleanup' => rewriteDealFieldWithExistingItems(
                $dealId,
                $cleanupData['fieldCode'],
                $cleanupData['entityTypeId']
            ),
        ];
    }

    $hasDeleteErrors = false;
    $hasCleanupErrors = false;

    foreach ($results as $resultRow)
    {
        if (($resultRow['status'] ?? '') === 'error')
        {
            $hasDeleteErrors = true;
            break;
        }
    }

    foreach ($cleanupResults as $cleanupRow)
    {
        if (($cleanupRow['cleanup']['status'] ?? '') === 'error')
        {
            $hasCleanupErrors = true;
            break;
        }
    }

    $finalStatus = 'success';
    $finalMessage = 'Удаление завершено успешно';

    if ($hasDeleteErrors || $hasCleanupErrors)
    {
        $finalStatus = 'partial';
        $finalMessage = 'Удаление завершено частично';
    }

    sendJson([
        'status' => $finalStatus,
        'message' => $finalMessage,
        'results' => $results,
        'cleanupResults' => $cleanupResults,
    ]);
}
catch (\Throwable $e)
{
    sendJson([
        'status' => 'error',
        'message' => 'Исключение на сервере: ' . $e->getMessage(),
    ], 500);
}
$items = json_decode((string)($_POST['items'] ?? '[]'), true);
$dealId = (int)($_POST['dealId'] ?? 0);

if ($dealId <= 0 && is_array($items) && !empty($items))
{
    foreach ($items as $item)
    {
        $candidateDealId = (int)($item['dealId'] ?? 0);

        if ($candidateDealId > 0)
        {
            $dealId = $candidateDealId;
            break;
        }
    }
}

if ($dealId <= 0) {
    sendJson([
        'status' => 'error',
        'message' => 'Не передан ID сделки',
    ]);
}

if (!is_array($items) || !$items) {
    sendJson([
        'status' => 'error',
        'message' => 'Не переданы элементы для удаления',
    ]);
}

$results = [];
$fieldsToCleanup = [];

/*
    Сначала пытаемся удалить выбранные элементы
    параллельно собираем список полей, которые потом нужно очистить
*/
foreach ($items as $item)
{
    $itemId = (int)($item['itemId'] ?? 0);
    $entityTypeId = (int)($item['entityTypeId'] ?? 0);
    $fieldCode = strtoupper(trim((string)($item['fieldCode'] ?? '')));

    if ($itemId <= 0 || $entityTypeId <= 0 || $fieldCode === '')
    {
        $results[] = [
            'itemId' => $itemId,
            'entityTypeId' => $entityTypeId,
            'fieldCode' => $fieldCode,
            'status' => 'error',
            'message' => 'Некорректные параметры удаления',
        ];
        continue;
    }

    $fieldsToCleanup[$fieldCode . '_' . $entityTypeId] = [
        'fieldCode' => $fieldCode,
        'entityTypeId' => $entityTypeId,
    ];

    $factory = Container::getInstance()->getFactory($entityTypeId);

    if (!$factory)
    {
        $results[] = [
            'itemId' => $itemId,
            'entityTypeId' => $entityTypeId,
            'fieldCode' => $fieldCode,
            'status' => 'error',
            'message' => 'Factory не найден',
        ];
        continue;
    }

    $crmItem = $factory->getItem($itemId);

    if (!$crmItem)
    {
        $results[] = [
            'itemId' => $itemId,
            'entityTypeId' => $entityTypeId,
            'fieldCode' => $fieldCode,
            'status' => 'error',
            'message' => 'Элемент не найден',
        ];
        continue;
    }

    $operation = $factory->getDeleteOperation($crmItem);
    $operationResult = $operation->launch();

    if (!$operationResult->isSuccess())
    {
        $results[] = [
            'itemId' => $itemId,
            'entityTypeId' => $entityTypeId,
            'fieldCode' => $fieldCode,
            'status' => 'error',
            'message' => implode('; ', $operationResult->getErrorMessages()),
            'debug' => [
                'factoryClass' => get_class($factory),
                'itemClass' => get_class($crmItem),
            ],
        ];
        continue;
    }

    $results[] = [
        'itemId' => $itemId,
        'entityTypeId' => $entityTypeId,
        'fieldCode' => $fieldCode,
        'status' => 'success',
        'message' => 'Элемент удалён',
    ];
}

$cleanupResults = [];

/*
    после удаления / ошибок перечитываем поле сделки
    и оставляем только реально существующие элементы
*/
foreach ($fieldsToCleanup as $cleanupData)
{
    $cleanupResults[] = [
        'fieldCode' => $cleanupData['fieldCode'],
        'entityTypeId' => $cleanupData['entityTypeId'],
        'cleanup' => rewriteDealFieldWithExistingItems(
            $dealId,
            $cleanupData['fieldCode'],
            $cleanupData['entityTypeId']
        ),
    ];
}

sendJson([
    'status' => 'success',
    'results' => $results,
    'cleanupResults' => $cleanupResults,
]);

function normalizeToArray($value): array
{
    if ($value === null || $value === '' || $value === false)
    {
        return [];
    }

    if (!is_array($value))
    {
        $value = [$value];
    }

    $result = [];

    foreach ($value as $item)
    {
        $itemId = (int)$item;

        if ($itemId > 0)
        {
            $result[] = $itemId;
        }
    }

    /*
        Убираем дубли и переиндексируем массив
        чтобы потом в поле сделки не записывался мусор
    */
    $result = array_values(array_unique($result));

    return $result;
}

function getExistingSmartProcessIds(array $ids, int $entityTypeId): array
{
    $result = [];

    foreach ($ids as $id)
    {
        $itemId = (int)$id;

        if ($itemId <= 0)
        {
            continue;
        }

        if (isSmartProcessItemExists($itemId, $entityTypeId))
        {
            $result[] = $itemId;
        }
    }

    return array_values(array_unique($result));
}

function rewriteDealFieldWithExistingItems(int $dealId, string $fieldCode, int $entityTypeId): array
{
    $fieldMeta = getDealUserFieldMeta($dealId, $fieldCode);

    if (!$fieldMeta)
    {
        return [
            'status' => 'error',
            'message' => 'Поле сделки не найдено',
            'fieldCode' => $fieldCode,
        ];
    }

    $currentValue = $fieldMeta['VALUE'] ?? null;
    $currentIds = normalizeToArray($currentValue);
    $existingIds = getExistingSmartProcessIds($currentIds, $entityTypeId);

    $isMultiple = (($fieldMeta['MULTIPLE'] ?? 'N') === 'Y');

    /*
        для множественного поля пишем очищенный массив
        для одиночного - одно значение или false, чтобы очистка была явной
    */
    $newValue = $isMultiple
        ? $existingIds
        : (!empty($existingIds) ? (int)$existingIds[0] : false);

    $deal = new \CCrmDeal(false);

    $updateFields = [
        $fieldCode => $newValue,
    ];

    $updateResult = $deal->Update($dealId, $updateFields);

    if (!$updateResult)
    {
        return [
            'status' => 'error',
            'message' => 'Не удалось обновить сделку: ' . (string)$deal->LAST_ERROR,
            'fieldCode' => $fieldCode,
            'currentIds' => $currentIds,
            'existingIds' => $existingIds,
            'newValue' => $newValue,
        ];
    }

    return [
        'status' => 'success',
        'message' => 'Поле очищено от несуществующих элементов',
        'fieldCode' => $fieldCode,
        'currentIds' => $currentIds,
        'existingIds' => $existingIds,
        'newValue' => $newValue,
    ];
}

function getDealUserFieldMeta(int $dealId, string $fieldCode): ?array
{
    global $USER_FIELD_MANAGER;

    $userFields = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', $dealId);

    return $userFields[$fieldCode] ?? null;
}

function isSmartProcessItemExists(int $itemId, int $entityTypeId): bool
{
    if ($itemId <= 0 || $entityTypeId <= 0)
    {
        return false;
    }

    $factory = Container::getInstance()->getFactory($entityTypeId);

    if (!$factory)
    {
        return false;
    }

    return (bool)$factory->getItem($itemId);
}


