<?php
$install = true;
define('IN_ADMIN', true);
require '../includes/common.php';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
function upgradeEscape($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['folder_upgrade_csrf'])) $_SESSION['folder_upgrade_csrf'] = bin2hex(random_bytes(32));
$message = ''; $success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($islogin != 1) {
        http_response_code(403); $message = '请先登录管理员账号。';
    } elseif (!isset($_POST['token']) || !is_string($_POST['token']) || !hash_equals($_SESSION['folder_upgrade_csrf'], $_POST['token'])) {
        http_response_code(403); $message = '页面校验失败，请刷新页面后重试。';
    } elseif (empty($_POST['backup_confirmed'])) {
        $message = '请先备份网站文件和数据库，并勾选备份确认。';
    } else {
        try {
            @set_time_limit(0);
            \lib\V6Migration::run($DB);
            $message = '兼容升级完成。原有文件夹、排序、置顶、外链及文件权限已保留，旧文件已关联原存储。';
            $success = true;
        } catch (\Throwable $e) {
            http_response_code(500);
            $message = '升级未完成：' . $e->getMessage() . ' 修复问题后可重新执行；不要运行全新安装 SQL。';
        }
    }
}
?>
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>V6.0.1 文件夹扩展升级</title><link href="../assets/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet"></head>
<body><div class="container" style="padding-top:30px"><div class="col-sm-10 col-md-8 center-block" style="float:none"><div class="panel panel-primary"><div class="panel-heading">V6.0.1 文件夹、排序和置顶扩展升级</div><div class="panel-body">
<?php if ($message !== '') { ?><div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?>"><?php echo upgradeEscape($message); ?></div><?php } ?>
<?php if ($success) { ?><a class="btn btn-primary" href="file.php">进入文件管理</a> <a class="btn btn-default" href="../">打开首页</a>
<?php } elseif ($islogin != 1) { ?><p>升级仅允许管理员执行。请先登录后台，然后返回本页面。</p><a class="btn btn-primary" href="login.php">管理员登录</a>
<?php } else { ?><p>当前数据库版本：<?php echo (int)$conf['version']; ?>；原生数据库版本：1002；独立扩展版本：601-1。</p><p>升级会检测并保留已有目录，补全 V6 存储表及 storage_id，并迁移原存储设置。不依赖有歧义的 1002 版本号。无需删除 install.lock。大文件表添加索引可能需要较长时间，请先开启维护模式。</p><form method="post"><input type="hidden" name="token" value="<?php echo upgradeEscape($_SESSION['folder_upgrade_csrf']); ?>"><div class="checkbox"><label><input type="checkbox" name="backup_confirmed" value="1" required> 我已备份完整数据库和网站文件，并暂停上传及后台写入。</label></div><button type="submit" class="btn btn-primary">执行增量升级</button></form><?php } ?>
</div></div></div></div></body></html>
