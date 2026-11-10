<?php
// lang/ru.php
//
// Русский.
//
// Переводятся только значения, ключи остаются как есть. Состояние перевода
// показывает:
//   docker compose exec app php bin/dcinfrasnap lang:check
//
// Отсутствующий здесь ключ берётся из английского, поэтому неполный перевод
// всё равно даёт работоспособный интерфейс.
//
// Технические термины, которые администраторы используют в оригинале (host,
// cluster, datastore, template), оставлены как есть. Перевод, которым никто
// не пользуется, мешает ориентироваться, а не помогает.

declare(strict_types=1);

return [
    'language.name' => 'Русский',

    // --- Приложение ------------------------------------------------------
    'app.name'    => 'DCInfraSnap',
    'app.tagline' => 'Инвентаризация ЦОД: собрано и сохранено',

    // --- Навигация -------------------------------------------------------
    'nav.overview'     => 'Обзор',
    'nav.vms'          => 'Виртуальные машины',
    'nav.hosts'        => 'Хосты',
    'nav.clusters'     => 'Кластеры',
    'nav.datastores'   => 'Хранилища',
    'nav.licenses'     => 'Лицензии',
    'nav.runs'         => 'Сборы',
    'nav.integrations' => 'Интеграции',
    'nav.database'     => 'База данных',
    'nav.logout'       => 'Выйти',
    'nav.language'     => 'Язык',

    // --- Общее -----------------------------------------------------------
    'common.actions' => 'Действия',
    'common.add'     => 'Добавить',
    'common.back'    => 'Назад',
    'common.cancel'  => 'Отмена',
    'common.delete'  => 'Удалить',
    'common.edit'    => 'Изменить',
    'common.name'    => 'Название',
    'common.never'   => 'Никогда',
    'common.no'      => 'Нет',
    'common.none'    => 'Нет',
    'common.save'    => 'Сохранить',
    'common.search'  => 'Искать',
    'common.status'  => 'Состояние',
    'common.unknown' => 'Неизвестно',
    'common.yes'     => 'Да',

    // --- Вход ------------------------------------------------------------
    'login.title'          => 'Вход',
    'login.password'       => 'Пароль',
    'login.submit'         => 'Войти',
    'login.failed'         => 'Неверный пароль.',
    'login.rate_limited'   => 'Слишком много попыток. Попробуйте через минуту.',
    'login.not_configured' => 'Интерфейс закрыт, потому что пароль не задан.',
    'login.setup_hint'     => 'Создайте хеш командой «docker compose run --rm app php bin/dcinfrasnap '
                              . 'password:hash», впишите его в APP_PASSWORD_HASH в .env (в одинарных '
                              . 'кавычках) и выполните «docker compose up -d».',

    // --- Обзор -----------------------------------------------------------
    'overview.title'           => 'Обзор',
    'overview.subtitle'        => 'Что собрано и откуда',
    'overview.no_integrations' => 'Источники ещё не добавлены.',
    'overview.add_first'       => 'Добавьте vCenter, чтобы начать сбор.',
    'overview.counts'          => 'Собранные объекты',
    'overview.last_collection' => 'Последний сбор',
    'overview.sources'         => 'Источники',

    // --- Источники -------------------------------------------------------
    'integrations.subtitle'    => 'Системы, с которых DCInfraSnap собирает данные',
    'integrations.add_vcenter' => 'Добавить vCenter',
    'integrations.none'        => 'Источники ещё не добавлены.',
    'integrations.none_hint'   => 'Добавьте vCenter, и DCInfraSnap сможет собирать с него данные.',

    'integrations.address'   => 'Адрес',
    'integrations.hostname'  => 'Имя узла или IP',
    'integrations.port'      => 'Порт',
    'integrations.username'  => 'Имя пользователя',
    'integrations.password'  => 'Пароль',
    'integrations.version'   => 'Версия',
    'integrations.last_test' => 'Подключение',

    'integrations.name_placeholder'  => 'vcenter-01',
    'integrations.name_hint'         => 'Показывается в списках и отчётах. Любое понятное вам обозначение.',
    'integrations.username_hint'     => 'Достаточно учётной записи только для чтения. '
                                        . 'DCInfraSnap никогда не пишет в vCenter.',
    'integrations.password_hint'     => 'Хранится в зашифрованном виде с SECRET_KEY, никогда открытым текстом.',
    'integrations.password_separate' => 'Пароль здесь не показывается.',
    'integrations.verify_peer'       => 'Проверять TLS-сертификат',
    'integrations.verify_peer_hint'  => 'Оставьте выключенным для vCenter с самоподписанным сертификатом.',
    'integrations.form_subtitle'     => 'Параметры подключения к одному vCenter.',
    'integrations.credentials_note'  => 'Учётные данные шифруются ключом SECRET_KEY и расшифровываются только '
                                        . 'в момент входа в vCenter. Повторно они нигде не показываются.',

    'integrations.collect'              => 'Собрать',
    'integrations.queued'               => ':name поставлен в очередь как задание :run. '
                                           . 'Worker возьмёт его через несколько секунд.',
    'integrations.test'                 => 'Проверить',
    'integrations.test_ok'              => 'OK',
    'integrations.test_failed'          => 'Ошибка',
    'integrations.untested'             => 'Не проверялось',
    'integrations.test_ok_message'      => 'подключено к :version',
    'integrations.change_password'      => 'Сменить пароль',
    'integrations.new_password'         => 'Новый пароль',
    'integrations.repeat_password'      => 'Повторите пароль',
    'integrations.password_title'       => 'Пароль для :name',
    'integrations.password_saved'       => 'Пароль сохранён.',
    'integrations.password_change_note' => 'Старый пароль перезаписывается и восстановлению не подлежит.',

    'integrations.edit_title'   => 'Изменение: :name',
    'integrations.added'        => ':name добавлен.',
    'integrations.added_hint'   => 'Проверьте подключение, чтобы убедиться, что учётные данные работают.',
    'integrations.saved'        => 'Изменения сохранены.',
    'integrations.enable'       => 'Включить',
    'integrations.disable'      => 'Отключить',
    'integrations.disabled'     => 'Отключён',
    'integrations.enabled_now'  => ':name включён.',
    'integrations.disabled_now' => ':name отключён.',

    'integrations.delete_title'        => 'Удалить :name?',
    'integrations.delete_warning'      => 'Вместе с ним удаляется всё, что собрано из этого источника:',
    'integrations.delete_empty'        => 'Из этого источника пока ничего не собрано.',
    'integrations.delete_irreversible' => 'Это действие необратимо.',
    'integrations.delete_confirm'      => 'Удалить навсегда',
    'integrations.deleted'             => ':name удалён.',

    'integrations.error_name'           => 'Название обязательно.',
    'integrations.error_hostname'       => 'Имя узла или IP-адрес обязательны.',
    'integrations.error_hostname_url'   => 'Укажите только имя узла, без https:// — например vcenter.example.local.',
    'integrations.error_port'           => 'Порт должен быть от 1 до 65535.',
    'integrations.error_username'       => 'Имя пользователя обязательно.',
    'integrations.error_password'       => 'Пароль обязателен.',
    'integrations.error_password_match' => 'Пароли не совпадают.',

    // --- Списки ----------------------------------------------------------
    'list.showing'            => 'Показано: :count',
    'list.nothing_found'      => 'По этим фильтрам ничего нет.',
    'list.nothing_found_hint' => 'Попробуйте более широкий поиск или сбросьте фильтры.',
    'list.nothing_yet'        => 'Пока ничего не собрано.',
    'list.collect_first'      => 'Запустите сбор, и страница заполнится.',
    'list.export_csv'         => 'Экспорт в CSV',
    'list.first_seen'         => 'Впервые замечено',
    'list.last_seen'          => 'Последний раз замечено',
    'list.missing'            => 'Отсутствует',
    'list.missing_hint'       => 'Не встречалось в последнем полном сборе из этого источника.',
    'list.previous'           => 'Назад',
    'list.next'               => 'Вперёд',
    'list.page_of'            => 'Страница :page из :pages',

    'filter.all_sources'  => 'Все источники',
    'filter.any_power'    => 'Любое состояние',
    'filter.any_cluster'  => 'Любой кластер',
    'filter.any_host'     => 'Любой хост',
    'filter.any_kind'     => 'Машины и шаблоны',
    'filter.missing_only' => 'Только отсутствующие',
    'filter.clear'        => 'Сбросить',

    // --- Виртуальные машины ----------------------------------------------
    'vms.search_placeholder' => 'Название, имя узла, адрес, UUID или заметки',
    'vms.power'              => 'Питание',
    'vms.guest'              => 'Гостевая ОС',
    'vms.address'            => 'Адрес',
    'vms.hostname'           => 'Имя узла',
    'vms.cpu'                => 'vCPU',
    'vms.cores_per_socket'   => 'Ядер на сокет',
    'vms.memory'             => 'Память',
    'vms.disks'              => 'Диски',
    'vms.disk_space'         => 'Дисковое пространство',
    'vms.nics'               => 'Сетевые адаптеры',
    'vms.template'           => 'Шаблон',
    'vms.templates_only'     => 'Только шаблоны',
    'vms.machines_only'      => 'Только машины',
    'vms.identity'           => 'Идентификаторы',
    'vms.resources'          => 'Ресурсы',
    'vms.placement'          => 'Размещение',
    'vms.notes'              => 'Заметки',
    'vms.folder'             => 'Папка',
    'vms.moref'              => 'Объект в vCenter',
    'vms.instance_uuid'      => 'Instance UUID',
    'vms.bios_uuid'          => 'BIOS UUID',
    'vms.hardware_version'   => 'Версия оборудования',
    'vms.guest_configured'   => 'ОС, указанная в vCenter',
    'vms.guest_reported'     => 'ОС по данным гостя',
    'vms.guest_state'        => 'Состояние гостя',
    'vms.tools'              => 'VMware Tools',

    // --- Хосты -----------------------------------------------------------
    'hosts.state'       => 'Состояние',
    'hosts.hardware'    => 'Оборудование',
    'hosts.capacity'    => 'Ресурсы',
    'hosts.software'    => 'Программное обеспечение',
    'hosts.cpu'         => 'Ядра / сокеты',
    'hosts.cpu_model'   => 'Процессор',
    'hosts.sockets'     => 'Сокеты',
    'hosts.cores'       => 'Ядра',
    'hosts.threads'     => 'Потоки',
    'hosts.cpu_mhz'     => 'МГц на ядро',
    'hosts.memory'      => 'Память',
    'hosts.vms'         => 'Машины',
    'hosts.esxi'        => 'ESXi',
    'hosts.build'       => 'Сборка',
    'hosts.bios'        => 'BIOS',
    'hosts.vendor'      => 'Производитель',
    'hosts.model'       => 'Модель',
    'hosts.serial'      => 'Серийный номер',
    'hosts.asset_tag'   => 'Инвентарный номер',
    'hosts.hw_uuid'     => 'UUID оборудования',
    'hosts.uptime'      => 'Время работы',
    'hosts.maintenance' => 'Обслуживание',
    'hosts.standalone'  => 'Отдельный',
    'hosts.running_vms' => 'Машины на этом хосте (:count)',
    'hosts.no_vms'      => 'На этом хосте нет зарегистрированных машин.',

    // --- Кластеры --------------------------------------------------------
    'clusters.datacenter' => 'Датацентр',
    'clusters.ha'         => 'HA',
    'clusters.drs'        => 'DRS',

    // --- Хранилища -------------------------------------------------------
    'datastores.type'         => 'Тип',
    'datastores.used'         => 'Занято',
    'datastores.capacity'     => 'Объём',
    'datastores.free'         => 'Свободно',
    'datastores.uncommitted'  => 'Не выделено',
    'datastores.inaccessible' => 'Недоступно',

    // --- Лицензии --------------------------------------------------------
    'licenses.key'         => 'Ключ',
    'licenses.product'     => 'Продукт',
    'licenses.used'        => 'Использовано',
    'licenses.total'       => 'Всего',
    'licenses.unit'        => 'Считается по',
    'licenses.unlimited'   => 'Без ограничений',
    'licenses.expires'     => 'Истекает',
    'licenses.perpetual'   => 'Бессрочная',
    'licenses.expired'     => 'Истекла',
    'licenses.days_left'   => 'ещё :days дн.',
    'licenses.masked_note' => 'Ключи лицензий хранятся в маскированном виде — остаётся только последний блок. '
                              . 'Полный ключ представляет собой покупаемый актив, и ему не место в базе '
                              . 'инвентаризации.',

    // --- Журнал изменений ------------------------------------------------
    'journal.title' => 'Что менялось',
    'journal.empty' => 'С момента первого сбора ничего не изменилось.',
    'journal.when'  => 'Когда',
    'journal.field' => 'Поле',
    'journal.from'  => 'Было',
    'journal.to'    => 'Стало',

    // --- Сборы -----------------------------------------------------------
    'runs.subtitle'       => 'Каждый сбор, удачный или нет',
    'runs.none'           => 'Сборов пока не было.',
    'runs.none_hint'      => 'Нажмите «Собрать» у источника или выполните discover из командной строки.',
    'runs.run'            => 'Задание',
    'runs.source'         => 'Источник',
    'runs.duration'       => 'Длилось',
    'runs.calls'          => 'Вызовов',
    'runs.when'           => 'Начато',
    'runs.release'        => 'Разблокировать',
    'runs.released'       => 'Задание :run разблокировано. Источник больше не заблокирован.',
    'runs.not_releasable' => 'Задание :run не в очереди и не выполняется.',
    'runs.status.queued'  => 'В очереди',
    'runs.status.running' => 'Выполняется',
    'runs.status.success' => 'Успешно',
    'runs.status.partial' => 'Частично',
    'runs.status.failed'  => 'Ошибка',
    'runs.partial_note'   => 'Частичный сбор охватил одни типы объектов и не охватил другие. '
                              . 'Вывод о том, что объект исчез, делается только по полному сбору.',

    // --- База данных -----------------------------------------------------
    'database.summary'       => 'таблиц: :tables, строк: :rows',
    'database.table'         => 'Таблица',
    'database.rows'          => 'Строк',
    'database.size'          => 'Размер',
    'database.purpose'       => 'Назначение',
    'database.row_count'     => 'строк: :rows',
    'database.empty_table'   => 'Эта таблица пуста.',
    'database.readonly_note' => 'Эта страница только читает. Никакого ввода SQL, никаких изменений и удалений. '
                                . 'Зашифрованный пароль vCenter вообще не читается в представление.',

    // --- Настройка и ошибки ----------------------------------------------
    'setup.not_migrated.title' => 'База данных пуста',
    'setup.not_migrated.body'  => 'Выполните миграции и обновите эту страницу:',
    'error.not_found'          => 'Такой страницы нет.',
    'error.server'             => 'Что-то пошло не так.',
    'error.csrf'               => 'Форма устарела. Попробуйте ещё раз.',
];
