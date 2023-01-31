<?php
require_once __DIR__ . '/websocket-exception.php';

/**
 * Websocket
 */
class Websocket
{
    /**
     * Кодовое имя вебсокета
     * @var string
     */
    public static $codeName;
    
    /**
     * Ресурс сокета
     * @var resource
     */
    protected $socket;
    
    /**
     * Тип сокета
     * @var int Может принимать значения:<br>
     * <b>self::CLIENT</b> - сокет клиента<br>
     * <b>self::SERVER</b> - сокет сервера<br>
     */
    protected $type;
    
    /**
     * Схема вебсокет сервера (tcp|ssl|tls)
     * @var string
     */
    public $scheme;
    
    /**
     * Хост вебсокет сервера
     * @var string
     */
    public $host;
    
    /**
     * Порт вебсокет сервера
     * @var string
     */
    public $port;

    /**
     * ID сокета (числовое значение ресурса сокета)
     * @var int
     */
    public $id;
    
    /**
     * Флаг установления соединения, содержит время успешного завершения рукопожатия
     * @var int
     */
    protected $handshaked;
    
    /**
     * Режим сокета, блокирующий или нет
     * @var int
     */
    private $blocking = false;
    
    /**
     * Полученные данные из сокета
     * @var string
     */
    protected $rcvData;
    
    /**
     * Данные для отправки в сокет
     * @var string
     */
    protected $sndData;
    
    /**
     * Массив полученных декодированных фреймов из сокета
     * @var array
     */
    protected $rcvFrames = [];
    
    /**
     * Массив параметров неполного полученного декодированного фрейма
     * @var array
     */
    protected $rcvIncompleteFrame;
    
    /**
     * @var bool Режим отладки
     */
    private $debug = false;
    
    
    /**
     * Сокет является сервером
     */
    const SERVER = 0;
    
    /**
     * Сокет является клиентом
     */
    const CLIENT = 1;
    
    /**
     * Флаг рукопожатия
     */
    const HANDSHAKE = 1;
    
    /**
     * Флаг данных, не требующих обработки
     */
    const RAW_DATA = 2;
    
    /**
     * Параметры контекста потока вебсокета
     * @var array
     */
    private $contextOptions = [];
    
    /**
     * Конструктор вебсокета
     * @param int   $type           Тип вебсокета
     * @param array $contextOptions Параметры контекста потока сокета
     * @throws WebsocketException
     */
    public function __construct($type = self::CLIENT, $contextOptions = [])
    {
        if (($type != self::CLIENT) && ($type != self::SERVER)):
            $this->error("Указан неверный тип сокета: $type");
        endif;
        
        // Установка типа websocket
        $this->type = $type;
        
        // Установка контекста сокета, если он передан
        if ($contextOptions):
            $this->contextOptions = $contextOptions;
        endif;
    }
    
    /**
     * Деструктор
     */
    public function __destruct()
    {
        // Закрытие сокета
        $this->close();
    }
    
    /**
     * @return resource Контекст сокета
     */
    protected function getContext()
    {
        // Подключение к локальному серверу по ssl без проверок
        if (
                empty($this->contextOptions) &&
                $this->type == self::CLIENT &&
                ($this->scheme == 'ssl' || $this->scheme == 'tls') &&
                ($this->host == '127.0.0.1' || $this->host == 'localhost')
           ):
            $this->contextOptions = [
                'ssl' => [
                    'allow_self_signed' => true,
                    'verify_peer'       => false,
                    'verify_peer_name'  => false
                ]
            ];
        endif;
        
        return stream_context_create($this->contextOptions);
    }
    
    /**
     * Проверка доступности сокета
     * @return boolean Флаг доступности сокета
     */
    public function active()
    {
        // Проверка доступности сокета
        return isset($this->socket) && is_resource($this->socket) && !feof($this->socket);
    }
    
