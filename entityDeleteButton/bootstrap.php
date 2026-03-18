<?php

use Bitrix\Main\Context;
use Bitrix\Main\Page\Asset;

/*
    Получаем текущий запрос
*/
$request = Context::getCurrent()->getRequest();

/*
    Определяем текущий путь
    подключаем скрипт только на страницах CRM
*/
$currentPage = $request->getRequestedPage();

/*
получаем страницу
*/
if (strpos($currentPage, '/crm/deal/details') !== false || strpos($currentPage, '/bitrix/') !== false)
{
        CJSCore::Init(['popup']);
    Asset::getInstance()->addJs('/local/entityDeleteButton/activity.js');
}