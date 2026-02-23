# 🚀 Security Fixes - Deployment Checklist

## Pre-Deployment Steps

### 1. Database Migration
```bash
php artisan migrate --force
```
**What it does:** Adds `wc_webhook_secret` column to websites table and generates secrets for existing websites.

---

### 2. Clear Application Caches
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan optimize
```

---

### 3. Rebuild Frontend Assets
```bash
npm run build
```
**What changed:** Fixed XSS vulnerabilities in 4 Vue components.

---

### 4. Update Environment Variables

**Production .env file:**
```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Session Security (NEW DEFAULTS)
SESSION_LIFETIME=120
SESSION_EXPIRE_ON_CLOSE=true
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict
```

---

## Post-Deployment Configuration

### 5. Update WooCommerce Webhook Secrets

**For EACH website in your application:**

1. Log into your admin panel
2. Navigate to the website edit page
3. Click "Reveal Webhook Secrets" (will require password confirmation)
4. Copy the **WooCommerce Secret**
5. Go to WooCommerce → Settings → Advanced → Webhooks
6. For each webhook pointing to your app:
   - Click Edit
   - Scroll to "Advanced Options"
   - Paste the secret in the **Secret** field
   - Click Save

**Important:** Without this step, WooCommerce webhooks will be rejected with 403 Forbidden.

---

### 6. Test Critical Functionality

#### ✅ Authentication & Sessions
- [ ] Login works
- [ ] Logout works
- [ ] Password reset works (max 3 attempts/hour)
- [ ] Session expires when browser closes
- [ ] Remember me functionality

#### ✅ Webhooks
- [ ] Create test order in WooCommerce → appears in app
- [ ] Submit test form in Fluent Forms → appears in app
- [ ] Check webhook logs for any 403 errors

#### ✅ Authorization
- [ ] Can view own orders
- [ ] Can edit own orders
- [ ] Can delete own submissions
- [ ] Generate Amadeus codes
- [ ] Download PDFs

#### ✅ Rate Limiting
- [ ] Try syncing same website 4 times quickly → 4th should fail
- [ ] Try password reset 4 times → 4th should fail
- [ ] Check rate limit error messages display correctly

#### ✅ Security Headers (Check with browser DevTools)
- [ ] X-Frame-Options: DENY
- [ ] X-Content-Type-Options: nosniff
- [ ] Content-Security-Policy present
- [ ] HTTPS enforced (HTTP redirects to HTTPS)

---

## Monitoring & Alerts

### 7. Set Up Log Monitoring

Watch for these critical log entries:

```bash
# Webhook signature failures
grep "WooCommerce webhook signature validation failed" storage/logs/laravel.log

# Debug mode in production alert
grep "DEBUG MODE IS ENABLED IN PRODUCTION" storage/logs/laravel.log

# Rate limit hits
grep "Too many" storage/logs/laravel.log
```

**Recommended:** Set up automated alerts for these log patterns.

---

### 8. Monitor Failed Webhooks

```sql
-- Check for failed webhooks
SELECT * FROM webhook_events 
WHERE status = 'failed' 
  AND signature_valid = 0 
ORDER BY received_at DESC 
LIMIT 50;
```

If you see many failed webhooks:
- Verify webhook secrets are configured correctly in WooCommerce
- Check that your app is accessible from the webhook source
- Review logs for specific error messages

---

## Rollback Plan (If Issues Occur)

### If Webhooks Stop Working:

1. **Quick Fix:** Temporarily disable signature validation
```php
// app/Http/Controllers/Webhook/WooWebhookController.php
// Comment out signature check (lines 19-32)
// $signatureValid = true; // Allow all during investigation
```

2. **Check logs:**
```bash
tail -f storage/logs/laravel.log | grep -i webhook
```

3. **Verify secrets match:**
   - Database: `SELECT webhook_secret, wc_webhook_secret FROM websites;`
   - WooCommerce: Settings → Advanced → Webhooks → Edit → Secret

---

### If Users Can't Login:

1. **Check session configuration:**
```bash
php artisan tinker
>>> config('session.same_site')
// Should return: "strict"
```

2. **Temporarily revert same_site to 'lax':**
```env
SESSION_SAME_SITE=lax
```

3. **Clear config cache:**
```bash
php artisan config:clear
```

---

### If Rate Limiting is Too Strict:

**Adjust limits in:** `app/Providers/FortifyServiceProvider.php`

```php
// Current: 3 syncs per minute
RateLimiter::for('sync', function (Request $request) {
    return Limit::perMinute(5)->by($request->user()->id); // Increase to 5
});

// Current: 3 password resets per hour
RateLimiter::for('password-reset', function (Request $request) {
    return Limit::perHour(5)->by($throttleKey); // Increase to 5
});
```

Then:
```bash
php artisan config:clear
```

---

## Success Criteria

Your deployment is successful when:

- ✅ All existing functionality works as before
- ✅ WooCommerce webhooks are received and processed
- ✅ Fluent Forms webhooks are received and processed
- ✅ No 403 errors in webhook logs (except actual attacks)
- ✅ Users can log in/out normally
- ✅ Password reset works (with rate limiting)
- ✅ HTTPS is enforced (check with http://yourdomain.com → redirects)
- ✅ Security headers visible in browser DevTools → Network tab
- ✅ No errors in Laravel logs related to new features

---

## Support & Troubleshooting

### Common Issues:

**1. "Invalid webhook signature" errors:**
- **Cause:** Secret mismatch between app and WooCommerce
- **Fix:** Re-copy secret from app to WooCommerce webhook settings

**2. "Too many sync attempts" error:**
- **Cause:** Rate limiting active (3 per minute)
- **Fix:** Wait 1 minute or increase limit in code

**3. HTTP redirects in loops:**
- **Cause:** Reverse proxy not passing HTTPS headers
- **Fix:** Configure web server to pass X-Forwarded-Proto header

**4. Session expires too quickly:**
- **Cause:** `SESSION_EXPIRE_ON_CLOSE=true`
- **Fix:** Expected behavior. User must login again after closing browser.

**5. CSP blocks inline scripts:**
- **Cause:** Content-Security-Policy too strict
- **Fix:** Temporarily allow in development (already configured)

---

## Need Help?

1. Check `SECURITY_FIXES_SUMMARY.md` for detailed explanations
2. Review Laravel logs: `storage/logs/laravel.log`
3. Check browser console for JavaScript errors
4. Verify environment variables are set correctly
5. Test webhooks with verbose logging enabled

---

**Last Updated:** January 21, 2026  
**Version:** 1.0  
**Security Audit:** Complete ✅
