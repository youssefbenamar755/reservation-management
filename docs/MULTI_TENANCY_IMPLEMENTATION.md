# 🔐 Multi-Tenancy Implementation Guide

## Overview

Multi-tenancy has been successfully implemented to ensure that users can only access their own websites, orders, submissions, and related data. This prevents unauthorized access to other users' information.

---

## What Was Changed

### 1. **Database Schema**

#### Migration: `2026_01_23_014801_add_user_id_to_websites_table.php`

- Added `user_id` column to `websites` table
- Added foreign key constraint to `users` table with cascade on delete
- Automatically assigned existing websites to the first user
- Added index on `user_id` for query performance

**Result**: Every website now belongs to a specific user.

---

### 2. **Models Updated**

#### `Website` Model (`app/Models/Website.php`)
- ✅ Added `user_id` to `$fillable` array
- ✅ Added `user()` relationship method
- ✅ Added `scopeForUser($userId)` query scope
- ✅ Added `belongsToUser($userId)` helper method

#### `User` Model (`app/Models/User.php`)
- ✅ Added `websites()` relationship method

---

### 3. **Policies Implemented**

#### `WebsitePolicy` (NEW)
**Authorization Rules**:
- ✅ `viewAny`: All authenticated users (list is filtered)
- ✅ `view`: Owner or admin only
- ✅ `create`: All authenticated users
- ✅ `update`: Owner or admin only
- ✅ `delete`: Owner or admin only

#### `WcOrderPolicy` (UPDATED)
**Removed TODOs, implemented**:
- ✅ `view`: User can view if they own the website or are admin
- ✅ `update`: User can update if they own the website or are admin
- ✅ `delete`: User can delete if they own the website or are admin
- ✅ `generateAmadeusCode`: Same as update

#### `FfSubmissionPolicy` (UPDATED)
**Removed TODOs, implemented**:
- ✅ `view`: User can view if they own the website or are admin
- ✅ `update`: User can update if they own the website or are admin
- ✅ `delete`: User can delete if they own the website or are admin
- ✅ `generateAmadeusCode`: Same as update
- ✅ `generatePnr`: Same as update
- ✅ `downloadPdf`: Same as view

---

### 4. **Controllers Updated**

#### `WebsiteController`
- ✅ Added `authorizeResource()` in constructor
- ✅ `index()`: Filters websites by user (non-admins see only their websites)
- ✅ `store()`: Automatically assigns `user_id` to current user

#### `WcOrderController`
- ✅ `index()`: Filters orders by user's websites
- ✅ `show()`: Already had authorization check (kept)
- ✅ Filters website dropdown by user's websites

#### `FfSubmissionController`
- ✅ `index()`: Filters submissions by user's websites
- ✅ `formEntries()`: Added authorization check for website
- ✅ `entryDetails()`: Already had authorization (kept)
- ✅ `destroy()`: Already had authorization (kept)
- ✅ `destroyAll()`: Added authorization check for website
- ✅ `getFormsForWebsite()`: Added authorization check
- ✅ Filters website dropdown by user's websites

#### `DashboardController`
- ✅ All statistics filtered by user's websites
- ✅ Recent orders filtered by user's websites
- ✅ Recent submissions filtered by user's websites
- ✅ Website health filtered by user's websites
- ✅ Webhook events filtered by user's websites

#### `AnalyticsController`
- ✅ All queries filtered by user's websites
- ✅ Website filter dropdown shows only user's websites
- ✅ Cache key includes `user_id` to prevent cross-user data leakage
- ✅ Admin users can see all data

---

## Security Improvements

### Before Implementation ⚠️
```php
// ANY authenticated user could access ANY order
WcOrder::all(); // Returns ALL orders from ALL users

// ANY authenticated user could delete ANY submission
$submission->delete(); // No authorization check
```

### After Implementation ✅
```php
// Users see only their own data
WcOrder::query()
    ->whereIn('website_id', $userWebsiteIds)
    ->get(); // Returns only orders from user's websites

// Authorization enforced
$this->authorize('delete', $submission); // Throws 403 if unauthorized
```

---

## Admin Privileges

Users with `is_admin = true` have special privileges:

- ✅ Can view all websites from all users
- ✅ Can edit/delete any website
- ✅ Can view all orders and submissions
- ✅ Can access all analytics data
- ✅ Bypasses ownership checks in policies

**How to make a user admin**:
```bash
php artisan tinker
>>> $user = User::find(1);
>>> $user->is_admin = true;
>>> $user->save();
```

---

## Testing the Implementation

### Test Case 1: User Isolation

**Setup**:
1. Create two users: User A and User B
2. User A creates Website 1
3. User B creates Website 2

**Expected Results**:
- ✅ User A can only see Website 1 in their websites list
- ✅ User B can only see Website 2 in their websites list
- ✅ User A cannot access Website 2's edit page (should get 403)
- ✅ User B cannot access Website 1's edit page (should get 403)

