<?php
require('src\LiTem.php');
$liTem = new \iBye\LiTem([
    'mode' => 'dev'
]);
$liTem->addFunction('sayHello',function($name){
    return 'Hello  ' . $name;
});
$liTem->addReplacements([
    'parameterA' => 'replace from addParameters'
]);
$liTem->run();