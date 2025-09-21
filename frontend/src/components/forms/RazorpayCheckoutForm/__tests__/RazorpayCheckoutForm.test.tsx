import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import RazorpayCheckoutForm from '../index'

// Mock dependencies
vi.mock('../../../../queries/useGetOrderPublic.ts', () => ({
  useGetOrderPublic: vi.fn(() => ({
    data: {
      id: 1,
      short_id: 'ABC123',
      first_name: 'John',
      last_name: 'Doe',
      email: 'john@example.com',
      total_gross: 5000,
      currency: 'INR',
      payment_status: 'AWAITING_PAYMENT',
      event: {
        id: 1,
        title: 'Test Event',
        slug: 'test-event'
      }
    },
    isFetched: true
  }))
}))

vi.mock('../../../../queries/useCreateRazorpayOrder.ts', () => ({
  useCreateRazorpayOrder: vi.fn(() => ({
    data: {
      razorpay_order_id: 'order_test123',
      amount: 5000,
      currency: 'INR',
      key_id: 'rzp_test_123'
    },
    isFetched: true,
    error: null
  }))
}))

vi.mock('../../../../mutations/useVerifyRazorpayPayment.ts', () => ({
  useVerifyRazorpayPayment: vi.fn(() => ({
    mutateAsync: vi.fn().mockResolvedValue({
      data: { success: true }
    })
  }))
}))

vi.mock('../../../../utilites/config.ts', () => ({
  getConfig: vi.fn((key: string, defaultValue: string) => {
    if (key === 'VITE_APP_PRIMARY_COLOR') return '#3399cc'
    if (key === 'VITE_RAZORPAY_KEY_ID') return 'rzp_test_123'
    return defaultValue
  })
}))

vi.mock('../../../../utilites/razorpayConfig.ts', () => ({
  getRazorpayConfig: vi.fn(() => ({
    keyId: 'rzp_test_123'
  })),
  validateRazorpayConfig: vi.fn(() => ({
    isValid: true,
    errors: []
  }))
}))

vi.mock('../../../../utilites/notifications.tsx', () => ({
  showError: vi.fn(),
  showSuccess: vi.fn()
}))

vi.mock('@lingui/macro', () => ({
  t: (str: TemplateStringsArray | string) => str
}))

