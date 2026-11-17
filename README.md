# 彩虹外链网盘 · 文件夹兼容版

本仓库基于 [netcccyun/pan](https://github.com/netcccyun/pan) 官方 V6.0.1，增加管理员统一管理的多层文件夹、文件/文件夹混合排序和置顶，保留官方前后台新界面。兼容版标识 `1541-folders-3`，独立扩展标记 `601-1`。

- **已有 V5.6/V5.7 文件夹扩展版：** [从 Git 仓库拉取并升级](docs/DEPLOY-FROM-GIT.md)。旧版和 V6 都可能显示数据库版本1002，本次仍必须执行 `admin/upgrade.php` 兼容迁移。
- **功能和迁移细节：** [V6.0.1 兼容升级说明](docs/UPGRADE-V6.0.1-FOLDERS.md)。不需要先安装 V5.7，不要使用原版安装 SQL 覆盖旧库。
- **Git 获取后需安装 PHP 依赖：** `composer install --no-dev --prefer-dist --optimize-autoloader`。`composer.lock` 已提交，`vendor/` 不提交。前后台已编译，部署不需要 Node.js 或 pnpm。
- **配置不入库：** 配置示例为 `config.example.php`；现有站点保留原 `config.php`。新建本地项目时先复制示例为 `config.php`。全新安装还需在官方安装流程完成后执行一次 `admin/upgrade.php` 初始化目录扩展。
- **排序规则：** 置顶优先；每组内文件与文件夹按所选规则混排，数字越小越靠前，不设文件夹优先。目录移动不改变实际文件、原外链或密码。
- **公告弹窗：** 自定义弹窗仍为待开发需求，当前沿用 V6 原有公告形式。

本次针对 PHP 8.2.28 / MariaDB 10.11.11 完成38项迁移/服务检查、26项HTTP检查、20项浏览器检查；已编译前后台并检查45个应用PHP文件。测试在独立数据库进行，不代表已验证任何生产站点。

---

以下保留上游项目介绍与使用说明。

# 彩虹外链网盘

彩虹外链网盘，是一款PHP网盘与外链分享程序，支持所有格式文件的上传，可以生成文件外链、图片外链、音乐视频外链，生成外链同时自动生成相应的UBB代码和HTML代码，还可支持文本、图片、音乐、视频在线预览，这不仅仅是一个网盘，更是一个图床亦或是音乐在线试听网站。新版本支持对接阿里云OSS、腾讯云COS、华为云OBS、又拍云、七牛云、S3协议（Amazon S3、MinIO、Cloudflare R2等）、Azure Blob 存储、OneDrive（国际版、世纪互联版）、Google Drive、WebDAV、FTP以及SFTP存储，同时增加了图片违规检测功能。

### 更新日志

[CHANGELOG](./CHANGELOG.md)


### 演示地址
- https://cccimg.com/

### 部署方法

- 环境要求`PHP` >= 8.1、`MySQL` >= 5.5，PHP 需要 `curl`、`pdo_mysql`、`gd` 扩展
- 使用 FTP 存储需要 PHP 的 `ftp` 扩展，使用 SFTP 存储需要 PHP 的 `ssh2` 扩展

1. 上传源码到网站根目录
2. 如果是下载的Source code包，还需Composer安装依赖（Release页面下载的安装包不需要）

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. 访问网站，按照提示安装
4. 后台默认账号密码：admin/123456，安装后请及时修改

- 建议禁止访问 `vendor` 、`file`目录。Apache 已在根目录的 `.htaccess` 中配置；Nginx 需要在站点配置中加上：

  ```nginx
  location ~ ^/(vendor|file|includes)/ {
      deny all;
  }
  ```

### 前端编译

前台和后台页面都是 Nuxt 4 + Nuxt UI 编写的纯静态单页应用（hash 路由），源码在 `frontend` 目录，编译后的文件已包含在程序中，只修改 PHP 代码时无需编译。

| 目录 | 说明 | 编译输出 |
|---|---|---|
| `frontend/` | 前台 | 网站根目录 `index.php`、`assets/_nuxt/` |
| `frontend/admin/` | 后台管理 | `admin/index.php`、`admin/assets/_nuxt/` |
| `frontend/layers/shared/` | 前后台共用的组件、工具函数、样式和配置 | - |

环境要求：Node.js >= 20.19、pnpm >= 10

```bash
cd frontend
pnpm install

# 编译前台和后台，并自动部署到网站根目录和 admin 目录
pnpm build

# 只编译前台 / 只编译后台
pnpm build:site
pnpm build:admin
```

### 官方网站

- https://pan.cccyun.cc/
- https://blog.cccyun.cn/
