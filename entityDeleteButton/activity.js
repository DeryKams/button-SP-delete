(function () {
  'use strict';

  /*
    Уникальный ID основной кнопки в карточке сделки.
  */
  var ACTION_BUTTON_ID = 'my-crm-activity-like-button';

  /*
    Уникальный ID popup.
  */
  var POPUP_ID = 'my-crm-deal-fields-popup';

  /*
    Здесь задаёшь поля сделки, которые хочешь показать в popup.
    Это и есть твой "селектор" нужных полей на уровне данных.
    Можешь менять этот массив как угодно.
  */

  var DEAL_FIELDS_FOR_POPUP = [

    {
      code: 'uf_crm_1768381090',
      label: 'Китай 1'
    }
  ];

  /*
    Кандидаты на область вставки кнопки
    Первый подходящий DOM-узел и будет использован.
  */
  var TARGET_SELECTORS = [
    '.crm-entity-bizproc-container'
  ];

  /*
    Получаем ID сделки из URL.
    Ожидаем стандартный путь вида /crm/deal/details/123/
  */
  function getDealIdFromUrl() {
    var match = window.location.pathname.match(/\/crm\/deal\/details\/(\d+)\/?/i);

    if (match && match[1]) {
      return parseInt(match[1], 10);
    }

    return 0;
  }

  /*
    Ищем целевой контейнер для кнопки.
  */
  function findTargetNode() {
    for (var i = 0; i < TARGET_SELECTORS.length; i++) {
      var node = document.querySelector(TARGET_SELECTORS[i]);

      if (node) {
        return node;
      }
    }

    return null;
  }

  /*
    Универсальное уведомление.
  */
  function showMessage(text) {
    if (
      window.BX &&
      BX.UI &&
      BX.UI.Notification &&
      BX.UI.Notification.Center
    ) {
      BX.UI.Notification.Center.notify({
        content: text
      });
      return;
    }

    alert(text);
  }

  /*
    Загружаем значения нужных полей сделки с сервера.
  */
  function loadDealFields(dealId) {
    var sessid = (window.BX && BX.bitrix_sessid) ? BX.bitrix_sessid() : '';
    var body = new URLSearchParams();

    body.append('sessid', sessid);
    body.append('dealId', String(dealId));
    body.append('fields', JSON.stringify(DEAL_FIELDS_FOR_POPUP));

    return fetch('/local/entityDeleteButton/action.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    }).then(function (response) {
      return response.json();
    });
  }

  /*
    Собираем DOM-содержимое popup.
    Для каждого поля создаём чекбокс, который по умолчанию отмечен.
  */
  function buildPopupContent(items) {
    var wrapper = BX.create('div', {
      style: {
        padding: '16px',
        minWidth: '520px',
        maxWidth: '700px',
        maxHeight: '70vh',
        overflowY: 'auto',
        boxSizing: 'border-box'
      }
    });

    /*
      Заголовок popup.
    */
    wrapper.appendChild(
      BX.create('div', {
        text: 'Выберите элементы для удаления',
        style: {
          marginBottom: '12px',
          fontWeight: '600',
          fontSize: '15px'
        }
      })
    );

    /*
      Если backend не вернул ни одного поля.
    */
    if (!items || !items.length) {
      wrapper.appendChild(
        BX.create('div', {
          text: 'Нет данных для отображения'
        })
      );

      return wrapper;
    }

    /*
      Каждое поле рисуем отдельным блоком.
    */
    items.forEach(function (fieldItem, fieldIndex) {
      var fieldBlock = BX.create('div', {
        style: {
          marginBottom: '12px',
          padding: '10px',
          border: '1px solid #e5e7eb',
          borderRadius: '6px'
        }
      });

      /*
        Название поля
      */
      fieldBlock.appendChild(
        BX.create('div', {
          text: fieldItem.label || fieldItem.code || 'Поле',
          style: {
            marginBottom: '8px',
            fontWeight: '600'
          }
        })
      );

      /*
        Если у поля нет значений
      */
      if (!fieldItem.values || !fieldItem.values.length) {
        fieldBlock.appendChild(
          BX.create('div', {
            text: 'Нет значений',
            style: {
              color: '#777'
            }
          })
        );

        wrapper.appendChild(fieldBlock);
        return;
      }

      /*
        Рисуем чекбоксы уже по значениям поля, а не по самому полю
      */
      fieldItem.values.forEach(function (valueItem, valueIndex) {
        var checkboxId = 'my-popup-value-checkbox-' + fieldIndex + '-' + valueIndex;

        var row = BX.create('div', {
          style: {
            marginBottom: '6px'
          }
        });

        var checkbox = BX.create('input', {
          attrs: {
            type: 'checkbox',
            id: checkboxId,
            checked: 'checked'
          },
          props: {
            className: 'my-popup-value-checkbox'
          }
        });

        /*
          Сохраняем данные значения в data-атрибутах
        */
        checkbox.setAttribute('data-field-code', fieldItem.code || '');
        checkbox.setAttribute('data-field-label', fieldItem.label || '');
        checkbox.setAttribute('data-value-id', valueItem.id || '');
        checkbox.setAttribute('data-item-id', valueItem.id || '');
        checkbox.setAttribute('data-value-title', valueItem.title || '');
        checkbox.setAttribute('data-entity-type-id', String(valueItem.entityTypeId || ''));
        checkbox.setAttribute('data-entity-title', String(valueItem.entityTitle || ''));
        checkbox.setAttribute('data-item-title', String(valueItem.itemTitle || ''));

        var label = BX.create('label', {
          attrs: {
            for: checkboxId
          },
          text: valueItem.title || valueItem.id || 'Без названия',
          style: {
            marginLeft: '8px',
            cursor: 'pointer'
          }
        });

        row.appendChild(checkbox);
        row.appendChild(label);
        fieldBlock.appendChild(row);
      });

      wrapper.appendChild(fieldBlock);
    });

    return wrapper;
  }


  function sendItemsToDelete(dealId, items, popup) {
    if (!items || !items.length) {
      showMessage('Не выбраны элементы для удаления');
      return;
    }

    if (!dealId || Number(dealId) <= 0) {
      console.error('dealId не определён', dealId, items);
      showMessage('Не удалось определить ID сделки');
      return;
    }

    var sessid = (window.BX && BX.bitrix_sessid) ? BX.bitrix_sessid() : '';
    var body = new URLSearchParams();

    body.append('sessid', sessid);
    body.append('dealId', String(dealId));
    body.append('items', JSON.stringify(items));

    fetch('/local/entityDeleteButton/crmDelete.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    })
      .then(async function (response) {
        var text = await response.text();

        console.log('Сырой ответ crmDelete.php:', text);

        var data;
        try {
          data = JSON.parse(text);
        } catch (e) {
          throw new Error('crmDelete.php вернул невалидный JSON: ' + text);
        }

        if (!response.ok) {
          throw new Error((data && data.message) ? data.message : ('HTTP ' + response.status));
        }

        return data;
      })
      .then(function (data) {
        console.log('Ответ crmDelete.php:', data);

        if (!data || (data.status !== 'success' && data.status !== 'partial')) {
          showMessage((data && data.message) ? data.message : 'Ошибка удаления');
          return;
        }

        var success = [];
        var skipped = [];
        var failed = [];
        var cleanupFailed = [];

        if (Array.isArray(data.results)) {
          data.results.forEach(function (item) {
            if (item.status === 'success') {
              success.push(item);
            } else if (item.status === 'skipped') {
              skipped.push(item);
            } else {
              failed.push(item);
            }
          });
        }

        if (Array.isArray(data.cleanupResults)) {
          data.cleanupResults.forEach(function (row) {
            if (!row.cleanup || row.cleanup.status !== 'success') {
              cleanupFailed.push(row);
            }
          });
        }

        console.log('Удалено успешно:', success);
        console.log('Пропущено:', skipped);
        console.log('Ошибки удаления:', failed);
        console.log('Ошибки очистки поля:', cleanupFailed);

        if (!failed.length && !cleanupFailed.length) {
          showMessage(
            'Готово. Удалено: ' + success.length +
            (skipped.length ? ', уже отсутствовали: ' + skipped.length : '')
          );

          popup.close();

          /*
            После успешного завершения лучше перезагрузить карточку
            чтобы пользователь сразу увидел обновлённое поле сделк
          */
          window.location.reload();
          return;
        }

        showMessage(
          'Удаление завершено частично. ' +
          'Удалено: ' + success.length +
          ', пропущено: ' + skipped.length +
          ', ошибок удаления: ' + failed.length +
          ', ошибок очистки поля: ' + cleanupFailed.length +
          '. Подробности смотри в console.log'
        );
      })
      .catch(function (error) {
        console.error('Ошибка запроса удаления:', error);
        showMessage(error.message || 'Ошибка запроса на удаление');
      });
  }  /*
    Открываем popup и вешаем тестовую кнопку:
    вывести выбранные элементы в консоль и закрыть окно.
  */
  function showFieldsPopup(items, popupDealId) {
    /*
    Если popup уже существует, сначала уничтожаем его,
    чтобы не осталось старого состояния.
  */
    var existingPopup = BX.PopupWindowManager.getPopupById(POPUP_ID);

    if (existingPopup) {
      existingPopup.destroy();
    }

    /*
      Собираем контент заранее.
      Так popup сразу знает реальные размеры содержимого.
    */
    var contentNode = buildPopupContent(items);

    /*
      ВАЖНО:
      Второй аргумент = null.
      Это означает, что popup не привязан к конкретному элементу
      и будет открыт как модальное окно по центру.
    */
    var popup = BX.PopupWindowManager.create(POPUP_ID, null, {
      autoHide: false,
      closeIcon: true,
      closeByEsc: true,
      overlay: true,
      lightShadow: true,
      draggable: false,
      titleBar: 'Поля сделки',
      content: contentNode,

      /*
        Нулевые отступы, чтобы не смещать окно вручную.
      */
      offsetLeft: 0,
      offsetTop: 0,

      buttons: [
        new BX.PopupWindowButton({
          text: 'Вывести выбранное в консоль',
          className: 'popup-window-button-accept',
          events: {
            click: function () {
              var selectedNodes = contentNode.querySelectorAll('.my-popup-value-checkbox:checked');
              var selectedItems = [];

              Array.prototype.forEach.call(selectedNodes, function (node) {
                selectedItems.push({
                  fieldCode: node.getAttribute('data-field-code') || '',
                  fieldLabel: node.getAttribute('data-field-label') || '',
                  itemId: Number(node.getAttribute('data-item-id') || 0),
                  entityTypeId: Number(node.getAttribute('data-entity-type-id') || 0),
                  entityTitle: node.getAttribute('data-entity-title') || '',
                  itemTitle: node.getAttribute('data-item-title') || '',
                  valueTitle: node.getAttribute('data-value-title') || ''
                });
              });

              console.log('Элементы для удаления:', selectedItems);

              sendItemsToDelete(popupDealId, selectedItems, popup);
            }
          }
        }),
        new BX.PopupWindowButtonLink({
          text: 'Отмена',
          className: 'popup-window-button-link-cancel',
          events: {
            click: function () {
              popup.close();
            }
          }
        })
      ],

      events: {
        onAfterPopupShow: function () {
          /*
            Если содержимое построилось динамически,
            принудительно пересчитываем позицию popup.
          */
          this.adjustPosition();

          /*
            На всякий случай пересчитываем overlay,
            если высота страницы изменилась.
          */
          if (this.overlay) {
            this.resizeOverlay();
          }
        },

        onPopupClose: function () {
          /*
            После закрытия уничтожаем объект popup,
            чтобы следующее открытие всегда было "чистым".
          */
          this.destroy();
        }
      }
    });

    popup.show();
  }  /*
    Обработка клика по основной кнопке.
    Сначала тянем значения сделки, потом показываем popup.
  */
  function handleActionClick(buttonNode) {
    var dealId = getDealIdFromUrl();

    if (!dealId) {
      showMessage('Не удалось определить ID сделки');
      return;
    }

    buttonNode.disabled = true;
    var oldText = buttonNode.textContent;
    buttonNode.textContent = 'Загрузка...';

    loadDealFields(dealId)
      .then(function (data) {
        console.log('Ответ action.php:', data);

        if (!data || data.status !== 'success') {
          showMessage((data && data.message) ? data.message : 'Не удалось загрузить данные сделки');
          return;
        }

        showFieldsPopup(data.items || [], Number(data.dealId || dealId || 0));
      })
      .catch(function (error) {
        console.error('Ошибка загрузки action.php:', error);
        showMessage(error.message || 'Ошибка загрузки данных сделки');
      })
      .finally(function () {
        /*
          Всегда возвращаем кнопку в исходное состояние,
          даже если backend ответил ошибкой.
        */
        buttonNode.disabled = false;
        buttonNode.textContent = oldText;
      });
  }

  /*
    Создаём кнопку, которая будет открывать popup.
  */
  function createActionButton() {
    var button = document.createElement('button');

    button.id = ACTION_BUTTON_ID;
    button.type = 'button';
    button.className = 'ui-btn ui-btn-sm ui-btn-round ui-btn-no-caps ui-btn-primary-border';
    button.textContent = 'Удалить Китай';

    button.addEventListener('click', function () {
      handleActionClick(button);
    });

    return button;
  }

  /*
    Вставляем кнопку в карточку сделки, если
    1.мы на сделке
    2.нашли целевой контейнер
    3. кнопку ещё не вставляли
  */
  function ensureButtonInserted() {
    var dealId = getDealIdFromUrl();

    if (!dealId) {
      return;
    }

    if (document.getElementById(ACTION_BUTTON_ID)) {
      return;
    }

    var targetNode = findTargetNode();

    if (!targetNode) {
      return;
    }

    targetNode.prepend(createActionButton());
  }

  /*
    используем MutationObserver.
  */
  function startObserver() {
    var observer = new MutationObserver(function () {
      ensureButtonInserted();
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  /*
    Запуск
  */
  function bootstrap() {
    ensureButtonInserted();
    startObserver();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();