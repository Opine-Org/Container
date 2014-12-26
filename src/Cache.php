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
use Exception;

class Cache
{
    private $bundleService;
    private $root;
    private $containerConfig;
    private $cacheKey;
    private $cacheFile;

    public function __construct($root, $bundleService)
    {
        $this->root = $root;
        $this->bundleService = $bundleService;
        $this->cacheFile = $root.'/../var/cache/container.json';
        $this->containerConfig = [
            'imports' => [],
            'services' => [],
            'parameters' => [],
        ];
    }

    public function read($containerFile)
    {
        $this->merge($containerFile);
        $bundles = $this->bundleService->bundles();
        if (!is_array($bundles) || count($bundles) == 0) {
            return;
        }
        foreach ($bundles as $bundleName => $bundle) {
            $containerFile = $bundle['root'].'/../config/containers/package-container.yml';
            if (!file_exists($containerFile)) {
                echo 'No container in bundle: ', $containerFile, "\n";
                continue;
            }
            $this->merge($containerFile);
        }
    }

    private function merge($containerFile)
    {
        $config = $this->yaml($containerFile);
        if (isset($config['imports']) && is_array($config['imports'])) {
            foreach ($config['imports'] as $path) {
                $first = substr($path, 0, 1);
                if ($first != '/') {
                    $path = dirname($containerFile).'/'.$path;
                }
                $this->merge($path);
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

    public function show()
    {
        var_dump($this->containerConfig);
    }

    public function write()
    {
        $json = json_encode($this->containerConfig, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json);

        return $json;
    }

    public function clear()
    {
        if (!file_exists($this->cacheFile)) {
            return;
        }
        unlink($this->cacheFile);
    }

    private function yaml($containerFile)
    {
        if (!file_exists($containerFile)) {
            throw new Exception('Container file not found: '.$containerFile);
        }
        return Yaml::parse(file_get_contents($containerFile));
    }
}
