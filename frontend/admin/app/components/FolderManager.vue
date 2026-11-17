<script setup lang="ts">
interface Entry { id: number; item_key: string; name: string; is_folder: number; is_pinned: number; sort_order: number }
const props = defineProps<{ rows: Entry[]; selected: string[]; mode: string; path: {id:number;name:string}[] }>()
const emit = defineEmits<{ refresh: [] }>()
const route = useRoute()
const current = computed(() => Number(route.query.folder || 0))
const { get, post } = useApi()
const toast = useToast()
const confirm = useConfirm()
let token = ''
const busy = ref(false)
const modes = [
  {label:'手动排序',value:'manual'}, {label:'名称升序',value:'name_asc'}, {label:'名称降序',value:'name_desc'},
  {label:'时间从新到旧',value:'time_desc'}, {label:'时间从旧到新',value:'time_asc'}
]
const chosenMode = ref(props.mode || 'manual')
watch(() => props.mode, v => { chosenMode.value = v })
async function request(action:string, data:Record<string,any> = {}) {
  if (!token) token = (await get('folderToken')).token
  return post(action, {...data, token}, 'ajax_folder.php')
}
async function run(action:()=>Promise<unknown>) {
  if (busy.value) return
  busy.value = true
  try { await action(); emit('refresh'); toast.add({title:'操作已保存',color:'success'}) }
  catch(e:any) { toast.add({title:e?.message || '操作失败',color:'error'}) }
  finally { busy.value = false }
}
const nameOpen = ref(false)
const name = ref('')
const editing = ref(0)
function create() { editing.value=0;name.value='';nameOpen.value=true }
function rename(row:Entry) { editing.value=row.id;name.value=row.name;nameOpen.value=true }
function saveName() { run(async()=>{await request('save',{id:editing.value,parent:current.value,name:name.value});nameOpen.value=false}) }
const moveOpen = ref(false)
const keys = ref<string[]>([])
const target = ref('0')
const options = ref<{label:string;value:string}[]>([])
async function move(items:string[]) {
  if (!items.length) { toast.add({title:'请选择文件或文件夹',color:'warning'});return }
  try {
    const data = await request('options')
    options.value=data.folders.map((f:any)=>({label:f.name,value:String(f.id)}))
    keys.value=[...items];target.value=String(current.value);moveOpen.value=true
  } catch(e:any) { toast.add({title:e?.message || '读取目录失败',color:'error'}) }
}
function saveMove() { run(async()=>{await request('move',{keys:keys.value,target:target.value});moveOpen.value=false}) }
function pin(row:Entry) { run(()=>request('pin',{key:row.item_key,value:row.is_pinned?0:1})) }
async function remove(row:Entry) {
  try {
    const ok=await confirm({title:'删除空文件夹',description:`确定删除“${row.name}”？只允许删除空文件夹，隐藏文件也算内容。`,color:'error',confirmText:'删除',onConfirm:()=>request('delete',{id:row.id})})
    if(ok) emit('refresh')
  }catch(e:any){toast.add({title:e?.message || '删除失败',color:'error'})}
}
function saveOrders() {
  run(()=>request('sort',{folder:current.value,mode:'manual',orders:JSON.stringify(Object.fromEntries(props.rows.map(r=>[r.item_key,r.sort_order])))}))
}
function applyMode(){run(()=>request('sort',{folder:current.value,mode:chosenMode.value}))}
defineExpose({move,rename,pin,remove})
</script>

<template>
  <UCard>
    <nav aria-label="文件夹路径" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
      <NuxtLink :to="{path:'/file',query:{}}" class="text-primary">根目录</NuxtLink>
      <template v-for="part in path" :key="part.id"><span class="text-muted">/</span><NuxtLink :to="{path:'/file',query:{folder:String(part.id)}}" class="break-all text-primary">{{ part.name }}</NuxtLink></template>
    </nav>
    <div class="flex flex-wrap items-center gap-2">
      <UButton icon="i-lucide-folder-plus" label="新建文件夹" @click="create" />
      <UButton variant="outline" label="批量移动" :disabled="!selected.length" @click="move(selected)" />
      <UButton variant="outline" label="保存排序" :loading="busy" @click="saveOrders" />
      <USelect v-model="chosenMode" :items="modes" aria-label="本目录排序" class="w-44" />
      <UButton variant="soft" label="应用排序" :loading="busy" @click="applyMode" />
    </div>
    <p class="mt-3 text-xs text-muted">置顶优先；文件和文件夹按同一规则混排，排序数字越小越靠前。新上传文件默认放在根目录。</p>
  </UCard>
  <UModal v-model:open="nameOpen" :title="editing?'重命名文件夹':'新建文件夹'">
    <template #body><form class="space-y-4" @submit.prevent="saveName"><UFormField label="文件夹名称"><UInput v-model="name" autofocus maxlength="255" required class="w-full" aria-label="文件夹名称" /></UFormField><div class="flex justify-end gap-2"><UButton color="neutral" variant="outline" label="取消" @click="nameOpen=false" /><UButton type="submit" label="保存" :loading="busy" /></div></form></template>
  </UModal>
  <UModal v-model:open="moveOpen" title="移动到文件夹">
    <template #body><form class="space-y-4" @submit.prevent="saveMove"><p>移动 {{ keys.length }} 个项目，原有外链保持不变。</p><USelect v-model="target" :items="options" aria-label="目标文件夹" class="w-full" /><p class="text-xs text-muted">不能移动到自身或子文件夹。</p><div class="flex justify-end gap-2"><UButton color="neutral" variant="outline" label="取消" @click="moveOpen=false" /><UButton type="submit" label="确定移动" :loading="busy" /></div></form></template>
  </UModal>
</template>