// Mock react-router
const mockNavigate = vi.fn()
vi.mock('react-router', () => ({
  useParams: () => ({
    eventId: '1',
    orderShortId: 'ABC123'
  }),
  useNavigate: () => mockNavigate
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

// Mock Razorpay
const mockRazorpayInstance = {
  open: vi.fn(),
  on: vi.fn()
}

const mockRazorpay = vi.fn(() => mockRazorpayInstance)

describe('RazorpayCheckoutForm', () => {
  let mockSetSubmitHandler: ReturnType<typeof vi.fn>

  beforeEach(() => {
    mockSetSubmitHandler = vi.fn()
    
    // Mock Razorpay global
    Object.defineProperty(window, 'Razorpay', {
      value: mockRazorpay,
      writable: true
    })

    // Mock document.createElement for script loading
    const mockScript = {
      src: '',
      async: false,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn()
    }
    
    vi.spyOn(document, 'createElement').mockImplementation((tagName) => {
      if (tagName === 'script') {
        return mockScript as any
      }
      return document.createElement(tagName)
    })

    vi.spyOn(document.body, 'appendChild').mockImplementation(() => mockScript as any)
    vi.spyOn(document.body, 'removeChild').mockImplementation(() => mockScript as any)
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders payment form with order details', () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    expect(screen.getByText('Payment')).toBeInTheDocument()
    expect(screen.getByText('Test Event')).toBeInTheDocument()
    expect(screen.getByText('#ABC123')).toBeInTheDocument()
    expect(screen.getByText('INR 50.00')).toBeInTheDocument()
    expect(screen.getByText('Click "Place Order" to proceed with Razorpay payment.')).toBeInTheDocument()
  })

  it('loads Razorpay script on mount', () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    expect(document.createElement).toHaveBeenCalledWith('script')
    expect(document.body.appendChild).toHaveBeenCalled()
  })

  it('sets submit handler on mount', () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    expect(mockSetSubmitHandler).toHaveBeenCalledWith(expect.any(Function))
  })

  it('shows loading skeleton when order is not fetched', () => {
    // Mock loading state
    vi.mocked(require('../../../../queries/useGetOrderPublic.ts').useGetOrderPublic).mockReturnValue({
      data: null,
      isFetched: false
    })

    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    // Should show skeleton loader
    expect(document.querySelector('.mantine-Skeleton-root')).toBeInTheDocument()
  })

  it('shows already paid message for completed orders', () => {
    // Mock completed order
    vi.mocked(require('../../../../queries/useGetOrderPublic.ts').useGetOrderPublic).mockReturnValue({
      data: {
        id: 1,
        short_id: 'ABC123',
        payment_status: 'PAYMENT_RECEIVED',
        event: { id: 1, title: 'Test Event', slug: 'test-event' }
      },
      isFetched: true
    })

    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    expect(screen.getByText('This order has already been paid.')).toBeInTheDocument()
    expect(screen.getByText('View order details')).toBeInTheDocument()
  })

  it('shows error message when Razorpay order creation fails', () => {
    // Mock error state
    vi.mocked(require('../../../../queries/useCreateRazorpayOrder.ts').useCreateRazorpayOrder).mockReturnValue({
      data: null,
      isFetched: true,
      error: {
        response: {
          data: {
            message: 'Failed to create Razorpay order'
          }
        }
      }
    })

    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    expect(screen.getByText('Failed to create Razorpay order')).toBeInTheDocument()
    expect(screen.getByText('Return to event page')).toBeInTheDocument()
  })

  it('shows payment failed alert for failed orders', () => {
    // Mock failed order
    vi.mocked(require('../../../../queries/useGetOrderPublic.ts').useGetOrderPublic).mockReturnValue({
      data: {
        id: 1,
        short_id: 'ABC123',
        payment_status: 'PAYMENT_FAILED',
        event: { id: 1, title: 'Test Event', slug: 'test-event' }
      },
      isFetched: true
    })

    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    expect(screen.getByText('Your payment was unsuccessful. Please try again.')).toBeInTheDocument()
  })

  it('initializes Razorpay payment with correct options', async () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    // Get the submit handler that was set
    const submitHandler = mockSetSubmitHandler.mock.calls[0][0]
    
    // Call the submit handler
    await submitHandler()

    expect(mockRazorpay).toHaveBeenCalledWith({
      key: 'rzp_test_123',
      amount: 5000,
      currency: 'INR',
      name: 'Test Event',
      description: 'Order #ABC123',
      order_id: 'order_test123',
      handler: expect.any(Function),
      prefill: {
        name: 'John Doe',
        email: 'john@example.com'
      },
      theme: {
        color: '#3399cc'
      },
      modal: {
        ondismiss: expect.any(Function)
      }
    })

    expect(mockRazorpayInstance.open).toHaveBeenCalled()
  })

  it('handles payment success correctly', async () => {
    const mockVerifyPayment = vi.fn().mockResolvedValue({
      data: { success: true }
    })
    
    vi.mocked(require('../../../../mutations/useVerifyRazorpayPayment.ts').useVerifyRazorpayPayment).mockReturnValue({
      mutateAsync: mockVerifyPayment
    })

    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    // Get the submit handler
    const submitHandler = mockSetSubmitHandler.mock.calls[0][0]
    await submitHandler()

    // Get the payment success handler from Razorpay options
    const razorpayOptions = mockRazorpay.mock.calls[0][0]
    const paymentSuccessHandler = razorpayOptions.handler

    // Simulate successful payment
    await paymentSuccessHandler({
      razorpay_payment_id: 'pay_test123',
      razorpay_order_id: 'order_test123',
      razorpay_signature: 'signature_test'
    })

    expect(mockVerifyPayment).toHaveBeenCalledWith({
      eventId: '1',
      orderShortId: 'ABC123',
      payload: {
        razorpay_payment_id: 'pay_test123',
        razorpay_order_id: 'order_test123',
        razorpay_signature: 'signature_test'
      }
    })

    expect(mockNavigate).toHaveBeenCalledWith('/events/1/order/ABC123/checkout/summary')
  })

  it('handles payment failure correctly', async () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    // Get the submit handler
    const submitHandler = mockSetSubmitHandler.mock.calls[0][0]
    await submitHandler()

    // Simulate payment error callback
    const onPaymentFailedCallback = mockRazorpayInstance.on.mock.calls.find(
      call => call[0] === 'payment.failed'
    )?.[1]

    if (onPaymentFailedCallback) {
      onPaymentFailedCallback({
        error: {
          code: 'PAYMENT_FAILED',
          description: 'Payment failed due to insufficient funds'
        }
      })
    }

    await waitFor(() => {
      expect(screen.getByText('Payment failed due to insufficient funds')).toBeInTheDocument()
    })
  })

  it('handles payment cancellation correctly', async () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    // Get the submit handler
    const submitHandler = mockSetSubmitHandler.mock.calls[0][0]
    await submitHandler()

    // Get the modal dismiss handler from Razorpay options
    const razorpayOptions = mockRazorpay.mock.calls[0][0]
    const modalDismissHandler = razorpayOptions.modal.ondismiss

    // Simulate modal dismissal (payment cancellation)
    modalDismissHandler()

    await waitFor(() => {
      expect(screen.getByText('Payment was cancelled. You can try again.')).toBeInTheDocument()
    })
  })

  it('shows configuration error when Razorpay is not properly configured', async () => {
    // Mock invalid configuration
    vi.mocked(require('../../../../utilites/razorpayConfig.ts').validateRazorpayConfig).mockReturnValue({
      isValid: false,
      errors: ['Missing RAZORPAY_KEY_ID']
    })

    const Wrapper = createWrapper()
    
    render(
      <RazorpayCheckoutForm setSubmitHandler={mockSetSubmitHandler} />,
      { wrapper: Wrapper }
    )

    // Get the submit handler
    const submitHandler = mockSetSubmitHandler.mock.calls[0][0]
    await submitHandler()

    await waitFor(() => {
      expect(screen.getByText('Razorpay is not properly configured. Please contact support.')).toBeInTheDocument()
    })
  })
})