{{-- The plain-text half; see new-account-credentials.blade.php. Raw output because this is not HTML. --}}
REMEDI - Inventory & Sales Management

Hello {!! $user->name !!},

An administrator created a REMEDI account for you. Use these details to sign in:

    Sign-in email:       {!! $user->email !!}
    Temporary password:  {!! $temporaryPassword !!}

You will be asked to choose your own password the first time you sign in.
Sign in here: {!! $loginUrl !!}

If you were not expecting this, tell your REMEDI administrator.

Sent by REMEDI because an account was created for you.
