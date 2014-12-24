<?php
namespace Test;

class ServiceB
{
    private $serviceA;
    private $parameter;

    public function __construct($serviceA)
    {
        $this->serviceA = $serviceA;
    }

    public function someMethod($parameter)
    {
        $this->parameter = $parameter;
    }

    public function getParameter()
    {
        return $this->parameter;
    }

    public function getService()
    {
        return $this->serviceA;
    }
}