    /**
     * Получение\установка режима блокировки сокета
     * @param  boolean $value Значение устанавливаемого режима сокета
     * @return boolean        Устанавленное значение режима сокета
     */
    public function blocking($value = null)
    {
        // Аргумент передан - установка значения
        if (func_num_args() > 0):
            $this->blocking = $value ? 1 : 0;
        
            if ($this->active()):
                // Установка режима сокета
                stream_set_blocking($this->socket, $this->blocking);
            endif;
        endif;
        
        return $this->blocking;
    }
    
    /**
     * Рукопожатие
     * Необходимо реализовать в наследуемом классе
     * @throws WebsocketException
     */
    protected function handshake()
    {
        $this->error("Необходимо реализовать в классе-наследнике метод " . __METHOD__);
    }
    
    /**
     * Обработка активности сокета
     * Вызывается внутри бесконечного цикла
     * В этом методе не должно быть бесконечного цикла
     */
    protected function processActivity()
    {
    }
    
    /**
     * Отправка данных
     * При вызове без параметров выполняется отправка неотправленных ранее данных
     * @param  string $data     Отправляемые данные
     * @param  string $dataType Тип данных
     * @param  int    $flags    Флаги отправки
     * @return int              Количество отправленных байт
     * @throws WebsocketException
     */
    public function send($data = null, $dataType = 'text', $flags = null)
    {
        // Проверка доступности сокета
        if (!$this->active())
            throw new WebsocketException("Ошибка отправки данных в сокет: неактивный сокет");
        
        // Запрет отправки любых сообщений, кроме рукопожатия, если оно ещё не совершено
        if (!$this->handshaked && !($flags & self::HANDSHAKE)) return 0;
        
        // Отправка новых переданных данных
        if (func_num_args() > 0 && $data != ''):
            // Отправка данных без обработки (к примеру, пересылка на другие вебсокеты)
            if ($flags & (self::RAW_DATA | self::HANDSHAKE)):
                $frame = $data;
            // Кодирование данных во фрейм
            else:
                $frame = $this->encode($data, $dataType);
            endif;

            if ($frame === false):
                $this->error("Ошибка отправки данных в сокет: ошибка кодирования фрейма");
            endif;

            $this->sndData .= $frame;
        endif;
        
        if (strlen($this->sndData) == 0) return 0;
        
        
        $socketWriteable = true;
        $result = 0;
        do {
            try
            {
                $written = fwrite($this->socket, $this->sndData, 4096);
                
                // Обработка при отсутствии связки errorHandler -> ErrorException
                if (!$written) throw new WebsocketException('Ошибка отправки данных в сокет');
    
                if (!$this->handshaked):
                    $this->debugMessage("Отправлены данные рукопожатия:" . PHP_EOL . substr($this->sndData, 0, $written));
                else:
                    $this->debugMessage("Отправлены данные типа $dataType. Размер: $written");
                endif;
                
                $this->sndData = substr($this->sndData, $written);
                $result += $written;
            } catch (Exception $e) {
                // Другого сопособа поймать EAGAIN нет
                if ($e instanceof ErrorException && strpos($e->getMessage(), 'errno=11') !== false):
                    $socketWriteable = false;
                    $this->debugMessage("Ошибка отправки данных: сокет не готов к записи");
                else:
                    $this->close();
                    $this->error("Ошибка отправки данных в сокет", $e);
                endif;
            }
            
            // Необходима небольшая пауза, иначе php падает с segmentation fault
            if (!$this->blocking) usleep(1000);
        } while (
            (static::class == 'WebsocketServerClient' && $this->sndData !== '') ||
            (static::class != 'WebsocketServerClient' && $this->sndData !== '' && $socketWriteable)
        );
    
        return $result;
    }
    
