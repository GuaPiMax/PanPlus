# 定制版验证

生产部署只需 Git、PHP >=8.1、Composer 和编译产物，不需要在服务器运行这些测试。

测试必须连接独立数据库，不能连接线上数据库。`v601.php` 创建/重置 `pan_folder_v6_test`，`http_v601.py` 创建/重置 `pan_v601_http_test`；默认本机端口13316、用户root，密码从 `PAN_TEST_PASSWORD` 环境变量读取。HTTP测试复制代码到 `.local-tools/test-site` 并生成专用配置，不修改真实配置。

```powershell
$env:PAN_TEST_PASSWORD = '独立测试库的密码'
php tests/v601.php
python tests/http_v601.py C:\path\to\php.exe
```

浏览器测试需先启动上面生成的独立测试站（18765端口）及Chrome本机调试端口9333，再运行 `node --experimental-websocket tests/browser_v601.mjs`。需使用支持 WebSocket 的 Node 版本或相应实验开关。截图写入 `dist/screenshots`，不会提交。

此次结果：38项迁移/服务检查、26项HTTP检查、20项PC/手机浏览器检查通过，45个应用PHP文件语法检查通过。测试环境PHP8.2.28、MariaDB10.11.11，Nuxt前后台使用Node24.11.1重新编译。
