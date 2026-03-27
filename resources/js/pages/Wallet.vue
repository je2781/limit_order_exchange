<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import LimitOrderForm from '@/components/LimitOrderForm.vue'
import { Order, OrderPayload, Profile, Trade } from '@/types/general'


// ─── State ────────────────────────────────────────────────────────────────────

const page = usePage()
const userId = computed(() => (page.props.auth as any)?.user?.id)

const profile = ref<Profile>({ balance: 0, assets: [] })
const orders = ref<Order[]>([])
const orderbook = ref<{ buy: Order[]; sell: Order[] }>({ buy: [], sell: [] })
const selectedSymbol = ref('BTC')
const symbols = ['BTC', 'ETH', 'SOL', 'XRP']
const recentTrades = ref<{ trade: Trade; fee: number }[]>([])
const loading = ref(true)
const notification = ref<{ msg: string; type: 'success' | 'error' } | null>(null)

const showOrderModal = ref(false)

const openMenuId = ref<number | null>(null)
const cancellingId = ref<number | null>(null)

// ─── Filters ──────────────────────────────────────────────────────────────────

const filterSymbol = ref<string>('ALL')
const filterSide = ref<'ALL' | 'buy' | 'sell'>('ALL')
const filterStatus = ref<'ALL' | '1' | '2' | '3'>('ALL')

let filteredOrders = computed(() =>
  orders.value.filter(o => {
    if (filterSymbol.value !== 'ALL' && o.symbol !== filterSymbol.value) return false
    if (filterSide.value !== 'ALL' && o.side !== filterSide.value) return false
    if (filterStatus.value !== 'ALL' && String(o.status) !== filterStatus.value) return false
    return true
  })
)

const activeFiltersCount = computed(() =>
  [filterSymbol.value !== 'ALL', filterSide.value !== 'ALL', filterStatus.value !== 'ALL']
    .filter(Boolean).length
)

function resetFilters() {
  filterSymbol.value = 'ALL'
  filterSide.value = 'ALL'
  filterStatus.value = 'ALL'
}

// ─── Volume stats ─────────────────────────────────────────────────────────────

const orderbookVolume = computed(() => {
  const buys = orderbook.value.buy ?? []
  const sells = orderbook.value.sell ?? []
  const buyVolume = buys.reduce((sum, o) => sum + o.price * o.amount, 0)
  const sellVolume = sells.reduce((sum, o) => sum + o.price * o.amount, 0)
  const bestBid = buys.length ? Math.max(...buys.map(o => o.price)) : null
  const bestAsk = sells.length ? Math.min(...sells.map(o => o.price)) : null
  const spread = bestBid && bestAsk ? bestAsk - bestBid : null
  return { buyVolume, sellVolume, totalOrders: buys.length + sells.length, bestBid, bestAsk, spread }
})

// ─── API ──────────────────────────────────────────────────────────────────────
async function endSession() {
  await axios.post('/api/logout')
  window.location.href = '/login';
}

async function fetchProfile() {
  const { data } = await axios.get<Profile>('/api/profile')
  profile.value = data
}

async function fetchOrders() {
  const { data } = await axios.get(`/api/orders?symbol=${selectedSymbol.value}`)
  orderbook.value = data as any
}

async function fetchAllOrders() {
  const results = await Promise.all(
    symbols.map(s => axios.get(`/api/orders?symbol=${s}&all=true`).then(r => r.data))
  )
  const all: Order[] = []
  results.forEach((r: any) => {
    if (r.buy) all.push(...r.buy)
    if (r.sell) all.push(...r.sell)
  })
  orders.value = all
}

async function selectSymbol(symbol: string) {
  selectedSymbol.value = symbol
  await fetchOrders()
}

async function onOrderPlaced(sym: string) {
  showOrderModal.value = false;
  await Promise.all([selectSymbol(sym), fetchProfile(), fetchAllOrders()])
  showNotification('Order placed successfully', 'success')
}

