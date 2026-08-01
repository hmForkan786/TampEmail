# User Settings Deployment Checklist

1. Deploy code on `feature/identity-layer` (or merged release branch).
2. Set Settings env keys from `.env.example` (password change revoke, export disk/TTL, rate limits, closure phrase).
3. Ensure Analytics collector classes required by Identity/Settings recorders are present in the release set.
4. Run migrations:

```bash
php artisan migrate --force
```

5. Cache:

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

6. Verify scheduler entries:

```bash
php artisan schedule:list
```

7. Health:

```bash
php artisan settings:health
```

8. Smoke:

- Login as active verified user
- Open `/settings` and each section
- Profile update (no email write)
- Password change notification
- Notification critical category locked
- API key create shows secret once
- Billing summary loads
- Privacy export request queues
- Account closure phrase enforced

9. Confirm Filament **User Settings Overview** is read-only for admins.
