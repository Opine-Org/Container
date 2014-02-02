<?php
namespace Test;

require __DIR__ . '/../src/Container.php';
use Container\Container;

class One {
    public $first;
    public $second;
    public $third;

    public function __construct ($first, $second) {
        $this->first = $first;
        $this->second = $second;
    }

    public function third ($third) {
        $this->third = $third;
    }

    public function show () {
        echo $this->first, "\n", $this->second, "\n", $this->third, "\n";
    }
}

class Two {
    private $one;

    public function __construct (\Test\One $one) {
        $this->one = $one;
    }

    public function show () {
        echo strtoupper($this->one->first), "\n", strtoupper($this->one->second), "\n", strtoupper($this->one->third), "\n";
    }
}

class Three {
    private $one;

    public function __construct (\Test\One $one) {
        $this->one = $one;
    }

    public function show () {
        echo 'X', $this->one->first, "\n", 'X', $this->one->second, "\n", 'X', $this->one->third, "\n";
    }
}

$container = new Container(__DIR__ . '/test.yml');
$one = $container->one;
$one->show();
$two = $container->two;
$two->show();
$three = $container->three;
$three->show();