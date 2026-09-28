{{-- The plain-text half of the reset-code email -- see
     password-reset-code.blade.php for why the pair matters for delivery. --}}
REMEDI - Inventory & Sales Management

Hello {!! $user->name !!},

You asked to reset your REMEDI password. Enter this code on the reset
screen to continue:

    {{ $code }}

The code works for {{ $expiresInMinutes }} minutes and only once.

If you did not ask for this, you can ignore this email; your password has not
changed. If it keeps happening, tell your REMEDI administrator.

Sent by REMEDI because a password reset was requested for this account.
