<?php
/**
 * Opine\Container
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
namespace Opine;
use Symfony\Component\Yaml\Yaml;

class Container {
    public $services = [];
    public $parameters = [];

    private static $instances = [];

    public function __construct ($root, $containerConfig, $parent=false) {
        if ($parent === false) {
            $parent = $this;
        }
        if (!file_exists($containerConfig)) {
            throw new \Exception ('Container file not found: ' . $containerConfig);
        }
        if (function_exists('yaml_parse_file')) {
            $config = yaml_parse_file($containerConfig);
        } else {
            $config = Yaml::parse($containerConfig);
        }
        if ($config == false) {
            throw new \Exception('Can not parse YAML file: ' . $containerConfig);
        }
        if (!isset($parent->parameters['root'])) {
            $parent->parameters['root'] = $root;
        }
        if (isset($config['imports']) && is_array($config['imports'])) {
            foreach ($config['imports'] as $import) {
                $first = substr($import, 0, 1);
                if ($first != '/') {
                    $import = $parent->parameters['root'] . '/../' . $import; 
                }
                $containerImports = new Container($root, $import, $parent);
            }
        }
        if (isset($config['parameters']) && is_array($config['parameters'])) {
            foreach ($config['parameters'] as $parameterName => $parameter) {
                $parent->parameters[$parameterName] = $parameter;
            }
        }
        if (isset($config['services']) && is_array($config['services'])) {
            foreach ($config['services'] as $serviceName => $service) {
                if (!isset($service['class'])) {
                    throw new \Exception('Service ' . $serviceName . ' does not specify a class');
                }
                if (is_array($service['class'])) {
                    throw new \Exception ('Class can not be array, near: ' . print_r($service['class'], true));
                }
                $first = substr($service['class'], 0, 1);
                if ($first == '%') {
                    $service['class'] = substr($service['class'], 1, -1);
                    if (!isset($parent->parameters[$service['class']])) {
                        throw new \Exception('Variable service class not defined as parameter: ' . $serviceName . ': ' . $service['class']);
                    }
                    $service['class'] = $parent->parameters[$service['class']];
                }
                $parent->services[$serviceName] = $service;
            }
        }
    }

    public function __get ($serviceName) {
        if ($serviceName == 'container') {
            return $this;
        }
        if (!isset($this->services[$serviceName])) {
            throw new \Exception ('Unknown service: ' . $serviceName);
        }
        $service = $this->services[$serviceName];
        $scope = 'container';
        if (isset($service['scope'])) {
            $scope = $service['scope'];
        }
        $arguments = [];
        if ($scope == 'container') {
            if (!isset(self::$instances[$serviceName])) {
                if (isset($service['arguments'])) {
                    $arguments = $this->_arguments($serviceName, $service['arguments'], 'construct');
                }
                //try {
                    $rc = new \ReflectionClass($service['class']);
                    self::$instances[$serviceName] = $rc->newInstanceArgs($arguments);
                    $this->_calls($serviceName, $service, self::$instances[$serviceName]);
                //} catch (\Exception $e) {
                //  self::$instances[$serviceName] = false;
                //  return;
                //}
            }
            return self::$instances[$serviceName];
        } elseif ($scope == 'prototype') {
            if (isset($service['arguments'])) {
                $arguments = $this->_arguments($serviceName, $service['arguments'], 'construct');
            }
            try {
                $rc = new \ReflectionClass($service['class']);
                $serviceInstance = $rc->newInstanceArgs($arguments);
            } catch (\Exception $e) {
                $serviceInstance = false;
            }
        } else {
            throw new \Exception('Unknown container scope: ' . $scope);
        }
        $this->_calls($serviceName, $service, $serviceInstance);
        return $serviceInstance;
    }

    private function _calls ($serviceName, $service, $serviceInstance) {
        if (!isset($service['calls']) || !is_array($service['calls'])) {
            return;
        }
        foreach ($service['calls'] as $call) {
            if (!is_array($call) || empty($call)) {
                throw new \Exception('Invalid Service Call for: ' . $serviceName);
            }
            $arguments = [];
            if (isset($call[1]) && is_array($call[1])) {
                $arguments = $this->_arguments($serviceName, $call[1], 'call');
            }
            call_user_func_array([$serviceInstance, $call[0]], $arguments);
        }
    }

    private function _arguments ($serviceName, &$arguments, $type) {
        if (!is_array($arguments)) {
            return [];
        }
        $argumentsOut = [];
        foreach ($arguments as $argument) {
            $argumentsOut[] = $this->_argument($serviceName, $argument, $type);
        }
        return $argumentsOut;
    }

    private function _argument ($serviceName, $argument, $type) {
        $first = substr($argument, 0, 1);
        $second = substr($argument, 1, 1);
        switch ($first) {
            case '%':
                $parameter = substr($argument, 1, -1);
                if (!isset($this->parameters[$parameter])) {
                    if ($second == '?') { 
                        $arguments[] = null;
                        break;
                    }
                    throw new \Excpetion($serviceName . ' ' . $type . ' requires parameter ' . $parameter . ', not set');
                }
                return $this->_argument($serviceName, $this->parameters[$parameter], $type);
                break;

            case '@':
                $argService = substr($argument, 1);
                if ($serviceName == $argService) {
                    throw new \Exception('Circular reference, ' . $serviceName . ' references ' . $serviceName);
                }
                return $this->__get($argService);
                break;

            default:
                return $argument;
        }
    }

    public function _show () {
        print_r($this->parameters);
        print_r($this->services);
    }
}