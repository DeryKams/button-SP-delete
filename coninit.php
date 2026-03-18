
// Скрипт содержится в /local/entityDeleteButton/
// Поле для получения значений привязки activity.js - DEAL_FIELDS_FOR_POPUP
// Необходим для создания кнопки внутри карточки сделки, которая открывается popUp со списком удаления
$customBootstrap = $_SERVER['DOCUMENT_ROOT'] . '/local/entityDeleteButton/bootstrap.php';

if (file_exists($customBootstrap))
{
    require_once $customBootstrap;
}
