<?php

namespace IBye\litem;
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
        'autoLang' => true,
        'langDir' => 'langs',
        'usingCache' => true,
        'cacheTime' => 60,
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

    private $lang = '';

    private $cachePath = 'cache';

    private $dispatchInfo = [];

    private $functions = [];

    private $jsVars = [];

    private $jsBlocks = [];

    public function __construct($config = [])
    {
        if (!empty($config)) {
            $this->config = array_replace_recursive($this->config, $config);
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
        $this->cachePath = $this->htmlPath . $this->cachePath;
    }

    /**
     * 激活配置项
     */
    private function activeConfig()
    {
        if ($this->mode == 'dev') {
            $this->config['isShowError'] = true;
        }
        if($this->autoLang){
            $this->lang = $this->getPreferredLanguage();
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
            $parentDir = dirname($replaceStr);
            $basename = basename($replaceStr);
            $projectName = substr($routeStr, 0, strpos($routeStr, $this->routeSeparator));

            $this->findConfigFile($this->htmlPath . $parentDir, $basename);

            $this->activeConfig();

            if($this->usingCache){
                $cacheDirPath = $this->cachePath . DIRECTORY_SEPARATOR . $parentDir;
                if ($this->checkDir($cacheDirPath)) {
                    $cacheFilePath = $this->addCacheExt($cacheDirPath . DIRECTORY_SEPARATOR . $this->createHashString($this->lang . $basename));
                    $this->dispatchInfo['cacheFilePath'] = $cacheFilePath;
                    $mtime = @filemtime($cacheFilePath);
                    if($mtime !== false && time() - $mtime < $this->cacheTime){
                        $this->dispatchInfo['type'] = 'cache';
                        $this->dispatch();
                    }
                }
            }

            $filePath = $this->htmlPath . $replaceStr;
            $this->dispatchInfo['filePath'] = $filePath;
            $this->dispatchInfo['fileName'] = $basename;
            $this->dispatchInfo['projectName'] = $projectName;
            $this->dispatch();
        }
    }

    /**
     * 查找配置文件
     * @param null $dir
     * @param $pageName
     */
    private function findConfigFile($dir = null, $pageName)
    {
        $flag = true;
        while($flag){
            $filePath = $dir . DIRECTORY_SEPARATOR . 'litem.json';
            if (file_exists($filePath)) {
                $file = fopen($filePath, 'r');
                $fileContent = @fread($file, filesize($filePath));
                fclose($file);
                $jsonArray = json_decode($fileContent, true);
                if (!empty($jsonArray)) {
                    if (!empty($jsonArray['local'][$pageName])) {
                        $localConfig = $jsonArray['local'][$pageName];
                        unset($jsonArray['local']);
                        $jsonArray = array_replace_recursive($jsonArray, $this->transformConfig($localConfig));
                    }
                    $this->config = array_replace_recursive($this->config, $this->transformConfig($jsonArray));
                }
                $flag = false;
            }
            else{
                if(!strpos($this->htmlPath,$dir))
                    $dir = dirname($dir);
                else
                    $flag = false;
            }
        }

    }

    /**
     * 分发器
     */
    private function dispatch()
    {
        $isCacheFile = empty($this->dispatchInfo['type']) ? false : $this->dispatchInfo['type'] == 'cache';
        if ($isCacheFile){
            echo file_get_contents($this->dispatchInfo['cacheFilePath']);
            exit;
        }

        $filePath = $this->getAllowExtFileName($this->dispatchInfo['filePath']);
        if (file_exists($filePath)) {
            $page = file_get_contents($filePath);
            echo $this->render($page, !$isCacheFile && $this->usingCache);
            exit;
        } else if ($this->isShowError) {
            $this->showErrorMsg('File not found or not valid format');
        }

    }

    /**
     * 渲染器
     * @param $page
     * @param bool $needCache
     * @return mixed
     */
    private function render($page, $needCache = false)
    {
        if (!empty($_GET['options'])) {
            $options = $this->handleOptions($_GET['options']);
            $this->config['replacements']['options'] = $options;
        }
        $this->handleApiReplacements();
        $page = $this->replaceReplacements($page);
        $page = $this->handleFunction($page);
        $page = $this->handleJs($page);

        if($needCache){
            $fo = fopen($this->dispatchInfo['cacheFilePath'], 'w');
            fwrite($fo, $page);
            fclose($fo);
        }

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

        foreach ($keyValueList as $key => $value) {
            if (strpos($value['url'],'://')) {
                //带协议，判定为url
                if($this->autoLang){
                     $api = strpos($value['url'], '?') ? $value['url'] . '&lang=' . $this->lang : $value['url'] . '?lang=' . $this->lang;
                }
                $ch = curl_init($value['url']);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_multi_add_handle($mh, $ch);
                $chs[$key] = $ch;
            }
            else {
                //判定为文件路径
                $filePath = $this->htmlPath . $this->dispatchInfo['projectName'] . DIRECTORY_SEPARATOR . $this->langDir . DIRECTORY_SEPARATOR . $this->lang . DIRECTORY_SEPARATOR . $value['url'];
                if(!file_exists($filePath)){
                    $filePath = $value['url'];
                }
                $fileContent = file_get_contents($filePath);
                $json = @json_decode($fileContent, true);
                if(is_array($json))
                    $val = $json;
                else
                    $val = $fileContent;
                $this->addReplacement($key, $val);
            }
        }

        if (!empty($chs)) {
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

    private function handleJs($page)
    {
        if (!empty($this->jsVars)) {
            $nullJsVars = '';
            $notNullJsVars = '';
            foreach ($this->jsVars as $key => $value) {
                if ($value === null) {
                    if ($nullJsVars == '') {
                        $nullJsVars .= "var _$$key";
                    } else {
                        $nullJsVars .= ",_$$key";
                    }
                } else {
                    $realValue = null;
                    if (is_string($value)) {
                        $realValue = "'$value'";
                    } else if (is_array($value)) {
                        $realValue = json_encode($value);
                    } else {
                        $realValue = $value;
                    }
                    if ($notNullJsVars == '') {
                        $notNullJsVars .= "var _$$key = $realValue";
                    } else {
                        $notNullJsVars .= ",_$$key = $realValue";
                    }
                }
            }
            $nullJsVars .= ';';
            $notNullJsVars .= ';';
            $this->addFrontJsBlock($nullJsVars . $notNullJsVars);
        }

        if (!empty($this->jsBlocks)) {
            $pattern = '/(<\s*\/body\s*>)()/i';
            $blocks = '';
            foreach ($this->jsBlocks as $jsBlock) {
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

    public function handleKeyValueString($str){
        $pattern = "/(?P<key>\w+)=(?P<value>[^\n|\r\n].*)/i";
        preg_match_all($pattern, $str, $match);
        $keyValueList = [];
        if(isset($match['key']) && isset($match['value'])){
            $keyLen = count($match['key']);
            if($keyLen == count($match['value'])){
                for($i = 0;$i < $keyLen; $i++){
                    $keyValueList[$match['key'][$i]] = $match['value'][$i];
                }
            }
        }
        return $keyValueList;
    }

    private function checkDir($dir)
    {
        return is_dir($dir) or $this->checkDir(dirname($dir)) and mkdir($dir, 0777);
    }

    private function getPreferredLanguage() {
        $langs = [];
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})?)s*(;s*qs*=s*(1|0.[0-9]+))?/i',
                $_SERVER['HTTP_ACCEPT_LANGUAGE'], $lang_parse);
            if (count($lang_parse[1])) {
                $langs = array_combine($lang_parse[1], $lang_parse[4]);
                foreach ($langs as $lang => $val) {
                    if ($val === '') $langs[$lang] = 1;
                }
                arsort($langs, SORT_NUMERIC);
            }
        }
        foreach ($langs as $lang => $val) { break; }
        if (stristr($lang,"-")) {$tmp = explode("-",$lang); $lang = $tmp[0]; }
        return $lang;
    }

    private function createHashString($str){
        return md5($str);
    }

    private function addCacheExt($filePath){
        return $filePath . '.ltcache';
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

    public function addJsBlock($block)
    {
        $this->jsBlocks[] = $block;
    }

    public function addFrontJsBlock($block)
    {
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