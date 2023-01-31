Websocket

Сервер и клиент.

Поддерживается приём\передача данных по 1 байту, как в блокирующем режиме сокета, так и в неблокирующем.

---

Сервер:

    $host = '127.0.0.1';
    $port = '10101';

    $contextOptions = [
        'ssl' => [
            // Для сервера
            'local_cert' => 'path/to/cert/ws.crt',
            'local_pk'   => 'path/to/cert/ws.key',
            // Разрешить незашифрованные подключения (не является стандартным параметром)
            // Это позволит подключаться клиентам как по ssl\tls (внешним, к примеру), так и локальным по tcp одновременно
            'allow_unsecured' => true
        ]
    ];

    // Создание вебсокет сервера
    $server = new WSServer($contextOptions);

    //$server->debugMode(true);

    // Запуск сервера
    $server->start($host, $port);


Клиент:

    $scheme = 'tls';
    $host   = '127.0.0.1';
    $port   = '10101';
    $path   = '';

    // Для tcp не требуется
    $contextOptions = [
        'ssl' => [
            // Для клиента
            'allow_self_signed' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false
        ]
    ];

    // Создание клиента вебсокет
    $client = new WSClient($contextOptions);

    //$client->debugMode(true);

    // Запуск клиента вебсокет
    $client->start($scheme, $host, $port, $path);
