# EventForce Registration

A comprehensive event management and ticketing platform built for modern event organizers. Create, manage, and monetize events with ease using our feature-rich, self-hosted solution.

## ✨ Features

### 🎟 Ticketing & Sales
- **Multiple Ticket Types**: Free, Paid, Donation, and Tiered pricing
- **Capacity Management**: Event-wide and ticket-specific limits
- **Promo Codes**: Discount codes and pre-sale access
- **Product Sales**: Sell event merchandise and add-ons
- **Tax Management**: Custom taxes and fees per product/order

### 🔧 Payment Processing
- **Razorpay Integration**: Secure payment processing for Indian and international transactions
- **Stripe Support**: Global credit card payment processing
- **Offline Payments**: Bank transfers and custom payment methods
- **Multiple Currencies**: Support for various currencies

### 📊 Event Management
- **Real-time Analytics**: Revenue, sales, and attendee insights
- **Custom Event Pages**: Professional event landing pages
- **SEO Optimization**: Search engine friendly event pages
- **Mobile-Friendly**: Responsive design for all devices

### 👥 Attendee Management
- **Custom Forms**: Collect specific attendee information
- **QR Code Check-in**: Web-based check-in system
- **Bulk Messaging**: Email attendees by ticket type
- **Data Export**: CSV/XLSX exports for analysis

### 🚀 Advanced Features
- **Multi-language Support**: English, Hindi, and more
- **Role-based Access**: Team management with permissions
- **API Access**: Full REST API for integrations
- **Webhook Support**: Real-time event notifications

## 🛠 Technology Stack

- **Backend**: Laravel (PHP)
- **Frontend**: React with TypeScript
- **Database**: PostgreSQL
- **Cache**: Redis
- **Deployment**: Docker

## 🚀 Quick Start

### Prerequisites
- Docker and Docker Compose
- Git

### Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/gsabarinath02/eventforce-registration-.git
   cd eventforce-registration-
   ```

2. **Navigate to Docker directory**:
   ```bash
   cd docker/all-in-one
   ```

3. **Set up environment variables**:
   - Copy the environment file and configure your settings
   - Generate APP_KEY and JWT_SECRET as instructed in the setup guide

4. **Start the application**:
   ```bash
   docker compose up -d
   ```

5. **Access the application**:
   - Open your browser and go to `http://localhost:8123`
   - Create your admin account at `http://localhost:8123/auth/register`

## 💳 Payment Configuration

### Razorpay Setup
1. Create a [Razorpay account](https://dashboard.razorpay.com/)
2. Generate API keys (test/live)
3. Configure webhook endpoints
4. Update environment variables with your credentials

### Environment Variables
```env
RAZORPAY_KEY_ID=your_key_id
RAZORPAY_KEY_SECRET=your_secret_key
RAZORPAY_WEBHOOK_SECRET=your_webhook_secret
```

## 📁 Project Structure

```
eventforce-registration-/
├── backend/           # Laravel backend application
├── frontend/          # React frontend application
├── docker/            # Docker configuration files
├── docs/             # Documentation
└── misc/             # Miscellaneous utilities
```

## 🔐 Security

- Environment variables for sensitive configuration
- HTTPS enforcement in production
- Secure webhook signature verification
- Role-based access control
- SQL injection protection

## 🤝 Contributing

We welcome contributions! Please read our contributing guidelines before submitting PRs.

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## 📝 License

This project is licensed under the AGPL-3.0 License - see the [LICENSE](LICENCE) file for details.

## 🆘 Support

- **Documentation**: Check the `/docs` folder for detailed guides
- **Issues**: Create an issue for bug reports or feature requests
- **Community**: Join our discussions for help and ideas

## 🌟 Acknowledgments

Built with modern web technologies and best practices for scalable event management.

---

**EventForce Registration** - Empowering event organizers with professional-grade tools.