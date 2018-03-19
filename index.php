<?php
require('ScarL.php');
$scarL = new \IBye\ScarL([
    'dev'=>true
]);
$scarL->addFunction('sayHello',function($name){
    return 'Hello  ' . $name;
});
$scarL->addParameters([
    'parameterA' => 'replace from addParameters'
]);
$scarL->run();
