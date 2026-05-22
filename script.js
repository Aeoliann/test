        const importInput = document.createElement('input');
    importInput.type = 'file';
    importInput.accept = '.xlsx, .xls';

    // Функция, которая срабатывает при нажатии на кнопку "Импорт"
    document.querySelector('.btn-import').onclick = () => importInput.click();



    
async function loadProjectTtns(pid) {
    // 1. Проверяем, существует ли вообще контейнер на странице
    const container = document.getElementById('ttnListContainer');
    if (!container) {
        console.error("Критическая ошибка: Контейнер ttnListContainer не найден в разметке HTML!");
        return;
    }
    
    container.innerHTML = '<span style="color:#92929f; font-size:12px; padding:10px; display:block; text-align:left;">Загрузка списка отгрузок...</span>';
    
    try {
        const res = await fetch('get_ttns.php?pid=' + parseInt(pid));
        const data = await res.json();
        
        console.log("Получены данные ТТН от сервера:", data);
        
        let html = '<div style="display: flex; flex-direction: column; gap: 8px; width: 100%;">';
        
        if (data && data.length > 0) {
            data.forEach(function(t) {
                // Жесткое экранирование спецсимволов и кавычек, чтобы они не разрывали HTML
                const safeNum  = (t.ttn_number || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                const safeDate = (t.ttn_date || '');
                const safeProd = (t.product_info || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                const safeAmt  = parseFloat(t.amount || 0).toFixed(2);
                
                // Формируем блок управления файлом
                let fileControls = '';
                if (t.ttn_file) {
                    fileControls += '<a href="uploads/ttn/' + t.ttn_file + '" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">👁 PDF</a>';
                    fileControls += '<button type="button" onclick="deleteTtnPdf(' + t.id + ', ' + pid + ')" style="background:none; border:none; color:#f56565; cursor:pointer; font-size:12px; font-weight:bold; padding:4px; margin-left:2px;">❌</button>';
                } else {
                    fileControls += '<label for="ttn_file_input_' + t.id + '" style="cursor:pointer; color:#4f46e5; font-size:13px; padding:4px 8px; background:#1e1e2d; border:1px solid #323248; border-radius:4px; display:inline-block;">📎</label>';
                    fileControls += '<input type="file" id="ttn_file_input_' + t.id + '" accept=".pdf" style="display:none;" onchange="uploadTtnPdf(' + t.id + ', ' + pid + ', this)">';
                }

                // Сборка карточки ТТН без использования обратных кавычек
                html += '<div style="background: #242434; padding: 10px; border-radius: 8px; border: 1px solid #2b2b40; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">';
                html +=   '<div style="flex: 1; min-width: 0; padding-right: 10px; text-align:left;">';
                html +=     '<div style="font-weight: bold; color: #fff; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">ТТН № ' + safeNum + '</div>';
                html +=     '<div style="color: #92929f; font-size: 11px; margin-top: 2px;">Дата: ' + safeDate + '</div>';
                html +=     '<div style="color: #64748b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px;">' + (safeProd || 'Без спецификации') + '</div>';
                html +=   '</div>';
                html +=   '<div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">';
                html +=     '<div style="font-weight: bold; color: #10b981; font-size: 13px;">' + safeAmt + ' BYN</div>';
                html +=     '<div style="display: flex; align-items: center;">' + fileControls + '</div>';
                html +=     '<button type="button" onclick="editTtn(' + t.id + ', \'' + safeNum + '\', \'' + safeDate + '\', ' + t.amount + ', \'' + safeProd + '\')" style="background:none; border:none; color:#f6ad55; cursor:pointer; font-size:13px; padding:4px; margin-left:3px;">✏️</button>';
                html +=   '</div>';
                html += '</div>';
            });
        } else {
            html += '<span style="color:#666; font-size:12px; padding:15px; display:block; text-align:center;">Отгрузок в рамках контракта пока нет</span>';
        }
        
        html += '</div>';
        container.innerHTML = html;

    } catch (err) {
        console.error("Критическая ошибка выполнения в loadProjectTtns:", err);
        container.innerHTML = '<span style="color:#f56565; font-size:12px; padding:10px; display:block; text-align:center;">Ошибка подгрузки или парсинга JSON</span>';
    }
}


    // Функция для обновления таблицы новыми данными
    function renderTable(data) {
        const tbody = document.querySelector('tbody');
        tbody.innerHTML = ''; // Очищаем старые строки

        data.forEach((row, index) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${index + 1}</td>
                <td>
                    <div class="editable" contenteditable="true">${row['Наименование клиента'] || ''}</div>
                    <small style="color: #94a3b8">Источник: ${row['Источник привлечения'] || '—'}</small>
                </td>
                <td><div class="editable" contenteditable="true">${row['ФИО контактного лица'] || '—'}</div></td>
                <td><div class="editable" contenteditable="true" style="color: #4f46e5">${row['телефон'] || ''}</div></td>
                <td><span class="status work">${row['Статус'] || 'Новый'}</span></td>
                <td>${row['Менеджер'] || 'Не назначен'}</td>
            `;
            tbody.appendChild(tr);
        });
        alert('Данные успешно импортированы!');
    }

    function renderTable(data) {
    // ... ваш старый код отрисовки строк ...

    // НОВЫЙ КОД: Отправка в базу
    fetch('save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(res => {
        if(res.status === 'success') alert('Готово! Все данные в базе hoster.by');
    });
}
async function deleteCurrentClient() {
    const id = document.getElementById('client_id').value;
    console.log("Пытаюсь удалить клиента с ID:", id); // Это появится в консоли F12

    if (!id) {
        alert("ID не найден");
        return;
    }
   
async function updateCell(id, field, value) {
    try {
        const response = await fetch('update_cell.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, field, value })
        });
        const result = await response.json();
        
        if (result.status === 'success') {
            console.log('Сохранено');
            // Можно добавить легкую зеленую вспышку ячейке
        }
    } catch (error) {
        console.error('Ошибка сохранения:', error);
        alert('Данные не сохранились! Проверьте соединение.');
    }
}

// Вешаем событие на всю таблицу (делегирование)
document.querySelector('tbody').addEventListener('blur', (e) => {
    if (e.target.classList.contains('editable')) {
        const rowId = e.target.closest('tr').dataset.id; // ID из базы
        const field = e.target.dataset.field;           // Имя колонки
        const newValue = e.target.innerText;            // Текст
        
        updateCell(rowId, field, newValue);
    }
}, true);

tr.setAttribute('data-id', row.id); // ID записи из базы данных
tr.innerHTML = `
    <td>${index + 1}</td>
    <td>
        <div class="editable" contenteditable="true" data-field="client_name">${row.client_name}</div>
    </td>
    <td>
        <div class="editable" contenteditable="true" data-field="contact_person">${row.contact_person}</div>
    </td>
    <td>
        <div class="editable" contenteditable="true" data-field="phone">${row.phone}</div>
    </td>

`;
}
// 2. АСИНХРОННОЕ ДОБАВЛЕНИЕ / РЕДАКТИРОВАНИЕ ТТН (ОТПРАВЛЯЕТ СТРОГО 1 ЗАПРОС)
async function addTtnToProject() {
    console.log("Запущен контролируемый движок сохранения ТТН...");
    
    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num').value.trim();
    const date = document.getElementById('new_ttn_date').value;
    const amt = document.getElementById('new_ttn_amount').value.trim();
    const qty = document.getElementById('new_ttn_quantity') ? document.getElementById('new_ttn_quantity').value.trim() : '0';
    const prod = document.getElementById('new_ttn_prod').value.trim();

    if (!num || !amt) {
        alert("Пожалуйста, заполните обязательные поля: Номер ТТН и Сумму!");
        return;
    }

    try {
        // Блокируем кнопку на время отправки, чтобы менеджер не нажал ее дважды случайно
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = true;

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                ttn_id: ttnId, 
                project_id: pid, 
                ttn_number: num, 
                ttn_date: date, 
                amount: amt, 
                product_quantity: qty, 
                product_info: prod 
            })
        });
        
        const result = await res.json();
        
        // Разблокируем кнопку обратно
        if (btn) btn.disabled = false;

        if (result.status === 'success') {
            // Кристально чистый сброс полей формы
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            if (document.getElementById('new_ttn_quantity')) document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            if (btn) {
                btn.innerText = 'Добавить в рамках контракта';
                btn.style.background = '#10b981';
            }
            
            // Перерисовываем список на лету строго один раз
            loadProjectTtns(pid);
        } else {
            alert("Ошибка базы данных: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при отправке ТТН:", err);
        alert("Ошибка сети при отправке данных ТТН.");
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = false;
    }
}


function openTtnManager(pid, contractLabel) {
    console.log("Открываю ТТН для договора ID:", pid); // Проверка в консоли
    
    const modal = document.getElementById('ttnManagerModal');
    if (!modal) return alert("Ошибка: Окно ТТН не найдено!");

    // СОХРАНЯЕМ ID договора в атрибут окна, чтобы функция сохранения его увидела
    modal.setAttribute('data-pid', pid); 
    
    document.getElementById('ttnContractLabel').innerText = contractLabel;
    modal.style.display = 'flex';
    
    // Загружаем список уже существующих ТТН
    loadProjectTtns(pid);
}

   
function loadExcelLibrary() {
    return new Promise((resolve) => {
        const script = document.createElement('script');
        script.src = "https://jsdelivr.net";
        script.onload = resolve;
        document.head.appendChild(script);
    });
}
const nbrbRate = <?= $nbrbRubRate ?>; 

document.querySelectorAll('.amount-byn').forEach(cell => {
    cell.addEventListener('blur', async function() {
        const id = this.dataset.id;
        const newBynValue = parseFloat(this.innerText.replace(/\s/g, '')) || 0;

        // 1. Мгновенно пересчитываем RUB в этой строке
        const rubCell = document.querySelector(`.rub-column[data-id="${id}"]`);
        if (rubCell) {
            const newRubValue = newBynValue / (nbrbRate / 100);
            rubCell.innerText = newRubValue.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + ' RUB';
        }

        // 2. Отправляем сохранение в базу (update_contract_cell.php)
        try {
            await fetch('update_contract_cell.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: id, field: 'amount', value: newBynValue })
            });
            
            // 3. Пересчитываем ИТОГО внизу таблицы
            updateTableTotals();
            
        } catch (err) {
            console.error("Ошибка сохранения:", err);
        }
    });
});

// Функция пересчета общих итогов
function updateTableTotals() {
    let totalByn = 0;
    document.querySelectorAll('.amount-byn').forEach(el => {
        totalByn += parseFloat(el.innerText.replace(/\s/g, '')) || 0;
    });

    const totalRub = totalByn / (nbrbRate / 100);

    // Обновляем ячейки в tfoot (добавь им ID: total-byn-cell и total-rub-cell)
    document.getElementById('total-byn-cell').innerText = totalByn.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + ' BYN';
    document.getElementById('total-rub-cell').innerText = totalRub.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + ' RUB';
}
const importInput = document.createElement('input');
importInput.type = 'file';
importInput.accept = '.xlsx, .xls';

document.querySelector('.btn-import').onclick = async () => {
    await loadExcelLibrary(); // Гарантируем, что библиотека готова
    importInput.click();
};

importInput.onchange = (e) => {
    const reader = new FileReader();
    reader.onload = (event) => {
        const data = new Uint8Array(event.target.result);
        const workbook = XLSX.read(data, { type: 'array' });
        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        const jsonData = XLSX.utils.sheet_to_json(sheet);
        
        // Отправляем на сервер для сохранения в БД
        saveImportedData(jsonData);
    };
    reader.readAsArrayBuffer(e.target.files[0]);
};

    if (confirm("Удалить этого клиента из базы навсегда?")) {
        try {
            const res = await fetch('delete_row.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            });
            const result = await res.json();
            
            if (result.status === 'success') {
                location.reload();
            } else {
                alert("Ошибка: " + result.message);
            }
        } catch (e) {
            console.error(e);
            alert("Ошибка связи с сервером");
        }
    }
}
document.querySelectorAll('.contract-checkbox').forEach(cb => {
    cb.onclick = async function(e) {
        const clientId = this.dataset.clientId;
        const isChecked = this.checked;
        
        // Предотвращаем моментальное изменение, пока сервер не ответит
        e.preventDefault(); 

        if (!isChecked && userRole === 'admin') {
            if (!confirm("Удалить все договоры этого клиента?")) return;
        }

        try {
            const res = await fetch('update_cell.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: clientId, field: 'is_contract_signed', value: isChecked ? 1 : 0 })
            });
            const result = await res.json();
            
            if (result.status === 'success') {
                if (isChecked) {
                    window.location.href = 'contracts.php?auto_open_client_id=' + clientId;
                } else {
                    // Теперь принудительно обновляем, чтобы галка визуально снялась
                    location.reload(); 
                }
            }
        } catch (err) {
            console.error(err);
            alert("Ошибка связи с сервером");
        }
    }
});
function openAddContractModal(clientId, clientName) {
    document.getElementById('modal_client_id').value = clientId;
    document.getElementById('modalClientName').innerText = clientName;
    document.getElementById('contractModal').style.display = 'flex';
}

function closeContractModal() {
    document.getElementById('contractModal').style.display = 'none';
}

document.getElementById('contractForm').onsubmit = async function(e) {
    e.preventDefault();
    const fd = new FormData(this);

    const res = await fetch('save_new_contract.php', {
        method: 'POST',
        body: fd
    });

    const result = await res.json();
    if (result.status === 'success') {
        location.reload();
    } else {
        alert("Ошибка: " + result.message);
    }
};

async function transferToContracts(data) { 
    const data = await fetch('save.php', { 
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON. stringify(data)
    });
    const prom = await res.json();
    if (result.status === "success") try { 
        updateCell();
        sendToDatabase(data);
    }
}

const BYN_TO_RUB_COEFF = <?= $bynToRubRate ?>;

function recalculateTotal() {
    let totalByn = 0;
    
    document.querySelectorAll('[data-f="amount"]').forEach(cell => {
        // Убираем всё, кроме цифр и точки
        let text = cell.innerText.replace(/[^\d.]/g, '').replace(',', '.');
        let val = parseFloat(text) || 0;
        totalByn += val;
        
        // Считаем RUB для строки
        const rubCell = cell.closest('tr').querySelector('.rub-cell');
        if (rubCell) {
            let rubVal = val / BYN_TO_RUB_COEFF;
            rubCell.innerText = rubVal.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
        }
    });

    // Обновляем Итого внизу
    const displayByn = document.getElementById('totalAmountBYN');
    const displayRub = document.getElementById('totalAmountRUB');
    
    if (displayByn) displayByn.innerText = totalByn.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' BYN';
    if (displayRub) displayRub.innerText = (totalByn / BYN_TO_RUB_COEFF).toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
}





// Обновление обработчика blur (сохранение ячейки)
//document.addEventListener('blur', async (e) => {
  //  if (e.target.classList.contains('editable')) {
    //    const id = e.target.closest('tr').dataset.id;
      //  const field = e.target.dataset.f;
        //const value = e.target.innerText.trim();

        // Сохранение в базу через функцию saveData (которая у тебя уже есть)
       // await saveData(id, field, value);
        
        // Если менялась сумма — пересчитываем итог на экране мгновенно
        //if (field === 'amount') {
          //  recalculateTotal();
        //}
    //}
//}, true);

async function saveImportedData(data) {
    const res = await fetch('save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    });
    const result = await res.json();
    if(result.status === 'success') location.reload(); // Перезагружаем, чтобы увидеть данные из базы
}

async function addNewRow() {
    try {
        const response = await fetch('add_row.php', { method: 'POST' });
        const result = await response.json();
        if (result.status === 'success') {
            location.reload();
        } else {
            console.error(result.message);
        }
    } catch (err) {
        alert("Ошибка: проверьте консоль F12");
        console.error(err);
    }
}

document.addEventListener('blur', async (e) => {
    if (e.target.classList.contains('editable')) {
        const row = e.target.closest('tr');
        const id = row.dataset.id;
        const field = e.target.dataset.f;
        let value = e.target.innerText.trim();

        // Если это поле даты, попробуем привести его к формату ГГГГ-ММ-ДД
        if (field === 'first_contact_date' || field === 'next_contact_date') {
            const parts = value.split('.');
            if (parts.length === 3) {
                // Превращаем ДД.ММ.ГГГГ в ГГГГ-ММ-ДД
                value = `${parts[2]}-${parts[1]}-${parts[0]}`;
            }
        }

        await fetch('update_cell.php', {
            method: 'POST',
            body: JSON.stringify({ id, field, value })
        });
    }
}, true);
// 1. Создаем скрытый элемент выбора файла один раз
const importInput = document.createElement('input');
importInput.type = 'file';
importInput.accept = '.xlsx, .xls';

// 2. Функция для кнопки импорта
document.querySelector('.btn-import').onclick = function() {
    //console.log("Кнопка нажата");
    importInput.click();
};


// 3. Обработка выбора файла
importInput.onchange = function(e) {
    const file = e.target.files[0];
    if (!file) return;

    console.log("Файл выбран:", file.name);
    const reader = new FileReader();

    reader.onload = function(event) {
        try {
            console.log("Файл прочитан как массив");
            const data = new Uint8Array(event.target.result);
            
            // Проверка наличия библиотеки перед использованием
            if (typeof XLSX === 'undefined') {
                alert("Ошибка: Библиотека Excel не загружена! Проверьте интернет или путь к скрипту.");
                return;
            }

            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const jsonData = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName]);

            console.log("Данные извлечены:", jsonData);

            if (jsonData.length === 0) {
                alert("Файл пуст или имеет неверный формат.");
                return;
            }

            // Отправляем в базу
            sendToDatabase(jsonData);

        } catch (err) {
            console.error("Ошибка при разборе Excel:", err);
            alert("Не удалось прочитать Excel. Возможно, файл поврежден.");
        }
    };

    reader.readAsArrayBuffer(file);
};

// 4. Функция отправки на сервер
async function sendToDatabase(data) {
    const res = await fetch('save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    const result = await res.json();
    
    if (result.status === 'success') {
        alert("Импорт завершен успешно!");
        location.reload(); 
    } else {
        alert("Ошибка сервера: " + result.message);
    }
    
}

async function deleteUser(id) {
    if (confirm("Вы уверены? Менеджер будет удален, а его клиенты станут 'общими' (их увидит только Админ).")) {
        try {
            const response = await fetch('delete_user.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            });
            
            const result = await response.json();
            
            if (result.status === 'success') {
                // Плавно скрываем и удаляем карточку пользователя из списка
                const card = document.querySelector(`.user-card[data-id="${id}"]`);
                if (card) {
                    card.style.opacity = '0';
                    setTimeout(() => card.remove(), 300);
                }
            } else {
                alert("Ошибка: " + result.message);
            }
        } catch (e) {
            alert("Не удалось связаться с сервером");
        }
    }
}




// Запуск строго после полной загрузки страницы
window.onload = function() {
    checkAllDeadlines();
    // Если у тебя была функция recalculateTotal, вызови её здесь же:
    if (typeof recalculateEverything === "function") recalculateEverything();
};
    // Показываем блок, если есть проблемы
    if (urgentItems.length > 0) {
        box.style.display = 'block';
        list.innerHTML = urgentItems.join('');
    } else {
        box.style.display = 'none';
    }

// Запуск при загрузке
window.addEventListener('DOMContentLoaded', checkContractDeadlines);

// Запускаем проверку при загрузке страницы
window.addEventListener('DOMContentLoaded', checkDeadlines);

function updateCell(selectElement) {
    const id = selectElement.closest('tr').dataset.id; // Берем ID из строки
    const field = selectElement.dataset.f;           // Берем поле из data-f
    const value = selectElement.value;               // Берем выбранное значение
    
    // Вызываем нашу рабочую функцию сохранения
    saveData(id, field, value);
}

const BYN_TO_RUB_COEFF = <?= $bynToRubRate ?>;

function recalculateTotal() {
    let totalByn = 0;
    
    document.querySelectorAll('[data-f="amount"]').forEach(cell => {
        let val = parseFloat(cell.innerText.replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
        totalByn += val;
        

        const rubCell = cell.closest('tr').querySelector('.rub-cell');
        
        
        if (rubCell) {
            // Сумму BYN делим на курс 1-го рубля
            let rubVal = val / BYN_TO_RUB_COEFF;
            rubCell.innerText = rubVal.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
        }
    });

    document.getElementById('totalAmountBYN').innerText = totalByn.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' BYN';
    document.getElementById('totalAmountRUB').innerText = (totalByn / BYN_TO_RUB_COEFF).toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
    
}
const RATE = <?= $bynToRubRate ?>;

function doCalculate() {
    let totalByn = 0;
    const rows = document.querySelectorAll('#tableBody tr');
    console.log("Найдено строк для расчета:", rows.length);

    rows.forEach((row, index) => {
        // Ищем ячейку суммы BYN внутри этой строки
        const bynDiv = row.querySelector('[data-f="amount"]');
        const refreshRate = row.querySelector('[data-f="amount"');
        const rubCell = row.querySelector('.rub-value');

        if (bynDiv && rubCell) {
            let val = parseFloat(bynDiv.innerText.replace(',', '.')) || 0;
            totalByn += val;
            
            // Считаем RUB
            let rub = val / RATE;
            rubCell.innerText = rub.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " RUB";
        }
    });

    // Обновляем футер
    document.getElementById('totalBYN').innerText = totalByn.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " BYN";
    document.getElementById('totalRUB').innerText = (totalByn / RATE).toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " RUB";
}

// Слушаем изменения
document.addEventListener('input', (e) => {
    if (e.target.dataset.f === 'amount') {
        doCalculate();
    }
});

// Сохранение (blur)
document.addEventListener('blur', async (e) => {
    if (e.target.classList.contains('editable')) {
        const id = e.target.closest('tr').dataset.id;
        const field = e.target.dataset.f;
        const value = e.target.innerText;
        
        await fetch('update_cell.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id, field, value })
        });
    }
}, true);


function openAddModal() {
    const modal = document.getElementById('clientModal');
    document.getElementById('clientForm').reset();
    document.getElementById('client_id').value = '';
    
    // Важно: ставим именно flex, чтобы сработало центрирование
    modal.style.display = 'flex'; 
}

function closeModal() {
    document.getElementById('clientModal').style.display = 'none';
}

// Закрытие при клике на темную область вокруг окна
window.onclick = function(event) {
    const modal = document.getElementById('clientModal');
    if (event.target == modal) closeModal();
}

// Открыть для редактирования
function openAddModal() {
    document.getElementById('clientForm').reset();
    document.getElementById('client_id').value = '';
    document.getElementById('modalTitle').innerText = 'Новый клиент';
    
    // Скрываем удаление (нового клиента еще нет в базе)
    document.getElementById('btnDelete').style.display = 'none'; 
    
    document.getElementById('clientModal').style.display = 'flex';
}

// Когда нажимаем "Редактировать"
function openEditModal(id) {
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;

    document.getElementById('clientForm').reset();
    document.getElementById('client_id').value = id;
    
    // Вспомогательная функция для поиска текста в ячейках по классам
    const fill = (inputId, cls) => {
        const input = document.getElementById(inputId);
        const cell = row.querySelector(cls);
        if (input && cell) input.value = cell.innerText.trim();
    };

    // Сопоставляем поля формы и колонки таблицы
    fill('first_contact_date', '.cell-date');
    fill('client_name', '.cell-name');
    fill('unp', '.cell-unp');
    fill('contact_person', '.cell-person');
    fill('phone', '.cell-phone');
    fill('email', '.cell-email');
    fill('status', '.cell-status');
    fill('next_contact_date', '.cell-next');
    fill('comment', '.cell-comment');
    fill('product_type', '.cell-product');
    fill('source', '.cell-source'); // если добавил скрытую колонку

    document.getElementById('clientModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('clientModal').style.display = 'none';
}

// Запуск при старте
window.onload = doCalculate;
// Функция удаления из модального окна

function openAddModal() {
    const modal = document.getElementById('clientModal');
    if (!modal) return;

    document.getElementById('clientForm').reset(); // Чистим форму
    document.getElementById('client_id').value = ''; // ID пустой
    document.getElementById('modalTitle').innerText = 'Новый клиент';
    
    modal.style.setProperty('display', 'flex', 'important');
}

// Функция ЗАКРЫТИЯ
function closeModal() {
    document.getElementById('clientModal').style.setProperty('display', 'none', 'important');}

   
// АСИНХРОННОЕ УДАЛЕНИЕ PDF ФАЙЛА ТТН (СТРОГО ДЛЯ АДМИНА)
async function deleteTtnPdf(ttnId, pid) {
    console.log("Клик по крестику пойман. ttnId:", ttnId, "pid:", pid);
    
    // 1. Жёсткое окно подтверждения на уровне браузера
    if (!confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить прикрепленный PDF-файл этой ТТН?")) {
        console.log("Удаление отменено пользователем.");
        return;
    }

    console.log("Отправляю fetch запрос на delete_ttn_pdf.php...");

    try {
        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ttn_id: parseInt(ttnId) })
        });
        
        // Читаем сырой текст ответа на случай, если PHP выдал Fatal Error вместо JSON
        const rawText = await res.text();
        console.log("Сырой ответ сервера:", rawText);
        
        const result = JSON.parse(rawText);

        if (result.status === 'success') {
            console.log("Файл успешно удален, обновляю список ТТН...");
            // Перерисовываем список накладных прямо в открытом окне
            if (typeof loadProjectTtns === 'function') {
                loadProjectTtns(pid);
            } else {
                location.reload();
            }
        } else {
            alert("Ошибка при удалении файла: " + result.message);
        }
    } catch (err) {
        console.error("Критическая ошибка JS при удалении PDF:", err);
        alert("Ошибка связи с сервером delete_ttn_pdf.php. Откройте консоль F12.");
    }
}
// НЕУБИВАЕМЫЙ СЛУШАТЕЛЬ КЛИКА ПО КРЕСТИКУ УДАЛЕНИЯ PDF
document.addEventListener('click', function(e) {
    const deleteBtn = e.target.closest('.js-pdf-delete-btn');
    if (deleteBtn) {
        e.preventDefault();
        
        const ttnId = deleteBtn.dataset.ttnId;
        const projectId = deleteBtn.dataset.projectId;
        
        // Перенаправляем выполнение в твою готовую функцию удаления
        if (typeof deleteTtnPdf === 'function') {
            deleteTtnPdf(ttnId, projectId);
        } else {
            console.error("Функция deleteTtnPdf не найдена в файле!");
        }
    }
});

async function uploadContractFile(projectId) {
    const fileInput = document.getElementById('contract_file_input');
    if (!fileInput.files[0]) return;

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('project_id', projectId);

    const res = await fetch('upload_contract.php', {
        method: 'POST',
        body: formData
    });

    const result = await res.json();
    if (result.status === 'success') {
        alert("Файл загружен!");
        location.reload();
    } else {
        alert("Ошибка: " + result.message);
    }
}
function openAddContractModal(clientId, clientName) {
    document.getElementById('modal_client_id').value = clientId;
    document.getElementById('modalClientName').innerText = clientName;
    document.getElementById('contractModal').style.display = 'flex';
}

function closeContractModal() {
    document.getElementById('contractModal').style.display = 'none';
}

document.getElementById('contractForm').onsubmit = async function(e) {
    e.preventDefault();
    const fd = new FormData(this);

    const res = await fetch('save_new_contract.php', {
        method: 'POST',
        body: fd
    });

    const result = await res.json();
    if (result.status === 'success') {
        location.reload();
    } else {
        alert("Ошибка: " + result.message);
    }
};

document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.js-ttn-edit-btn');
    
    if (editBtn) {
        e.preventDefault();
        
        // Вытаскиваем данные ТТН из атрибутов кнопки
        const id = editBtn.dataset.ttnId;
        const num = editBtn.dataset.ttnNum;
        const date = editBtn.dataset.ttnDate;
        const amount = editBtn.dataset.ttnAmount;
        const prod = editBtn.dataset.ttnProd;

        console.log("Редактирую ТТН ID:", id, "Номер:", num);

        // Набиваем поля формы ввода данными из ТТН
        document.getElementById('edit_ttn_id_storage').value = id;
        document.getElementById('new_ttn_num').value = num;
        document.getElementById('new_ttn_date').value = date;
        document.getElementById('new_ttn_amount').value = amount;
        document.getElementById('new_ttn_prod').value = prod;

        // Визуально переключаем форму в режим редактирования
        const titleEl = document.getElementById('ttnFormTitle');
        if (titleEl) titleEl.innerText = 'Редактировать отгрузку №' + num + ':';
        
        const actionBtn = document.getElementById('ttnActionBtn');
        if (actionBtn) {
            actionBtn.innerText = 'Сохранить изменения';
            actionBtn.style.background = '#f6ad55'; // Меняем цвет кнопки на оранжевый
        }
    }
     if (result.status === 'success') {
            // Очищаем поля формы ввода, чтобы менеджер мог вбить следующую накладную
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            // Сразу же перерендерим список ТТН прямо в открытом окне
            loadProjectTtns(pid);
        } else {
            alert("Ошибка сохранения на сервере: " + result.message);
        }
    
});
           

document.getElementById('contractForm').onsubmit = async function(e) {
    e.preventDefault();
    
    // Проверка: все ли поля на месте
    const fd = new FormData(this);
    console.log("Отправляю данные договора для клиента:", fd.get('client_id'));

    try {
        const res = await fetch('save_new_contract.php', {
            method: 'POST',
            body: fd
        });

        // Читаем текст ответа, чтобы увидеть ошибку PHP, если она есть
        const text = await res.text();
        console.log("Ответ сервера:", text);

        const result = JSON.parse(text);
        if (result.status === 'success') {
            location.reload();
        } else {
            alert("Ошибка сохранения: " + result.message);
        }
    } catch (err) {
        console.error("Ошибка при отправке:", err);
        alert("Сбой сети. Проверь консоль (F12)");
    }
};


// АСИНХРОННОЕ УДАЛЕНИЕ PDF ФАЙЛА ТТН (СТРОГО ДЛЯ АДМИНА)
async function deleteTtnPdf(ttnId, pid) {
    console.log("Клик по крестику пойман. ttnId:", ttnId, "pid:", pid);
    
    // 1. Жёсткое окно подтверждения на уровне браузера
    if (!confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить прикрепленный PDF-файл этой ТТН?")) {
        console.log("Удаление отменено пользователем.");
        return;
    }

    console.log("Отправляю fetch запрос на delete_ttn_pdf.php...");

    try {
        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ttn_id: parseInt(ttnId) })
        });
        
        // Читаем сырой текст ответа на случай, если PHP выдал Fatal Error вместо JSON
        const rawText = await res.text();
        console.log("Сырой ответ сервера:", rawText);
        
        const result = JSON.parse(rawText);

        if (result.status === 'success') {
            console.log("Файл успешно удален, обновляю список ТТН...");
            // Перерисовываем список накладных прямо в открытом окне
            if (typeof loadProjectTtns === 'function') {
                loadProjectTtns(pid);
            } else {
                location.reload();
            }
        } else {
            alert("Ошибка при удалении файла: " + result.message);
        }
    } catch (err) {
        console.error("Критическая ошибка JS при удалении PDF:", err);
        alert("Ошибка связи с сервером delete_ttn_pdf.php. Откройте консоль F12.");
    }
}

async function executeContractUpload(pid, inputElement) {
    console.log("Движок поймал выбор файла. Начинаю асинхронную отправку договора ID:", pid);
    
    if (!inputElement.files || !inputElement.files.length) {
        console.warn("Файл не выбран пользователем.");
        return;
    }

    const file = inputElement.files[0];

    // Проверка формата файла строго на уровне браузера перед отправкой
    if (file.type !== 'application/pdf') {
        alert("Критическая ошибка: Допускаются к загрузке файлы только в формате PDF!");
        inputElement.value = ''; // Сбрасываем некорректный выбор
        return;
    }

    // Собираем виртуальный пакет данных FormData для отправки на сервер
    const fd = new FormData();
    fd.append('project_id', parseInt(pid));
    fd.append('contract_pdf', file);

    try {
        const response = await fetch('upload_contract_file.php', {
            method: 'POST',
            body: fd
        });

        // Читаем ответ сервера
        const rawText = await response.text();
        console.log("Сырой ответ сервера upload_contract_file.php:", rawText);

        // Мягко обновляем страницу для перерисовки кнопок
        window.location.reload();

    } catch (err) {
        console.error("Критический сбой сети при отправке fetch договора:", err);
        alert("Не удалось загрузить файл на сервер. Проверьте соединение или нажмите F12.");
    }
}