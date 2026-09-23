<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Deliberately empty. This app has no API surface: every screen is
| server-rendered Blade, and the "AJAX" endpoints it does have live in
| routes/web.php behind the same ['auth', 'active', 'must_change_password']
| group as the pages, so a deactivated or password-locked account is refused
| there too.
|
| Breeze's stock `GET /api/user` (auth:sanctum) was removed 2026-09-23. It was
| unreachable -- User does not use HasApiTokens, nothing ever called
| createToken(), and production held zero personal_access_tokens -- but it was
| the ONLY authenticated route in the app carrying neither the `active` check
| nor the password lock, which breaks the rule in CLAUDE.md that no route sits
| in the auth group without `active`. Left in place it was a trap rather than
| a hole: adding HasApiTokens to User later would have silently turned it into
| an ungated endpoint returning the full user record.
|
| If a real API is ever needed, gate it the same way web.php does rather than
| relying on the `api` middleware group, which carries neither check.
|
*/
