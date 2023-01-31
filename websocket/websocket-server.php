<?php
require_once __DIR__ . '/websocket.php';
require_once __DIR__ . '/websocket-server-client.php';

/**
 * Сервер вебсокет
 */
class WebsocketServer extends Websocket
{
    /**
     * Кодовое имя вебсокета
     * @var string
     */
    public static $codeName = 'WSServer';
    
    /**
     * Объект клиента сервера
     * @var WebsocketServerClient
     */
    protected $client;
    
    /**
     * Массив клиентов
     * @var WebsocketServerClient[]
     */
    protected $clients = [];
    
    /**
     * Массив соединений сокетов
     * @var array
     */
    protected $connections = [];
    
    /**
     * IP адрес сервереа
     * @var string
     */
    protected $ip;
    
    
    /**
     * Конструктор сервера
     * @param array $options Параметры контекста потока сокета
     * @throws WebsocketException
     */
    public function __construct($options = [])
    {
        parent::__construct(self::SERVER, $options);
        
        // Определение локального адреса
        $this->ip = '127.0.0.1';
//        $this->ip = gethostbyname(SITE_DOMAIN);
    }
    
    /**
     * Запуск сервера
     * @param string $host Адрес сервера
     * @param string $port Порт сервера
     * @throws WebsocketException
     */
    public function start($host, $port)
    {
        $this->host = $host;
        $this->port = $port;
        
        $this->socket = stream_socket_server("$host:$port", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $this->getContext());
        if ($this->socket === false):
            $this->error("Ошибка создания сокета сервера: ($errno) " . mb_convert_encoding($errstr, 'UTF-8', 'UTF-8, Windows-1251'));
        endif;
        
        $this->debugMessage("Сервер $host:$port запущен");
        $this->debugMessage("server: stream_meta_data ip: {$this->ip}:\n" . print_r(stream_get_meta_data($this->socket), true));
        
        // Цикл прослушивания
        while (true):
            $read   = $this->connections;
            $read[] = $this->socket;
            $write  = $except = null;
            
            // Ожидание появления сокетов, доступных для чтения
            $activeCount = stream_select($read, $write, $except, 0, 10000);
            if ($activeCount === false):
                $this->error("Ошибка чтения данных соединения");
            endif;
            
            // Обработка активных клиентских сокетов
            foreach ($read as $connection):
                try
                {
                    // Активность на серверном сокете - новое подключение
                    if ($connection == $this->socket):
                        // Создание объекта соединения с клиентом
                        $this->client = new WebsocketServerClient();

                        // Установка режима отладки
                        $this->client->debugMode($this->debugMode());
                        
                        // Приём соединения клиента
                        $this->client->accept($this->socket);
                        
                        // Добавление клиента в массив клиентов сервера
                        $this->clients[$this->client->id]     = $this->client;
                        // Добавление соединения в массив соединений сервера
                        $this->connections[$this->client->id] = $this->client->socket;
                        
                        // Выполнение обработчика подключения
                        $this->onConnect();
                    // Активность на клиентском сокете - приём данных
                    else:
                        $this->client = $this->clients[(int)$connection];
                        
                        if (!$this->client->handshaked):
                            $this->client->handshake();
                        else:
                            // Получение данных сокета
                            $this->client->receive(self::RAW_DATA);
                            
                            // Передача полученных фреймов клиентского сокета серверу
                            // Неполный фрейм остаётся у клиента
                            $this->rcvFrames = $this->client->processRcvData();
                            
                            // Обработка полученных клиентских фреймов
                            $this->processRcvFrames();
                        endif;
                    endif;
                }
                catch (Exception $e)
                {
                    // Обработка ошибки
                    $this->onError($e);
                }
            endforeach;
            
            $this->client = null;
            
            $this->processActivity();
        endwhile;
    }
    
    /**
     * Обработчик приёма фрейма типа "text" из сокета клиента
     * @param string $frame Текст фрейма
     */
    protected function onFrameText($frame)
    {
        echo "Сообщение (текстовые данные) от клиента {$this->client->id}: $frame";
    }
    