### Test Case 2: Orders & Submissions

**Setup**:
1. Website 1 (owned by User A) receives an order
2. Website 2 (owned by User B) receives an order

**Expected Results**:
- ✅ User A sees only orders from Website 1
- ✅ User B sees only orders from Website 2
- ✅ User A's dashboard shows only stats from Website 1
- ✅ User B's dashboard shows only stats from Website 2

### Test Case 3: Direct URL Access

**Setup**:
1. User A tries to access User B's website edit URL directly

**Expected Results**:
- ✅ Should receive 403 Forbidden error
- ✅ Should be redirected or shown error page

### Test Case 4: Admin Access

**Setup**:
1. Create an admin user
2. User A owns Website 1
3. User B owns Website 2

**Expected Results**:
- ✅ Admin can see all websites (1 and 2)
- ✅ Admin can edit any website
- ✅ Admin can see all orders from all websites
- ✅ Admin can access all submissions

### Test Case 5: New Website Creation

**Setup**:
1. User A creates a new website

**Expected Results**:
- ✅ Website automatically assigned to User A
- ✅ User A can immediately see and manage it
- ✅ Other users cannot see or access it

---

## Database Query Examples

### Get User's Websites
```php
// Non-admin user
$websites = Website::where('user_id', auth()->id())->get();

// Admin user (sees all)
$websites = Website::when(!auth()->user()->is_admin, 
    fn($q) => $q->where('user_id', auth()->id())
)->get();
```

### Get User's Orders
```php
$userWebsiteIds = auth()->user()->websites()->pluck('id');
$orders = WcOrder::whereIn('website_id', $userWebsiteIds)->get();
```

### Check Ownership
```php
// In controller
$this->authorize('view', $website);

// In policy
public function view(User $user, Website $website): bool
{
    return $website->user_id === $user->id || $user->is_admin;
}
```

---

## Rollback Plan

If issues occur, you can rollback the migration:

```bash
php artisan migrate:rollback --step=1
```

**This will**:
- Remove `user_id` column from `websites` table
- Remove the foreign key constraint

**You'll need to manually**:
- Revert the policy changes (use git)
- Revert the controller changes (use git)
- Clear application cache

---

## Performance Considerations

### Indexes Added
- ✅ `websites.user_id` - Indexed for fast filtering
- ✅ Foreign key automatically indexed

### Query Optimization
- ✅ `whereIn('website_id', $userWebsiteIds)` - Uses index
- ✅ Eager loading maintained with `with('website')`
- ✅ Analytics queries cached with user-specific keys

### Monitoring
Monitor these queries for performance:
- Dashboard statistics (multiple aggregations)
- Analytics page (complex joins and groupings)
- Large website lists (admins only)

---

## Common Issues & Solutions

### Issue 1: 403 Errors on Existing Data
**Cause**: Existing records have NULL `user_id`
**Solution**: Migration automatically assigns to first user, but verify:
```sql
SELECT id, name, user_id FROM websites WHERE user_id IS NULL;
```

### Issue 2: Admin Can't See Data
**Cause**: `is_admin` field is NULL or false
**Solution**: Set admin flag:
```bash
php artisan tinker
>>> User::find(1)->update(['is_admin' => true]);
```

### Issue 3: Webhook Processing Fails
**Cause**: Webhooks don't check authorization (by design)
**Solution**: This is correct behavior - webhooks create data for the website owner automatically.

### Issue 4: Analytics Cache Shows Wrong Data
**Cause**: Cache key doesn't include user_id (already fixed)
**Solution**: Clear cache:
```bash
php artisan cache:clear
```

---

## Next Steps (Optional Enhancements)

1. **Website Sharing**: Allow users to share websites with team members
2. **Role-Based Access**: Implement granular permissions (viewer, editor, admin)
3. **Audit Logging**: Track who accessed/modified what
4. **API Tokens**: User-specific API tokens for programmatic access
5. **Billing**: Charge per website or per user

---

## Security Checklist

- ✅ All queries filter by user's websites
- ✅ All policies check ownership or admin status
- ✅ Direct URL access is protected
- ✅ Cache keys include user_id
- ✅ New records automatically assigned to current user
- ✅ Foreign key constraints ensure data integrity
- ✅ Cascade delete removes user's websites when user deleted

---

## Summary

**Multi-tenancy is now fully implemented!** 

Users can only access their own data, admins can access everything, and all security boundaries are properly enforced at both the database and application layers.

**Files Modified**: 11
**New Policies**: 1
**Migration Created**: 1
**Security Issues Fixed**: 6 critical TODOs

---

**Questions or Issues?**
Check the policies and controller authorization logic, or review the test cases above to ensure everything works as expected.
