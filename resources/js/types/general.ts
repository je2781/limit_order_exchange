
export interface OrderPayload {
  symbol: string
  side: 'buy' | 'sell'
  price: number
  amount: number
}


export interface Asset {
  symbol: string
  amount: number
  locked_amount: number
}

export interface Profile {
  balance: number
  assets: Asset[]
}

export interface Order {
  id: number
  symbol: string
  side: 'buy' | 'sell'
  price: number
  amount: number
  status: 1 | 2 | 3
}

export interface Trade {
  id: number
  symbol: string
  price: number
  amount: number
  buy_order_id: number
  sell_order_id: number
}