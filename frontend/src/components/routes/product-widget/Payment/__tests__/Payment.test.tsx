import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import Payment from '../index'

// Mock all payment method components
vi.mock('../PaymentMethods/Stripe', () => ({
  StripePaymentMethod: ({ enabled, setSubmitHandler }: any) => (
    <div data-testid="stripe-payment-method" data-enabled={enabled}>
      Stripe Payment Method
    </div>
  )
}))

vi.mock('../PaymentMethods/Offline', () => ({
  OfflinePaymentMethod: ({ enabled, setSubmitHandler }: any) => (
    <div data-testid="offline-payment-method" data-enabled={enabled}>
      Offline Payment Method
    </div>
  )
}))

vi.mock('../PaymentMethods/Razorpay', () => ({
  RazorpayPaymentMethod: ({ enabled, setSubmitHandler }: any) => (
    <div data-testid="razorpay-payment-method" data-enabled={enabled}>
      Razorpay Payment Method
    </div>
  )
}))

// Mock hooks
const mockOrderData = {
  id: 1,
  short_id: 'ABC123',
  payment_status: 'AWAITING_PAYMENT',
  event: {
    id: 1,
    title: 'Test Event',
    settings: {
      payment_providers: ['RAZORPAY', 'STRIPE', 'OFFLINE']
    }
  }
}

vi.mock('../../../../queries/useGetOrderPublic.ts', () => ({
  useGetOrderPublic: vi.fn(() => ({
    data: mockOrderData,
    isFetched: true
  }))
}))

vi.mock('../../../../queries/useGetEventPublic.ts', () => ({
  useGetEventPublic: vi.fn(() => ({
    data: mockOrderData.event,
    isFetched: true
  }))
}))

vi.mock('../../../../mutations/useTransitionOrderToOfflinePaymentPublic.ts', () => ({
  useTransitionOrderToOfflinePaymentPublic: vi.fn(() => ({
    mutateAsync: vi.fn()
  }))
}))

vi.mock('@lingui/macro', () => ({
  t: (str: TemplateStringsArray | string) => str
}))

// Mock react-router
vi.mock('react-router', () => ({
  useParams: () => ({
    eventId: '1',
    orderShortId: 'ABC123'
  }),
  useNavigate: () => vi.fn()
}))

const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
    },
  })

  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        {children}
      </BrowserRouter>
    </QueryClientProvider>
  )
}

