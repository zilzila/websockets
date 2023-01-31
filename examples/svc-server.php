<?php
require_once __DIR__ . '/server.php';

// Бесконечное выполнение скрипта
set_time_limit(0);
// Игнорирование закрытия браузера
ignore_user_abort(true);

$contextOptions = [
    'ssl' => [
        // Для сервера
        'local_cert' => 'cert/ws.crt',
        'local_pk'   => 'cert/ws.key',
// Разрешить незашифрованные подключения (не является стандартным параметром)
// Это позволит подключаться клиентам как по ssl\tls (внешним, к примеру), так и локальным по tcp одновременно
        'allow_unsecured' => true
    ]
];

// Без поддержки ssl\tls
//$contextOptions = [];

while (true):
    try
    {
        // Создание вебсокет сервера
        $server = new WSServer($contextOptions);

        $server->debugMode(true);
        
        // Запуск сервера
        $server->start('127.0.0.1', 10101);
    }
    catch (Exception $e)
    {
        // Уничтожение объекта
        unset($server);

        // Обработка исключения
        echo date('Y-m-d H:i:s ') . "Необработанное исключение: " . print_r($e->getMessage(), true);
    }

    // Пауза между подключениями
    sleep(1);
endwhile;
