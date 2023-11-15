# maccms10-tvbox-api
TVBOX默认可以对接苹果CMS v10的接口，但是不支持过滤。
这个项目用来增加一个支持过滤的tvbox接口。


# 安装方法
下载Tvbox.php，上传到maccms10/application/api/controller/目录

浏览器地址栏访问：http://<maccms10地址>/api.php/tvbox
如果显示出json数据就表示安装成功。然后在TVBOX里面配置相应的接口即可。

主要功能：

* 只示一级分类
* 在分类上按遥控器OK键，弹出过滤窗口，添加了各种过滤功能
* 不过滤时可以设置默认过滤条件，在苹果CMS后台系统->网站参数->预留参数->自定义参数填写：
```
tvbox_default_year$$$2023
tvbox_default_area$$$中国
```

# 过滤功能展示

![屏幕截图](screenshot.png)