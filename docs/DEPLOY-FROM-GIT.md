# 从 GuaPiMax/PanPlus 仓库拉取代码并升级

仓库：<https://github.com/GuaPiMax/PanPlus>，部署分支：`main`。

本版基于官方 V6.0.1，兼容版 `1541-folders-3`，目录扩展标记 `601-1`。你当前的 `1532-folders-1`（V5.6 文件夹扩展版、本地存储）可以直接升级，不用先装 V5.7。

**以下终端示例适用于 Linux / 宝塔常见环境。将路径替换为你真实的路径后再执行。** Windows 服务器请按同样的目录边界同步文件，不要直接照抄 Linux 命令。

## 为什么代码仓库和线上目录分开

建议把 Git 工作副本放在站点根目录以外，通过它拉取代码、安装依赖，再同步程序到原站点。这样 Git 不会处理线上的 `config.php`、上传文件和安装锁；原网站根目录不变，默认 `file/` 的物理位置也不变。

不要在已有线上目录直接运行 `git reset --hard`、`git clean`，也不要用原版安装 SQL 重装网站。**线上旧数据库虽为1002，本次仍必须运行专用兼容迁移。**

## 一、首次拉取本仓库

确认网站使用 PHP >= 8.1（不仅是 CLI），有 PDO MySQL、cURL、GD 等站点所需扩展。服务器需要 Git、Composer 和 rsync。前后台已编译，不需要在服务器安装 Node.js 或 pnpm。

选择一个站点根目录以外、当前不存在的目录作为代码副本，例如 `/www/pan-code`：

```bash
git clone --branch main https://github.com/GuaPiMax/PanPlus.git /www/pan-code
cd /www/pan-code
git log -1 --oneline
composer install --no-dev --prefer-dist --optimize-autoloader
```

使用 `composer install` 读取仓库里的锁定版本，不要改用 `composer update`。`vendor/` 不入库，必须完成这一步，否则运行时会缺依赖。服务器没有 Composer 时，可以在本地按锁文件安装，再把完整 `vendor/` 上传到代码副本；不要沿用旧版 `includes/vendor/`。

仓库中不跟踪真实 `config.php`，示例在 `config.example.php`。**已有站点升级时无需在代码副本填写数据库密码，保留线上原配置即可。**

## 二、维护、备份、核对文件位置

1. 在站点面板开启维护/限制访问，暂停网页、API 上传和后台写入，允许管理员自己的 IP 访问。
2. 导出完整数据库，备份完整网站；备份放到站点以外，并确认备份成功。
3. 记录旧站文件、文件夹、用户数量，以及本地存储路径。默认路径为原网站根目录 `file/`；自定义路径必须原样保留。
4. 下列同步会替换 `admin/`、`assets/`、`includes/`、`vendor/` 四个**程序目录**。只有在确认实际上传文件不在这四个目录内部时，才可执行。若你把上传内容放在其中，先停止本流程，按真实存储位置安排保留方式。
5. 本次保持原站点根目录不变，不同时更换根目录或搬动存储目录。

升级前可在 phpMyAdmin 记录以下只读查询结果：

```sql
SELECT v FROM pre_config WHERE k='version';
SELECT COUNT(*) AS files FROM pre_file;
SELECT COUNT(*) AS folders FROM pre_folder;
SELECT COUNT(*) AS users FROM pre_user;
SELECT v FROM pre_config WHERE k='filepath';
```

## 三、同步程序到原站点

下面示例中，`APP` 为上一步的代码副本，`LIVE` 为**已部署旧版的网站根目录**。先替换 `LIVE` 为真实路径，核对输出，绝对不能把这两个变量填成同一目录。

```bash
APP=/www/pan-code
LIVE=/www/wwwroot/你的站点目录

APP=$(realpath "$APP")
LIVE=$(realpath "$LIVE")
printf '代码副本: %s\n线上目录: %s\n' "$APP" "$LIVE"
test "$APP" != "$LIVE" || { echo '代码副本不能等于线上目录'; exit 1; }
test -f "$APP/admin/upgrade.php" || { echo '代码副本路径错误'; exit 1; }
test -f "$APP/vendor/autoload.php" || { echo '请先安装 Composer 依赖'; exit 1; }
test -f "$LIVE/config.php" || { echo '未找到原站配置，停止操作'; exit 1; }
test -f "$LIVE/install/install.lock" || { echo '未找到原安装锁，先检查站点路径'; exit 1; }
```

