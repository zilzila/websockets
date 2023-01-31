<?php
require_once __DIR__ . '/websocket.php';

/**
 * Клиент websocket
 */
class WebsocketClient extends Websocket
{
    /**
     * Кодовое имя вебсокета
     * @var string
     */
    public static $codeName = 'WSClient';
    
    /**
     * Путь вебсокет сервера
     * @var string
     */
    public $path;
    
    /**
     * Конструктор клиента
     * @param array $options Параметры контекста потока сокета
     * @throws WebsocketException
     */
    public function __construct($options = [])
    {
        parent::__construct(self::CLIENT, $options);
    }
    
    /**
     * Запуск клиента в бесконечном цикле
     * @param  string $scheme Схема сервера
     * @param  string $host   Адрес сервера
     * @param  string $port   Порт сервера
     * @param  string $path   Путь сервера
     */
    public function start($scheme, $host, $port = '80', $path = null)
    {
        // Бесконечный цикл переподключений
        while (true):
            try
            {
                // Подключение к серверу
                $this->connect($scheme, $host, $port, $path);
                
                // Цикл пока сокет активен
                while ($this->active()):
                    // Обработка активности сокета
                    $this->processActivity();
                    
                    // Обработка паузы при неблокирующем режиме для предотвращения перегрузки ресурсов
                    if (!$this->blocking()) usleep(10000);
                endwhile;
                
                // Закрытие сокета
                $this->close();
            }
            catch (WebsocketException $e)
            {
                // Вызов обработчика ошибки
                $this->onError($e);
            }
            
            // Пауза между переподключениями
            sleep(1);
        endwhile;
    }
    
    /**
     * Обработка активности сокета
     * @throws WebsocketException
     */
    protected function processActivity()
    {
        // Получение данных
        $this->receive();
        
        // Отправление недоотправленных данных
        $this->send();
    }
    
    /**
     * Подключение к сокету сервера
     * @param  string $scheme Схема сервера
     * @param  string $host   Адрес сервера
     * @param  string $port   Порт сервера
     * @param  string $path   Путь сервера
     * @return resource       Сокет
     * @throws WebsocketException
     */
    public function connect($scheme, $host, $port = '80', $path = null)
    {
        $this->scheme = $scheme;
        $this->host   = $host;
        $this->port   = $port;
        $this->path   = $path;
        
        try
        {
            $this->socket = stream_socket_client("$scheme://$host:$port", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $this->getContext());
            // Обработка при отсутствии связки errorHandler -> ErrorException
            if ($this->socket === false) throw new WebsocketException();
        }
        catch (Exception $w)
        {
            $this->error("Ошибка создания сокета: ($errno) " . mb_convert_encoding($errstr, 'UTF-8', 'UTF-8, Windows-1251'), $w);
        }
        
        // Установка заданного режима блокировки сокета
        stream_set_blocking($this->socket, $this->blocking());
        
        // Установка ID сокета
        $this->id = (int)$this->socket;
    
        $this->debugMessage("client: stream_meta_data ID: {$this->id}:\n" . print_r(stream_get_meta_data($this->socket), true));
        $this->debugMessage("client: stream_context_get_options ID: {$this->id}:\n" . print_r(stream_context_get_options($this->socket), true));
        
        if ($scheme == 'ssl' || $scheme == 'tls'):
            $this->debugMessage("Установлено зашифрованное соединение ($scheme). ID: {$this->id}");
        elseif ($scheme == 'tcp'):
            $this->debugMessage("Установлено незашифрованное соединение ($scheme). ID: {$this->id}");
        else:
            $this->error("Установлено соединение по неизвестному протоколу ($scheme). ID: {$this->id}");
        endif;
        
        // Выолнение рукопожатия
        $this->handshake();
        
        return $this->socket;
    }
    
    /**
     * Рукопожатие
     * @throws WebsocketException
     */
    protected function handshake()
    {
        // Отправка рукопожатия
        $sndHeaders  = "GET /$this->path HTTP/1.1\r\n";
        $sndHeaders .= "Upgrade: websocket\r\n";
        $sndHeaders .= "Connection: Upgrade\r\n";
        $sndHeaders .= "Host: $this->host:$this->port\r\n";
        $sndHeaders .= "Sec-WebSocket-Key: " . base64_encode(substr(md5(microtime(true) . __CLASS__), 0, 16)) . "\r\n";
        $sndHeaders .= "Sec-WebSocket-Version: 13\r\n";
        $sndHeaders .= "\r\n";
        
        /*
            Ожидание полной отправки и получения заголовков рукопожатия
            будет гарантировать осуществление рукопожатия в рамках одного цикла, что важно,
            потому что соединение может устанавливаться только для отправки сообщений
            и вызов метода receive(), для получения ответа на рукопожатие,
            может вообще не произойти, что нарушит работу вебсокета
        */
        $this->send($sndHeaders, 'text', self::HANDSHAKE);
        
        while (strlen($this->sndData) > 0):
            // Отправка рукопожатия
            $this->send(null, 'text', self::HANDSHAKE);
            
            if (!$this->blocking()) usleep(10000);
        endwhile;
        
        // Получение данных, пока в них не будет обнаружено окончание блока заголовков
        do {
            // Получение данных завершения рукопожатия
            $this->receive();
            $pos = strpos($this->rcvData, "\r\n\r\n");
            if (!$this->blocking()) usleep(10000);
        } while ($pos === false);
        
        // Вычленение и разбор заголовков
        $rcvHeaders = $this->parseHeaders(substr($this->rcvData, 0, $pos));
        
        // Проверка заголовков соединения
        if (!isset($rcvHeaders['ResponseCode']) || $rcvHeaders['ResponseCode'] != 101):
            // Заголовки отличны от нормальных
            $this->error("Ошибка рукопожатия: $this->rcvData");
        endif;
        
        // Установка времени соединения
        $this->handshaked = microtime(true);
        $this->debugMessage("Успешное рукопожатие");
        
        // Дальнейшая обработка данных при их наличии
        // Вычленение данных
        $this->rcvData = substr($this->rcvData, $pos + 4);
    
        // Обработка полученных данных
        $this->processRcvData();
        
        // Обработка полученных фреймов
        $this->processRcvFrames();
    }
    
    /**
     * Обработчик ошибки подключения
     * @param Exception $exception Исключение
     */
    protected function onError($exception)
    {
        $this->sndData = null;
        $this->rcvData = null;
        
        // Закрытие сокета
        $this->close();
        
        // Журналирование ошибки
        $this->log("Ошибка вебсокета на клиенте вебсокет: " . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString());
    }
}
