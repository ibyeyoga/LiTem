<?php
namespace IBye\litem;
/**
 * http://litem.ibye.cn
 * Class LiTem version 1.0
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
        'allowExtList' => [
            '.html',
            '.htm',
            '.shtml'
        ]
    ];

    //自定义函数容器
    private $functions = [];

    public function __construct($config = [])
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
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
            $this->findConfigFile($this->htmlPath . $parentDir);
            $this->activeConfig();
            $filePath = $this->htmlPath . $replaceStr;
            $this->dispatch($filePath);
        }
    }

    /**
     * 查找配置文件
     * @param null $dir
     */
    private function findConfigFile($dir = null)
    {
        $filePath = $dir . DIRECTORY_SEPARATOR . 'litem.json';
        if (file_exists($filePath)) {
            $file = fopen($filePath, 'r');
            $fileContent = @fread($file, filesize($filePath));
            fclose($file);
            $jsonArray = json_decode($fileContent, true);
            if (!empty($jsonArray)) {
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
        if(!empty($_GET['options'])){
            $options = $this->dealOptions($_GET['options']);
            $this->config['replacements']['options'] = $options;
        }

        $page = $this->replaceReplacements($page);
        $page = $this->handleFunction($page);

        return $page;
    }

    /**
     * 替换值
     * @param $page
     * @return mixed
     */
    private function replaceReplacements($page)
    {
        $keyValueList = $this->createKeyValueList('', $this->replacements);
        $keyList = [];
        $valueList = [];

        foreach ($keyValueList as $key => $value) {
            $keyList[] = $key;
            $valueList[] = $value;
        }

        return str_replace($keyList, $valueList, $page);
    }

    private function createKeyValueList($parentKey, $array)
    {
        $keyValueList = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $keyValueList = array_merge($keyValueList, $this->createKeyValueList($this->createRelationKey($parentKey, $key), $value));
                continue;
            }
            $keyValueList['{$' . $this->createRelationKey($parentKey, $key) . '}'] = $value;
        }

        return $keyValueList;
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

    private function dealOptions($options)
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
            if(strpos($ae, '.') === false)
                $tmpFileName = $filePath . '.' .$ae;
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
        $this->replacements[$key] = $value;
    }

    public function addReplacements($array)
    {
        $this->replacements += $array;
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

    function __set($name, $value)
    {
        // TODO: Implement __set() method.
        if(isset($this->$name)){
            $this->$name = $value;
        }
        else{
            $this->config[$name] = $value;
        }
    }
}