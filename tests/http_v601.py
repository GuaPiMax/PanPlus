from pathlib import Path
import hashlib, http.cookiejar, json, os, re, shutil, subprocess, sys, time, urllib.request, urllib.error, urllib.parse
ROOT=Path(__file__).resolve().parents[1]; SITE=ROOT/'.local-tools/test-site';PHP=str(Path(sys.argv[1]).resolve());BASE='http://127.0.0.1:18765';checks=0
def ok(value,label):
 global checks
 if not value:raise AssertionError(label)
 checks+=1;print('PASS',label,flush=True)
def prepare():
 SITE.mkdir(parents=True,exist_ok=True)
 for name in ['admin','assets','includes','vendor','install']:shutil.copytree(ROOT/name,SITE/name,dirs_exist_ok=True)
 for p in ROOT.glob('*.php'):
  if p.name!='config.php':shutil.copyfile(p,SITE/p.name)
 shutil.copyfile(ROOT/'favicon.ico',SITE/'favicon.ico')
 (SITE/'file').mkdir(exist_ok=True);(SITE/'install/install.lock').write_text('installed')
 c={'host':'127.0.0.1','port':13316,'user':'root','pwd':os.environ['PAN_TEST_PASSWORD'],'dbname':'pan_v601_http_test'}
 (SITE/'config.php').write_text("<?php $dbconfig=json_decode('"+json.dumps(c)+"',true);",encoding='utf-8')
 content=b'Original local storage bytes.\n';digest=hashlib.md5(content).hexdigest();(SITE/'file'/digest).write_bytes(content)
 php=r'''<?php
 require 'includes/lib/PdoHelper.php'; require 'includes/lib/FolderMigration.php'; require 'includes/lib/FolderService.php';require 'includes/functions.php';
 $c=json_decode(getenv('HTTP_TEST_CONFIG'),true);
 $p=new PDO('mysql:host=127.0.0.1;port=13316',$c['user'],$c['pwd'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $p->exec('CREATE DATABASE IF NOT EXISTS pan_v601_http_test CHARACTER SET utf8mb4');$p->exec('USE pan_v601_http_test');$p->exec('SET NAMES utf8mb4');
 $p->exec('DROP TABLE IF EXISTS pre_folder');$p->exec('DROP TABLE IF EXISTS pre_storage');
 foreach(explode(';',file_get_contents('tests/fixtures/v56.sql')) as $s)if(trim($s)!=='')$p->exec($s);
 $db=new lib\PdoHelper($c);$db->exec("INSERT INTO pre_config VALUES('syskey','v601-test-key')");
 foreach([['使用说明.txt',0,null],['隐藏文件.txt',1,null],['加密文件.txt',0,'abc123']] as $f){$db->exec("INSERT INTO pre_file(name,type,size,hash,addtime,ip,hide,pwd) VALUES(:name,'txt',:size,:hash,NOW(),'127.0.0.1',:hide,:pwd)",[':name'=>$f[0],':size'=>(int)getenv('HTTP_TEST_SIZE'),':hash'=>$f[0]==='使用说明.txt'?getenv('HTTP_TEST_HASH'):md5($f[0]),':hide'=>$f[1],':pwd'=>$f[2]]);}
 lib\FolderMigration::run($db);$folders=new lib\FolderService($db);
 $folder=$folders->saveFolder(0,0,'项目文档');$sub=$folders->saveFolder(0,$folder,'子文件夹');$folders->saveFolder(0,0,'图片素材');
 $folders->sort(0,'manual',['f:1'=>1,'d:'.$folder=>5]);$folders->pin('f:1',1);$folders->pin('d:'.$folder,1);
 $sid=md5('admin'.'123456'.'!@#%!s!0');$token=authcode("admin\t".$sid."\t".(time()+86400),'ENCODE','v601-test-key');
 echo json_encode(['token'=>$token,'folder'=>$folder,'child'=>$sub],JSON_UNESCAPED_UNICODE);
 '''
 env=dict(os.environ,HTTP_TEST_CONFIG=json.dumps(c),HTTP_TEST_HASH=digest,HTTP_TEST_SIZE=str(len(content)))
 result=subprocess.run([PHP],cwd=ROOT,input=php.encode(),env=env,capture_output=True,check=True)
 return json.loads(result.stdout),digest,content
class Client:
 def __init__(self,token=None):
  self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
  if token:self.jar.set_cookie(http.cookiejar.Cookie(0,'admin_token',token,None,False,'127.0.0.1',False,False,'/admin',True,False,None,True,None,None,{},False))
 def request(self,path,data=None):
  req=urllib.request.Request(BASE+path,None if data is None else urllib.parse.urlencode(data,doseq=True).encode(),headers={'Referer':BASE+'/admin/','User-Agent':'Mozilla/5.0','Accept':'text/html,application/json'})
  try:r=self.opener.open(req,timeout=30)
  except urllib.error.HTTPError as e:r=e
  return r.status,r.read()
 def api(self,path,data=None):
  code,body=self.request(path,data)
  try:return code,json.loads(body)
  except Exception:raise AssertionError('Non-JSON '+path+': '+body[:500].decode(errors='replace'))
