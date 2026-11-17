<?php
namespace lib;

class FolderService
{
    private $db;
    public static $modes = [
        'manual' => '手动排序', 'name_asc' => '名称升序', 'name_desc' => '名称降序',
        'time_desc' => '时间从新到旧', 'time_asc' => '时间从旧到新'
    ];

    public function __construct($db) { $this->db = $db; }

    public static function number($value, $signed = false)
    {
        if (!is_scalar($value) || !preg_match($signed ? '/^-?\d+$/' : '/^\d+$/', (string)$value)
            || (float)$value > 2147483647 || (float)$value < ($signed ? -2147483648 : 0)) {
            throw new \InvalidArgumentException('编号或排序数字无效。');
        }
        return (int)$value;
    }

    public function folder($id)
    {
        $id = self::number($id);
        if ($id === 0) return ['id' => 0, 'parent_id' => 0, 'name' => '根目录', 'sort_mode' => $this->mode(0)];
        $row = $this->db->getRow('SELECT * FROM pre_folder WHERE id=:id', [':id' => $id]);
        if (!$row) throw new \InvalidArgumentException('文件夹不存在或已被删除。');
        return $row;
    }

    public function path($id)
    {
        $path = []; $seen = [];
        while ($id) {
            $row = $this->folder($id);
            if (isset($seen[$row['id']])) throw new \RuntimeException('文件夹路径异常。');
            $seen[$row['id']] = true;
            array_unshift($path, $row);
            $id = $row['parent_id'];
        }
        return $path;
    }

    public function mode($id)
    {
        if ($id) return $this->folder($id)['sort_mode'];
        $mode = $this->db->getColumn("SELECT v FROM pre_config WHERE k='folder_sort_root'");
        return isset(self::$modes[$mode]) ? $mode : 'manual';
    }

    public function folderOptions()
    {
        $all = $this->db->getAll('SELECT id,parent_id,name FROM pre_folder ORDER BY name,id');
        if ($all === false) throw new \RuntimeException('读取文件夹失败。');
        $map = [];
        foreach ($all as $row) $map[$row['id']] = $row;
        $options = [['id' => 0, 'name' => '根目录']];
        foreach ($all as $row) {
            $parts = []; $seen = []; $node = $row;
            while ($node) {
                if (isset($seen[$node['id']])) throw new \RuntimeException('文件夹路径异常。');
                $seen[$node['id']] = true;
                array_unshift($parts, $node['name']);
                if (!$node['parent_id']) break;
                if (!isset($map[$node['parent_id']])) throw new \RuntimeException('父文件夹不存在。');
                $node = $map[$node['parent_id']];
            }
            $options[] = ['id' => (int)$row['id'], 'name' => '根目录 / ' . implode(' / ', $parts)];
        }
        return $options;
    }

