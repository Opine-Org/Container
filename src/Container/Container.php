<?php
namespace Container;

class Container {
	private $services = [];
	private $parameters = [];
	private static $instances = [];

	public function __construct ($root, $name='/container.yml') {
		$containerConfig = $root . $name;
		if (!file_exists($containerConfig)) {
			throw new \Exception ('Container file not found: ' . $containerConfig);
		}
		if (!function_exists('yaml_parse')) {
			throw new \Exception('PHP must be compiled with YAML PECL extension');
		}
		$config = yaml_parse_file($containerConfig);
		if ($config == false) {
			throw new \Exception('Can not parse YAML file: ' . $containerConfig);
		}
		$this->parameters['root'] = $root;
		if (isset($config['parameters']) && is_array($config['parameters'])) {
			foreach ($config['parameters'] as $parameterName => $parameter) {
				$this->parameters[$parameterName] = $parameter;
			}
		}
		if (isset($config['services']) && is_array($config['services'])) {
			foreach ($config['services'] as $serviceName => $service) {
				if (!isset($service['class'])) {
					throw new \Exception('Service ' . $serviceName . ' does not specift a class');
				}
				if (is_array($service['class'])) {
					throw new \Exception ('Class can not be array, near: ' . print_r($service['class'], true));
				}
				$first = substr($service['class'], 0, 1);
				if ($first == '%') {
					$service['class'] = substr($service['class'], 1, -1);
					if (!isset($this->parameters[$service['class']])) {
						throw new \Exception('Variable service class not defined as parameter: ' . $serviceName . ': ' . $service['class']);
					}
					$service['class'] = $this->parameters[$service['class']];
				}
				$this->services[$serviceName] = $service;
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
				$rc = new \ReflectionClass($service['class']);
				self::$instances[$serviceName] = $rc->newInstanceArgs($arguments);
				$this->_calls($serviceName, $service, self::$instances[$serviceName]);		
			}
			return self::$instances[$serviceName];
		} elseif ($scope == 'prototype') {
			if (isset($service['arguments'])) {
				$arguments = $this->_arguments($serviceName, $service['arguments'], 'construct');
			}
			$rc = new \ReflectionClass($service['class']);
			$serviceInstance = $rc->newInstanceArgs($arguments);
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