// Cancel order action
async function cancelOrder(id: number) {
  cancellingId.value = id
  openMenuId.value = null
  try {
    await axios.post(`/api/orders/${id}/cancel`)
    // Remove from orders list or mark as cancelled
    const order = orders.value.find(o => o.id === id)
    if (order) order.status = 3
    await Promise.all([fetchProfile(), fetchOrders(), fetchAllOrders()])
    showNotification('Order cancelled successfully', 'success')
  } catch (err: any) {
    showNotification(err?.response?.data?.message ?? 'Failed to cancel order', 'error')
  } finally {
    cancellingId.value = null
  }
}

// ─── Real-time ────────────────────────────────────────────────────────────────

let echo: Echo<'pusher'> | null = null

function setupEcho() {
  window.Pusher = Pusher

  echo = new Echo<'pusher'>({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
    Pusher
  })

  echo.private(`user.${userId.value}`)
    .listen('OrderMatched', async (e: { trade: Trade; fee: number }) => {
      recentTrades.value.unshift(e)
      if (recentTrades.value.length > 10) recentTrades.value.pop()

      orders.value = orders.value.map(o => {
        if (
          o.status === 1 &&
          o.symbol === e.trade.symbol &&
          Math.abs(o.price - e.trade.price) < 0.00000001
        ) {
          return { ...o, status: 2 as const }
        }
        return o
      })

      await Promise.all([fetchProfile(), fetchOrders()])

      showNotification(
        `Matched: ${e.trade.amount} ${e.trade.symbol} @ $${Number(e.trade.price).toLocaleString()} · fee $${Number(e.fee).toFixed(2)}`,
        'success'
      )
    })
}

function showNotification(msg: string, type: 'success' | 'error' = 'success') {
  notification.value = { msg, type }
  setTimeout(() => (notification.value = null), 4000)
}

// Close dropdown when clicking outside
function onDocumentClick() {
  openMenuId.value = null
}

// ─── Lifecycle ────────────────────────────────────────────────────────────────

onMounted(async () => {
  await Promise.all([fetchProfile(), fetchOrders(), fetchAllOrders()])
  loading.value = false
  setupEcho()

  document.addEventListener('click', onDocumentClick)

})

onUnmounted(() => {
  echo?.disconnect()
  document.removeEventListener('click', onDocumentClick)
})

// ─── Helpers ──────────────────────────────────────────────────────────────────

