<script setup lang="ts">
import { ref, computed } from 'vue'
import axios from 'axios'
import { Order, OrderPayload } from '@/types/general';

// ─── Types ────────────────────────────────────────────────────────────────────

type Status = 'idle' | 'loading' | 'success' | 'error'

// ─── Emits ────────────────────────────────────────────────────────────────────

const emit = defineEmits<{
  (e: 'placed', sym: string): void
  (e: 'cancel'): void  
}>()

// ─── State ────────────────────────────────────────────────────────────────────

const symbols = ['BTC', 'ETH', 'SOL', 'XRP']

const form = ref<OrderPayload>({
  symbol: 'BTC',
  side: 'buy',
  price: 0,
  amount: 0,
})

const status = ref<Status>('idle')
const errorMsg = ref<string | null>(null)

// ─── Computed ─────────────────────────────────────────────────────────────────

const total = computed(() =>
  form.value.price > 0 && form.value.amount > 0
    ? (form.value.price * form.value.amount).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
      })
    : null
)

const isBuy = computed(() => form.value.side === 'buy')

const canSubmit = computed(
  () =>
    form.value.price > 0 &&
    form.value.amount > 0 &&
    status.value !== 'loading'
)

// ─── Actions ──────────────────────────────────────────────────────────────────


async function submit() {
  if (!canSubmit.value) return

  status.value = 'loading'
  errorMsg.value = null

  try {
    const { data } = await axios.post('/api/orders', form.value)
    status.value = 'success'
    emit('placed', data.symbol)

    // Reset amount + price after successful placement
    form.value.price = 0
    form.value.amount = 0

    setTimeout(() => (status.value = 'idle'), 2500)
  } catch (err: any) {
    status.value = 'error'
    errorMsg.value =
      err?.response?.data?.message ?? 'Order failed. Please try again.'
    setTimeout(() => (status.value = 'idle'), 3500)
  }
}
</script>

<template>
  <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 w-full max-w-sm font-mono">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-2">
        <div class="w-1.5 h-1.5 rounded-full bg-emerald-400" />
        <span class="text-[10px] tracking-[0.2em] uppercase text-zinc-400">Limit Order</span>
      </div>
      <span class="text-[10px] text-zinc-600 tracking-widest uppercase">{{ form.symbol }}/USD</span>
    </div>

    <form @submit.prevent="submit" class="flex flex-col gap-5">

      <!-- Symbol + Side row -->
      <div class="grid grid-cols-2 gap-3">

        <!-- Symbol -->
        <div class="flex flex-col gap-1.5">
          <label class="text-[10px] tracking-widest uppercase text-zinc-500">Symbol</label>
          <div class="relative">
            <select
              v-model="form.symbol"
              class="w-full appearance-none bg-zinc-800 border border-zinc-700 text-zinc-100 text-xs rounded-lg px-3 py-2.5 pr-8 focus:outline-none focus:border-zinc-500 transition-colors cursor-pointer"
            >
              <option v-for="sym in symbols" :key="sym" :value="sym">{{ sym }}</option>
            </select>
            <div class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-zinc-500">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </div>
          </div>
        </div>

        <!-- Side toggle -->
        <div class="flex flex-col gap-1.5">
          <label class="text-[10px] tracking-widest uppercase text-zinc-500">Side</label>
          <div class="grid grid-cols-2 bg-zinc-800 rounded-lg p-0.5 border border-zinc-700">
            <button
              type="button"
              @click="form.side = 'buy'"
              :class="[
                'text-xs py-2 rounded-md font-bold tracking-widest uppercase transition-all duration-150',
                isBuy
                  ? 'bg-emerald-500 text-black shadow-sm'
                  : 'text-zinc-500 hover:text-zinc-300'
              ]"
            >
              Buy
            </button>
            <button
              type="button"
              @click="form.side = 'sell'"
              :class="[
                'text-xs py-2 rounded-md font-bold tracking-widest uppercase transition-all duration-150',
                !isBuy
                  ? 'bg-rose-500 text-white shadow-sm'
                  : 'text-zinc-500 hover:text-zinc-300'
              ]"
            >
              Sell
            </button>
          </div>
        </div>
      </div>

      <!-- Price -->
      <div class="flex flex-col gap-1.5">
        <label class="text-[10px] tracking-widest uppercase text-zinc-500">Price (USD)</label>
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500 text-xs">$</span>
          <input
            v-model.number="form.price"
            type="number"
            step="0.01"
            min="0"
            placeholder="0.00"
            class="w-full bg-zinc-800 border border-zinc-700 text-zinc-100 text-xs rounded-lg pl-7 pr-4 py-2.5 focus:outline-none focus:border-zinc-500 tabular-nums transition-colors placeholder-zinc-600"
          />
        </div>
      </div>

      <!-- Amount -->
      <div class="flex flex-col gap-1.5">
        <label class="text-[10px] tracking-widest uppercase text-zinc-500">
          Amount ({{ form.symbol }})
        </label>
        <input
          v-model.number="form.amount"
          type="number"
          step="0.00000001"
          min="0"
          placeholder="0.00000000"
          class="w-full bg-zinc-800 border border-zinc-700 text-zinc-100 text-xs rounded-lg px-3 py-2.5 focus:outline-none focus:border-zinc-500 tabular-nums transition-colors placeholder-zinc-600"
        />
      </div>

      <!-- Order total preview -->
      <div
        v-if="total"
        class="flex items-center justify-between bg-zinc-800/50 border border-zinc-800 rounded-lg px-3 py-2.5"
      >
        <span class="text-[10px] uppercase tracking-widest text-zinc-500">Total</span>
        <span class="text-xs font-bold tabular-nums" :class="isBuy ? 'text-emerald-400' : 'text-rose-400'">
          {{ total }}
        </span>
      </div>

      <!-- Error message -->
      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 -translate-y-1"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="opacity-0"
      >
        <div
          v-if="status === 'error' && errorMsg"
          class="text-[11px] text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2.5"
        >
          {{ errorMsg }}
        </div>
      </Transition>

      <button
  type="button"
  @click="emit('cancel')"
  class="w-full py-2.5 rounded-lg text-xs text-zinc-500 hover:text-zinc-300 tracking-widest uppercase transition-colors"
>
  Cancel
</button>
      <!-- Submit button -->
      <button
        type="submit"
        :disabled="!canSubmit"
        :class="[
          'w-full py-3 rounded-lg text-xs font-bold tracking-[0.15em] uppercase transition-all duration-200',
          status === 'success'
            ? 'bg-emerald-500 text-black'
            : status === 'loading'
              ? 'bg-zinc-700 text-zinc-400 cursor-not-allowed'
              : !canSubmit
                ? 'bg-zinc-800 text-zinc-600 cursor-not-allowed border border-zinc-700'
                : isBuy
                  ? 'bg-emerald-500 hover:bg-emerald-400 text-black active:scale-[0.98]'
                  : 'bg-rose-500 hover:bg-rose-400 text-white active:scale-[0.98]'
        ]"
      >
        <span v-if="status === 'loading'" class="flex items-center justify-center gap-2">
          <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
          </svg>
          Placing...
        </span>
        <span v-else-if="status === 'success'">✓ Order Placed</span>
        <span v-else>
          Place {{ isBuy ? 'Buy' : 'Sell' }} Order
        </span>
      </button>
    </form>
  </div>
</template>