    /** One UNION and one ORDER BY: no folder-first priority, even across pages. */
    public function listing($id, $offset = 0, $limit = 15, $public = true, $filters = [])
    {
        $id = self::number($id);
        $this->folder($id);
        $offset = max(0, (int)$offset); $limit = max(1, min(100, (int)$limit));
        $where = $public ? 'hide=0' : '1=1';
        $bind = [];
        $search = isset($filters['kw']) && $filters['kw'] !== '';
        // Public search remains site-wide; admin filters stay inside the current folder.
        if (!$public || !$search) { $where .= ' AND folder_id=:folder'; $bind[':folder'] = $id; }
        if ($search) {
            $field = isset($filters['type']) ? (int)$filters['type'] : 1;
            $fields = [1 => 'name', 2 => 'hash', 3 => 'type', 4 => 'ip'];
            if (!isset($fields[$field])) throw new \InvalidArgumentException('搜索类型无效。');
            $where .= ' AND ' . $fields[$field] . ($field === 1 ? ' LIKE :kw' : '=:kw');
            $bind[':kw'] = $field === 1 ? '%' . htmlspecialchars($filters['kw'], ENT_QUOTES, 'UTF-8') . '%' : $filters['kw'];
        }
        if (!$public && !empty($filters['uid'])) {
            $where .= ' AND uid=:uid'; $bind[':uid'] = self::number($filters['uid']);
        }
        if (!$public && isset($filters['dstatus']) && $filters['dstatus'] !== '-1' && $filters['dstatus'] !== '') {
            $status = self::number($filters['dstatus']);
            if ($status > 2) throw new \InvalidArgumentException('状态无效。');
            $where .= ' AND block=:block'; $bind[':block'] = $status;
        }
        if (!$public && !empty($filters['storage_id'])) { $where .= ' AND storage_id=:storage'; $bind[':storage'] = self::number($filters['storage_id']); }
        $fileSql = "SELECT id,name,type,size,hash,addtime,lasttime,ip,hide,pwd,block,count,uid,storage_id,folder_id,sort_order,is_pinned,0 AS is_folder FROM pre_file WHERE " . $where;
        $includeFolders = empty($filters['storage_id']) && !$search && ($public || (empty($filters['uid']) && (!isset($filters['dstatus']) || $filters['dstatus'] === '-1' || $filters['dstatus'] === '')));
        if ($includeFolders) {
            $fileSql .= " UNION ALL SELECT id,name,'' AS type,0 AS size,'' AS hash,addtime,NULL AS lasttime,'' AS ip,0 AS hide,NULL AS pwd,0 AS block,0 AS count,0 AS uid,0 AS storage_id,parent_id AS folder_id,sort_order,is_pinned,1 AS is_folder FROM pre_folder WHERE parent_id=:parent";
            $bind[':parent'] = $id;
        }
        $mode = ($public && $search) ? $this->mode(0) : $this->mode($id);
        $orders = ['manual' => 'sort_order ASC,addtime DESC', 'name_asc' => 'name ASC,addtime DESC',
            'name_desc' => 'name DESC,addtime DESC', 'time_desc' => 'addtime DESC', 'time_asc' => 'addtime ASC'];
        $order = isset($orders[$mode]) ? $orders[$mode] : $orders['manual'];
        if (!$public && isset($filters['orderby']) && $filters['orderby'] == '1') $order = 'count DESC,addtime DESC';
        $sql = '(' . $fileSql . ') AS entries';
        $count = $this->db->getRow('SELECT COUNT(*) AS total,COALESCE(SUM(is_folder),0) AS folders FROM ' . $sql, $bind);
        $rows = $this->db->getAll('SELECT * FROM ' . $sql . ' ORDER BY is_pinned DESC,' . $order . ',id DESC,is_folder DESC LIMIT ' . $offset . ',' . $limit, $bind);
        if ($count === false || $rows === false) throw new \RuntimeException('读取目录失败，请确认数据库升级已完成。');
        foreach ($rows as &$row) $row['item_key'] = ($row['is_folder'] ? 'd:' : 'f:') . $row['id'];
        unset($row);
        return ['total' => (int)$count['total'], 'folders' => (int)$count['folders'], 'rows' => $rows, 'mode' => $mode];
    }

    private function exec($sql, $bind = [])
    {
        if ($this->db->exec($sql, $bind) === false) throw new \RuntimeException('保存失败，请稍后重试。');
    }

