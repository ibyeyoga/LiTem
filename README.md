# LiTem - 针对i18n的模板引擎
### 安装
 * composer require ibyeyoga/litem （推荐）
 * 下载链接：https://github.com/ibyeyoga/LiTem/archive/master.zip

### 使用
 1. 将安装后的项目放到任何一个PHP环境下的服务器根目录，或者使用PHP内置Web Server
 2. 即可通过 http://localhost/?r=demo/index 访问demo
 3. 详细使用细节在后面

### 适用场景
 * 微信/APP网页开发
 * 国际化网站开发
 * 用作前后端分离中间件
 * 其他数据不方便用js处理的应用
 
### 细节
#### 配置文件
 > 配置文件在任何一个项目的根目录下，默认名称为litem.json
 > ##### litem.json 解释
 ```
 {
        "mode": "dev", //默认为prod，为dev时会输出错误
        "route-key": "r", //默认为r，即路由的键名(?r=demo/index中的r)
        "using-cache":true, //默认为true，是否缓存页面
        "cache-time":60,//默认为60，页面缓存过期时间，单位为秒
        "route-separator": "/", //默认为/，路由的分隔符(?r=demo/index中的/)
        "lang-dir":"langs", //默认为langs，语言包的文件夹名
        "allow-ext-list": [ //默认为[html]，允许被渲染的文件扩展名
            ".html",
            ".htm",
            ".shtml"
        ],
        "replacements": { //替换数组 如下面的配置，会替换文件中的所有{$parameterA}为AAA
          "parameterA": "AAA"
        },
        "local": { //指定某些文件的特定替换数组
          "index": { //project文件夹/index.html文件
            "replacements": {
              "where": "demo/index"
            },
            "api-replacements": { //替换数组里面的值可以是通过api或者本地文件名来获取
              "userinfo": { //api，返回json
                "url": "http://localhost/api.php"
              },
              "userlist": { //本地文件，返回json
                "url": "abc.json"
              }
            }
          },
          "index2": {
            "replacements": {
              "where": "demo/index2"
            }
          }
        }
      }
 ```
 ### 入口文件
 > 入口文件代码参考：
 ```
 //第一步先引入LiTem
 //第二步初始化
$litem = new IBye\litem\LiTem([
    'mode' => 'prod',
    'htmlPath' => __DIR__ . DIRECTORY_SEPARATOR . 'html'
]);
//第三步运行
$litem->run();
//注意htmlPath的值是绝对路径
```
 
 #### api-replacements
 > 配置项中的api-replacements会将指定链接中的返回值填入replacements中，
 例：http://ibye.cn/getUserinfo中的返回值是{"name":"kobe","age":24}
 ```
 //第一种情况，url是一个api
           "api-replacements": {
              "userinfo": {
                "url": "http://ibye.cn/getUserinfo"
              }
           }
如果autoLang == true，且处于中文环境，则http://ibye.cn/getUserinfo会变成http://ibye.cn/getUserinfo?lang=zh
api中可以根据lang来判断语言
那么现在{$userinfo.name}就是kobe，{$userinfo.age}就是24

//第二种情况，url是一个文件名
           "api-replacements": {
              "userinfo": {
                "url": "userinfo.json"
              }
           }
如果autoLang == true，且处于中文环境，则系统会优先查找project根目录/langs/zh/中的userinfo.json，
如果没有找到则找project根目录中的userinfo.json，结果和上面一样
```