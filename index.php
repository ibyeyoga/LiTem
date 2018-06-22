<?php
require('src\LiTem.php');
$liTem = new \IBye\litem\LiTem([
    'mode' => 'dev'
]);
$liTem->addFunction('sayHello',function($name){
    return 'Hello  ' . $name;
});
$liTem->addReplacements([
    'parameterA' => 'replace from addParameters'
]);
//$liTem->run();

$a=file_get_contents('html/demo/kv.txt');