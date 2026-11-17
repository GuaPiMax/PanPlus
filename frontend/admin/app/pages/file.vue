<script setup lang="ts">
import type { TableColumn, DropdownMenuItem } from '@nuxt/ui'
import type { PreviewFile } from '~/utils/types'

useHead({ title: '文件管理' })

interface FileRow extends PreviewFile {
  id: number
  item_key: string
  is_folder: number
  is_pinned: number
  sort_order: number
  uid: number
  storage_id: number
  storage_name: string
  size: number
  hash: string
  addtime: string
  lasttime: string | null
  ip: string
  count: number
  block: number
  hide: number
  haspwd: number
  pageurl: string
}

const route = useRoute()
const folderId = computed(() => Number(route.query.folder || 0))
const manager = ref<any>(null)
const folderPath = ref<{id:number;name:string}[]>([])
const folderMode = ref('manual')
const { get, post } = useApi()
const toast = useToast()
const confirm = useConfirm()

const { page, pagesize, query, filters, search, reset, setPage, setPagesize } = useListQuery({
  type: '1',
  kw: '',
  uid: '',
  dstatus: '-1',
  storage_id: '0',
  orderby: '0',
  folder: '0'
})

const storageOptions = ref([{ label: '全部存储', value: '0' }])
get('storageList').then((res) => {
  storageOptions.value = [{ label: '全部存储', value: '0' }, ...(res.data || []).map((s: { id: number, name: string }) => ({ label: s.name, value: String(s.id) }))]
}).catch(() => {})

const TYPE_OPTIONS = [
  { label: '文件名', value: '1' },
  { label: '文件Hash', value: '2' },
  { label: '文件格式', value: '3' },
  { label: '上传者IP', value: '4' }
]
const STATUS_OPTIONS = [
  { label: '全部状态', value: '-1' },
  { label: '正常文件', value: '0' },
  { label: '已屏蔽文件', value: '1' },
  { label: '待审核文件', value: '2' }
]
const ORDER_OPTIONS = [
  { label: '默认排序', value: '0' },
  { label: '按下载量排序', value: '1' }
]
const PAGE_SIZE_OPTIONS = PAGE_SIZES.map(n => ({ label: `${n} 条/页`, value: n }))

const loading = ref(false)
const list = ref<FileRow[]>([])
const total = ref(0)
const selected = ref<string[]>([])

async function fetchList() {
  loading.value = true
  try {
    const q = query.value
    const data = await post('fileList', {
      folder: folderId.value,
      type: q.type,
      kw: q.kw,
      uid: q.uid,
      dstatus: q.dstatus,
      storage_id: q.storage_id,
      orderby: q.orderby,
      offset: (page.value - 1) * pagesize.value,
      limit: pagesize.value
    })
    folderPath.value=data.path || []
    folderMode.value=data.mode || 'manual'
    list.value = data.rows || []
    total.value = data.total || 0
    selected.value = selected.value.filter(id => list.value.some(row => row.item_key === id))
  } catch (e: any) {
    toast.add({ title: e?.message || '加载文件列表失败', color: 'error' })
  } finally {
    loading.value = false
  }
}

watch(() => [page.value, pagesize.value, query.value, folderId.value], fetchList, { immediate: true, deep: true })

const allSelected = computed(() => list.value.length > 0 && list.value.every(row => selected.value.includes(row.item_key)))
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value)

function toggleAll(value: boolean | 'indeterminate') {
  selected.value = value === true ? list.value.map(row => row.item_key) : []
}

function toggleRow(id: string, value: boolean | 'indeterminate') {
  selected.value = value === true ? [...new Set([...selected.value, id])] : selected.value.filter(v => v !== id)
}

const STATUS_META: Record<number, { label: string, color: 'success' | 'error' | 'warning', next: number, tip: string }> = {
  0: { label: '正常', color: 'success', next: 1, tip: '点击封禁' },
  1: { label: '封禁', color: 'error', next: 0, tip: '点击解封' },
  2: { label: '待审', color: 'warning', next: 0, tip: '点击通过审核' }
}
const statusMeta = (block: number) => STATUS_META[block] || STATUS_META[0]!

