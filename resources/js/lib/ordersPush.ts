export type OrdersConnectionState = 'connecting' | 'connected' | 'reconnecting' | 'disconnected'

type Handler = (data?: any) => void
interface EventSource {
  bind: (event: string, handler: Handler) => unknown
  unbind: (event: string, handler: Handler) => unknown
}

interface OrdersEcho {
  private: (name: string) => {
    listen: (event: string, handler: Handler) => unknown
    stopListening: (event: string, handler: Handler) => unknown
    subscription: EventSource & { subscribed?: boolean }
  }
  leave: (name: string) => unknown
  connector: { pusher: { connection: EventSource & { state: string } } }
}

/** Own the page's channel and connection handlers without changing the shared Echo connection. */
export function subscribeToOrders(options: {
  userId: number
  getEcho: () => Promise<OrdersEcho | null>
  onOrder: Handler
  onSubscribed: () => void
  onState: (state: OrdersConnectionState) => void
}): () => void {
  let active = true
  let connectedBefore = false
  let cleanup = () => {}
  const setState = (state: OrdersConnectionState) => {
    if (active) options.onState(state)
  }
  setState('connecting')

  void options.getEcho().then((echo) => {
    if (!active) return
    if (!echo) {
      setState('disconnected')
      return
    }
    const name = `orders.${options.userId}`
    const channel = echo.private(name)
    const subscription = channel.subscription
    const connection = echo.connector.pusher.connection
    const onOrder: Handler = (data) => {
      if (active) options.onOrder(data)
    }
    const onSubscribed = () => {
      if (!active) return
      connectedBefore = true
      setState('connected')
      // Also runs after Pusher resubscribes: recover any orders missed offline.
      options.onSubscribed()
    }
    const onError = () => setState('disconnected')
    const onStateChange: Handler = (change) => {
      if (['initialized', 'connecting', 'connected'].includes(change?.current)) {
        // A connected socket alone does not confirm private-channel authorization.
        setState(connectedBefore ? 'reconnecting' : 'connecting')
      } else {
        setState('disconnected')
      }
    }

    cleanup = () => {
      channel.stopListening('.order.received', onOrder)
      subscription.unbind('pusher:subscription_succeeded', onSubscribed)
      subscription.unbind('pusher:subscription_error', onError)
      connection.unbind('state_change', onStateChange)
      connection.unbind('error', onError)
      echo.leave(name)
    }
    channel.listen('.order.received', onOrder)
    // Bind directly so teardown can remove these exact callbacks. Echo's
    // subscribed()/error() methods wrap callbacks and do not expose the wrappers.
    subscription.bind('pusher:subscription_succeeded', onSubscribed)
    subscription.bind('pusher:subscription_error', onError)
    connection.bind('state_change', onStateChange)
    connection.bind('error', onError)
    onStateChange({ current: connection.state })
    if (connection.state === 'connected' && subscription.subscribed) onSubscribed()
  }).catch(() => {
    cleanup()
    cleanup = () => {}
    setState('disconnected')
  })

  return () => {
    active = false
    cleanup()
    cleanup = () => {}
  }
}