确认已完成备份、暂停写入、核对存储目录后，先预览程序目录的同步差异：

```bash
for dir in admin assets includes vendor; do
  rsync -an --delete "$APP/$dir/" "$LIVE/$dir/"
done
```

`--delete` 仅用于上面四个程序目录，清理旧页面、旧编译资源和旧 SDK。**不要对整个网站目录使用 `--delete`。** 确认预览中没有实际上传文件后执行：

```bash
for dir in admin assets includes vendor; do
  rsync -a --delete "$APP/$dir/" "$LIVE/$dir/"
done

for name in index.php ajax.php api.php down.php view.php file.php player.php login.php upload.php favicon.ico composer.json composer.lock; do
  rsync -a "$APP/$name" "$LIVE/$name"
done

rsync -a "$APP/install/update.php" "$LIVE/install/update.php"
rsync -a "$APP/install/storage-v6.sql" "$LIVE/install/storage-v6.sql"
```

这些命令不覆盖 `config.php`，不操作 `file/` 和其他实际存储目录，不覆盖安装锁，不同步全新安装 SQL。

如果根目录有旧 `index.html`，备份后移出站点，避免服务器优先打开它。Apache 的自定义 `.htaccess` 不要直接覆盖，应合并仓库 `.htaccess` 中对 `vendor/`、`file/`、`includes/` 的访问保护；Nginx 按站点路径配置同样的目录保护，参见[详细说明](UPGRADE-V6.0.1-FOLDERS.md)。保持原来的 HTTPS、域名、PHP 和上传限制配置。

## 四、执行本次必要的兼容迁移

1. 访问 `https://你的域名/admin/upgrade.php`。
2. 若提示未登录，在同一浏览器登录新版后台 `https://你的域名/admin/#/login`，然后返回升级页。
3. 勾选备份确认，执行迁移，等待“兼容升级完成”。
4. 子目录部署时加上相应前缀，如 `/pan/admin/upgrade.php`。

迁移会保留原目录、排序、置顶，增加 V6 存储管理所需结构，将旧文件关联原本地存储，**不搬动磁盘文件、不改变外链或密码**。

官方和旧定制版都使用数据库1002，所以不要以“版本已经是1002”为由跳过。成功标记应为：

```sql
SELECT k,v FROM pre_config WHERE k IN ('version','folder_extension');
-- version = 1002
-- folder_extension = 601-1

SELECT COUNT(*) AS unmatched_files
FROM pre_file f LEFT JOIN pre_storage s ON f.storage_id=s.id
WHERE s.id IS NULL;
-- 应为0
```

遇到错误时保持维护模式，修复后可重试；不要手工改版本号，也不要执行全新安装。更详细的异常恢复见[完整迁移指南](UPGRADE-V6.0.1-FOLDERS.md)。

## 五、验收并恢复访问

重载网站 PHP/清理 OPcache，浏览器强制刷新。测试目录导航、新建/移动/批量移动、混合排序、置顶/取消置顶、非空目录保护、旧下载和预览链接、密码文件、新上传，以及手机端。

同目录中文件数字1、文件夹数字5时，文件应在前；置顶组中也按同一规则混排。核对文件、目录、用户数量与备份前一致，通过后关闭维护模式。

## 六、以后如何从仓库更新

在**代码副本目录**执行，不是在网站目录执行：

```bash
cd /www/pan-code
git status --short
git pull --ff-only origin main
composer install --no-dev --prefer-dist --optimize-autoloader
git log -1 --oneline
```

如果 `git status` 有你自己的代码修改，或 `--ff-only` 失败，先停下比对，不要强制重置。每次更新按当次仓库说明决定是否需要数据库迁移，再重复备份、程序同步和验收；不能永久假定后续版本无数据库变化。

前台源码位于 `frontend/`，后台源码位于 `frontend/admin/`，已编译产物也提交到仓库。以后若自行修改 `.vue`，需要在本地按前端构建脚本重新编译再提交。不要仅修改源码却继续部署旧产物。

## 回退

本次旧站可能来自压缩包，并不对应本仓库某个提交；回退以升级前完整程序和数据库备份为准，不要只 `git checkout` 到官方原版。保持维护模式，恢复匹配的旧程序/依赖/数据库，原存储位置不变，再清理缓存、验证外链。

若上线后已有新增数据或启用了 V6 的其他存储，先保存增量再处理回退，避免用旧数据库覆盖新增记录，或让旧代码错误读取多存储文件。