    private function atomic($callback)
    {
        if (!$this->db->beginTransaction()) throw new \RuntimeException('无法开始数据库事务。');
        try {
            // Serialize tree mutations so delete/move/create cannot orphan a child.
            if (!$this->db->getRow("SELECT k FROM pre_config WHERE k='folder_tree_lock' FOR UPDATE")) {
                throw new \RuntimeException('请先完成数据库升级。');
            }
            $result = $callback();
            if (!$this->db->commit()) throw new \RuntimeException('提交失败。');
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function name($name)
    {
        if (!is_string($name)) throw new \InvalidArgumentException('文件夹名称无效。');
        $name = trim($name);
        if ($name === '.' || $name === '..' || !preg_match('/^.{1,255}$/us', $name) || preg_match('/[\x00-\x1f\x7f\\\\\/]/u', $name)) {
            throw new \InvalidArgumentException('文件夹名称需为 1–255 个字符，不能包含斜杠或控制字符。');
        }
        return $name;
    }

    private function uniqueName($parent, $name, $except = 0)
    {
        if ($this->db->getRow('SELECT id FROM pre_folder WHERE parent_id=:parent AND name=:name AND id<>:id', [':parent' => $parent, ':name' => $name, ':id' => $except])) {
            throw new \InvalidArgumentException('目标目录已存在同名文件夹。');
        }
    }

    public function saveFolder($id, $parent, $name)
    {
        $id = self::number($id); $parent = self::number($parent); $name = $this->name($name);
        return $this->atomic(function () use ($id, $parent, $name) {
            $this->folder($parent);
            if ($id) {
                $folder = $this->folder($id);
                // Renaming preserves the current parent; moving uses move().
                $parent = (int)$folder['parent_id'];
                $this->uniqueName($parent, $name, $id);
                $this->exec('UPDATE pre_folder SET name=:name WHERE id=:id', [':name' => $name, ':id' => $id]);
            } else {
                $this->uniqueName($parent, $name);
                $this->exec('INSERT INTO pre_folder (parent_id,name,addtime) VALUES (:parent,:name,NOW())', [':parent' => $parent, ':name' => $name]);
                $id = (int)$this->db->lastInsertId();
            }
            return $id;
        });
    }

    public function deleteFolder($id)
    {
        $id = self::number($id);
        if (!$id) throw new \InvalidArgumentException('根目录不能删除。');
        $this->atomic(function () use ($id) {
            $this->folder($id);
            $files = $this->db->getColumn('SELECT COUNT(*) FROM pre_file WHERE folder_id=:id', [':id' => $id]);
            $children = $this->db->getColumn('SELECT COUNT(*) FROM pre_folder WHERE parent_id=:id', [':id' => $id]);
            if ($files === false || $children === false) throw new \RuntimeException('检查文件夹内容失败，请重试。');
            if ($files || $children) {
                throw new \InvalidArgumentException('只允许删除空文件夹，请先移走或删除其中全部文件和子文件夹（包括隐藏文件）。');
            }
            $this->exec('DELETE FROM pre_folder WHERE id=:id', [':id' => $id]);
        });
    }

    private function item($key)
    {
        if (!is_string($key) || !preg_match('/^([fd]):([1-9]\d*)$/', $key, $m)) throw new \InvalidArgumentException('项目编号无效。');
        $id = self::number($m[2]);
        $table = $m[1] === 'd' ? 'pre_folder' : 'pre_file';
        $row = $this->db->getRow('SELECT * FROM ' . $table . ' WHERE id=:id', [':id' => $id]);
        if (!$row) throw new \InvalidArgumentException('所选项目已不存在，请刷新列表。');
        return [$table, $row];
    }

    public function move($keys, $target)
    {
        if (!is_array($keys) || !$keys || count($keys) > 100) throw new \InvalidArgumentException('每次请选择 1–100 个项目。');
        $target = self::number($target);
        $this->atomic(function () use ($keys, $target) {
            $this->folder($target);
            $ancestors = array_column($this->path($target), 'id');
            foreach (array_unique($keys) as $key) {
                list($table, $row) = $this->item($key);
                if ($table === 'pre_folder') {
                    if (in_array($row['id'], $ancestors)) throw new \InvalidArgumentException('不能移入自己或自己的子文件夹。');
                    $this->uniqueName($target, $row['name'], $row['id']);
                }
                $column = $table === 'pre_folder' ? 'parent_id' : 'folder_id';
                $this->exec('UPDATE ' . $table . ' SET ' . $column . '=:target WHERE id=:id', [':target' => $target, ':id' => $row['id']]);
            }
        });
    }

    public function pin($key, $value)
    {
        $value = self::number($value);
        if ($value > 1) throw new \InvalidArgumentException('置顶状态无效。');
        $this->atomic(function () use ($key, $value) {
            list($table, $row) = $this->item($key);
            $this->exec('UPDATE ' . $table . ' SET is_pinned=:pin WHERE id=:id', [':pin' => $value, ':id' => $row['id']]);
        });
    }

    public function sort($folder, $mode, $orders = [])
    {
        $folder = self::number($folder);
        if (!is_string($mode) || !isset(self::$modes[$mode])) throw new \InvalidArgumentException('排序规则无效。');
        if (!is_array($orders) || count($orders) > 100) throw new \InvalidArgumentException('排序项目过多。');
        $this->atomic(function () use ($folder, $mode, $orders) {
            $this->folder($folder);
            foreach ($orders as $key => $order) {
                list($table, $row) = $this->item($key);
                $parent = $table === 'pre_folder' ? $row['parent_id'] : $row['folder_id'];
                if ((int)$parent !== $folder) throw new \InvalidArgumentException('项目已移动，请刷新当前目录再排序。');
                $this->exec('UPDATE ' . $table . ' SET sort_order=:value WHERE id=:id', [':value' => self::number($order, true), ':id' => $row['id']]);
            }
            if ($folder) $this->exec('UPDATE pre_folder SET sort_mode=:mode WHERE id=:id', [':mode' => $mode, ':id' => $folder]);
            else $this->exec("UPDATE pre_config SET v=:mode WHERE k='folder_sort_root'", [':mode' => $mode]);
        });
    }
}
