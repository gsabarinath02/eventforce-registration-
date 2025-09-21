# EventForce Registration - Deployment Guide

## 🚀 Quick Setup Instructions

### Prerequisites
- Docker & Docker Compose installed
- Git installed
- Basic knowledge of environment configuration

### Step 1: Clone and Setup
```bash
git clone https://github.com/gsabarinath02/eventforce-registration-.git
cd eventforce-registration-
cd docker/all-in-one
```

### Step 2: Environment Configuration

1. **Generate Security Keys**:
   ```bash
   # For Linux/Mac:
   echo base64:$(openssl rand -base64 32)  # For APP_KEY
   openssl rand -base64 32                 # For JWT_SECRET
   
   # For Windows: Use the provided scripts or online generators
   ```

2. **Update .env file** in `docker/all-in-one/.env`:
   ```env
   APP_KEY=base64:YOUR_GENERATED_KEY
   JWT_SECRET=YOUR_GENERATED_SECRET
   ```

### Step 3: Payment Gateway Setup (Razorpay)

1. **Create Razorpay Account**: https://dashboard.razorpay.com/
2. **Generate API Keys** (Test/Live)
3. **Update Environment Variables**:
   ```env
   RAZORPAY_KEY_ID=rzp_test_your_key_id
   RAZORPAY_KEY_SECRET=your_secret_key
   RAZORPAY_WEBHOOK_SECRET=your_webhook_secret
   VITE_RAZORPAY_KEY_ID=rzp_test_your_key_id
   ```

### Step 4: Deploy

```bash
docker compose up -d
```

### Step 5: Access Application

- **Application**: http://localhost:8123
- **Registration**: http://localhost:8123/auth/register

### Step 6: Configure Payment Provider

1. Login to admin panel
2. Go to Event Settings → Payment & Invoicing
3. Enable Razorpay payment provider
4. Save settings

## 🔧 Production Deployment

### Domain Configuration
Update these variables for production:
```env
VITE_FRONTEND_URL=https://yourdomain.com
VITE_API_URL_CLIENT=https://yourdomain.com/api
APP_FRONTEND_URL=https://yourdomain.com
```

### SSL/HTTPS
- Configure SSL certificates
- Update nginx configuration if needed
- Set `APP_ENV=production` in environment

### Database Backup
Set up regular PostgreSQL backups:
```bash
docker exec postgres pg_dump -U postgres hi-events > backup_$(date +%Y%m%d).sql
```

## 📋 Troubleshooting

### Common Issues:

1. **Port Conflicts**: Change port 8123 in docker-compose.yml
2. **Permission Issues**: Ensure Docker has proper permissions
3. **Payment Failures**: Verify Razorpay credentials and webhook URL
4. **Build Failures**: Clear Docker cache with `docker system prune`

### Health Checks:
```bash
# Check container status
docker compose ps

# View logs
docker compose logs -f

# Restart services
docker compose restart
```

## 🛡 Security Checklist

- [ ] Change default passwords
- [ ] Update all environment variables
- [ ] Configure proper webhook secrets
- [ ] Set up SSL certificates
- [ ] Enable firewall rules
- [ ] Regular security updates

## 📞 Support

For issues or questions:
1. Check this deployment guide
2. Review application logs
3. Create an issue in the repository

---

**Note**: This platform includes Razorpay integration optimized for Indian markets with international support.
