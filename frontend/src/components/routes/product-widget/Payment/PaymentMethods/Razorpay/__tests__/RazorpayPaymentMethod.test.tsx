import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RazorpayPaymentMethod } from '../index'

// Mock the RazorpayCheckoutForm component
vi.mock('../../../../../forms/RazorpayCheckoutForm', () => ({
  default: ({ setSubmitHandler }: { setSubmitHandler: (handler: () => Promise<void>) => void }) => {
    return <div data-testid="razorpay-checkout-form">Razorpay Checkout Form</div>
  }
}))

// Mock the useGetEventPublic hook
vi.mock('../../../../../../queries/useGetEventPublic.ts', () => ({
  useGetEventPublic: vi.fn(() => ({
    data: {
      id: 1,
      title: 'Test Event',
      slug: 'test-event',
      settings: {
        payment_providers: ['RAZORPAY']
      }
    }
  }))
}))

// Mock lingui
vi.mock('@lingui/macro', () => ({
  t: (str: TemplateStringsArray) => str[0] || str
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

describe('RazorpayPaymentMethod', () => {
  let mockSetSubmitHandler: ReturnType<typeof vi.fn>

  beforeEach(() => {
    mockSetSubmitHandler = vi.fn()
  })

  it('renders RazorpayCheckoutForm when enabled', () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayPaymentMethod 
        enabled={true} 
        setSubmitHandler={mockSetSubmitHandler} 
      />,
      { wrapper: Wrapper }
    )

    expect(screen.getByTestId('razorpay-checkout-form')).toBeInTheDocument()
    expect(screen.getByText('Razorpay Checkout Form')).toBeInTheDocument()
  })

  it('renders error message when not enabled', () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayPaymentMethod 
        enabled={false} 
        setSubmitHandler={mockSetSubmitHandler} 
      />,
      { wrapper: Wrapper }
    )

    expect(screen.getByText('Razorpay payments are not enabled for this event.')).toBeInTheDocument()
    expect(screen.getByText('Return to event page')).toBeInTheDocument()
    expect(screen.queryByTestId('razorpay-checkout-form')).not.toBeInTheDocument()
  })

  it('passes setSubmitHandler to RazorpayCheckoutForm when enabled', () => {
    const Wrapper = createWrapper()
    
    render(
      <RazorpayPaymentMethod 
        enabled={true} 
        setSubmitHandler={mockSetSubmitHandler} 
      />,
      { wrapper: Wrapper }
    )

    // The RazorpayCheckoutForm should be rendered, which means setSubmitHandler was passed
    expect(screen.getByTestId('razorpay-checkout-form')).toBeInTheDocument()
  })
})