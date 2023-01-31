<?php
require_once __DIR__ . '/../websocket/websocket-client.php';

/**
 * Вебсокет клиент
 */
class WSClient extends WebsocketClient
{
    /**
     * Обработка активности сокета
     * @throws WebsocketException
     */
    protected function processActivity()
    {
        $this->receive();
    
        // Отправка сообщения на сервер
        $this->send(json_encode([
            'type' => 'echo',
            'data' => [
                'text' => date('H:i:s')
            ]
        ]));

        sleep(1);
    }
    
    /**
     * Обработчик приёма фрейма типа "text" из сокета
     * @param string $frame Текст сообщения
     * @throws Exception
     */
    protected function onFrameText($frame)
    {
        // Преобразование полученного сообщения формата json в массив
        $message = json_decode($frame, true);
        $data = $message['data'] ?? null;
        $type = $message['type'] ?? null;
        
        // Обработка полученного сообщения
        switch ($type):
            case 'response': $this->onResponse($data); break;
            case 'result':   $this->onResult($data);   break;
            default: $this->log("Неизвестный тип сообщения: " . print_r($frame, true)); break;
        endswitch;
    }
    
    /**
     * Обработчик сообщения подключения к серверу
     * @param array $message Массив параметров сообщения:<br>
     * <b>text</b> - Текст ответа<br>
     */
    protected function onResponse($message)
    {
        $this->log("Получено сообщение типа 'response': " . print_r($message, true));
    }
    
    /**
     * Обработчик сообщения подключения к серверу
     * @param array $message Массив параметров сообщения:<br>
     * <b>value</b> - Значение вычисленного выражения<br>
     */
    protected function onResult($message)
    {
        $this->log("Получено сообщение типа 'result': " . print_r($message, true));
    }
}
