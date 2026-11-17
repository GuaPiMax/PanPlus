<?php
define('IN_ADMIN',true);
define('IN_API',true);
include '../includes/common.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function folderReply($code, $msg, $extra = []) {
    exit(json_encode(array_merge(['code' => $code, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE));
}
if ($islogin != 1) { http_response_code(403); folderReply(403, '请先登录管理员账号。'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); folderReply(405, '请使用 POST 请求。'); }
if (!isset($_POST['token']) || !is_string($_POST['token']) || empty($_SESSION['folder_csrf'])
    || !hash_equals($_SESSION['folder_csrf'], $_POST['token'])) {
    http_response_code(403); folderReply(403, '页面校验已失效，请刷新后重试。');
}
$folders = new \lib\FolderService($DB);
try {
    $act = isset($_GET['act']) ? $_GET['act'] : '';
    switch ($act) {
        case 'options':
            folderReply(0, '成功', ['folders' => $folders->folderOptions()]);
            break;
        case 'save':
            $id = $folders->saveFolder(isset($_POST['id']) ? $_POST['id'] : 0, isset($_POST['parent']) ? $_POST['parent'] : 0, isset($_POST['name']) ? $_POST['name'] : '');
            folderReply(0, '文件夹保存成功。', ['id' => $id]);
            break;
        case 'delete':
            $folders->deleteFolder(isset($_POST['id']) ? $_POST['id'] : '');
            folderReply(0, '空文件夹已删除。');
            break;
        case 'move':
            $folders->move(isset($_POST['keys']) ? $_POST['keys'] : [], isset($_POST['target']) ? $_POST['target'] : '');
            folderReply(0, '移动成功，原有外链保持不变。');
            break;
        case 'pin':
            $folders->pin(isset($_POST['key']) ? $_POST['key'] : '', isset($_POST['value']) ? $_POST['value'] : '');
            folderReply(0, '置顶状态已保存。');
            break;
        case 'sort':
            $orders = isset($_POST['orders']) && is_string($_POST['orders']) ? json_decode($_POST['orders'], true) : [];
            if (!is_array($orders)) throw new \InvalidArgumentException('排序数据无效。');
            $folders->sort(isset($_POST['folder']) ? $_POST['folder'] : 0, isset($_POST['mode']) ? $_POST['mode'] : '', $orders);
            folderReply(0, '本目录排序已保存。');
            break;
        default:
            folderReply(-1, '未知操作。');
    }
} catch (\InvalidArgumentException $e) {
    folderReply(-1, $e->getMessage());
} catch (\Throwable $e) {
    error_log('Folder operation: ' . $e->getMessage());
    folderReply(-1, '操作失败，请刷新重试或检查服务器日志。');
}
