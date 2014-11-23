<?php
/**
 * Opine\Container\Cache
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

class Cache {
    private $bundleService;
    private $root;
    private $containerConfig = false;
    private $cacheKey;
    private $cacheFile;

    public function __construct ($root, $bundleService) {
        $this->root = $root;
        $this->bundleService = $bundleService;
        $this->cacheFile = $root . '/../cache/container.json';
    }

    public function read ($containerFile) {
        $this->containerConfig = $this->yaml($containerFile);
        $bundles = $this->bundleService->bundles();
        if (!is_array($bundles) || count($bundles) == 0) {
            return;
        }
        foreach ($bundles as $bundleName => $bundle) {
            $containerFile = $bundle['root'] . '/../container.yml';
            if (!file_exists($containerFile)) {
                echo 'No container in bundle: ', $containerFile, "\n";
                continue;
            }
            $this->merge($containerFile);
        }
    }

    private function merge ($containerFile) {
        $config = $this->yaml($containerFile);
        if (isset($config['imports']) && is_array($config['imports'])) {
            foreach ($config['imports'] as $path) {
                if (!in_array($path, $this->containerConfig['imports'])) {
                    $this->containerConfig['imports'][] = $path;
                }
            }
        }
        if (isset($config['services']) && is_array($config['services'])) {
            foreach ($config['services'] as $name => $service) {
                if (!array_key_exists($name, $this->containerConfig['services'])) {
                    $this->containerConfig['services'][$name] = $service;
                }
            }
        }
    }

    public function show () {
        var_dump($this->containerConfig);
    }

    private function unfold (&$config, $sub=false) {
        if ($sub === true) {
            if (isset($config['services']) && is_array($config['services'])) {
                foreach ($config['services'] as $name => $service) {
                    if (!array_key_exists($name, $this->containerConfig['services'])) {
                        $this->containerConfig['services'][$name] = $service;
                    }
                }
            }
        }
        if (isset($config['imports']) && is_array($config['imports'])) {
            while (count($config['imports']) > 0) {
                $import = $config['imports'][0];
                $first = substr($import, 0, 1);
                if ($first != '/') {
                    $import = $this->root . '/../' . $import;
                }
                unset($config['imports'][0]);
                sort($config['imports']);
                if (file_exists($import)) {
                    $subconfig = $this->yaml($import);
                    $this->unfold($subconfig, true);
                }
            }
        }
    }

    public function write () {
        $this->unfold($this->containerConfig);
        $json = json_encode($this->containerConfig, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json);
        return $json;
    }

    public function clear () {
        if (!file_exists($this->cacheFile)) {
            return;
        }
        unlink($this->cacheFile);
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
}