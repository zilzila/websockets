<?php
require_once __DIR__ . '/client.php';

// Бесконечное выполнение скрипта
set_time_limit(0);
// Игнорирование закрытия браузера
ignore_user_abort(true);

$contextOptions = [
    'ssl' => [
        // Для клиента
        'allow_self_signed' => true,
//        'verify_peer'       => true,
//        'verify_peer_name'  => true
    ]
];

while (true):
    try
    {
        // Создание клиента вебсокет
        $client = new WSClient($contextOptions);
        
         $client->debugMode(true);
        
        // Запуск клиента вебсокет
        $client->start('tcp', '127.0.0.1', 10101);
    }
    catch (Exception $e)
    {
        // Уничтожение объекта
        unset($client);
        
        // Обработка исключения
        echo date('Y-m-d H:i:s ') . "Необработанное исключение: " . print_r($e->getMessage(), true);
    }
    
    // Пауза между подключениями
    sleep(1);
endwhile;
