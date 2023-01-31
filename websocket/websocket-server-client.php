<?php
require_once __DIR__ . '/websocket.php';
require_once __DIR__ . '/websocket-server.php';

/**
 * Соединение сервера с клиентом
 */
class WebsocketServerClient extends Websocket
{
    /**
     * Кодовое имя вебсокета
     * @var string
     */
    public static $codeName = 'WSServerClient';
    
    /**
     * IP адрес клиента
     * @var string
     */
    public $ip;
    
    /**
     * Флаг завершения обработки запроса на рукопожатие
     * @var bool
     */
    private $handshakeRequestComplete;
    
    
    /**
     * Конструктор
     * @throws WebsocketException
     */
    public function __construct()
    {
        // Вызов конструктора объекта серверного сокета
        parent::__construct(self::SERVER);
    }
    
    /**
     * Принятие соединения
     * @param resource $serverSocket Сокет сервера
     * @throws WebsocketException
     */
    public function accept($serverSocket)
    {
        $this->socket = stream_socket_accept($serverSocket);
        
        if ($this->socket === false):
            $this->error("Ошибка принятия соединения");
        endif;
        
        // Установка ID сокета
        $this->id = (int)$this->socket;
        
        // Установка IP сокета
        $this->ip = strstr(stream_socket_get_name($this->socket, true), ':', true);
    
        $this->debugMessage("accept: stream_meta_data ID: {$this->id}, ip: {$this->ip}:\n" . print_r(stream_get_meta_data($this->socket), true));
    
        // Получение параметров контекста сокета
        $ssl = stream_context_get_options($this->socket)['ssl'] ?? null;
    
        $this->scheme = 'tcp';
        if ($ssl):
            $crypto = false;
            try {
                // Включение блокирующего режима. Необходим для установки зашифрованноого соединения
                stream_set_blocking($this->socket, true);
                // Попытка включения tls
                $crypto = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
            } catch (Exception $e){
                $this->debugMessage("Обработано исключение при вызове stream_socket_enable_crypto(). Значение: $crypto");
            }
            $this->debugMessage("stream_socket_enable_crypto: $crypto");
            
            if ($crypto):
                $this->scheme = 'tls';
                $this->debugMessage("accept (crypto enabled): stream_meta_data ID: {$this->id}, ip: {$this->ip}:\n" . print_r(stream_get_meta_data($this->socket), true));
                $this->debugMessage("Установлено шифрованное соединение ID: {$this->id}, ip: {$this->ip}");
            else:
                // $ssl['allow_unsecured'] не вяляется встроенным параметром и добавляется вручную в массив опций
                if (isset($ssl['allow_unsecured']) && $ssl['allow_unsecured']):
                    $this->debugMessage("Установлено незашифрованное соединение ID: {$this->id}, ip: {$this->ip}");
                else:
                    $this->error("Сервер вебсокет не позволяет незашифрованные соединения");
                endif;
            endif;
        else:
            $this->debugMessage("Установлено незашифрованное соединение ID: {$this->id}, ip: {$this->ip}");
        endif;
        
        // Установка заданного режима
        stream_set_blocking($this->socket, $this->blocking());

        // Выполнение рукопожатия
        $this->handshake();
    }
    
    /**
     * Рукопожатие
     * @throws WebsocketException
     */
    protected function handshake()
    {
        $sndHeaders = null;
        
        // Запрос на рукопожатие не получен
        if (!$this->handshakeRequestComplete):
            // Получение запроса на рукопожатие
            $this->receive();
    
            // При неблокирующем режиме отсеивать пустые данные
            if ($this->rcvData === '') return;
    
            // Отделение заголовков от фреймов (если переданы)
            $pos = strpos($this->rcvData, "\r\n\r\n");
    
            // Определение корректности заголовка
            // При плохом\медленном соединении, заголовки могут передаваться по частям.
            // Так что, вместо ошибки, ожидание получения всех заголовков
            if ($pos === false):
                $this->debugMessage("Заголовки рукопожатия переданы частично:" . PHP_EOL . $this->rcvData);
                return;
            endif;
    
            // Вычленение и разбор заголовков
            $rcvHeaders = $this->parseHeaders(substr($this->rcvData, 0, $pos));
    
            if (empty($rcvHeaders['Sec-WebSocket-Key'])):
                $this->error("Передан пустой ключ");
            endif;
    
            // Удаление заголовков из полученных данных
            $this->rcvData = substr($this->rcvData, $pos + 4, strlen($this->rcvData));
            
            // Запрос на рукопожатие выполнен
            $this->handshakeRequestComplete = true;
        
            // Вычисление ключа ответа на рукопожатие
            $secWebSocketAccept = base64_encode(pack('H*', sha1($rcvHeaders['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    
            // Формирование заголовков ответа
            $sndHeaders  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n";
            $sndHeaders .= "Upgrade: websocket\r\n";
            $sndHeaders .= "Connection: Upgrade\r\n";
            $sndHeaders .= "Sec-WebSocket-Accept: $secWebSocketAccept\r\n";
            $sndHeaders .= "\r\n";
        endif;
        
        // Отправка ответа на рукопожатие
        $this->send($sndHeaders, 'text', self::HANDSHAKE);
        
        // Отправлены все данные рукопожатия
        // Рукопожатие завершено
        if ($this->sndData === ''):
            // Установка времени соединения
            $this->handshaked = microtime(true);
            $this->debugMessage("Успешное рукопожатие");
        endif;
    }
    
    /**
     * Обработка ошибки
     * @param string    $text              Текст ошибки
     * @param Exception $previousException Предыдущее исключение
     * @throws WebsocketException
     */
    protected function error($text, $previousException = null)
    {
        // Закрытие сокета
        $this->close();
        
        $this->handshakeRequestComplete  = false;
        
        parent::error("$text ID: {$this->id}, ip: {$this->ip}", $previousException);
    }
}
