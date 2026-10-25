{{-- The plain-text half of the Safeguard code email -- see safeguard-code.blade.php. --}}
REMEDI - Inventory & Sales Management

Hello {!! $user->name !!},

You asked to replace the {!! $what !!} on the Safeguard page without the
current one. Enter this code in the "Current" box instead:

    {{ $code }}

The code works for {{ $expiresInMinutes }} minutes and only once.

If you did not ask for this, you can ignore this email; nothing has changed.
If it keeps happening, tell your REMEDI administrator.

Sent by REMEDI because a Safeguard code was requested for this account.
