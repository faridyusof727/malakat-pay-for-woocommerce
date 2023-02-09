<?php

class LogHelper
{
    private $logger;

    public function __construct($isEnabled)
    {
        $this->isEnabled = $isEnabled;
        $this->logger = $this->getLogger();
    }

    public function log($message)
    {
        if ($this->isEnabled) {
            $this->logger->add('raudhahpay', $message);
        }
    }

    private function getLogger()
    {
        return new WC_Logger();
    }
}