    /**
     * Получение данных из сокета
     * @param  int $flags Флаги приёма
     * @return string|boolean Принятые данные в случае успеха, false в случае ошибки
     * @throws WebsocketException
     */
    public function receive($flags = null)
    {
        // Проверка доступности сокета
        if (!$this->active())
            throw new WebsocketException("Ошибка получения данных из сокета: неактивный сокет");
    
        $socketReadable = true;
        $data = '';
        // Получение фреймов
        do
        {
            try
            {
                // Получение данных
                $data = fread($this->socket, 4096);
                $this->rcvData .= $data;
                
                // Обработка при отсутствии связки errorHandler -> ErrorException
                if ($data === false) throw new WebsocketException('Ошибка получения данных из сокета');
                
                if (strlen($data) > 0):
                    if (!$this->handshaked):
                        $this->debugMessage("Приняты данные:" . PHP_EOL . $data);
                    else:
                        $this->debugMessage("Приняты данные размером " . strlen($data));
                    endif;
                endif;
                
                if ($data === ''):
                    // Получение данных текущего статуса сокета
                    $streamMetaData = stream_get_meta_data($this->socket);
                    // Проверка обрыва соединения
                    if ($streamMetaData['eof']):
                        // Закрытие соединения
                        $this->close();
                        $this->error("Обрыв соединения");
                    endif;
        
                    // Проверка таймаута соединения
                    if ($streamMetaData['timed_out']):
                        // Закрытие соединения
                        $this->close();
                        $this->error("Таймаут соединения");
                    endif;
                endif;
            }
            catch (Exception $e)
            {
                if ($e instanceof ErrorException && strpos($e->getMessage(), 'errno=11') !== false):
                    $socketReadable = false;
                    $this->debugMessage("Ошибка отправки данных: сокет не готов к чтению");
                else:
                    $this->close();
                    $this->error("Ошибка получения данных из сокета", $e);
                endif;
            }
    
//            if (!$this->blocking) usleep(1000);
        } while ($socketReadable && $data !== '' && !$this->blocking);
        
        
        if ($this->rcvData === '') return '';
        
        // Рукопожатие было успешно осуществлено, приём данных
        if ($this->handshaked):
            // Получение данных, которые не нужно обрабатывать (к примеру, для пересылки)
            if (!($flags & self::RAW_DATA)):
                // Обработка полученных данных
                $this->processRcvData();
                
                // Обработка полученных фреймов
                $this->processRcvFrames();
            endif;
        endif;

        return $this->rcvData;
    }
    
    /**
     * Обработка полученных данных
     * @return array Массив полученных фреймов после обработки данных
     * @throws WebsocketException
     */
    public function processRcvData()
    {
        // Получение неполного фрейма с последней передачи при его наличии (должен быть один единственный в массиве)
        $frame           = $this->rcvIncompleteFrame;
        $this->rcvFrames = [];
        
        // Обработка фреймов
        while (strlen($this->rcvData) > 0):
            // Неполный фрейм присутствует
            if ($this->rcvIncompleteFrame):
                // Получение недостающей части фрейма из следующего сообщения
                $part = substr($this->rcvData, 0, $frame['incomplete']);
                
                // Добавление недостающей части фрейма
                $frame['payload'] .= $part;
                
                // Удаление обработанной части фрейма из полученных данных
                $this->rcvData = substr($this->rcvData, $frame['incomplete']);
                
                // Определение полноты фрейма
                $frame['incomplete'] -= strlen($part);
                
                // Собран полный фрейм
                if ($frame['incomplete'] <= 0):
                    // Декодирование фрейма сообщения
                    $frame = $this->decode($frame['payload']);
                    if ($frame === false):
                        $this->error("Ошибка декодирования сообщения: $this->rcvData");
                        break;
                    endif;
                endif;
            // Неполный фрейм отсутствует
            else:
                // Декодирование фрейма сообщения
                $frame = $this->decode($this->rcvData);
                if ($frame === false):
                    $this->error("Ошибка декодирования сообщения: $this->rcvData");
                    break;
                endif;
                
                // Удаление обработанного фрейма из полученных данных, переход к следующему фрейму
                $this->rcvData = substr($this->rcvData, $frame['offset'] + strlen($frame['payload']));
            endif;
            
            // Фрейм передан полностью
            if ($frame['incomplete'] <= 0):
                // Добавление полного фрейма в массив полученных фреймов
                $this->rcvFrames[] = $frame;
                
                // Удаление неполного фрейма
                $this->rcvIncompleteFrame = null;
            // Фрейм передан частично
            else:
                // Сохранение неполного фрейма в массив полученных фреймов
                $this->rcvIncompleteFrame = $frame;
            endif;
        endwhile;
        
        return $this->rcvFrames;
    }
    
