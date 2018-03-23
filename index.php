<?php
require('src\LiTem.php');
$scarL = new \iBye\LiTem([
    'mode' => 'dev'
]);
$scarL->addFunction('sayHello',function($name){
    return 'Hello  ' . $name;
});
$scarL->addReplacements([
    'parameterA' => 'replace from addParameters'
]);
$scarL->run();