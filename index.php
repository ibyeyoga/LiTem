<?php
require('src\LiTem.php');
$scarL = new \IBye\litem\LiTem([
    'mode' => 'dev'
]);
$scarL->addFunction('sayHello',function($name){
    return 'Hello  ' . $name;
});
$scarL->addReplacements([
    'parameterA' => 'replace from addParameters'
]);
$scarL->run();