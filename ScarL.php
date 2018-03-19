<?php
namespace IBye;
/**
 * Class ScarL version 0.1
 * @package IBye
 */

class ScarL
{
    private $showError = false;
    //the dir of html root
    private $htmlDirPath;
    //the separator of route
    //example:demo/index
    private $routesSeparator = '/';
    //key of GET
    //example:?r=demo/index
    private $routesKey = 'r';
    //replace target key - value
    private $parameters = [];
    //white list of static file
    private $allowExtList = [
        '.html',
        '.htm',
        '.shtml'
    ];
    //custom functions
    private $functions = [];

    public function __construct($config = [])
    {
        if(isset($config['dev']) && $config['dev'] === true){
            $this->showError = true;
        }
        if(!empty($config['htmlDirPath'])){
            $this->htmlDirPath = $config['htmlDirPath'];
            $this->htmlDirPath = str_replace(['/','\\'], DIRECTORY_SEPARATOR, $this->htmlDirPath);
            $tempChar = substr($this->htmlDirPath, -1);
            if($tempChar != DIRECTORY_SEPARATOR){
                $this->htmlDirPath .= DIRECTORY_SEPARATOR;
            }
        }
        else{
            $this->htmlDirPath = '.' . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR;
        }
        if(!empty($config['routesSeparator'])){
            $this->routesSeparator = $config['routesSeparator'];
        }
        if(!empty($config['routesKey'])){
            $this->routesKey = $config['routesKey'];
        }
        if(!empty($config['parameters'])){
            $this->parameters = $config['parameters'];
        }
        if(!empty($config['allowExtList'])){
            if(is_array($config['allowExtList'])){
                $this->allowExtList = $config['allowExtList'];
            }
        }
    }

    //runner
    public function run(){
        $routeStr = $_GET[$this->routesKey];
        $replaceStr = str_replace($this->routesSeparator, DIRECTORY_SEPARATOR, $routeStr);
        $parentDir = dirname($routeStr);
        $this->findParameters($this->htmlDirPath . $parentDir);
        $filePath = $this->htmlDirPath . $replaceStr;
        $this->dispatch($filePath);
    }

    //find parameters file and get key - value
    private function findParameters($dir = null){
            $filePath = $dir . DIRECTORY_SEPARATOR . 'parameters.txt';
            if(file_exists($filePath)){
                $file = fopen($filePath, 'r');
                $fileContent = @fread($file, filesize($filePath));
                fclose($file);
                $notHandleArray = explode(PHP_EOL, $fileContent);
                $parameters = [];
                foreach($notHandleArray as $keyValueStrWithSeparator){
                    $array = explode('=', $keyValueStrWithSeparator);
                    $parameters[$array[0]] = isset($array[1]) ? $array[1] : '';
                }
                $this->parameters = array_merge($this->parameters, $parameters);
            }
    }

    /**
     * dispatcher
     */
    public function dispatch($filePath)
    {
        //识别文件扩展名，只能访问白名单里的文件类型
        $filePath = $this->getAllowExtFileName($filePath);
        if (file_exists($filePath)) {
            $page = file_get_contents($filePath);
            echo $this->render($page);
            exit;
        } else if($this->showError){
            echo __METHOD__ . ':File not found or not valid format';
            exit;
        }
    }

    /**
     * render
     */
    public function render($page){
        $page = $this->replaceParameters($page, $this->parameters);
        $page = $this->handleFunction($page);
        return $page;
    }

    //replace target parameter
    private function replaceParameters($page, $parameters)
    {
        $keyValueList = $this->createKeyValueList('', $parameters);
        $keyList = [];
        $valueList = [];
        foreach($keyValueList as $key => $value){
            $keyList[] = $key;
            $valueList[] = $value;
        }
        return str_replace($keyList, $valueList, $page);
    }
    //generate keys and values,ready for replacing target parameter
    private function createKeyValueList($parentKey, $array){
        $keyValueList = [];
        foreach($array as $key => $value){
            if(is_array($value)){
                $keyValueList = array_merge($keyValueList,$this->createKeyValueList($this->createRelationKey($parentKey, $key), $value));
                continue;
            }
            $keyValueList['{$' . $this->createRelationKey($parentKey, $key) . '}'] = $value;
        }
        return $keyValueList;
    }

    private function handleFunction($page){
        foreach($this->functions as $functionName => $function){
            $regex = '/{:' . $functionName . '\((?P<parameters>.+)\)}/';
            $matches = [];
            if(preg_match($regex, $page, $matches)){
                $allMatch = $matches[0];
                $parameters = explode(',', $matches['parameters']);
                $executionResult = call_user_func_array($function, $parameters);
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
            $tmpFileName = $filePath . $ae;
            if (file_exists($tmpFileName)) {
                $realFileName = $tmpFileName;
            }
        }

        if (empty($realFileName) && $this->showError) {
            echo __METHOD__ . ':File not found or not valid format';
            exit;
        }

        return $realFileName;
    }

    private function createRelationKey($parentKey, $childKey){
        if($parentKey == ''){
            return $childKey;
        }
        return $parentKey . '.' . $childKey;
    }

    public function addFunction($functionName, \Closure $function){
        $this->functions[$functionName] = $function;
    }

    public function addParameter($key, $value){
        $this->parameters[$key] = $value;
    }

    public function addParameters($array){
        $this->parameters += $array;
    }
}