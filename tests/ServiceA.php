<?php
namespace Test;

class ServiceA {
    private $config;
    private $parameter;
    private $escaped;

    public function __construct ($config, $parameter, $escaped) {
        $this->config = $config;
        $this->parameter = $parameter;
        $this->escaped = $escaped;
    }

    public function getConfig () {
        return $this->config;
    }

    public function getParameter () {
        return $this->parameter;
    }

    public function getEscaped () {
        return $this->escaped;
    }
}