    /**
     * Обработчик приёма фрейма типа "binary" из сокета
     * @param string $frame Текст фрейма
     */
    protected function onFrameBinary($frame)
    {
        echo "Сообщение (бинарные данные) от клиента {$this->client->id}: $frame";
    }
    
    /**
     * Обработчик приёма фрейма типа "close" из сокета
     */
    protected function onFrameClose()
    {
        // Закрытие соединения клиента
        $this->closeClient();
    }
    
    /**
     * Обработчик приёма фрейма типа "ping" из сокета
     * @throws WebsocketException
     */
    protected function onFramePing()
    {
        $this->send('pong...', 'pong');
    }
    
    /**
     * Обработчик подключения
     */
    protected function onConnect()
    {
    }
    
    /**
     * Обработчик ошибки клиентского подключения
     * @param Exception $exception Исключение
     */
    protected function onError($exception)
    {
        // Принудительное закрытие клиентского соединения
        $this->closeClient();
        
        // Журналирование ошибки
        $this->log("Ошибка вебсокета на сервере вебсокет: " . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString());
    }
    
    /**
     * Закрытие соединения клиента
     */
    protected function closeClient()
    {
        // Клиент присутствует в массиве клиентов сервера
        if (isset($this->client) && isset($this->clients[$this->client->id])):
            try
            {
                // Закрытие соединения
                $this->client->close();
                
                // Удаление соединения клиента из массива соединений сервера
                unset($this->connections[$this->client->id]);
                // Удаление клиента из массива клиентов сервера
                unset($this->clients[$this->client->id]);
                // Уничтожение объекта клиента
                unset($this->client);
            }
            catch (Exception $e)
            {
                // Удаление из массивов в любом случае, что бы ни случилось
                // Удаление соединения клиента из массива соединений сервера
                unset($this->connections[$this->client->id]);
                // Удаление клиента из массива клиентов сервера
                unset($this->clients[$this->client->id]);
                // Уничтожение объекта клиента
                unset($this->client);
                // Журналирование
                $this->log("Ошибка закрытия соединения клиента на сервере вебсокет: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
            }
        endif;
    }
    
    /**
     * Отправка данных на текущий клиентский сокет
     * @param  string $data     Отправляемые данные
     * @param  string $dataType Тип данных
     * @param  int    $flags    Флаги отправки
     * @return int              Количество отправленных байт
     * @throws WebsocketException
     */
    public function send($data = null, $dataType = 'text', $flags = null)
    {
        if (!isset($this->client)):
            $this->error("Ошибка отправки сообщения клиенту: клиент не выбран");
        endif;
        
        return $this->client->send($data, $dataType, $flags);
    }
    
    /**
     * Отправка данных всем клиентам
     * @param  string $data     Отправляемые данные
     * @param  string $dataType Тип данных
     * @param  array  $excluded Массив клиентов, исключённых из списка отправки
     * @throws WebsocketException
     */
    public function sendToAll($data, $dataType = 'text', $excluded = [])
    {
        // Кодирование сообщения во фрейм
        // для предотвращения кодирования каждым клиентом
        $frame = $this->encode($data, $dataType);
        
        // Цикл по подключенным клиентам
        foreach ($this->clients as $client):
            try
            {
                // Обход клиента из списка исключённых
                if ($excluded && in_array($client, $excluded)) continue;
                
                // Отправка кодированного сообщения выбранному клиенту
                $client->send($frame, $dataType, self::RAW_DATA);
            }
            catch (Exception $e)
            {
                $this->debugMessage("Ошибка во время отправки сообщения всем клиентам. ID клиента: $client->id" . PHP_EOL . $e->getMessage());
                
                // Закрытие соединения
                $client->close();
                // Удаление соединения клиента из массива соединений сервера
                unset($this->connections[$client->id]);
                // Удаление клиента из массива клиентов сервера
                unset($this->clients[$client->id]);
            }
        endforeach;
    }
}
