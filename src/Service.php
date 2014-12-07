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
    private $configService = false;

    public static function instance ($root=false, $configService=false, $fallback=false, $nocache=false) {
        static $container = null;
        if ($container === null) {
            if ($root === false) {
                throw new Exception('Can not get container instance without passing root and config service');
            }
            $container = new Service($root, $configService, $fallback, $nocache);
        }
        return $container;
    }

    private function __construct ($root, ConfigInterface $configService, $fallback, $nocache) {
        $this->root = $root;
        $this->configService = $configService;
        $this->set('config', $configService);
        $this->set('container', $this);
        $config = false;
        if ($nocache !== true) {
            if (is_array($nocache)) {
                $config = $nocache;
            }
            $path = $root . '/../var/cache/container.json';
            if ($config == false && file_exists($path)) {
                $config = file_get_contents($path);
                if ($config == false && $fallback === false) {
                    return;
                }
                $config = (array)json_decode($config, true);
            }
            $this->processConfig($config, dirname($path));
        }
        if ($config == false && $fallback !== false) {
            $this->readFile($fallback);
            $this->bundles();
        }
    }

    private function bundles () {
        $bundleService = new BundleModel($this->root, $this);
        $bundles = $bundleService->bundles();
        if (!is_array($bundles) || count($bundles) == 0) {
            return;
        }
        foreach ($bundles as $bundleName => $bundle) {
            $containerFile = $bundle['root'] . '/../config/container.yml';
            if (!file_exists($containerFile)) {
                continue;
            }
            $this->readFile($containerFile);
        }
    }

    private function yaml ($containerFile) {
        if (!file_exists($containerFile)) {
            return ['services' => [], 'imports' => []];
        }
        if (function_exists('yaml_parse_file')) {
            return yaml_parse_file($containerFile);
        }
        return Yaml::parse(file_get_contents($containerFile));
    }

    private function readFile ($containerConfig) {
        if (!file_exists($containerConfig)) {
            throw new Exception ('Container file not found: ' . $containerConfig);
        }
        $config = $this->yaml($containerConfig);
        if ($config == false) {
            throw new Exception('Can not parse YAML file: ' . $containerConfig);
        }
        $this->processConfig($config, dirname($containerConfig));
    }

    private function processConfig ($config, $dirname) {
        if (!isset($this->parameters['root'])) {
            $this->parameters['root'] = $this->root;
        }
        if (isset($config['imports']) && is_array($config['imports'])) {
            foreach ($config['imports'] as $import) {
                $first = substr($import, 0, 1);
                if ($first != '/') {
                    $import = $dirname . '/' . $import;
                }
                $this->readFile($import);
            }
        }
        if (isset($config['parameters']) && is_array($config['parameters'])) {
            foreach ($config['parameters'] as $parameterName => $parameter) {
                $this->parameters[$parameterName] = $parameter;
            }
        }
        if (isset($config['services']) && is_array($config['services'])) {
            foreach ($config['services'] as $serviceName => $service) {
                if (!isset($service['class'])) {
                    throw new Exception('Service ' . $serviceName . ' does not specify a class');
                }
                if (is_array($service['class'])) {
                    throw new Exception ('Class can not be array, near: ' . print_r($service['class'], true));
                }
                $first = substr($service['class'], 0, 1);
                if ($first == '%') {
                    $service['class'] = substr($service['class'], 1, -1);
                    if (!isset($this->parameters[$service['class']])) {
                        throw new Exception('Variable service class not defined as parameter: ' . $serviceName . ': ' . $service['class']);
                    }
                    $service['class'] = $this->parameters[$service['class']];
                }
                $this->services[$serviceName] = $service;
            }
        }
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

    public function get ($serviceName) {
        if (!isset($this->services[$serviceName])) {
            return false;
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
                    $arguments = $this->arguments($serviceName, $service['arguments'], 'construct');
                }
                $rc = new ReflectionClass($service['class']);
                self::$instances[$serviceName] = $rc->newInstanceArgs($arguments);
                $this->calls($serviceName, $service, self::$instances[$serviceName]);
            }
            return self::$instances[$serviceName];
        } elseif ($scope == 'prototype') {
            if (isset($service['arguments'])) {
                $arguments = $this->arguments($serviceName, $service['arguments'], 'construct');
            }
            try {
                $rc = new ReflectionClass($service['class']);
                $serviceInstance = $rc->newInstanceArgs($arguments);
            } catch (Exception $e) {
                $serviceInstance = false;
            }
        } else {
            throw new Exception('Unknown container scope: ' . $scope);
        }
        $this->calls($serviceName, $service, $serviceInstance);
        return $serviceInstance;
    }

    private function calls ($serviceName, $service, $serviceInstance) {
        if (!isset($service['calls']) || !is_array($service['calls'])) {
            return;
        }
        foreach ($service['calls'] as $call) {
            if (!is_array($call) || empty($call)) {
                throw new Exception('Invalid Service Call for: ' . $serviceName);
            }
            $arguments = [];
            if (isset($call[1]) && is_array($call[1])) {
                $arguments = $this->arguments($serviceName, $call[1]);
            }
            call_user_func_array([$serviceInstance, $call[0]], $arguments);
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

    private function argument ($serviceName, $argument) {
        if (substr($argument, 0, 7) == 'config.') {
            if ($this->configService === false) {
                throw new Exception('For service container to inject configuration, configuration object must be set.');
            }
            $argument = substr($argument, 7);
            return $this->configService->get($argument);
        }
        $first = substr($argument, 0, 1);
        $optional = false;
        switch ($first) {
            case '%':
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
                if (!isset($this->parameters[$parameter])) {
                    if ($optional) {
                        return null;
                    } else {
                        throw new Exception($serviceName . ' requires parameter ' . $parameter . ', not set');
                    }
                }
                return $this->parameters[$parameter];

            case '@':
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
                if (!isset($this->services[$argService])) {
                    if ($optional) {
                        return null;
                    } else {
                        throw new Exception('Service: ' . $argService . ' not defined in container');
                    }
                }
                return $this->get($argService);

            default:
                return $argument;
        }
    }

    public function show () {
        return [
            'parameters' => $this->parameters,
            'services'   => array_keys($this->services)
        ];
    }
}