/** 正在修改状态的文件 */
const blocking = reactive<Record<number, boolean>>({})

async function setBlock(row: FileRow) {
  if (blocking[row.id]) return
  blocking[row.id] = true
  try {
    await post('setBlock', { id: row.id, status: statusMeta(row.block).next })
    row.block = statusMeta(row.block).next
    toast.add({ title: '修改成功', color: 'success' })
  } catch (e: any) {
    toast.add({ title: e?.message || '修改失败', color: 'error' })
  } finally {
    delete blocking[row.id]
  }
}

async function delFile(row: FileRow) {
  try {
    // 删除期间确认弹窗保持打开并显示加载中
    const ok = await confirm({
      title: '删除文件',
      description: `你确定要删除文件「${row.name}」吗？删除后无法恢复。`,
      confirmText: '删除',
      color: 'error',
      onConfirm: () => post('delFile', { id: row.id })
    })
    if (!ok) return
    toast.add({ title: '删除成功', color: 'success' })
    fetchList()
  } catch (e: any) {
    toast.add({ title: e?.message || '删除失败', color: 'error' })
  }
}

/** 批量操作进行中 */
const batching = ref(false)

async function operation(status: 0 | 1 | 2) {
  if (!selected.value.length) {
    toast.add({ title: '未选中文件', color: 'warning' })
    return
  }
  if (batching.value) return
  const fileIds = list.value.filter(r=>!r.is_folder && selected.value.includes(r.item_key)).map(r=>r.id)
  if(!fileIds.length){toast.add({title:'此批量操作只处理文件，请使用目录删除或批量移动',color:'warning'});return}
  const request = () => post('operation', { status, checkbox: fileIds })
  batching.value = true
  try {
    let res: { msg?: string } | undefined
    if (status === 0) {
      const ok = await confirm({
        title: '批量删除',
        description: `确定要删除已选中的 ${selected.value.length} 个文件吗？删除后无法恢复。`,
        confirmText: '删除',
        color: 'error',
        onConfirm: async () => { res = await request() }
      })
      if (!ok) return
    } else {
      res = await request()
    }
    toast.add({ title: res?.msg || '操作成功', color: 'success' })
    selected.value = []
    fetchList()
  } catch (e: any) {
    toast.add({ title: e?.message || '操作失败', color: 'error' })
  } finally {
    batching.value = false
  }
}

const batchItems = computed<DropdownMenuItem[]>(() => [
  { label: '删除', icon: 'i-lucide-trash-2', color: 'error', onSelect: () => operation(0) },
  { label: '封禁', icon: 'i-lucide-ban', onSelect: () => operation(1) },
  { label: '解封', icon: 'i-lucide-circle-check', onSelect: () => operation(2) }
])

const previewOpen = ref(false)
const previewFile = ref<FileRow | null>(null)
function preview(row: FileRow) {
  previewFile.value = row
  previewOpen.value = true
}

const editOpen = ref(false)
const editId = ref<number | null>(null)
function edit(row: FileRow) {
  editId.value = row.id
  editOpen.value = true
}

const ipUrl = (ip: string) => `https://m.ip138.com/iplookup.asp?ip=${encodeURIComponent(ip)}`
const userTo = (uid: number) => ({ path: '/user', query: { type: '1', kw: String(uid) } })

const columnPinning = { right: ['action'] }
const pinWidth = (px: number) => {
  const width = `${px}px`
  return { th: { width, minWidth: width }, td: { width, minWidth: width } }
}

const columns: TableColumn<FileRow>[] = [
  { id: 'select' },
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'uid', header: '用户' },
  { accessorKey: 'name', header: '文件名' },
  { accessorKey: 'size', header: '大小' },
  { accessorKey: 'type', header: '格式' },
  { accessorKey: 'storage_name', header: '存储' },
  { accessorKey: 'addtime', header: '上传日期/上次下载' },
  { accessorKey: 'ip', header: '上传IP/下载量' },
  { accessorKey: 'sort_order', header: '排序数字' },
  { accessorKey: 'block', header: '状态', size: 80, meta: { style: pinWidth(80), class: { th: 'shadow-[-8px_0_8px_-6px_rgb(15_23_42/0.12)]', td: 'shadow-[-8px_0_8px_-6px_rgb(15_23_42/0.12)]' } } },
  { id: 'action', header: '操作', size: 300, meta: { style: pinWidth(300) } }
]
</script>

