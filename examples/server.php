<?php
require_once __DIR__ . '/../websocket/websocket-server.php';

/**
 * Вебсокет сервер
 */
class WSServer extends WebsocketServer
{
    /**
     * Кодовое имя вебсокета
     * @var string
     */
    public static $codeName = 'WSServer';
    
    
    /**
     * @throws WebsocketException
     */
    protected function processActivity()
    {
        $this->sendToAll("Сообщение для всех клиентов. Текущее время: " . date('Y-m-d H:i:s'));
        
        sleep(1);
    }
    
    /**
     * Обработчик приёма фрейма типа "text" из сокета клиента
     * @param string $frame Текст фрейма
     * @throws WebsocketException
     */
    protected function onFrameText($frame)
    {
        // Преобразование полученного сообщения формата json в массив
        $message = json_decode($frame, true, 512, JSON_BIGINT_AS_STRING);
        
        $type = $message['type'] ?? null;
        $data = $message['data'] ?? null;
        // Обработка полученного сообщения
        switch ($type):
            case 'echo':        $this->onEcho($data);        break;
            case 'addition':    $this->onAddition($data);    break;
            case 'subtraction': $this->onSubtraction($data); break;
            default: $this->log("Неизвестный тип сообщения: " . print_r($message, true)); break;
        endswitch;
    }
    
    /**
     * Обработка сообщения типа "echo"
     * @param array $message Массив параметров сообщения:<br>
     * <b>text</b> - Текст<br>
     * @throws WebsocketException
     */
    protected function onEcho($message)
    {
        $this->log("Получено сообщение типа 'echo': " . print_r($message, true));
        
        // Формирование сообщения для отправки клиенту
        $response = [
            'type' => 'response',
            'data' => [
                'text' => "эхо: $message[text]"
            ]
        ];
        
        $this->log("Отправка ответного сообщения типа 'response'");
        
        // Отправка сообщения в формате json
        $this->send(json_encode($response));
    }
    
    /**
     * Обработка сообщения типа "addition"
     * @param array $message Массив параметров сообщения:<br>
     * <b>leftOperand</b>  - Лквый операнд<br>
     * <b>rightOperand</b> - Правый операнд<br>
     * @throws WebsocketException
     */
    protected function onAddition($message)
    {
        $this->log("Получено сообщение типа 'addition': " . print_r($message, true));
        
        $result = $message["leftOperand"] + $message["rightOperand"];
    
        // Формирование сообщения для отправки клиенту
        $response = [
            'type' => 'result',
            'data' => [
                'value' => "$message[leftOperand] + $message[rightOperand] = $result"
            ]
        ];
        
        $this->log("Отправка ответного сообщения типа 'result'");
    
        // Отправка сообщения в формате json
        $this->send(json_encode($response));
    }
    
    /**
     * Обработка сообщения типа "subtraction"
     * @param array $message Массив параметров сообщения:<br>
     * <b>leftOperand</b>  - Лквый операнд<br>
     * <b>rightOperand</b> - Правый операнд<br>
     * @throws WebsocketException
     */
    protected function onSubtraction($message)
    {
        $this->log("Получено сообщение типа 'subtraction': " . print_r($message, true));
    
        $result = $message["leftOperand"] - $message["rightOperand"];
    
        // Формирование сообщения для отправки клиенту
        $response = [
            'type' => 'result',
            'data' => "$message[leftOperand] - $message[rightOperand] = $result"
        ];
        
        $this->log("Отправка ответного сообщения типа 'result'");
        
        // Отправка сообщения в формате json
        $this->send(json_encode($response));
    }
}