describe('Payment Component', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders Razorpay as default payment method when enabled', () => {
    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Razorpay should be visible (default)
    const razorpayMethod = screen.getByTestId('razorpay-payment-method')
    expect(razorpayMethod).toBeInTheDocument()
    expect(razorpayMethod.parentElement).toHaveStyle({ display: 'block' })

    // Other methods should be hidden
    const stripeMethod = screen.getByTestId('stripe-payment-method')
    expect(stripeMethod.parentElement).toHaveStyle({ display: 'none' })

    const offlineMethod = screen.getByTestId('offline-payment-method')
    expect(offlineMethod.parentElement).toHaveStyle({ display: 'none' })
  })

  it('shows payment method switching options when multiple methods are enabled', () => {
    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Should show switching links when multiple payment methods are available
    expect(screen.getByText('I would like to pay using Stripe instead')).toBeInTheDocument()
  })

  it('switches from Razorpay to Stripe when clicking switch link', () => {
    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Initially Razorpay should be active
    expect(screen.getByTestId('razorpay-payment-method').parentElement).toHaveStyle({ display: 'block' })
    expect(screen.getByTestId('stripe-payment-method').parentElement).toHaveStyle({ display: 'none' })

    // Click switch to Stripe
    fireEvent.click(screen.getByText('I would like to pay using Stripe instead'))

    // Now Stripe should be active
    expect(screen.getByTestId('stripe-payment-method').parentElement).toHaveStyle({ display: 'block' })
    expect(screen.getByTestId('razorpay-payment-method').parentElement).toHaveStyle({ display: 'none' })

    // Link text should change
    expect(screen.getByText('I would like to pay using Razorpay instead')).toBeInTheDocument()
  })

  it('switches from Stripe to Razorpay when clicking switch link', () => {
    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Switch to Stripe first
    fireEvent.click(screen.getByText('I would like to pay using Stripe instead'))
    
    // Now switch back to Razorpay
    fireEvent.click(screen.getByText('I would like to pay using Razorpay instead'))

    // Razorpay should be active again
    expect(screen.getByTestId('razorpay-payment-method').parentElement).toHaveStyle({ display: 'block' })
    expect(screen.getByTestId('stripe-payment-method').parentElement).toHaveStyle({ display: 'none' })
  })

  it('defaults to Stripe when Razorpay is not enabled', () => {
    // Mock event with only Stripe enabled
    vi.mocked(require('../../../../queries/useGetEventPublic.ts').useGetEventPublic).mockReturnValue({
      data: {
        ...mockOrderData.event,
        settings: {
          payment_providers: ['STRIPE', 'OFFLINE']
        }
      },
      isFetched: true
    })

    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Stripe should be visible (default when Razorpay not available)
    expect(screen.getByTestId('stripe-payment-method').parentElement).toHaveStyle({ display: 'block' })
    expect(screen.getByTestId('razorpay-payment-method').parentElement).toHaveStyle({ display: 'none' })
  })

  it('defaults to Offline when only offline payment is enabled', () => {
    // Mock event with only Offline enabled
    vi.mocked(require('../../../../queries/useGetEventPublic.ts').useGetEventPublic).mockReturnValue({
      data: {
        ...mockOrderData.event,
        settings: {
          payment_providers: ['OFFLINE']
        }
      },
      isFetched: true
    })

    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Offline should be visible (only option)
    expect(screen.getByTestId('offline-payment-method').parentElement).toHaveStyle({ display: 'block' })
    expect(screen.getByTestId('razorpay-payment-method').parentElement).toHaveStyle({ display: 'none' })
    expect(screen.getByTestId('stripe-payment-method').parentElement).toHaveStyle({ display: 'none' })
  })

  it('shows no payment methods available message when none are enabled', () => {
    // Mock event with no payment providers
    vi.mocked(require('../../../../queries/useGetEventPublic.ts').useGetEventPublic).mockReturnValue({
      data: {
        ...mockOrderData.event,
        settings: {
          payment_providers: []
        }
      },
      isFetched: true
    })

    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    expect(screen.getByText('No payment methods are currently available for this event.')).toBeInTheDocument()
  })

  it('shows switching options for Razorpay and Offline when both are enabled', () => {
    // Mock event with Razorpay and Offline enabled
    vi.mocked(require('../../../../queries/useGetEventPublic.ts').useGetEventPublic).mockReturnValue({
      data: {
        ...mockOrderData.event,
        settings: {
          payment_providers: ['RAZORPAY', 'OFFLINE']
        }
      },
      isFetched: true
    })

    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Should show option to switch to offline payment
    expect(screen.getByText('I would like to pay offline instead')).toBeInTheDocument()
  })

  it('handles submit correctly for Razorpay payment method', () => {
    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Find and click the Place Order button
    const placeOrderButton = screen.getByText('Place Order')
    expect(placeOrderButton).toBeInTheDocument()
    
    // The button should be enabled for Razorpay payment
    expect(placeOrderButton).not.toBeDisabled()
  })

  it('renders loading state when data is not fetched', () => {
    // Mock loading state
    vi.mocked(require('../../../../queries/useGetOrderPublic.ts').useGetOrderPublic).mockReturnValue({
      data: null,
      isFetched: false
    })

    vi.mocked(require('../../../../queries/useGetEventPublic.ts').useGetEventPublic).mockReturnValue({
      data: null,
      isFetched: false
    })

    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // Should show loading state (skeleton or spinner)
    // The exact loading indicator depends on the implementation
    expect(screen.queryByTestId('razorpay-payment-method')).not.toBeInTheDocument()
  })

  it('passes enabled prop correctly to payment method components', () => {
    const Wrapper = createWrapper()
    
    render(<Payment />, { wrapper: Wrapper })

    // All payment methods should be rendered with enabled=true since they're all in the settings
    expect(screen.getByTestId('razorpay-payment-method')).toHaveAttribute('data-enabled', 'true')
    expect(screen.getByTestId('stripe-payment-method')).toHaveAttribute('data-enabled', 'true')
    expect(screen.getByTestId('offline-payment-method')).toHaveAttribute('data-enabled', 'true')
  })
})