<template>
  <AdminPage id="file" title="文件管理" icon="i-lucide-folder-open">
    <template #right>
      <UDropdownMenu :items="batchItems" :disabled="batching">
        <UButton color="neutral" variant="outline" trailing-icon="i-lucide-chevron-down" :loading="batching" :label="batching ? '处理中…' : selected.length ? `批量操作（${selected.length}）` : '批量操作'" />
      </UDropdownMenu>
    </template>

    <div class="space-y-4">
      <FolderManager ref="manager" :rows="list" :selected="selected" :mode="folderMode" :path="folderPath" @refresh="fetchList" />
      <UCard :ui="{ body: 'p-3 sm:p-4' }">
        <form class="flex flex-wrap items-center gap-2" @submit.prevent="search">
          <UFieldGroup class="w-full sm:w-auto">
            <USelect v-model="filters.type" :items="TYPE_OPTIONS" class="w-28" />
            <UInput v-model="filters.kw" placeholder="搜索内容" class="flex-1 sm:w-56" />
          </UFieldGroup>
          <UInput v-model="filters.uid" placeholder="UID" class="w-24" />
          <USelect v-model="filters.dstatus" :items="STATUS_OPTIONS" class="w-32" />
          <USelect v-if="storageOptions.length > 2" v-model="filters.storage_id" :items="storageOptions" class="w-36" />
          <USelect v-model="filters.orderby" :items="ORDER_OPTIONS" class="w-36" />
          <UButton type="submit" icon="i-lucide-search" label="搜索" />
          <UButton color="neutral" variant="outline" icon="i-lucide-rotate-ccw" label="重置" @click="reset" />
        </form>
      </UCard>

      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <!-- 电脑端表格 -->
        <UTable
          class="hidden lg:block [&_[data-pinned]]:bg-(--ui-bg)"
          :data="list"
          :columns="columns"
          :column-pinning="columnPinning"
          :loading="loading"
          :ui="{ td: 'py-2.5 align-middle', th: 'whitespace-nowrap' }"
        >
          <template #select-header>
            <UCheckbox :model-value="someSelected ? 'indeterminate' : allSelected" aria-label="全选" @update:model-value="toggleAll" />
          </template>
          <template #select-cell="{ row }">
            <UCheckbox :model-value="selected.includes(row.original.item_key)" aria-label="选择" @update:model-value="(v: boolean | 'indeterminate') => toggleRow(row.original.item_key, v)" />
          </template>
          <template #id-cell="{ row }">
            <span class="font-semibold">{{ row.original.id }}</span>
          </template>
          <template #uid-cell="{ row }">
            <span v-if="row.original.is_folder">—</span>
            <NuxtLink v-else-if="row.original.uid > 0" :to="userTo(row.original.uid)" class="text-primary hover:underline">{{ row.original.uid }}</NuxtLink>
            <span v-else class="text-(--ui-text-muted)">游客</span>
          </template>
          <template #name-cell="{ row }">
            <NuxtLink v-if="row.original.is_folder" :to="{path:'/file',query:{folder:String(row.original.id)}}" class="flex gap-2 items-center text-primary"><UIcon name="i-lucide-folder" class="size-5" />{{ row.original.name }}<UBadge v-if="row.original.is_pinned" size="sm" variant="subtle">置顶</UBadge></NuxtLink>
            <template v-else>
            <div class="flex max-w-64 items-center gap-2 xl:max-w-sm 2xl:max-w-md">
              <UIcon :name="typeIcon(row.original.type)" class="size-5 shrink-0 text-primary/80" />
              <a :href="row.original.fileurl" class="truncate hover:text-primary" :title="`${row.original.name}（点击下载）`">{{ row.original.name }}</a>
              <UBadge v-if="row.original.is_pinned" size="sm" variant="subtle">置顶</UBadge>
              <UIcon v-if="row.original.haspwd" name="i-lucide-lock" class="size-3.5 shrink-0 text-(--ui-text-muted)" title="已设置下载密码" />
              <UIcon v-if="row.original.hide" name="i-lucide-eye-off" class="size-3.5 shrink-0 text-(--ui-text-muted)" title="首页隐藏" />
              <UButton v-if="row.original.view_type" size="xs" variant="link" class="shrink-0 px-0" label="预览" @click="preview(row.original)" />
            </div>
          </template>
          </template>
          <template #size-cell="{ row }">
            <span class="whitespace-nowrap">{{ row.original.is_folder ? "—" : sizeFormat(row.original.size) }}</span>
          </template>
          <template #type-cell="{ row }">
            <UBadge color="info" variant="subtle">{{ row.original.is_folder ? '文件夹' : (row.original.type || '未知') }}</UBadge>
          </template>
          <template #storage_name-cell="{ row }">
            <span class="whitespace-nowrap text-xs text-(--ui-text-muted)">{{ row.original.storage_name }}</span>
          </template>
          <template #addtime-cell="{ row }">
            <div class="whitespace-nowrap text-xs leading-5 text-(--ui-text-muted)">
              <div>{{ row.original.addtime }}</div>
              <div>{{ row.original.lasttime || '-' }}</div>
            </div>
          </template>
          <template #ip-cell="{ row }"><span v-if="row.original.is_folder">—</span><template v-else>
            <div class="text-xs leading-5">
              <a :href="ipUrl(row.original.ip)" target="_blank" rel="noreferrer" class="text-primary hover:underline">{{ row.original.ip }}</a>
              <div class="font-semibold">{{ row.original.count }}</div>
            </div>
          </template></template>
          <template #sort_order-cell="{ row }"><UInput v-model="row.original.sort_order" type="number" aria-label="排序数字" class="w-24" /></template>
          <template #block-cell="{ row }"><span v-if="row.original.is_folder">—</span>
            <UButton v-if="!row.original.is_folder" size="xs" variant="soft" :color="statusMeta(row.original.block).color" :label="statusMeta(row.original.block).label" :title="statusMeta(row.original.block).tip" :loading="blocking[row.original.id]" @click="setBlock(row.original)" />
          </template>
          <template #action-cell="{ row }">
            <div class="flex flex-wrap items-center gap-1">
              <UButton size="xs" variant="soft" color="neutral" label="移动" @click="manager?.move([row.original.item_key])" />
              <UButton size="xs" variant="soft" color="neutral" :label="row.original.is_pinned?'取消置顶':'置顶'" @click="manager?.pin(row.original)" />
              <template v-if="row.original.is_folder"><UButton size="xs" variant="soft" label="重命名" @click="manager?.rename(row.original)" /><UButton size="xs" variant="soft" color="error" label="删除目录" @click="manager?.remove(row.original)" /></template>
              <template v-else><UButton size="xs" variant="soft" color="info" label="编辑" @click="edit(row.original)" />
              <UButton size="xs" variant="soft" color="warning" label="查看" :href="row.original.pageurl" target="_blank" external />
              <UButton size="xs" variant="soft" color="error" label="删除" @click="delFile(row.original)" />
              </template>
            </div>
          </template>
          <template #empty>
            <div class="py-10 text-center text-(--ui-text-muted)">没有找到文件</div>
          </template>
        </UTable>

        <!-- 手机端卡片 -->
        <div class="divide-y divide-(--ui-border) lg:hidden">
          <div class="flex items-center gap-3 px-4 py-2.5 text-sm text-(--ui-text-muted)">
            <UCheckbox :model-value="someSelected ? 'indeterminate' : allSelected" label="全选" @update:model-value="toggleAll" />
          </div>
          <template v-if="loading && !list.length">
            <div v-for="i in 5" :key="i" class="flex gap-3 p-4">
              <USkeleton class="size-10 rounded-lg" />
              <div class="flex-1 space-y-2">
                <USkeleton class="h-4 w-3/4" />
                <USkeleton class="h-3 w-1/2" />
              </div>
            </div>
          </template>
          <div v-else-if="!list.length" class="py-10 text-center text-(--ui-text-muted)">没有找到文件</div>
          <div v-for="item in list" v-else :key="item.item_key" class="flex items-start gap-3 p-4" :class="{ 'opacity-60': loading }">
            <UCheckbox class="mt-2.5" :model-value="selected.includes(item.item_key)" @update:model-value="(v: boolean | 'indeterminate') => toggleRow(item.item_key, v)" />
            <div v-if="item.is_folder" class="min-w-0 flex-1 space-y-3"><NuxtLink :to="{path:'/file',query:{folder:String(item.id)}}" class="flex gap-2 items-center text-primary break-all"><UIcon name="i-lucide-folder" class="size-6 shrink-0" />{{ item.name }}<UBadge v-if="item.is_pinned" variant="subtle">置顶</UBadge></NuxtLink><UInput v-model="item.sort_order" type="number" aria-label="排序数字" class="w-24" /><div class="flex flex-wrap gap-2"><UButton size="xs" variant="soft" label="重命名" @click="manager?.rename(item)" /><UButton size="xs" variant="soft" label="移动" @click="manager?.move([item.item_key])" /><UButton size="xs" variant="soft" :label="item.is_pinned?'取消置顶':'置顶'" @click="manager?.pin(item)" /><UButton size="xs" color="error" variant="soft" label="删除目录" @click="manager?.remove(item)" /></div></div>
            <template v-else>
            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <UIcon :name="typeIcon(item.type)" class="size-6 text-primary" />
            </div>
            <div class="min-w-0 flex-1">
              <a :href="item.fileurl" class="line-clamp-2 block font-medium break-all">{{ item.name }}</a>
              <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-(--ui-text-muted)">
                <span>#{{ item.id }}</span>
                <span>{{ sizeFormat(item.size) }}</span>
                <span class="uppercase">{{ item.type || '未知' }}</span>
                <span>{{ item.storage_name }}</span>
                <span>下载 {{ item.count }}</span>
                <NuxtLink v-if="item.uid > 0" :to="userTo(item.uid)" class="text-primary">UID {{ item.uid }}</NuxtLink>
                <span v-else>游客</span>
                <span>{{ item.addtime }}</span>
                <a :href="ipUrl(item.ip)" target="_blank" rel="noreferrer">{{ item.ip }}</a>
              </div>
              <UBadge v-if="item.is_pinned" variant="subtle">置顶</UBadge><UInput v-model="item.sort_order" type="number" aria-label="排序数字" class="mt-2 w-24" />
              <div class="mt-2 flex flex-wrap gap-1.5"><UButton size="xs" variant="soft" label="移动" @click="manager?.move([item.item_key])" /><UButton size="xs" variant="soft" :label="item.is_pinned?'取消置顶':'置顶'" @click="manager?.pin(item)" />
                <UButton size="xs" variant="soft" :color="statusMeta(item.block).color" :label="statusMeta(item.block).label" :loading="blocking[item.id]" @click="setBlock(item)" />
                <UButton v-if="item.view_type" size="xs" variant="soft" color="neutral" label="预览" @click="preview(item)" />
                <UButton size="xs" variant="soft" color="info" label="编辑" @click="edit(item)" />
                <UButton size="xs" variant="soft" color="warning" label="查看" :href="item.pageurl" target="_blank" external />
                <UButton size="xs" variant="soft" color="error" label="删除" @click="delFile(item)" />
              </div>
            </div>
            </template>
          </div>
        </div>

        <template #footer>
          <div class="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
              <span class="text-(--ui-text-muted)">共 {{ total }} 个项目</span>
              <USelect :model-value="pagesize" :items="PAGE_SIZE_OPTIONS" size="sm" class="w-28" @update:model-value="(v: number) => setPagesize(v)" />
            </div>
            <UPagination
              v-if="total > pagesize"
              :page="page"
              :total="total"
              :items-per-page="pagesize"
              :sibling-count="1"
              show-edges
              size="sm"
              @update:page="setPage"
            />
          </div>
        </template>
      </UCard>
    </div>

    <FilePreviewModal v-model:open="previewOpen" :file="previewFile" />
    <FileEditModal v-model:open="editOpen" :id="editId" @saved="fetchList" />
  </AdminPage>
</template>
