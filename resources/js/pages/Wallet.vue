<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import LimitOrderForm from '@/components/LimitOrderForm.vue';

// ─── Types ────────────────────────────────────────────────────────────────────
// Make Pusher available globally — Echo needs this
window.Pusher = Pusher;

interface Asset {
  symbol: string
  amount: number
  locked_amount: number
}

interface Profile {
  balance: number
  assets: Asset[]
}

interface Order {
  id: number
  symbol: string
  side: 'buy' | 'sell'
  price: number
  amount: number
  status: 1 | 2 | 3 // 1=open, 2=filled, 3=cancelled
}

interface Trade {
  id: number
  symbol: string
  price: number
  amount: number
  buy_order_id: number
  sell_order_id: number
}

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
const notification = ref<string | null>(null)

// ─── API ──────────────────────────────────────────────────────────────────────

async function fetchProfile() {
  const { data } = await axios.get<Profile>('/profile')
  profile.value = data
}

async function fetchOrders() {
  const { data } = await axios.get<Order[]>(`/orders?symbol=${selectedSymbol.value}`)
  // Store all orders passed back (open/filled/cancelled) separately
  orderbook.value = (data as any)
}

async function fetchAllOrders() {
  // Fetch orders for all symbols and combine
  const results = await Promise.all(
    symbols.map(s => axios.get(`/orders?symbol=${s}`).then(r => r.data))
  )
  // Flatten buy + sell from each symbol into a flat orders list (open only shown in orderbook)
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

// ─── Real-time ────────────────────────────────────────────────────────────────

let echo: Echo<'pusher'> | null = null

function setupEcho() {
  echo = new Echo<'pusher'>({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
  })

  echo.private(`user.${userId.value}`)
    .listen('OrderMatched', (e: { trade: Trade; fee: number }) => {
      // 1. Patch trade into recent trades list
      recentTrades.value.unshift(e)
      if (recentTrades.value.length > 10) recentTrades.value.pop()

      // 2. Update order status in list
      const buyOrder = orders.value.find(o => o.id === e.trade.buy_order_id)
      const sellOrder = orders.value.find(o => o.id === e.trade.sell_order_id)
      if (buyOrder) buyOrder.status = 2
      if (sellOrder) sellOrder.status = 2

      // 3. Refresh balance + assets from server
      fetchProfile()

      // 4. Refresh orderbook
      fetchOrders()

      // 5. Show notification
      showNotification(`Trade matched: ${e.trade.amount} ${e.trade.symbol} @ $${Number(e.trade.price).toLocaleString()}`)
    })
}

function showNotification(msg: string) {
  notification.value = msg
  setTimeout(() => (notification.value = null), 4000)
}

// ─── Lifecycle ────────────────────────────────────────────────────────────────

onMounted(async () => {
  await Promise.all([fetchProfile(), fetchOrders(), fetchAllOrders()])
  loading.value = false
  setupEcho()
})

onUnmounted(() => {
  echo?.disconnect()
})

// ─── Helpers ─────────────────────────────────────────────────────────────────

function statusLabel(status: number) {
  return { 1: 'Open', 2: 'Filled', 3: 'Cancelled' }[status] ?? '—'
}

function statusClass(status: number) {
  return {
    1: 'text-amber-400 bg-amber-400/10',
    2: 'text-emerald-400 bg-emerald-400/10',
    3: 'text-zinc-500 bg-zinc-500/10',
  }[status] ?? ''
}

function usd(val: number) {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(val)
}

function num(val: number, dp = 8) {
  return Number(val).toFixed(dp)
}
</script>

<template>
  <div class="min-h-screen bg-zinc-950 text-zinc-100 font-mono">

    <!-- Notification toast -->
    <Transition
      enter-active-class="transition duration-300 ease-out"
      enter-from-class="translate-y-4 opacity-0"
      leave-active-class="transition duration-200 ease-in"
      leave-to-class="translate-y-4 opacity-0"
    >
      <div
        v-if="notification"
        class="fixed bottom-6 right-6 z-50 bg-emerald-500 text-black text-xs font-bold px-4 py-3 rounded-lg shadow-xl shadow-emerald-500/20 max-w-sm"
      >
        ⚡ {{ notification }}
      </div>
    </Transition>

    <!-- Loading skeleton -->
    <div v-if="loading" class="flex items-center justify-center min-h-screen">
      <div class="flex flex-col items-center gap-4">
        <div class="w-10 h-10 border-2 border-zinc-700 border-t-emerald-400 rounded-full animate-spin" />
        <p class="text-zinc-500 text-xs tracking-widest uppercase">Loading wallet</p>
      </div>
    </div>

    <template v-else>
      <!-- Header -->
      <header class="border-b border-zinc-800 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
          <span class="text-xs tracking-[0.2em] uppercase text-zinc-400">Wallet Overview</span>
        </div>
        <div class="text-xs text-zinc-600 tracking-widest">LIVE</div>
      </header>

      <section class="max-w-7xl mx-auto px-6 py-8 flex flex-col gap-8 items-center">

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

            <!-- ── LEFT COLUMN ── -->
            <div class="xl:col-span-1 flex flex-col gap-6">

            <!-- USD Balance card -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
                <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500 mb-3">USD Balance</p>
                <p class="text-4xl font-bold tracking-tight text-white tabular-nums">
                {{ usd(profile.balance) }}
                </p>
                <p class="text-[10px] text-zinc-600 mt-2 tracking-widest">AVAILABLE FUNDS</p>
            </div>

            <!-- Asset balances -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
                <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500 mb-4">Asset Balances</p>

                <div v-if="profile.assets.length === 0" class="text-zinc-600 text-xs">
                No assets held.
                </div>

                <div v-else class="flex flex-col gap-3">
                <div
                    v-for="asset in profile.assets"
                    :key="asset.symbol"
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
                    <p v-if="asset.locked_amount > 0" class="text-[10px] text-amber-500 tabular-nums">
                        {{ num(asset.locked_amount) }} locked
                    </p>
                    </div>
                </div>
                </div>
            </div>

            <!-- Recent matched trades -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
                <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500 mb-4">Recent Matches</p>
                <div v-if="recentTrades.length === 0" class="text-zinc-600 text-xs">
                No matches yet — listening live.
                </div>
                <div v-else class="flex flex-col gap-2">
                <div
                    v-for="(entry, i) in recentTrades"
                    :key="i"
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

            <!-- ── RIGHT COLUMN ── -->
            <div class="xl:col-span-2 flex flex-col gap-6">

            <!-- Symbol selector + Orderbook -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
                <div class="flex items-center justify-between mb-5">
                <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500">Orderbook</p>
                <div class="flex gap-1">
                    <button
                    v-for="sym in symbols"
                    :key="sym"
                    @click="selectSymbol(sym)"
                    :class="[
                        'px-3 py-1 rounded text-[11px] font-bold tracking-widest transition-all',
                        selectedSymbol === sym
                        ? 'bg-emerald-500 text-black'
                        : 'text-zinc-500 hover:text-zinc-300 border border-zinc-800 hover:border-zinc-600'
                    ]"
                    >
                    {{ sym }}
                    </button>
                </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                <!-- Bids (buy) -->
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-emerald-500 mb-2">Bids</p>
                    <div class="flex flex-col gap-1">
                    <div class="grid grid-cols-2 text-[10px] text-zinc-600 uppercase tracking-widest mb-1 px-1">
                        <span>Price</span><span class="text-right">Amount</span>
                    </div>
                    <div
                        v-for="order in orderbook.buy"
                        :key="order.id"
                        class="relative grid grid-cols-2 text-xs px-2 py-1.5 rounded overflow-hidden"
                    >
                        <div
                        class="absolute inset-0 bg-emerald-500/5 rounded"
                        :style="{ width: `${Math.min(100, (order.amount / 1) * 100)}%` }"
                        />
                        <span class="relative text-emerald-400 tabular-nums">{{ usd(order.price) }}</span>
                        <span class="relative text-zinc-300 text-right tabular-nums">{{ num(order.amount, 4) }}</span>
                    </div>
                    <div v-if="!orderbook.buy?.length" class="text-zinc-700 text-xs px-2 py-2">No bids</div>
                    </div>
                </div>

                <!-- Asks (sell) -->
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-rose-400 mb-2">Asks</p>
                    <div class="flex flex-col gap-1">
                    <div class="grid grid-cols-2 text-[10px] text-zinc-600 uppercase tracking-widest mb-1 px-1">
                        <span>Price</span><span class="text-right">Amount</span>
                    </div>
                    <div
                        v-for="order in orderbook.sell"
                        :key="order.id"
                        class="relative grid grid-cols-2 text-xs px-2 py-1.5 rounded overflow-hidden"
                    >
                        <div
                        class="absolute inset-0 bg-rose-500/5 rounded"
                        :style="{ width: `${Math.min(100, (order.amount / 1) * 100)}%` }"
                        />
                        <span class="relative text-rose-400 tabular-nums">{{ usd(order.price) }}</span>
                        <span class="relative text-zinc-300 text-right tabular-nums">{{ num(order.amount, 4) }}</span>
                    </div>
                    <div v-if="!orderbook.sell?.length" class="text-zinc-700 text-xs px-2 py-2">No asks</div>
                    </div>
                </div>
                </div>
            </div>

            <!-- All orders table -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-6">
                <p class="text-[10px] tracking-[0.2em] uppercase text-zinc-500 mb-4">Order History</p>

                <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                    <tr class="text-zinc-600 uppercase tracking-widest border-b border-zinc-800">
                        <th class="text-left pb-3 font-normal">ID</th>
                        <th class="text-left pb-3 font-normal">Symbol</th>
                        <th class="text-left pb-3 font-normal">Side</th>
                        <th class="text-right pb-3 font-normal">Price</th>
                        <th class="text-right pb-3 font-normal">Amount</th>
                        <th class="text-right pb-3 font-normal">Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr
                        v-for="order in orders"
                        :key="order.id"
                        class="border-b border-zinc-800/50 hover:bg-zinc-800/30 transition-colors"
                    >
                        <td class="py-3 text-zinc-600">#{{ order.id }}</td>
                        <td class="py-3 font-semibold text-zinc-200">{{ order.symbol }}</td>
                        <td class="py-3">
                        <span :class="order.side === 'buy' ? 'text-emerald-400' : 'text-rose-400'" class="uppercase tracking-widest">
                            {{ order.side }}
                        </span>
                        </td>
                        <td class="py-3 text-right tabular-nums text-zinc-300">{{ usd(order.price) }}</td>
                        <td class="py-3 text-right tabular-nums text-zinc-300">{{ num(order.amount, 6) }}</td>
                        <td class="py-3 text-right">
                        <span :class="['text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-widest', statusClass(order.status)]">
                            {{ statusLabel(order.status) }}
                        </span>
                        </td>
                    </tr>
                    <tr v-if="orders.length === 0">
                        <td colspan="6" class="py-8 text-center text-zinc-700 text-xs tracking-widest uppercase">
                        No orders yet
                        </td>
                    </tr>
                    </tbody>
                </table>
                </div>
            </div>
            </div>
        </div>
        <LimitOrderForm @placed="fetchOrders" />
      </section>

    </template>
  </div>
</template>