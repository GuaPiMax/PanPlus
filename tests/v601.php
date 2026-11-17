<?php
require __DIR__.'/../includes/lib/PdoHelper.php';
require __DIR__.'/../includes/lib/FolderMigration.php';
require __DIR__.'/../includes/lib/FolderService.php';
require __DIR__.'/../includes/lib/V6Migration.php';
use lib\FolderMigration;use lib\FolderService;use lib\V6Migration;
$c=['host'=>'127.0.0.1','port'=>13316,'user'=>'root','pwd'=>getenv('PAN_TEST_PASSWORD'),'dbname'=>'pan_folder_v6_test'];
$pdo=new PDO('mysql:host=127.0.0.1;port=13316',$c['user'],$c['pwd'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS pan_folder_v6_test CHARACTER SET utf8mb4');
$db=new lib\PdoHelper($c);$checks=0;
function ok($v,$label){global $checks;if(!$v)throw new Exception('FAIL '.$label);$checks++;echo "PASS $label\n";}
function sql($s,$p=[]){global $db;if($db->exec($s,$p)===false)throw new Exception($db->error());}
function resetOld(){
 foreach(['pre_storage','pre_folder'] as $t)sql('DROP TABLE IF EXISTS '.$t);
 foreach(explode(';',file_get_contents(__DIR__.'/fixtures/v56.sql')) as $s)if(trim($s)!=='')sql($s);
}
function fileRow($name,$hide=0,$pwd=null){global $db;sql("INSERT INTO pre_file (name,type,size,hash,addtime,ip,hide,pwd) VALUES (:name,'txt',7,:hash,NOW(),'127.0.0.1',:hide,:pwd)",[':name'=>$name,':hash'=>md5($name),':hide'=>$hide,':pwd'=>$pwd]);return (int)$db->lastInsertId();}
function reject($fn,$label){try{$fn();}catch(InvalidArgumentException $e){ok(true,$label);return;}throw new Exception('Accepted '.$label);}
resetOld();
$file=fileRow('说明.txt',0,'abc123');$hidden=fileRow('隐藏.txt',1);
FolderMigration::run($db);
ok((int)$db->getColumn("SELECT v FROM pre_config WHERE k='version'")===1002,'fixture is old extension 1002');
ok(!$db->getRow("SHOW TABLES LIKE 'pre_storage'"),'1002 does not imply V6 storage schema');
$folders=new FolderService($db);$d=$folders->saveFolder(0,0,'项目');$sub=$folders->saveFolder(0,$d,'子目录');
$folders->move(['f:'.$file],$sub);$folders->pin('f:'.$file,1);$folders->pin('d:'.$d,1);$folders->sort($sub,'manual',['f:'.$file=>-3]);
$oldFiles=$db->getAll('SELECT * FROM pre_file ORDER BY id');$oldFolders=$db->getAll('SELECT * FROM pre_folder ORDER BY id');
$path=realpath(__DIR__.'/../file');sql("REPLACE INTO pre_config(k,v) VALUES ('filepath',:p)",[':p'=>$path]);
V6Migration::run($db);
ok($db->getColumn("SELECT v FROM pre_config WHERE k='folder_extension'")===V6Migration::BUILD,'separate compatibility marker');
$newFiles=$db->getAll('SELECT * FROM pre_file ORDER BY id');
foreach($oldFiles as $i=>$row)ok(array_intersect_key($newFiles[$i],$row)===$row,'old file metadata retained '.$row['id']);
ok($oldFolders===$db->getAll('SELECT * FROM pre_folder ORDER BY id'),'all hierarchy and folder settings retained');
$storage=$db->getRow('SELECT * FROM pre_storage WHERE isdefault=1');
ok($storage['type']==='local' && json_decode($storage['config'],true)['filepath']===$path,'custom local storage path retained exactly');
ok((int)$newFiles[0]['storage_id']===(int)$storage['id'],'old file linked to original storage');
$snapshot=$db->getAll('SELECT * FROM pre_file ORDER BY id');V6Migration::run($db);
ok($snapshot===$db->getAll('SELECT * FROM pre_file ORDER BY id'),'retry does not alter files');
ok((int)$db->getColumn('SELECT COUNT(*) FROM pre_storage')===1,'retry does not duplicate storages');
$plain=fileRow('A.txt');$a=$folders->saveFolder(0,0,'B目录');
$folders->sort(0,'manual',['f:'.$plain=>1,'d:'.$a=>5,'d:'.$d=>30]);
$folders->pin('d:'.$d,0);
ok($folders->listing(0)['rows'][0]['item_key']==='f:'.$plain,'numeric mixing file before folder');
$folders->pin('f:'.$plain,1);$folders->pin('d:'.$a,1);
ok($folders->listing(0,0,1)['rows'][0]['item_key']==='f:'.$plain,'pin mixing across page one');
ok($folders->listing(0,1,1)['rows'][0]['item_key']==='d:'.$a,'pin mixing across page two');
ok($folders->listing(0,0,10,true,['kw'=>'说明'])['total']===1,'global search finds nested file');
ok($folders->listing(0,0,10,true,['kw'=>'隐藏'])['total']===0,'hidden files not public');
ok($folders->listing($sub,0,15,false,['storage_id'=>$storage['id'],'dstatus'=>'-1'])['total']===1,'storage filter retains matching file');
reject(fn()=>$folders->move(['d:'.$d],$sub),'no cyclic move');
reject(fn()=>$folders->deleteFolder($d),'nonempty folder protected');
$before=(int)$db->getColumn('SELECT folder_id FROM pre_file WHERE id='.$file);
reject(fn()=>$folders->move(['f:'.$file,'d:2147483647'],0),'bad batch rejected');
ok((int)$db->getColumn('SELECT folder_id FROM pre_file WHERE id='.$file)===$before,'batch rollback preserved');
$folders->pin('f:'.$plain,0);$folders->pin('d:'.$a,0);
$folders->sort(0,'name_asc');ok($folders->listing(0)['rows'][0]['item_key']==='f:'.$plain,'name ascending mixes types');
$folders->sort(0,'name_desc');ok($folders->listing(0)['rows'][0]['item_key']!=='f:'.$plain,'name descending persisted');
sql("UPDATE pre_file SET addtime='2020-01-01 00:00:00' WHERE id=:id",[':id'=>$plain]);
sql("UPDATE pre_folder SET addtime='2025-01-01 00:00:00' WHERE id=:id",[':id'=>$a]);
sql("UPDATE pre_folder SET addtime='2026-01-01 00:00:00' WHERE id=:id",[':id'=>$d]);
$folders->sort(0,'time_asc');ok($folders->listing(0)['rows'][0]['item_key']==='f:'.$plain,'time ascending mixes types');
$folders->sort(0,'time_desc');ok($folders->listing(0)['rows'][0]['item_key']==='d:'.$d,'time descending mixes types');
ok($folders->mode($sub)==='manual','child sorting independent');
reject(fn()=>$folders->saveFolder(0,0,'项目'),'duplicate sibling rejected');
reject(fn()=>$folders->saveFolder(0,0,'../bad'),'invalid path name rejected');
reject(fn()=>$folders->sort(0,'manual',['f:'.$plain=>'2147483648']),'invalid numeric order rejected');
reject(fn()=>$folders->sort(0,'DROP TABLE pre_file'),'invalid sort mode rejected');
$target=$folders->saveFolder(0,0,'批量目标');
$folders->move(['f:'.$plain,'d:'.$a],$target);
ok($folders->listing($target)['total']===2,'batch move files and folders');
$folders->move(['f:'.$plain,'d:'.$a],0);$folders->deleteFolder($target);
ok(!$db->getRow('SELECT id FROM pre_folder WHERE id=:id',[':id'=>$target]),'empty folder deletion');
// A 1001 official database also upgrades; no classification required beforehand.
resetOld();fileRow('old.txt');V6Migration::run($db);
ok((int)$db->getColumn('SELECT storage_id FROM pre_file LIMIT 1')>0,'official 1001 source supported');
// Invalid local path cannot silently map old files to a different storage.
resetOld();fileRow('path.txt');FolderMigration::run($db);
sql("REPLACE INTO pre_config(k,v) VALUES('filepath','Z:/nonexistent/pan-v6-test')");
try { V6Migration::run($db);throw new Exception('Missing path accepted'); }catch(RuntimeException $e){ok(true,'missing local path stops migration');}
ok(!$db->getColumn("SELECT v FROM pre_config WHERE k='folder_extension'"),'failed migration not marked complete');
ok((int)$db->getColumn('SELECT COUNT(*) FROM pre_storage')===0,'failed migration rolls back storage creation');
sql("UPDATE pre_config SET v='' WHERE k='filepath'");V6Migration::run($db);
ok($db->getColumn("SELECT v FROM pre_config WHERE k='folder_extension'")===V6Migration::BUILD,'retry completes after correcting path');
// Native V6 schema already has storage: installing extension must not remap it.
foreach(explode(';',file_get_contents(__DIR__.'/fixtures/v601.sql')) as $s)if(trim($s)!=='')sql($s);
sql('DROP TABLE IF EXISTS pre_folder');fileRow('native.txt');sql('UPDATE pre_file SET storage_id=1');
V6Migration::run($db);
ok((int)$db->getColumn('SELECT storage_id FROM pre_file LIMIT 1')===1,'native V6 existing storage id preserved');
ok((int)$db->getColumn('SELECT COUNT(*) FROM pre_storage')===1,'native V6 storage not duplicated');
echo "SUCCESS $checks V6 migration and service checks\n";
