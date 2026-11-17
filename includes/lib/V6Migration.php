<?php
namespace lib;

/** The old folder extension and upstream V6 BOTH use DB version 1002. */
class V6Migration
{
    const BUILD = '601-1';
    private static function exec($db, $sql, $bind = []) {
        if ($db->exec($sql, $bind) === false) throw new \RuntimeException('数据库操作失败：'.$db->error());
    }
    public static function run($db) {
        $lock = 'pan_v6_'.sha1($db->getColumn('SELECT DATABASE()'));
        if ($db->getColumn('SELECT GET_LOCK(:k,10)', [':k'=>$lock]) != 1) throw new \RuntimeException('另一个升级正在执行，请稍后重试。');
        try {
            FolderMigration::run($db); // additive; preserves existing folder rows and values
            $sql = file_get_contents(dirname(__DIR__,2).'/install/storage-v6.sql');
            if ($sql === false) throw new \RuntimeException('缺少存储迁移结构文件。');
            self::exec($db, trim(explode(';',$sql)[0]));
            if (!$db->getRow("SHOW COLUMNS FROM pre_file LIKE 'storage_id'")) self::exec($db,'ALTER TABLE pre_file ADD COLUMN storage_id int(11) unsigned NOT NULL DEFAULT 0');
            if (!$db->getRow("SHOW INDEX FROM pre_file WHERE Key_name='storage_id'")) self::exec($db,'ALTER TABLE pre_file ADD INDEX storage_id (storage_id)');
            $db->beginTransaction();
            try {
                if (!$db->getRow("SELECT k FROM pre_config WHERE k='folder_tree_lock' FOR UPDATE")) throw new \RuntimeException('缺少迁移锁。');
                $settings = $db->getAll('SELECT k,v FROM pre_config');
                if ($settings === false) throw new \RuntimeException('无法读取原配置。');
                $conf = array_column($settings,'v','k');
                $type = $conf['storage'] ?? 'local';
                $get = function($key) use($conf) { return isset($conf[$key]) ? trim((string)$conf[$key]) : ''; };
                $maps = [
                    'local'=>['filepath'=>'filepath'],
                    'oss'=>['accessKeyId'=>'oss_ak','accessKeySecret'=>'oss_sk','endpoint'=>'oss_endpoint','bucket'=>'oss_bucket'],
                    'qcloud'=>['secretId'=>'qcloud_id','secretKey'=>'qcloud_key','region'=>'qcloud_region','bucket'=>'qcloud_bucket'],
                    'obs'=>['accessKey'=>'obs_ak','secretKey'=>'obs_sk','endpoint'=>'obs_endpoint','bucket'=>'obs_bucket'],
                    'qiniu'=>['accessKey'=>'qiniu_ak','secretKey'=>'qiniu_sk','bucket'=>'qiniu_bucket','domain'=>'qiniu_domain'],
                    'upyun'=>['serviceName'=>'upyun_name','operatorName'=>'upyun_user','operatorPwd'=>'upyun_pwd']
                ];
                if (!isset($maps[$type])) throw new \RuntimeException('原存储类型需要人工迁移，未修改文件存储关联。');
                $config=[];
                foreach ($maps[$type] as $key=>$old) $config[$key]=$get($old);
                if ($type!=='local') foreach (['uploadfile_type','downfile_type','downfile_protocol','downfile_domain'] as $key) $config[$key]=$get($key);
                if ($type==='local' && $config['filepath']!=='' && !is_dir($config['filepath'])) throw new \RuntimeException('原本地存储路径不可访问，请先恢复原目录或确认服务器路径。');
                $storages=$db->getAll('SELECT * FROM pre_storage ORDER BY id');
                if ($storages===false) throw new \RuntimeException('无法读取存储表。');
                $legacy=(int)($conf['folder_v6_legacy_storage']??0);
                if (!$storages) {
                    self::exec($db,'INSERT INTO pre_storage (name,type,config,status,isdefault,sort,addtime) VALUES (:name,:type,:config,1,1,0,NOW())',
                        [':name'=>$type==='local'?'本地存储':'原站存储',':type'=>$type,':config'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                    $legacy=(int)$db->lastInsertId();
                    self::exec($db,"REPLACE INTO pre_config (k,v) VALUES ('folder_v6_legacy_storage',:id)",[':id'=>$legacy]);
                }
                $unassigned=$db->getColumn('SELECT COUNT(*) FROM pre_file WHERE storage_id=0');
                if ($unassigned===false) throw new \RuntimeException('无法检查旧文件。');
                if ($unassigned) {
                    if (!$legacy) {
                        foreach ($storages as $storage) {
                            if ($storage['type']===$type && json_decode($storage['config'],true)==$config) { $legacy=(int)$storage['id'];break; }
                        }
                    }
                    if (!$legacy || !$db->getRow('SELECT id FROM pre_storage WHERE id=:id',[':id'=>$legacy])) throw new \RuntimeException('无法确定旧文件对应存储，已停止关联，请检查存储配置。');
                    self::exec($db,'UPDATE pre_file SET storage_id=:id WHERE storage_id=0',[':id'=>$legacy]);
                }
                $orphans=$db->getColumn('SELECT COUNT(*) FROM pre_file f LEFT JOIN pre_storage s ON f.storage_id=s.id WHERE s.id IS NULL');
                if ($orphans===false || $orphans) throw new \RuntimeException('存在未匹配存储的文件，升级未标记完成。');
                self::exec($db,"INSERT IGNORE INTO pre_config (k,v) VALUES ('upload_choose_storage','0')");
                self::exec($db,"REPLACE INTO pre_config (k,v) VALUES ('folder_extension',:v)",[':v'=>self::BUILD]);
                self::exec($db,"UPDATE pre_config SET v='' WHERE k='cache'");
                $db->commit();
            } catch (\Throwable $e) { $db->rollBack();throw $e; }
        } finally { $db->getColumn('SELECT RELEASE_LOCK(:k)',[':k'=>$lock]); }
    }
}
