# SMS Setup Guide - HeroSys Mobile App

## Current State
🔧 **Development Mode**: OTPs are only logged to `/Applications/XAMPP/xamppfiles/logs/php_error_log`

## Steps to Enable Real SMS

### Option 1: Twilio (Recommended - Easy & Reliable)

1. **Sign up**: https://www.twilio.com/try-twilio
   - Get $15 free credit for testing

2. **Get credentials**:
   - Account SID: From dashboard
   - Auth Token: From dashboard
   - Phone Number: Get a Twilio number

3. **Configure** in `auth_login.php`:
```php
define('SMS_ENABLED', true);
define('SMS_PROVIDER', 'twilio');
define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxx');
define('TWILIO_AUTH_TOKEN', 'your_auth_token');
define('TWILIO_FROM_NUMBER', '+15551234567');
```

4. **Test** with your phone number first!

**Cost**: ~$0.0075 per SMS (varies by country)

---

### Option 2: Unifonic (Best for UAE/Middle East)

1. **Sign up**: https://www.unifonic.com
   - Popular in UAE/Saudi Arabia
   - Good rates for Gulf region

2. **Get App SID** from dashboard

3. **Configure**:
```php
define('SMS_ENABLED', true);
define('SMS_PROVIDER', 'unifonic');
define('UNIFONIC_APP_SID', 'your_app_sid_here');
```

4. **Register Sender ID**: "HeroSys" or your company name
   - Must be approved by Unifonic

**Cost**: Competitive rates for Middle East

---

### Option 3: AWS SNS (If you use AWS)

1. **Install AWS SDK**:
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/herosys/api/mobile
composer require aws/aws-sdk-php
```

2. **Configure AWS credentials**:
```php
define('SMS_ENABLED', true);
define('SMS_PROVIDER', 'aws_sns');
putenv('AWS_REGION=us-east-1');
putenv('AWS_ACCESS_KEY_ID=your_key');
putenv('AWS_SECRET_ACCESS_KEY=your_secret');
```

---

## Quick Test

### 1. Keep Development Mode (Current)
```php
// In sms_service.php
define('SMS_ENABLED', false); // OTP only logged
```

Check OTP in logs:
```bash
tail -f /Applications/XAMPP/xamppfiles/logs/php_error_log
```

### 2. Enable Real SMS
```php
// In sms_service.php
define('SMS_ENABLED', true);
define('SMS_PROVIDER', 'twilio'); // or unifonic
```

### 3. Test with Your Phone
- Enter your phone number in the app
- Should receive SMS within seconds
- Enter OTP to verify it works

---

## Phone Number Format

**Important**: Phone numbers must include country code!

✅ **Correct**:
- +971501234567 (UAE)
- +966501234567 (Saudi)
- +201234567890 (Egypt)

❌ **Wrong**:
- 0501234567
- 501234567

---

## Security Tips

1. **Never commit credentials** to git
2. **Use environment variables** in production
3. **Rate limit OTP requests** (already implemented)
4. **Monitor SMS usage** to avoid abuse
5. **Set spending limits** in your SMS provider

---

## Troubleshooting

### SMS not received?
1. Check error logs: `/Applications/XAMPP/xamppfiles/logs/php_error_log`
2. Verify phone number format has country code
3. Check SMS provider balance
4. Test with a different number

### Wrong OTP?
1. OTP expires after 10 minutes
2. Each new request generates new OTP
3. Check if you're using the latest OTP

### Cost concerns?
1. Start with Twilio free credit
2. Set spending alerts
3. Implement OTP request limits (already done)

---

## Current Configuration

File: `/Applications/XAMPP/xamppfiles/htdocs/herosys/api/mobile/sms_service.php`

```php
define('SMS_ENABLED', false);  // Change to true when ready
define('SMS_PROVIDER', 'log'); // Change to: twilio, unifonic, or aws_sns
```

---

## Next Steps

1. ✅ Choose an SMS provider
2. ✅ Sign up and get credentials
3. ✅ Configure in `sms_service.php`
4. ✅ Test with your phone
5. ✅ Deploy to production

---

## Support

- Twilio Docs: https://www.twilio.com/docs/sms
- Unifonic Docs: https://www.unifonic.com/docs
- AWS SNS Docs: https://docs.aws.amazon.com/sns/

