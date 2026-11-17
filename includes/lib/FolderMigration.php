<?php
namespace lib;

/** Additive, retryable migration for existing V5.6 databases. */
class FolderMigration
{
    public static function run($db)
    {
        $lock = 'pan_folders_' . sha1($db->getColumn('SELECT DATABASE()'));
        if ($db->getColumn('SELECT GET_LOCK(:name, 10)', [':name' => $lock]) != 1) {
            throw new \RuntimeException('另一个升级正在运行，请稍后重试。');
        }
        try {
            $version = (int)$db->getColumn("SELECT v FROM pre_config WHERE k='version'");
            if ($version < 1001 || $version > 1002) {
                throw new \RuntimeException('此升级仅适用于数据库版本 1001 的 V5.6，或本扩展版本 1002。');
            }
            $table = $db->getRow("SHOW TABLE STATUS WHERE Name='pre_file'");
            if (!$table || strtolower($table['Engine']) !== 'innodb') {
                throw new \RuntimeException('pre_file 必须使用 InnoDB 引擎，请备份后转换再升级。');
            }
            self::exec($db, "CREATE TABLE IF NOT EXISTS pre_folder (
                id int(11) unsigned NOT NULL AUTO_INCREMENT,
                parent_id int(11) unsigned NOT NULL DEFAULT 0,
                name varchar(255) NOT NULL,
                addtime datetime NOT NULL,
                sort_order int(11) NOT NULL DEFAULT 0,
                is_pinned tinyint(1) NOT NULL DEFAULT 0,
                sort_mode varchar(20) NOT NULL DEFAULT 'manual',
                PRIMARY KEY (id), KEY parent_sort (parent_id,is_pinned,sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $folder = $db->getRow("SHOW TABLE STATUS WHERE Name='pre_folder'");
            if (!$folder || strtolower($folder['Engine']) !== 'innodb') {
                throw new \RuntimeException('pre_folder 必须使用 InnoDB 引擎。');
            }
            $columns = $db->getAll('SHOW COLUMNS FROM pre_file');
            if ($columns === false) throw new \RuntimeException('读取文件表结构失败。');
            $columns = array_column($columns, 'Field');
            foreach ([
                'folder_id' => 'int(11) unsigned NOT NULL DEFAULT 0',
                'sort_order' => 'int(11) NOT NULL DEFAULT 0',
                'is_pinned' => 'tinyint(1) NOT NULL DEFAULT 0'
            ] as $name => $definition) {
                if (!in_array($name, $columns, true)) {
                    self::exec($db, 'ALTER TABLE pre_file ADD COLUMN ' . $name . ' ' . $definition);
                }
            }
            if (!$db->getRow("SHOW INDEX FROM pre_file WHERE Key_name='folder_sort'")) {
                self::exec($db, 'ALTER TABLE pre_file ADD INDEX folder_sort (folder_id,is_pinned,sort_order)');
            }
            self::exec($db, "INSERT IGNORE INTO pre_config (k,v) VALUES ('folder_sort_root','manual'),('folder_tree_lock','1')");
            self::exec($db, "UPDATE pre_config SET v='' WHERE k='cache'");
            // Only mark complete once every DDL statement has succeeded.
            self::exec($db, "UPDATE pre_config SET v='1002' WHERE k='version'");
        } finally {
            $db->getColumn('SELECT RELEASE_LOCK(:name)', [':name' => $lock]);
        }
    }

    private static function exec($db, $sql)
    {
        if ($db->exec($sql) === false) {
            throw new \RuntimeException('数据库升级失败：' . $db->error());
        }
    }
}
