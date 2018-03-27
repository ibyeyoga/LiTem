<?php

namespace iBye;
/**
 * http://litem.ibye.cn
 * Class LiTem version 1.2.0
 * @package IBye
 */

class LiTem
{
    //默认配置项
    private $config = [
        'mode' => 'prod',
        'isShowError' => false,
        'htmlPath' => '',
        'routeSeparator' => '/',
        'routeKey' => 'r',
        'replacements' => [],
        'apiReplacements' => [],
        'allowExtList' => [
            '.html',
            '.htm',
            '.shtml'
        ]
    ];

    //自定义函数容器
    private $functions = [];

    private $jsVars = [];

    private $jsBlocks = [];

    public function __construct($config = [])
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
        if (!empty($config['htmlPath'])) {
            $this->htmlPath = $config['htmlPath'];
            $this->htmlPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->htmlPath);
            $tempChar = substr($this->htmlPath, -1);
            if ($tempChar != DIRECTORY_SEPARATOR) {
                $this->htmlPath .= DIRECTORY_SEPARATOR;
            }
        } else {
            $this->htmlPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR;
        }
    }

    /**
     * 激活配置项
     */
    private function activeConfig()
    {
        if ($this->mode == 'dev') {
            $this->isShowError = true;
        }
    }

    /**
     * Runner
     */
    public function run()
    {
        if (empty($_GET[$this->routeKey])) {
            $this->showErrorMsg($this->isShowError ? 'running wrong' : '');
        } else {
            $routeStr = $_GET[$this->routeKey];
            $replaceStr = str_replace($this->routeSeparator, DIRECTORY_SEPARATOR, $routeStr);
            $parentDir = dirname($routeStr);
            $basename = basename($routeStr);
            $this->findConfigFile($this->htmlPath . $parentDir, $basename);
            $this->activeConfig();
            $filePath = $this->htmlPath . $replaceStr;
            $this->dispatch($filePath);
        }
    }

    /**
     * 查找配置文件
     * @param null $dir
     */
    private function findConfigFile($dir = null, $pagename)
    {
        $filePath = $dir . DIRECTORY_SEPARATOR . 'litem.json';
        if (file_exists($filePath)) {
            $file = fopen($filePath, 'r');
            $fileContent = @fread($file, filesize($filePath));
            fclose($file);
            $jsonArray = json_decode($fileContent, true);
            if (!empty($jsonArray)) {
                if (!empty($jsonArray['local'][$pagename])) {
                    $localConfig = $jsonArray['local'][$pagename];
                    unset($jsonArray['local']);
                    $jsonArray = array_merge_recursive($jsonArray, $this->transformConfig($localConfig));
                }
                $this->config = array_merge_recursive($this->config, $this->transformConfig($jsonArray));
            }
        }
    }

    /**
     * 分发器
     * @param $filePath
     */
    private function dispatch($filePath)
    {
        //识别文件扩展名，只能访问白名单里的文件类型
        $filePath = $this->getAllowExtFileName($filePath);
        if (file_exists($filePath)) {
            $page = file_get_contents($filePath);
            echo $this->render($page);
            exit;
        } else if ($this->isShowError) {
            $this->showErrorMsg('File not found or not valid format');
        }
    }

    /**
     * 渲染器
     * @param $page
     * @return mixed
     */
    private function render($page)
    {
        if (!empty($_GET['options'])) {
            $options = $this->handleOptions($_GET['options']);
            $this->config['replacements']['options'] = $options;
        }
        $this->handleApiReplacements();
        $page = $this->replaceReplacements($page);
        $page = $this->handleFunction($page);
        $page = $this->handleJs($page);

        return $page;
    }

    /**
     * 替换值
     * @param $page
     * @return mixed
     */
    private function replaceReplacements($page)
    {
        $keyValueList = $this->createKeyValueList('', $this->replacements, function ($value) {
            return is_array($value);
        });
        $keyList = [];
        $valueList = [];

        foreach ($keyValueList as $key => $value) {
            $keyList[] = $key;
            $valueList[] = $value;
        }

        return str_replace($keyList, $valueList, $page);
    }

    private function handleApiReplacements()
    {
        $keyValueList = $this->createKeyValueList('', $this->apiReplacements, function ($value) {
            return is_array($value) && !isset($value['url']);
        }, true);

        $mh = curl_multi_init();
        $chs = [];

        $jsBlock = '';
        foreach ($keyValueList as $key => $value) {
            if (isset($value['type']) && $value['type'] == 'js') {
                //js调用
                $key = str_replace('.', '_', $key);
                $this->addJsVar($key);
                $jsBlock .= "
                axios.get('$value[url]').then(function (response) {
                    if(response.data){
                        _$$key = response.data;
                    }
                })
                .catch(function (error) {
                console.log(error);
                });
                ";
            } else {
                //php调用
                $ch = curl_init($value['url']);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_multi_add_handle($mh, $ch);
                $chs[$key] = $ch;
            }
        }

        if(!empty($jsBlock)){
            $this->addJsBlock($jsBlock);
        }

        if(!empty($chs)){
            $running = 0;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($chs as $key => $ch) {
                $val = (string)curl_multi_getcontent($ch);
                $this->addReplacement($key, $val);
                curl_multi_remove_handle($mh, $ch);
            }
        }
    }

    private function createKeyValueList($parentKey, $array, $condition, $isOriginKey = false)
    {
        $keyValueList = [];

        foreach ($array as $key => $value) {
            if ($condition($value)) {
                $keyValueList = array_merge($keyValueList, $this->createKeyValueList($this->createRelationKey($parentKey, $key), $value, $condition, $isOriginKey));
                continue;
            }
            $keyValueList[$this->createReplaceKey($this->createRelationKey($parentKey, $key), $isOriginKey)] = $value;
        }

        return $keyValueList;
    }

    private function createReplaceKey($val, $doNothing = false)
    {
        if ($doNothing) return $val;
        return '{$' . $val . '}';
    }

    private function handleFunction($page)
    {
        foreach ($this->functions as $functionName => $function) {
            $regex = '/{:' . $functionName . '\((?P<replacements>.+)\)}/';
            $matches = [];
            if (preg_match($regex, $page, $matches)) {
                $allMatch = $matches[0];
                $replacements = explode(',', $matches['replacements']);
                $executionResult = call_user_func_array($function, $replacements);
                $page = str_replace($allMatch, $executionResult, $page);
            }
        }

        return $page;
    }

    private function handleJs($page){
        if(!empty($this->jsVars)){
            $nullJsVars = '';
            $notNullJsVars = '';
            foreach($this->jsVars as $key => $value){
                if($value === null){
                    if($nullJsVars == ''){
                        $nullJsVars .= "var _$$key";
                    }
                    else{
                        $nullJsVars .= ",_$$key";
                    }
                }
                else{
                    $realValue = null;
                    if(is_string($value)){
                        $realValue = "'$value'";
                    }
                    else if(is_array($value)){
                        $realValue = json_encode($value);
                    }
                    else{
                        $realValue = $value;
                    }
                    if($notNullJsVars == ''){
                        $notNullJsVars .= "var _$$key = $realValue";
                    }
                    else{
                        $notNullJsVars .= ",_$$key = $realValue";
                    }
                }
            }
            $nullJsVars .= ';';
            $notNullJsVars .= ';';
            $this->addFrontJsBlock($nullJsVars.$notNullJsVars);
        }

        if(!empty($this->jsBlocks)){
            $pattern = '/(<\s*\/body\s*>)()/i';
            $blocks = '<script src="https://unpkg.com/axios/dist/axios.min.js"></script>';

            foreach($this->jsBlocks as $jsBlock){
                $blocks .= "<script>$jsBlock</script>";
            }

            $page = preg_replace($pattern, '\1' . $blocks . '\2', $page);
        }

        return $page;
    }

    private function handleOptions($options)
    {
        $initOptions = explode('-', $options);
        $realOptions = [];
        foreach ($initOptions as $option) {
            $tmp = explode('=', $option);
            $realOptions[$tmp[0]] = $tmp[1];
        }
        return $realOptions;
    }

    private function getAllowExtFileName($filePath)
    {
        $allowExtList = $this->allowExtList;

        $realFileName = null;

        foreach ($allowExtList as $ae) {
            if (strpos($ae, '.') === false)
                $tmpFileName = $filePath . '.' . $ae;
            else
                $tmpFileName = $filePath . $ae;
            if (file_exists($tmpFileName)) {
                $realFileName = $tmpFileName;
                break;
            }
        }

        if (empty($realFileName) && $this->isShowError) {
            $this->showErrorMsg('File ' . $tmpFileName . ' not found or not valid format');
        }

        return $realFileName;
    }

    private function createRelationKey($parentKey, $childKey)
    {
        if ($parentKey == '') {
            return $childKey;
        }
        return $parentKey . '.' . $childKey;
    }

    private function showErrorMsg($msg)
    {
        echo $msg, '<br>';
        $array = debug_backtrace();
        echo 'file : ', $array[0]['file'], '<br>function : ', $array[1]['function'], '()<br>', 'line : ', $array[0]['line'];
        exit;
    }

    private function transformConfig($config)
    {
        $tmpConfig = [];
        foreach ($config as $key => $value) {
            $str = ucwords(str_replace('-', ' ', $key));
            $str = str_replace(' ', '', lcfirst($str));
            $tmpConfig[$str] = $value;
        }
        return $tmpConfig;
    }

    public function addFunction($functionName, \Closure $function)
    {
        $this->functions[$functionName] = $function;
    }

    public function addReplacement($key, $value)
    {
        $this->config['replacements'][$key] = $value;
    }

    public function addReplacements($array)
    {
        $this->config['replacements'] += $array;
    }

    public function addJsVar($varName, $value = null)
    {
        $this->jsVars[$varName] = $value;
    }

    public function addJsBlock($block){
        $this->jsBlocks[] = $block;
    }

    public function addFrontJsBlock($block){
        array_unshift($this->jsBlocks, $block);
    }

    /**
     * 重写
     * override
     */
    function __get($name)
    {
        // TODO: Implement __get() method.
        if (isset($this->$name)) {
            return $this->$name;
        }
        return $this->config[$name];
    }

//    function __set($name, $value)
//    {
//        // TODO: Implement __set() method.
//        if(isset($this->$name)){
//            $this->$name = $value;
//        }
//        else{
//            $this->config[$name] = $value;
//        }
//    }
}