def run(state,digest,content):
 guest=Client();admin=Client(state['token'])
 ok(guest.api('/ajax.php?act=filelist')[1]['code']==-10,'old 1002 blocked until real V6 migration')
 ok(admin.api('/admin/ajax.php?act=status')[1]['islogin']==1,'old V5.6 admin cookie recognized before migration')
 ok(guest.request('/admin/upgrade.php',{'token':'bad','backup_confirmed':1})[0]==403,'guest migration denied')
 page=admin.request('/admin/upgrade.php')[1];token=re.search(rb'name="token" value="([a-f0-9]+)"',page).group(1).decode()
 ok(admin.request('/admin/upgrade.php',{'token':'bad','backup_confirmed':1})[0]==403,'migration CSRF enforced')
 page=admin.request('/admin/upgrade.php',{'token':token,'backup_confirmed':1})[1]
 ok('兼容升级完成'.encode() in page,'old extension 1002 to V6 migration succeeds')
 ok('兼容升级完成'.encode() in admin.request('/admin/upgrade.php',{'token':token,'backup_confirmed':1})[1],'migration retry succeeds')
 data=guest.api('/ajax.php?act=filelist')[1]
 ok(data['code']==0 and data['total']==4,'public mixed listing works')
 ok(data['list'][0]['item_key']=='f:1' and data['list'][1]['item_key']=='d:'+str(state['folder']),'pinned file before pinned folder by number')
 ok(all('pwd' not in x for x in data['list']) and all(x['name']!='隐藏文件.txt' for x in data['list']),'public passwords and hidden files excluded')
 ok(guest.api('/admin/ajax_folder.php?act=save',{'name':'bad'})[0]==403,'guest cannot manage folders')
 token=admin.api('/admin/ajax.php?act=folderToken')[1]['token']
 ok(admin.api('/admin/ajax_folder.php?act=save',{'name':'bad','token':'invalid'})[0]==403,'folder CSRF enforced')
 def action(act,**data):
  data['token']=token;code,result=admin.api('/admin/ajax_folder.php?act='+act,data)
  ok(code==200 and result['code']==0,'folder API '+act);return result
 created=action('save',name='临时目录',parent=0)['id']
 action('save',id=created,parent=0,name='改名成功');action('delete',id=created)
 data=admin.api('/admin/ajax.php?act=fileList',{'folder':0,'dstatus':-1,'offset':0,'limit':15})[1]
 ok(data['code']==0 and data['total']==5,'admin mixed listing and hidden files')
 before=guest.request('/down.php/'+digest+'.txt')[1];ok(before==content,'original external download bytes')
 action('move',**{'keys[]':['f:1'],'target':state['child']})
 ok(guest.request('/down.php/'+digest+'.txt')[1]==before,'same external link after directory move')
 ok(any(x['item_key']=='f:1' for x in guest.api('/ajax.php?act=filelist&folder='+str(state['child']))[1]['list']),'nested browsing')
 ok(guest.api('/ajax.php?act=filelist&kw='+urllib.parse.quote('使用说明'))[1]['total']==1,'global search finds nested file')
 ok(admin.api('/admin/ajax_folder.php?act=delete',{'token':token,'id':state['folder']})[1]['code']!=0,'nonempty folder protected')
 ok(admin.api('/admin/ajax_folder.php?act=move',{'token':token,'keys[]':['d:'+str(state['folder'])],'target':state['child']})[1]['code']!=0,'cyclic move rejected')
 # Password behavior remains on the original URL; provide an actual underlying test object.
 pwdhash=hashlib.md5('加密文件.txt'.encode()).hexdigest();(SITE/'file'/pwdhash).write_bytes(content)
 ok('密码'.encode() in guest.request('/down.php/'+pwdhash+'.txt')[1],'old password protection retained')
 ok(guest.request('/down.php/'+pwdhash+'.txt&abc123')[1]==content,'password download works')
 ok(guest.api('/ajax.php?act=filelist&m=mine')[1]['code']==0,'original mine endpoint')
 # Keep a pinned root file for the visual mix-order check.
 action('move',**{'keys[]':['f:1'],'target':0})
 (ROOT/'.local-tools/browser-test-state.json').write_text(json.dumps({'base':BASE,'folder':state['folder'],'child':state['child'],'token':state['token'],'cookies':[{'name':c.name,'value':c.value,'domain':c.domain,'path':c.path}for c in admin.jar]}),encoding='utf-8')
 print('SUCCESS',checks,'V6 HTTP checks',flush=True)
if __name__=='__main__':
 state,digest,content=prepare();log=open(ROOT/'.local-tools/php-http.log','wb')
 server=subprocess.Popen([PHP,'-S','127.0.0.1:18765','-t',str(SITE)],cwd=SITE,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
 try:time.sleep(.7);run(state,digest,content)
 finally:server.terminate();server.wait(timeout=10);log.close()
