<?php
/**
 * Исключение вебсокета
 */
class WebsocketException extends Exception
{
    /**
     * Конструктор исключения уровня вебсокета
     * @param string    $message  Текст исключения
     * @param Exception $previous Предыдущее исключение
     */
    public function __construct($message = null, $previous = null)
    {
        $this->message = $message;
        
        // обработка предыдущего исключения
        if (isset($previous)):
            $this->code     = $previous->code;
            $this->message .= " ($previous->message)";
        endif;
        
        // Родительский конструктор
        parent::__construct($this->message, $this->code, $previous);
    }
}
