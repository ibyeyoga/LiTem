<?php
header('Content-Type:application/json');
echo json_encode([
    'nickname' => '测试例子',
    'age' => 18
]);