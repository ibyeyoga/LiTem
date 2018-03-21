<?php
require('src\ScarL.php');
$scarL = new \iBye\ScarL([
    'dev'=>true
]);
$scarL->addFunction('sayHello',function($name){
    return 'Hello  ' . $name;
});
$scarL->addParameters([
    'parameterA' => 'replace from addParameters'
]);
$scarL->run();
