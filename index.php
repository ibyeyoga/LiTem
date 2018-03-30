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
//$liTem->run();

$a=file_get_contents('html/demo/kv.txt');

//$a=preg_replace('/\n|\r\n/','bj',$a);
//$array = preg_split("/\n|\r\n/",$a);

$keyValueList = $liTem->handleKeyValueString($a);
echo '<pre>';
var_dump($keyValueList);
echo '</pre>';