const statusLabel = (s: number) => ({ 1: 'Open', 2: 'Filled', 3: 'Cancelled' }[s] ?? '—')
const statusClass = (s: number) => ({
  1: 'text-amber-400 bg-amber-400/10',
  2: 'text-emerald-400 bg-emerald-400/10',
  3: 'text-zinc-500 bg-zinc-500/10',
}[s] ?? '')
const usd = (v: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(v)
const num = (v: number, dp = 8) => Number(v).toFixed(dp)
</script>

<template>
  <div class="min-h-screen bg-zinc-950 text-zinc-100 font-mono">

    <!-- Toast -->
    <Transition
      enter-active-class="transition duration-300 ease-out"
      enter-from-class="translate-y-4 opacity-0"
      leave-active-class="transition duration-200 ease-in"
      leave-to-class="translate-y-4 opacity-0"
    >
      <div
        v-if="notification"
        :class="[
          'fixed bottom-6 right-6 z-50 text-xs font-bold px-4 py-3 rounded-lg shadow-xl max-w-sm',
          notification.type === 'success'
            ? 'bg-emerald-500 text-black shadow-emerald-500/20'
            : 'bg-rose-500 text-white shadow-rose-500/20'
        ]"
      >
        {{ notification.type === 'success' ? '⚡' : '✕' }} {{ notification.msg }}
      </div>
    </Transition>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center min-h-screen">
      <div class="flex flex-col items-center gap-4">
        <div class="w-10 h-10 border-2 border-zinc-700 border-t-emerald-400 rounded-full animate-spin" />
        <p class="text-zinc-500 text-xs tracking-widest uppercase">Loading wallet</p>
      </div>
    </div>

    <template v-else>
      <header class="border-b border-zinc-800 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
          <span class="text-xs tracking-[0.2em] uppercase text-zinc-400">Wallet Overview</span>
        </div>
        <div class="inline-flex gap-3 flex-row items-center">
                <!-- Trigger button -->
            <button
                @click="showOrderModal = true"
                class="px-6 py-3 bg-emerald-500 sm:inline-block hidden hover:bg-emerald-400 text-black text-xs font-bold tracking-[0.15em] uppercase rounded-lg transition-all active:scale-[0.98]"
            >
                + Place Order
            </button>
            <form @submit.prevent="endSession">
                <button class="text-xs text-zinc-400 font-semibold tracking-widest cursor-pointer" type="submit">LOGOUT</button>
            </form>
        </div>
      </header>

      <section class="max-w-7xl mx-auto px-6 py-8 flex flex-col gap-8">
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

          <!-- LEFT -->
          <div class="xl:col-span-1 flex flex-col gap-6">

            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
              <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500 mb-3">USD Balance</p>
              <p class="text-4xl font-bold tracking-tight text-white tabular-nums">{{ usd(profile.balance) }}</p>
              <p class="text-[10px] text-zinc-600 mt-2 tracking-widest">AVAILABLE FUNDS</p>
            </div>

            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
              <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500 mb-4">Asset Balances</p>
              <p v-if="profile.assets.length === 0" class="text-zinc-600 text-xs">No assets held.</p>
              <div v-else class="flex flex-col gap-3">
                <div
                  v-for="asset in profile.assets" :key="asset.symbol"
                  class="flex items-center justify-between border border-zinc-800 rounded-lg px-4 py-3 hover:border-zinc-700 transition-colors"
                >
                  <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-full bg-zinc-800 flex items-center justify-center text-[10px] font-bold text-zinc-300">
                      {{ asset.symbol.slice(0, 2) }}
                    </div>
                    <span class="text-sm font-semibold text-zinc-200">{{ asset.symbol }}</span>
                  </div>
                  <div class="text-right">
                    <p class="text-sm tabular-nums text-white">{{ num(asset.amount) }}</p>
                    <p v-if="asset.locked_amount > 0" class="text-[10px] text-amber-500 tabular-nums">{{ num(asset.locked_amount) }} locked</p>
                  </div>
                </div>
              </div>
            </div>

            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
              <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500 mb-4">Recent Matches</p>
              <p v-if="recentTrades.length === 0" class="text-zinc-600 text-xs">No matches yet — listening live.</p>
              <div v-else class="flex flex-col gap-2">
                <div
                  v-for="(entry, i) in recentTrades" :key="i"
                  class="flex justify-between items-center text-xs border-b border-zinc-800 pb-2 last:border-0"
                >
                  <div>
                    <span class="text-emerald-400 font-semibold">{{ entry.trade.symbol }}</span>
                    <span class="text-zinc-400 ml-2">{{ num(entry.trade.amount, 6) }}</span>
                  </div>
                  <div class="text-right">
                    <p class="text-white tabular-nums">{{ usd(entry.trade.price) }}</p>
                    <p class="text-[10px] text-rose-400">fee {{ usd(entry.fee) }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- RIGHT -->
          <div class="xl:col-span-2 flex flex-col gap-6">

            <!-- Orderbook + Volume -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4 md:p-6">
              <div class="flex items-center justify-between mb-5">
                <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500">Orderbook</p>
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="sym in symbols" :key="sym"
                    @click="selectSymbol(sym)"
                    :class="[
                      'px-2.5 py-1 rounded text-[11px] font-bold tracking-widest transition-all',
                      selectedSymbol === sym ? 'bg-emerald-500 text-black' : 'text-zinc-500 hover:text-zinc-300 border border-zinc-800 hover:border-zinc-600'
                    ]"
                  >{{ sym }}</button>
                </div>
              </div>

              <!-- Volume stats — 2 cols on mobile, 4 on md+ -->
              <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
                <div class="bg-zinc-800/50 rounded-lg px-3 py-2">
                  <p class="text-[9px] uppercase tracking-widest text-zinc-600 mb-1">Best Bid</p>
                  <p class="text-xs font-bold text-emerald-400 tabular-nums">{{ orderbookVolume.bestBid ? usd(orderbookVolume.bestBid) : '—' }}</p>
                </div>
                <div class="bg-zinc-800/50 rounded-lg px-3 py-2">
                  <p class="text-[9px] uppercase tracking-widest text-zinc-600 mb-1">Best Ask</p>
                  <p class="text-xs font-bold text-rose-400 tabular-nums">{{ orderbookVolume.bestAsk ? usd(orderbookVolume.bestAsk) : '—' }}</p>
                </div>
                <div class="bg-zinc-800/50 rounded-lg px-3 py-2">
                  <p class="text-[9px] uppercase tracking-widest text-zinc-600 mb-1">Spread</p>
                  <p class="text-xs font-bold text-zinc-300 tabular-nums">{{ orderbookVolume.spread != null ? usd(orderbookVolume.spread) : '—' }}</p>
                </div>
                <div class="bg-zinc-800/50 rounded-lg px-3 py-2">
                  <p class="text-[9px] uppercase tracking-widest text-zinc-600 mb-1">Open Orders</p>
                  <p class="text-xs font-bold text-zinc-300 tabular-nums">{{ orderbookVolume.totalOrders }}</p>
                </div>
              </div>

              <!-- Buy/sell volume bar -->
              <div v-if="orderbookVolume.buyVolume > 0 || orderbookVolume.sellVolume > 0" class="mb-5">
                <div class="flex justify-between text-[10px] mb-1.5">
                  <span class="text-emerald-500">Buy {{ usd(orderbookVolume.buyVolume) }}</span>
                  <span class="text-rose-400">Sell {{ usd(orderbookVolume.sellVolume) }}</span>
                </div>
                <div class="h-1.5 bg-zinc-800 rounded-full overflow-hidden flex">
                  <div
                    class="bg-emerald-500 h-full transition-all duration-500"
                    :style="{ width: `${(orderbookVolume.buyVolume / (orderbookVolume.buyVolume + orderbookVolume.sellVolume)) * 100}%` }"
                  />
                  <div class="bg-rose-500 h-full flex-1" />
                </div>
              </div>

              <!-- Bids + Asks — stack on mobile, side by side on sm+ -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <p class="text-[10px] uppercase tracking-widest text-emerald-500 mb-2">Bids</p>
                  <div class="grid grid-cols-2 text-[10px] text-zinc-600 uppercase tracking-widest mb-1 px-1">
                    <span>Price</span><span class="text-right">Amount</span>
                  </div>
                  <div v-for="order in orderbook.buy" :key="order.id" class="relative grid grid-cols-2 text-xs px-2 py-1.5 rounded overflow-hidden">
                    <div class="absolute inset-0 bg-emerald-500/5 rounded" :style="{ width: `${Math.min(100, order.amount * 100)}%` }" />
                    <span class="relative text-emerald-400 tabular-nums">{{ usd(order.price) }}</span>
                    <span class="relative text-zinc-300 text-right tabular-nums">{{ num(order.amount, 4) }}</span>
                  </div>
                  <p v-if="!orderbook.buy?.length" class="text-zinc-700 text-xs px-2 py-2">No bids</p>
                </div>
                <div>
                  <p class="text-[10px] uppercase tracking-widest text-rose-400 mb-2">Asks</p>
                  <div class="grid grid-cols-2 text-[10px] text-zinc-600 uppercase tracking-widest mb-1 px-1">
                    <span>Price</span><span class="text-right">Amount</span>
                  </div>
                  <div v-for="order in orderbook.sell" :key="order.id" class="relative grid grid-cols-2 text-xs px-2 py-1.5 rounded overflow-hidden">
                    <div class="absolute inset-0 bg-rose-500/5 rounded" :style="{ width: `${Math.min(100, order.amount * 100)}%` }" />
                    <span class="relative text-rose-400 tabular-nums">{{ usd(order.price) }}</span>
                    <span class="relative text-zinc-300 text-right tabular-nums">{{ num(order.amount, 4) }}</span>
                  </div>
                  <p v-if="!orderbook.sell?.length" class="text-zinc-700 text-xs px-2 py-2">No asks</p>
                </div>
              </div>
            </div>

            <!-- Order history + filters -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4 md:p-6">
              <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                  <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500">Order History</p>
                  <span v-if="activeFiltersCount > 0" class="text-[9px] bg-emerald-500/20 text-emerald-400 px-1.5 py-0.5 rounded-full font-bold">
                    {{ activeFiltersCount }} filter{{ activeFiltersCount > 1 ? 's' : '' }}
                  </span>
                </div>
                <button v-if="activeFiltersCount > 0" @click="resetFilters" class="text-[10px] text-zinc-500 hover:text-zinc-300 transition-colors tracking-widest uppercase">
                  Reset
                </button>
              </div>

              <!-- Filters — wrap gracefully on small screens -->
              <div class="flex flex-wrap gap-2 mb-4 items-center">
                <div class="relative">
                  <select v-model="filterSymbol" class="appearance-none bg-zinc-800 border border-zinc-700 text-zinc-300 text-[11px] rounded-lg px-3 py-1.5 pr-7 focus:outline-none focus:border-zinc-500 cursor-pointer">
                    <option value="ALL">All Symbols</option>
                    <option v-for="sym in symbols" :key="sym" :value="sym">{{ sym }}</option>
                  </select>
                  <svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-2.5 h-2.5 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </div>

                <div class="flex gap-0.5 bg-zinc-800 border border-zinc-700 rounded-lg p-0.5">
                  <button v-for="s in (['ALL', 'buy', 'sell'] as const)" :key="s" @click="filterSide = s"
                    :class="['px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-widest transition-all',
                      filterSide === s
                        ? s === 'buy' ? 'bg-emerald-500 text-black' : s === 'sell' ? 'bg-rose-500 text-white' : 'bg-zinc-600 text-white'
                        : 'text-zinc-500 hover:text-zinc-300']"
                  >{{ s === 'ALL' ? 'Both' : s }}</button>
                </div>

                <div class="flex gap-0.5 bg-zinc-800 border border-zinc-700 rounded-lg p-0.5">
                  <button
                    v-for="[val, label] in ([['ALL','All'],['1','Open'],['2','Filled'],['3','Cancelled']] as [string,string][])"
                    :key="val" @click="filterStatus = val as any"
                    :class="['px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-widest transition-all',
                      filterStatus === val
                        ? val === '1' ? 'bg-amber-500/30 text-amber-300' : val === '2' ? 'bg-emerald-500/30 text-emerald-300' : val === '3' ? 'bg-zinc-600 text-zinc-300' : 'bg-zinc-600 text-white'
                        : 'text-zinc-500 hover:text-zinc-300']"
                  >{{ label }}</button>
                </div>

                <span class="ml-auto text-[10px] text-zinc-600 tracking-widest">{{ filteredOrders.length }}/{{ orders.length }}</span>
              </div>

              <!-- Empty — no orders -->
              <div v-if="orders.length === 0" class="flex flex-col items-center justify-center py-16 gap-4">
                <div class="w-12 h-12 rounded-full bg-zinc-800 flex items-center justify-center">
                  <svg class="w-5 h-5 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                  </svg>
                </div>
                <div class="text-center">
                  <p class="text-zinc-400 text-sm font-semibold mb-1">No orders yet</p>
                  <p class="text-zinc-600 text-xs tracking-wide">Place your first limit order to get started</p>
                </div>
                <button @click="showOrderModal = true" class="mt-2 px-5 py-2 bg-emerald-500 hover:bg-emerald-400 text-black text-xs font-bold tracking-[0.15em] uppercase rounded-lg transition-all active:scale-[0.98]">
                  + Place Order
                </button>
              </div>

              <!-- Empty — filters return nothing -->
              <div v-else-if="filteredOrders.length === 0" class="flex flex-col items-center justify-center py-16 gap-3">
                <div class="w-12 h-12 rounded-full bg-zinc-800 flex items-center justify-center">
                  <svg class="w-5 h-5 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z" />
                  </svg>
                </div>
                <div class="text-center">
                  <p class="text-zinc-400 text-sm font-semibold mb-1">No orders match</p>
                  <p class="text-zinc-600 text-xs tracking-wide">Try adjusting or clearing your filters</p>
                </div>
                <button @click="resetFilters" class="mt-2 px-5 py-2 border border-zinc-700 hover:border-zinc-500 text-zinc-400 hover:text-zinc-200 text-xs font-bold tracking-[0.15em] uppercase rounded-lg transition-all">
                  Clear Filters
                </button>
              </div>

              <!-- Table -->
              <div v-else class="overflow-x-auto -mx-4 md:mx-0">
                <table class="w-full text-xs min-w-[520px] px-4 md:px-0">
                  <thead>
                    <tr class="text-zinc-600 uppercase tracking-widest border-b border-zinc-800">
                      <th class="text-left pb-3 font-normal pl-4 md:pl-0">ID</th>
                      <th class="text-left pb-3 font-normal">Symbol</th>
                      <th class="text-left pb-3 font-normal">Side</th>
                      <th class="text-right pb-3 font-normal">Price</th>
                      <th class="text-right pb-3 font-normal">Amount</th>
                      <th class="text-right pb-3 font-normal">Volume</th>
                      <th class="text-right pb-3 font-normal">Status</th>
                      <th class="pb-3 w-8"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="order in filteredOrders" :key="order.id"
                      class="border-b border-zinc-800/50 hover:bg-zinc-800/30 transition-colors group relative"
                    >
                      <td class="py-3 text-zinc-600 pl-4 md:pl-0">#{{ order.id }}</td>
                      <td class="py-3 font-semibold text-zinc-200">{{ order.symbol }}</td>
                      <td class="py-3">
                        <span :class="order.side === 'buy' ? 'text-emerald-400' : 'text-rose-400'" class="uppercase tracking-widest">{{ order.side }}</span>
                      </td>
                      <td class="py-3 text-right tabular-nums text-zinc-300">{{ usd(order.price) }}</td>
                      <td class="py-3 text-right tabular-nums text-zinc-300">{{ num(order.amount, 6) }}</td>
                      <td class="py-3 text-right tabular-nums text-zinc-400">{{ usd(order.price * order.amount) }}</td>
                      <td class="py-3 text-right">
                        <span :class="['text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-widest', statusClass(order.status)]">
                          {{ statusLabel(order.status) }}
                        </span>
                      </td>

                      <!-- Actions column -->
                      <td class="py-3 text-right pr-1 relative">
                        <!-- Only show menu for open orders -->
                        <div v-if="order.status === 1" class="relative inline-block">
                          <button
                            @click.stop="openMenuId = openMenuId === order.id ? null : order.id"
                            class="w-6 h-6 flex items-center justify-center rounded text-zinc-600 hover:text-zinc-300 hover:bg-zinc-700 transition-all opacity-0 group-hover:opacity-100 focus:opacity-100"
                            :class="{ 'opacity-100': openMenuId === order.id }"
                          >
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                              <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                            </svg>
                          </button>

                          <!-- Dropdown -->
                          <Transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="scale-95 opacity-0"
                            leave-active-class="transition duration-75 ease-in"
                            leave-to-class="scale-95 opacity-0"
                          >
                            <div
                              v-if="openMenuId === order.id"
                              class="absolute right-0 top-8 z-30 w-36 bg-zinc-800 border border-zinc-700 rounded-lg shadow-xl shadow-black/40 overflow-hidden"
                            >
                              <button
                                @click.stop="cancelOrder(order.id)"
                                :disabled="cancellingId === order.id"
                                class="w-full flex items-center gap-2 px-3 py-2.5 text-[11px] font-semibold text-rose-400 hover:bg-zinc-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                              >
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                                {{ cancellingId === order.id ? 'Cancelling...' : 'Cancel Order' }}
                              </button>
                            </div>
                          </Transition>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>


        <!-- Modal overlay -->
        <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="opacity-0"
        >
            <div
                v-if="showOrderModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4"
                @click.self="showOrderModal = false"
            >
                <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="scale-95 opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="scale-95 opacity-0"
                >
                <div v-if="showOrderModal" class="relative">
                    <!-- Close button -->
                    <button
                    @click="showOrderModal = false"
                    class="absolute -top-3 -right-3 z-10 w-7 h-7 rounded-full bg-zinc-800 border border-zinc-700 text-zinc-400 hover:text-white flex items-center justify-center text-xs transition-colors"
                    >
                    ✕
                    </button>
                    <LimitOrderForm @placed="onOrderPlaced" @cancel="showOrderModal = false" />
                </div>
                </Transition>
            </div>
        </Transition>
      </section>
    </template>
  </div>
</template>