    /**
     * Обработка полученных фреймов
     * @throws WebsocketException
     */
    protected function processRcvFrames()
    {
        // Обработка полученных декодированных фреймов
        if ($this->rcvFrames):
            $this->debugMessage("Приняты фреймы:" . PHP_EOL . print_r($this->rcvFrames, true));
    
            // Цикл по фреймам
            foreach ($this->rcvFrames as $frame):
                switch ($frame['type']):
                    case 'text':   $this->onFrameText($frame['payload']);               break;
                    case 'close':  $this->onFrameClose();                               break;
                    case 'binary': $this->onFrameBinary($frame['payload']);             break;
                    case 'ping':   $this->onFramePing();                                break;
                    case 'pong':                                                        break;
                    default:       $this->error("Неизвестный тип фрейма $frame[type]"); break;
                endswitch;
            endforeach;
            
            // Очистка массива полученных фреймов
            $this->rcvFrames = [];
        endif;
    }
    
    /**
     * Обработчик приёма фрейма типа "text" из сокета
     * @param string $frame Текст фрейма
     */
    protected function onFrameText($frame)
    {
        $this->debugMessage("Сообщение (текстовые данные): $frame");
    }
    
    /**
     * Обработчик приёма фрейма типа "binary" из сокета
     * @param string $frame Текст фрейма
     */
    protected function onFrameBinary($frame)
    {
        $this->debugMessage("Сообщение (бинарные данные): $frame");
    }
    
    /**
     * Обработчик приёма фрейма типа "close" из сокета
     */
    protected function onFrameClose()
    {
        $this->close();
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
     * Закрытие соединения
     */
    public function close()
    {
        if ($this->active()):
            // Закрытие сокета
            fclose($this->socket);
            $this->debugMessage("Закрытие сокета ID: {$this->id}");
        endif;
        
        $this->socket     = null;
        $this->handshaked = null;
    }
    
    /**
     * Генерация ошибки
     * @param  string    $text              Текст ошибки
     * @param  Exception $previousException Предыдущее исключение при наличии
     * @throws WebsocketException           Исключение вебсокета
     */
    protected function error($text, $previousException = null)
    {
        $this->debugMessage("$text\n$previousException");
        
        throw new WebsocketException(empty(static::$codeName) ? $text : ('[' . static::$codeName . '] ' . $text), $previousException);
    }
    
    /** Обработчик ошибки
     * @param $data mixed Данные ошибки
     */
    protected function onError($data)
    {
        $this->log($data);
    }
    
    /**
     * Кодирование сообщения во фрейм
     * @param  string  $payload Кодируемое сообщение
     * @param  string  $type    Тип сообщения
     * @return string           Фрейм - закодированное сообщение
     * @throws WebsocketException
     */
    protected function encode($payload, $type = 'text')
    {
        $frameHead = [];
        $payloadLength = strlen($payload);
        
        switch ($type):
            case 'text':  $frameHead[0] = 129; break; // first byte indicates FIN, Text  frame (10000001):
            case 'close': $frameHead[0] = 136; break; // first byte indicates FIN, Close frame (10001000):
            case 'ping':  $frameHead[0] = 137; break; // first byte indicates FIN, Ping  frame (10001001):
            case 'pong':  $frameHead[0] = 138; break; // first byte indicates FIN, Pong  frame (10001010):
        endswitch;
        
        // set mask and payload length (using 1, 3 or 9 bytes) 
        if ($payloadLength > 65535):
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($this->type == self::CLIENT) ? 255 : 127;
            
            for ($i = 0; $i < 8; $i++):
                $frameHead[$i+2] = bindec($payloadLengthBin[$i]);
            endfor;
            
            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127):
                // Закрытие соединения
                $this->close();
                $this->error("Фрейм слишком большой");
            endif;
        elseif ($payloadLength > 125):
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($this->type == self::CLIENT) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        else:
            $frameHead[1] = ($this->type == self::CLIENT) ? $payloadLength + 128 : $payloadLength;
        endif;
        
        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i):
            $frameHead[$i] = chr($frameHead[$i]);
        endforeach;
        
