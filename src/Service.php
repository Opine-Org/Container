<?php
/**
 * Opine\Container\Service
 *
 * Copyright (c)2013, 2014 Ryan Mahoney, https://github.com/Opine-Org <ryan@virtuecenter.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
namespace Opine\Container;
use Symfony\Component\Yaml\Yaml;
use ReflectionClass;
use Exception;
use Opine\Bundle\Model as BundleModel;
use Opine\Interfaces\Config as ConfigInterface;
use Opine\Interfaces\Container as ContainerInterface;

final class Service implements ContainerInterface {
    private $services = [];
    private $parameters = [];
    private $root;
    private static $instances = [];
    private $configService;

    public static function instance ($root, ConfigInterface $configService, $fallbackContainerPath=null, $fileCache=true) {
        if (empty($root)) {
            throw new Exception('Can not get container instance without passing root and config service');
        }
        if (!is_bool($fileCache)) {
            throw new Exception('Cache flag must be a boolean value');
        }
        static $container = null;
        if ($container != null) {
            return $container;
        }
        return new Service($root, $configService, $fallbackContainerPath, $fileCache);
    }

    private function __construct ($root, ConfigInterface $configService, $fallbackContainerPath, $fileCache) {
        $this->root = $root;
        $this->configService = $configService;
        $this->set('config', $configService);
        $this->set('container', $this);
        $status = $this->fileCache($root, $fileCache);
        $this->bootstrap($status, $fallbackContainerPath);
    }

    private function bootstrap ($status, $fallbackContainerPath) {
        if ($status === true) {
            return;
        }
        if (empty($fallbackContainerPath)) {
            throw new Exception('can not bootstrap container without fallback container path');
        }
        $this->readFile($fallbackContainerPath);
        $this->bundles();
    }

    private function fileCache ($root, $fileCache) {
        if ($fileCache !== true) {
            return null;
        }
        $path = $root . '/../var/cache/container.json';
        if (!file_exists($path)) {
            return null;
        }
        $containerConfig = file_get_contents($path);
        if ($containerConfig === false) {
            return null;
        }
        $containerConfig = json_decode($containerConfig, true);
        $this->processConfig($containerConfig, dirname($path));
        return true;
    }

    private function bundles () {
        $bundleService = new BundleModel($this->root, $this);
        $bundles = $bundleService->bundles();
        if (!is_array($bundles) || count($bundles) == 0) {
            return;
        }
        foreach ($bundles as $bundleName => $bundle) {
            $this->processBundleContainer($bundle);
        }
    }

    private function processBundleContainer (&$bundle) {
        $containerFile = $bundle['root'] . '/../config/container.yml';
        if (!file_exists($containerFile)) {
            return;
        }
        $this->readFile($containerFile);
    }

    private function yaml ($containerFile) {
        if (function_exists('yaml_parse_file')) {
            return yaml_parse_file($containerFile);
        }
        return Yaml::parse(file_get_contents($containerFile));
    }

    private function readFile ($containerPath) {
        if (!file_exists($containerPath)) {
            throw new Exception ('Container file not found: ' . $containerPath);
        }
        $containerConfig = $this->yaml($containerPath);
        if ($containerConfig === false) {
            throw new Exception('Can not parse YAML file: ' . $containerPath);
        }
        $this->processConfig($containerConfig, dirname($containerPath));
    }

    private function processImports ($containerConfig, $dirname) {
        if (!isset($containerConfig['imports']) || !is_array($containerConfig['imports'])) {
            return;
        }
        foreach ($containerConfig['imports'] as $import) {
            $first = substr($import, 0, 1);
            if ($first != '/') {
                $import = $dirname . '/' . $import;
            }
            $this->readFile($import);
        }
    }

    private function processParameters ($containerConfig) {
        if (!isset($containerConfig['parameters']) || !is_array($containerConfig['parameters'])) {
            return;
        }
        foreach ($containerConfig['parameters'] as $parameterName => $parameter) {
            $this->parameters[$parameterName] = $parameter;
        }
    }

    private function processServices ($containerConfig) {
        if (!isset($containerConfig['services']) || !is_array($containerConfig['services'])) {
            return;
        }
        foreach ($containerConfig['services'] as $serviceName => $service) {
            if (!isset($service['class'])) {
                throw new Exception('Service ' . $serviceName . ' does not specify a class');
            }
            if (is_array($service['class'])) {
                throw new Exception ('Class can not be array, near: ' . print_r($service['class'], true));
            }
            $this->processVariableService($service);
            $this->services[$serviceName] = $service;
        }
    }

    private function processVariableService (&$service) {
        $first = substr($service['class'], 0, 1);
        if ($first != '%') {
            return;
        }
        $class = substr($service['class'], 1, -1);
        if (!isset($this->parameters[$class])) {
            throw new Exception('Variable service class not defined as parameter: ' . $serviceName . ': ' . $class);
        }
        $service['class'] = $this->parameters[$class];
    }

    private function processConfig ($containerConfig, $dirname) {
        if (!isset($this->parameters['root'])) {
            $this->parameters['root'] = $this->root;
        }
        $this->processImports($containerConfig, $dirname);
        $this->processParameters($containerConfig);
        $this->processServices($containerConfig);
    }

    public function set ($serviceName, $value, $scope='container', Array $arguments=[], Array $calls=[]) {
        if ($value === null) {
            unset(self::$instances[$serviceName]);
            return;
        }
        self::$instances[$serviceName] = $value;
        $this->services[$serviceName] = [
            'scope'     => $scope,
            'arguments' => $arguments,
            'calls'     => $calls
        ];
    }

    private function getServiceArguments ($serviceName, $service) {
        if (!isset($service['arguments'])) {
            return [];
        }
        return $this->arguments($serviceName, $service['arguments'], 'construct');
    }

    private function getFromContainer ($serviceName, $service) {
        if (isset($service['scope']) && $service['scope'] == 'prototype') {
            return null;
        }
        if (isset(self::$instances[$serviceName])) {
            return self::$instances[$serviceName];
        }
        $arguments = $this->getServiceArguments($serviceName, $service);
        $rc = new ReflectionClass($service['class']);
        self::$instances[$serviceName] = $rc->newInstanceArgs($arguments);
        $this->calls($serviceName, $service, self::$instances[$serviceName]);
        return self::$instances[$serviceName];
    }

    private function getFromPrototype ($serviceName, $service) {
        if ($service['scope'] != 'prototype') {
            return null;
        }
        $arguments = $this->getServiceArguments($serviceName, $service);
        $rc = new ReflectionClass($service['class']);
        return $rc->newInstanceArgs($arguments);
    }

    public function get ($serviceName) {
        if (!isset($this->services[$serviceName])) {
            return false;
        }
        $service = $this->services[$serviceName];
        $scope = 'container';
        if (isset($service['scope'])) {
            $scope = $service['scope'];
        }
        $serviceInstance = $this->getFromContainer($serviceName, $service);
        if ($serviceInstance != null) {
            return $serviceInstance;
        }
        $serviceInstance = $this->getFromPrototype($serviceName, $service);
        if ($serviceInstance != null) {
            $this->calls($serviceName, $service, $serviceInstance);
        }
        return $serviceInstance;
    }

    private function processCall ($serviceName, $service, $serviceInstance, $call) {
        if (!is_array($call) || empty($call)) {
            throw new Exception('Invalid Service Call for: ' . $serviceName);
        }
        $arguments = [];
        if (isset($call[1]) && is_array($call[1])) {
            $arguments = $this->arguments($serviceName, $call[1]);
        }
        call_user_func_array([$serviceInstance, $call[0]], $arguments);
    }

    private function calls ($serviceName, $service, $serviceInstance) {
        if (!isset($service['calls']) || !is_array($service['calls'])) {
            return;
        }
        foreach ($service['calls'] as $call) {
            $this->processCall($serviceName, $service, $serviceInstance, $call);
        }
    }

    private function arguments ($serviceName, &$arguments) {
        if (!is_array($arguments)) {
            return [];
        }
        $argumentsOut = [];
        foreach ($arguments as $argument) {
            $argumentsOut[] = $this->argument($serviceName, $argument);
        }
        return $argumentsOut;
    }

    private function argumentConfig ($serviceName, $argument) {
        if (substr($argument, 0, 7) != 'config.') {
            return false;
        }
        if (empty($this->configService)) {
            throw new Exception('For service container to inject configuration, configuration object must be set.');
        }
        $argument = substr($argument, 7);
        return $this->configService->get($argument);
    }

    private function argumentParameter ($serviceName, $argument) {
        $first = substr($argument, 0, 1);
        $optional = false;
        if ($first != '%') {
            return false;
        }
        $escape = substr($argument, 1, 1);
        if ($escape == '%') {
            return substr($argument, 1);
        }
        $parameter = substr($argument, 1, -1);
        $optional = substr($parameter, 0, 1);
        if ($optional == '?') {
            $optional = true;
            $parameter = substr($argument, 1);
        }
        if (isset($this->parameters[$parameter])) {
            return $this->parameters[$parameter];
        }
        if ($optional) {
            return null;
        } else {
            throw new Exception($serviceName . ' requires parameter ' . $parameter . ', not set');
        }
    }

    private function argumentService ($serviceName, $argument) {
        $first = substr($argument, 0, 1);
        $optional = false;
        if ($first != '@') {
            return false;
        }
        $argService = substr($argument, 1);
        $escape = substr($argService, 0, 1);
        if ($escape == '@') {
            return $argService;
        }
        $optional = substr($argService, 0, 1);
        if ($optional == '?') {
            $optional = true;
            $argService = substr($argService, 1);
        }
        if ($serviceName == $argService) {
            throw new Exception('Circular reference to self, ' . $serviceName . ' references ' . $serviceName);
        }
        if (isset($this->services[$argService])) {
            return $this->get($argService);
        }
        if ($optional) {
            return null;
        } else {
            throw new Exception('Service: ' . $argService . ' not defined in container');
        }
    }

    private function argument ($serviceName, $argument) {
        $arg = $this->argumentConfig($serviceName, $argument);
        if ($arg !== false) {
            return $arg;
        }
        $arg = $this->argumentParameter($serviceName, $argument);
        if ($arg !== false) {
            return $arg;
        }
        $arg = $this->argumentService($serviceName, $argument);
        if ($arg !== false) {
            return $arg;
        }
        return $argument;
    }

    public function show () {
        return [
            'parameters' => $this->parameters,
            'services'   => array_keys($this->services)
        ];
    }
}