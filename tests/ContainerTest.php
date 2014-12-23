<?php
namespace Opine\Container;

use PHPUnit_Framework_TestCase;
use Opine\Container\Service as Container;
use Opine\Config\Service as Config;

require __DIR__ . '/ServiceA.php';
require __DIR__ . '/ServiceB.php';

class ContainerTest extends PHPUnit_Framework_TestCase {
    private $container;

    public function setup () {
        $root = __DIR__ . '/../public';
        $config = new Config($root);
        $config->cacheSet();
        $this->container = Container::instance($root, $config, $root . '/../config/containers/test-container.yml');
    }

    public function testShow () {
        $container = $this->container->show();
        $this->assertTrue(is_array($container));
        $this->assertTrue(count($container['parameters']) == 2);
        $this->assertTrue('abc' === $container['parameters']['test']);
        $this->assertTrue(
            'config' == $container['services'][0] &&
            'container' == $container['services'][1] &&
            'aService' == $container['services'][2] &&
            'bService' == $container['services'][3]);
    }

    public function testArguments () {
        $serviceA = $this->container->get('aService');
        $config = $serviceA->getConfig();
        $parameter = $serviceA->getParameter();
        $escaped = $serviceA->getEscaped();
        $this->assertTrue(is_array($config));
        $this->assertTrue('phpunit' === $config['name']);
        $this->assertTrue('abc' === $parameter);
        $this->assertTrue('%escaped' === $escaped);
    }

    public function testCalls () {
        $serviceB = $this->container->get('bService');
        $service = $serviceB->getService();
        $parameter = $serviceB->getParameter();
        $this->assertTrue('Test\ServiceA' === get_class($service));
        $this->assertTrue('abc' === $parameter);
    }
}