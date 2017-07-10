<?php
namespace peang\base\middlewares\logger;

use Monolog\Logger as MonologLogger;

/**
 * @package base\logger
 * @author  Irvan Setiawan <peang.cookie@gmail.com>
 */
class Logger
{
    /**
     * @return MonologLogger
     */
    function __invoke()
    {
        $logger = new MonologLogger('base_logger');
        $file_handler = new \Monolog\Handler\StreamHandler("../logs/app.log");
        $logger->pushHandler($file_handler);
        return $logger;
    }
}