        $mask = [];
        if ($this->type == self::CLIENT):
            // generate a random mask:
            for ($i = 0; $i < 4; $i++):
                $mask[$i] = chr(rand(0, 255));
            endfor;
            
            $frameHead = array_merge($frameHead, $mask);
        endif;
        
        $frame = implode('', $frameHead);
        
        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++):
            $frame .= ($this->type == self::CLIENT) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        endfor;
        
        return $frame;
    }

    /**
     * Декодирование фрейма в сообщение
     * @param  string $data Фрейм закодированного сообщения
     * @return array        Массив параметров сообщения:<br>
     * <b>type</b>          - тип сообщения<br>
     * <b>payload</b>       - текст сообщения<br>
     * <b>offset</b>        - длина заголовка<br>
     * <b>incomplete</b>    - количество недополученных байт<br>
     * @throws WebsocketException
     */
    protected function decode($data)
    {
        $unmaskedPayload = '';
        $inLength        = strlen($data);
        $decodedData     = [];
        
        // Передана только часть заголовка фрейма (1 байт)
        if ($inLength == 1):
            // Количество байт заголовка
            $decodedData['offset']     = 0;
            // Количество недополученных байт
            $decodedData['incomplete'] = 1;
            // Полученные данные
            $decodedData['payload']    = $data;
            
            return $decodedData;
        endif;
        
        // Определение параметров фрейма
        $firstByteBinary  = sprintf('%08b', ord($data[0]));
        $secondByteBinary = sprintf('%08b', ord($data[1]));
        $opcode           = bindec(substr($firstByteBinary, 4, 4));
        $isMasked         = $secondByteBinary[0] == '1';
        $payloadLength    = ord($data[1]) & 127;
        
        // После рукопожатия клиент отправляет серверу только маскированные фреймы, 
        // а сервер клиенту только немаскированные, то есть где бит MASK = 0.
        if (($this->type == self::CLIENT) && $isMasked):
            // Закрытие соединения
            $this->close();
            $this->error("Ошибка декодирования фрейма: клиентом получен маскированный фрейм: $data");
        endif;
        
        // Определение типа фрейма
        switch ($opcode):
            case 1:  $decodedData['type'] = 'text';   break; // text фрейм
            case 2:  $decodedData['type'] = 'binary'; break; // binary фрейм
            case 8:  $decodedData['type'] = 'close';  break; // connection close фрейм
            case 9:  $decodedData['type'] = 'ping';   break; // ping фрейм
            case 10: $decodedData['type'] = 'pong';   break; // pong фрейм
            default:
                // Закрытие соединения                
                $this->close();
                $this->error("Ошибка определения типа фрейма. opcode: $opcode, фрейм: $data");
        endswitch;
        
        // Определение длины заголовка фрейма
        // Первые 2 байта + ключ маски 4 байта (при наличии)
        $payloadOffset = ($isMasked ? 6 : 2) + ($payloadLength == 126 ? 2 : ($payloadLength == 127 ? 8 : 0));
        
        // Передана только часть заголовка фрейма
        if ($inLength < $payloadOffset):
            // Количество байт заголовка
            $decodedData['offset']     = 0;
            // Количество недополученных байт
            $decodedData['incomplete'] = $payloadOffset - $inLength;
            // Полученные данные
            $decodedData['payload']    = $data;
            
            return $decodedData;
        endif;
        
        // Определение длины данных фрейма
        switch ($payloadLength):
            // Длина данных фрейма закодирована в двоичном виде в двух байтах и составляет от 126 до 65535 байт
            case 126:
                $payloadLength = base_convert(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3])), 2, 10);
            break;
            // Длина данных фрейма закодирована в двоичном виде в восьми байтах и составляет от 65536 до 2^64 - 1 байт
            case 127:
                // Получение значения длины данных в двоичной СС
                $payloadLength = null;
                for ($i = 2; $i < 10; $i++):
                    $payloadLength .= sprintf('%08b', ord($data[$i]));
                endfor;
                // Перевод значения длины данных в десятиричную СС
                $payloadLength = base_convert($payloadLength, 2, 10);
            break;
            // Длина данных фрейма закодирована в двоичном виде в текущих семи битах и составляет от 1 до 125 байт
            default:
            break;
        endswitch;

        $dataLength = $inLength - $payloadOffset;
        
        // Длина полученных данных больше длины данных фрейма
        if ($dataLength > $payloadLength):
            $dataLength = $payloadLength;
        endif;
        
        // Обработка частичного фрейма
        // Полученные данные меньше длины фрейма - получен частичный фрейм
        if ($dataLength < $payloadLength):
            // Сохранение недекодированного начала частичного фрейма с заголовком для последующего декодирования в полном виде
            $decodedData['payload'] = $data;
        // Обработка полного фрейма
        else:
            // Маскированный фрейм
            if ($isMasked):
                $mask = substr($data, $payloadOffset - 4, 4);
                for ($i = $payloadOffset; $i < $dataLength + $payloadOffset; $i++):
                    $j = $i - $payloadOffset;
                    $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
                endfor;
                $decodedData['payload'] = $unmaskedPayload;
            // Немаскированный фрейм
            else:
                $decodedData['payload'] = substr($data, $payloadOffset, $dataLength);
            endif;
        endif;
        
        // Установка параметров фрейма
        // Количество байт заголовка
        $decodedData['offset']     = $payloadOffset;
        // Количество недополученных байт
        $decodedData['incomplete'] = $payloadLength - $dataLength;
        
        return $decodedData;
    }
    
    /**
     * Преобразование строки заголовков соединения в массив с названиями заголовков в качестве ключей
     * @param  string $headers Строка заголовков соединения
     * @return array           Массив с названиями заголовков в качестве ключей
     */
    protected function parseHeaders($headers)
    {
        // Преобразование строк заголовков в массив
        $headers = explode("\r\n", $headers);
        $head    = [];

        foreach($headers as &$header):
            $t = explode(':', $header, 2);
            if(isset($t[1])):
                $head[trim($t[0])] = trim($t[1]);
            else:
                $head[] = $header;
                if (preg_match("#HTTP/[0-9.]+\s+([0-9]+)#", $header, $matches)):
                    $head['ResponseCode'] = intval($matches[1]);
                endif;
            endif;
        endforeach;

        return $head;
    }
    
    /** Получение статуса или установка режима отладки
     * @param  bool $mode Флаг режима отладки
     * @return bool       Статус режима отладки
     */
    public function debugMode($mode = true)
    {
        if (func_num_args() > 0):
            $this->debug = $mode;
        endif;
        
        return $this->debug;
    }
    
    /**
     * Вывод сообщения отладки
     * @param $text string Текст сообщения отладки
     */
    protected function debugMessage($text)
    {
        if ($this->debugMode()):
            $this->log($text);
        endif;
    }
    
    /**
     * Журналирование
     * @param $data mixed Данные для журналирования
     */
    protected function log($data)
    {
        if (is_array($data) || is_object($data)):
            $data = print_r($data, true);
        endif;
        
        echo date('Y-m-d H:i:s [') . static::$codeName . "] $data" . PHP_EOL;
    }
}
