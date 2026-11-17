import fs from 'node:fs/promises';import path from 'node:path';
const root=process.cwd(),state=JSON.parse(await fs.readFile('.local-tools/browser-test-state.json','utf8'));
const out=path.join(root,'dist/screenshots');await fs.mkdir(out,{recursive:true});
const target=await(await fetch('http://127.0.0.1:9333/json/new?about:blank',{method:'PUT'})).json();
const ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j});
let id=0,count=0;const pending=new Map(),errors=[],failures=[];
ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){let p=pending.get(m.id);pending.delete(m.id);m.error?p.j(m.error):p.r(m.result)}else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);else if(m.method==='Network.responseReceived'&&m.params.response.url.startsWith(state.base)&&m.params.response.status>=400)failures.push(m.params.response.status+' '+m.params.response.url)};
function call(method,params={}){return new Promise((r,j)=>{const n=++id;pending.set(n,{r,j});ws.send(JSON.stringify({id:n,method,params}))})}
async function ev(expression){const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value}
async function wait(expression){for(let i=0;i<100;i++){if(await ev(expression))return;await new Promise(r=>setTimeout(r,200))}throw Error('Timeout '+expression)}
function ok(v,t){if(!v)throw Error(t);count++;console.log('PASS',t)}
async function go(url){await call('Page.navigate',{url:state.base+url});await wait('document.readyState==="complete"');}
async function shot(name){await ev('document.fonts.ready.then(()=>true)');await new Promise(r=>setTimeout(r,250));const r=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await fs.writeFile(path.join(out,name+'.png'),Buffer.from(r.data,'base64'))}
async function click(label,scope='document'){
 const result=await ev(`(()=>{const root=${scope};const b=Array.from(root.querySelectorAll('button,a')).find(e=>e.getClientRects().length && e.textContent.trim()===${JSON.stringify(label)});if(!b)return false;b.click();return true})()`);
 if(!result)throw Error('Button missing '+label)
}
async function fill(selector,value){await ev(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw Error('Input missing');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(String(value))});e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return true})()`)}
async function api(){return ev(`fetch('./ajax.php?act=fileList',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'folder=0&dstatus=-1&offset=0&limit=100'}).then(r=>r.json())`)}
async function rowButton(name,label){return click(label,`Array.from(document.querySelectorAll('tbody tr')).find(r=>r.getClientRects().length && r.innerText.includes(${JSON.stringify(name)}))`)}
try{
 await call('Page.enable');await call('Runtime.enable');await call('Network.enable');
 await call('Network.setCookies',{cookies:state.cookies.map(c=>({...c,url:state.base}))});
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await go('/admin/#/file');await wait('document.body.innerText.includes("新建文件夹") && document.body.innerText.includes("使用说明.txt")');
 let data=await api();ok(data.code===0 && data.rows.some(r=>r.is_folder),'new Nuxt admin mixed listing');
 ok(data.rows[0].item_key==='f:1'&&data.rows[1].item_key==='d:1','migrated pinned file/folder numeric ordering');
 await shot('pc-admin');
 await ev(`for(const text of ['使用说明.txt','项目文档']){const r=Array.from(document.querySelectorAll('tbody tr')).find(e=>e.innerText.includes(text));r.querySelector('[role=checkbox]').click()}`);
 await click('批量移动');await wait('document.querySelector("[role=dialog]")?.innerText.includes("移动 2 个项目")');
 ok(true,'file and folder sharing an id selected independently');
 await click('取消','document.querySelector("[role=dialog]")');
 await ev(`for(const text of ['使用说明.txt','项目文档']){const r=Array.from(document.querySelectorAll('tbody tr')).find(e=>e.innerText.includes(text));r.querySelector('[role=checkbox]').click()}`);
 for(const [label,value] of [['名称升序','name_asc'],['手动排序','manual']]) {
  await ev('document.querySelector("[aria-label=本目录排序]").click()');await wait('document.querySelector("[role=option]")!==null');
  await new Promise(r=>setTimeout(r,250));
  await ev(`(()=>{const e=Array.from(document.querySelectorAll('[role=option]')).find(e=>e.textContent.trim()===${JSON.stringify(label)});e.focus();e.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));return true})()`);
  await click('应用排序');await new Promise(r=>setTimeout(r,350));ok((await api()).mode===value,'persist directory rule '+value);
 }
 await click('新建文件夹');await wait('document.querySelector("[role=dialog] input")!==null');
 const name='浏览器测试-'+Date.now();await fill('[role=dialog] input',name);await click('保存','document.querySelector("[role=dialog]")');
 await wait(`document.body.innerText.includes(${JSON.stringify(name)}) && !document.querySelector('[role=dialog]')`);
 data=await api();let item=data.rows.find(r=>r.name===name);ok(!!item,'create directory through Nuxt modal');
 await rowButton(name,'重命名');await wait('document.querySelector("[role=dialog] input")!==null');await fill('[role=dialog] input',name+'改名');await click('保存','document.querySelector("[role=dialog]")');await wait(`document.body.innerText.includes(${JSON.stringify(name+'改名')})`);
 const renamed=name+'改名';ok((await api()).rows.some(r=>r.name===renamed),'rename directory through Nuxt modal');
 await rowButton(renamed,'置顶');await wait(`document.body.innerText.includes(${JSON.stringify(renamed)}) && Array.from(document.querySelectorAll('tbody tr')).find(r=>r.innerText.includes(${JSON.stringify(renamed)}))?.innerText.includes('取消置顶')`);
 ok((await api()).rows.find(r=>r.name===renamed).is_pinned===1,'pin through Nuxt action');
 await rowButton(renamed,'取消置顶');await wait(`Array.from(document.querySelectorAll('tbody tr')).find(r=>r.innerText.includes(${JSON.stringify(renamed)}))?.innerText.includes('置顶') && !Array.from(document.querySelectorAll('tbody tr')).find(r=>r.innerText.includes(${JSON.stringify(renamed)}))?.innerText.includes('取消置顶')`);
 ok((await api()).rows.find(r=>r.name===renamed).is_pinned===0,'unpin through Nuxt action');
 const key=item.item_key;
 await ev(`(()=>{const r=Array.from(document.querySelectorAll('tbody tr')).find(r=>r.innerText.includes(${JSON.stringify(renamed)}));r.querySelector('input[type=number]').setAttribute('data-sort-test','true')})()`);
 await fill('[data-sort-test]',-10);await click('保存排序');await new Promise(r=>setTimeout(r,350));
 ok(Number((await api()).rows.find(r=>r.item_key===key).sort_order)===-10,'save manual numeric order');
 await rowButton(renamed,'移动');await wait('document.querySelector("[role=dialog] [role=combobox]")!==null');await shot('pc-move-dialog');
 await ev('document.querySelector("[role=dialog] [role=combobox]").click()');await wait('document.querySelector("[role=option]")!==null');
 await new Promise(r=>setTimeout(r,350));
 await ev(`(()=>{const e=Array.from(document.querySelectorAll('[role=option]')).find(e=>e.textContent.includes('根目录 / 项目文档 / 子文件夹'));e.focus();e.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',code:'Enter',bubbles:true}));return true})()`);
 await wait('document.querySelector("[role=dialog] [role=combobox]").textContent.includes("子文件夹")');
 await click('确定移动','document.querySelector("[role=dialog]")');await wait(`!document.body.innerText.includes(${JSON.stringify(renamed)})`);
 ok(!(await api()).rows.some(r=>r.item_key===key),'move directory through Nuxt selector');
 // Open the destination through routing, verify multi-level navigation, then delete the empty test directory.
 await go('/admin/#/file?folder='+state.child);await wait(`document.body.innerText.includes(${JSON.stringify(renamed)})`);
 ok(await ev('document.querySelector("nav[aria-label=文件夹路径]").innerText.includes("子文件夹")'),'nested breadcrumbs');
 await rowButton(renamed,'删除目录');await wait('document.querySelector("[role=dialog]")!==null');await click('删除','document.querySelector("[role=dialog]")');await wait(`!document.body.innerText.includes(${JSON.stringify(renamed)})`);ok(true,'empty directory deletion through confirmation');
 await go('/');await wait('document.body.innerText.includes("使用说明.txt") && document.body.innerText.includes("根目录")');await shot('pc-home');
 ok(await ev('document.body.innerText.indexOf("使用说明.txt")<document.body.innerText.indexOf("项目文档")'),'new public interface keeps mixed ordering');
 await go('/#/');await wait('document.body.innerText.includes("项目文档")');await ev('Array.from(document.querySelectorAll("a")).find(e=>e.getClientRects().length && e.textContent.includes("项目文档") && e.getAttribute("href")?.includes("folder=1")).click()');await wait('location.hash.includes("folder=1") && document.body.innerText.includes("子文件夹")');ok(true,'public directory navigation');await shot('pc-folder');
 await call('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:true});
 await go('/');await wait('document.body.innerText.includes("使用说明.txt")');ok(await ev('document.documentElement.scrollWidth<=innerWidth'),'mobile public no page overflow');await shot('mobile-home');
 await go('/admin/#/file');await wait('document.body.innerText.includes("新建文件夹") && document.body.innerText.includes("使用说明.txt")');
 ok(await ev('document.documentElement.scrollWidth<=innerWidth'),'mobile admin no page overflow');await shot('mobile-admin');
 await click('新建文件夹');await wait('document.querySelector("[role=dialog] input")!==null');await shot('mobile-folder-dialog');
 ok(await ev('(()=>{const r=document.querySelector("[role=dialog]").getBoundingClientRect();return r.left>=0 && r.right<=innerWidth})()'),'mobile folder dialog fits');await click('取消','document.querySelector("[role=dialog]")');
 ok(errors.length===0,'no JavaScript exceptions');ok(failures.length===0,'no failed application resources');
 await fs.writeFile(path.join(out,'results.json'),JSON.stringify({checks:count,errors,failures},null,2));console.log('SUCCESS',count,'V6 browser checks');
}catch(error){console.log('ERRORS',JSON.stringify(errors));console.log('BODY',await ev('document.body.innerText.slice(-2500)'));await shot('failure');throw error}
finally{ws.close();await fetch('http://127.0.0.1:9333/json/close/'+target.id)}
