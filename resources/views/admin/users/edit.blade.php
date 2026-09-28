@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
<style>
    /* Compact Edit User (2026-09-28, at the user's request): the same shared
       form vocabulary, tightened for this one page -- smaller chips, less air
       between fields, slimmer inputs. Scoped to .is-compact so Add User and
       the product forms keep their spacing. Two classes deep, to outrank the
       layout's one-class rules. */
    .form-card.is-compact { padding: 18px 22px; }
    .form-card.is-compact .section-head { margin: -18px -22px 16px; }
    .form-card.is-compact .section-head h4 { padding: 11px 22px; font-size: 13px; }
    .form-card.is-compact .form-grid { gap: 12px 20px; }
    .form-card.is-compact .form-field-head { gap: 8px; margin-bottom: 5px; }
    .form-card.is-compact .form-chip { width: 28px; height: 28px; border-radius: 8px; font-size: 15px; }
    .form-card.is-compact .form-field-head label { font-size: 13.5px; }
    .form-card.is-compact .form-field input[type="text"],
    .form-card.is-compact .form-field input[type="email"],
    .form-card.is-compact .form-field input[type="password"],
    .form-card.is-compact .form-field select { padding: 8px 11px; font-size: 13.5px; border-radius: 9px; }
    .form-card.is-compact .form-field .hint { margin-top: 4px; font-size: 12px; }
    .form-card.is-compact .form-actions { margin-top: 16px; padding-top: 14px; }
    .form-card.is-compact .form-actions .btn-lg { padding: 9px 18px; font-size: 14px; }
</style>

{{-- The avatar reuses .user-avatar from the topbar rather than a second circle
     style: it is the same object, showing the same initials. --}}
<div class="page-head">
    <a href="{{ route('users.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="user-avatar" aria-hidden="true">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
    <div class="page-head-text">
        <h3>{{ $user->name }}</h3>
        <p>{{ $user->email }}</p>
    </div>
</div>

{{-- Same .form-card vocabulary as Add Product and Add User. Laid out to the
     user's mockup (2026-09-28): an icon in the band, "Full Name", a red star
     on every field the server requires, "Enter …" placeholders, and the two
     columns reading Full Name | Email, Role | Personal Email, Phone | New
     Password, then Confirm New Password -- the order the grid flows in. --}}
<div class="form-card is-compact">
    <div class="section-head">
        <h4><i class="ti ti-user" aria-hidden="true"></i>Account Details</h4>
    </div>

    <form method="POST" action="{{ route('users.update', $user) }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-user-cog"
          data-confirm-title="Save changes to this account?" data-confirm-body="The account details, including its role, will be updated."
          data-confirm-label="Save changes">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-user" aria-hidden="true"></i></span>
                    <label for="name">Full Name<span class="req" aria-hidden="true">*</span></label>
                </div>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                       placeholder="Enter full name" required>
                @error('name')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-mail" aria-hidden="true"></i></span>
                    <label for="email">Email<span class="req" aria-hidden="true">*</span></label>
                </div>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                       placeholder="Enter email address" required>
                @error('email')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-shield" aria-hidden="true"></i></span>
                    <label for="role">Role<span class="req" aria-hidden="true">*</span></label>
                </div>
                {{-- Disabled inputs are not submitted, so the hidden field below
                     carries the unchanged role. UserController refuses a
                     self-demotion anyway; this only keeps the form honest.
                     The blocked cursor on hover (.form-field select:disabled,
                     defined in the layout) is the visual cue. --}}
                <select id="role" name="role" required {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                    <option value="admin" {{ $user->role === 'admin' ? 'selected' : '' }}>Admin</option>
                    <option value="staff" {{ $user->role === 'staff' ? 'selected' : '' }}>Staff</option>
                </select>
                @if($user->id === auth()->id())
                    <input type="hidden" name="role" value="{{ $user->role }}">
                    <span class="form-field-note">
                        <i class="ti ti-info-circle" aria-hidden="true"></i>
                        You cannot change your own role.
                    </span>
                @endif
            </div>

            {{-- Where a password-reset code is emailed, so it must be an
                 address reachable while LOCKED OUT -- not the @remedi.com one
                 above, which only exists inside the system. --}}
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-mail" aria-hidden="true"></i></span>
                    <label for="personal_email">Personal Email</label>
                </div>
                <input type="email" id="personal_email" name="personal_email"
                       value="{{ old('personal_email', $user->personal_email) }}"
                       placeholder="Enter personal email" autocomplete="off">
                <p class="hint">Used to send password reset codes. Optional.</p>
                @error('personal_email')<p class="err">{{ $message }}</p>@enderror
            </div>

            {{-- Optional, same rule as ProfileUpdateRequest's own phone field
                 (nullable, max:40) -- so no star: most accounts have none. --}}
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-phone" aria-hidden="true"></i></span>
                    <label for="phone">Phone Number</label>
                </div>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"
                       placeholder="Enter phone number">
                @error('phone')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock" aria-hidden="true"></i></span>
                    {{-- "(leave blank to keep current)" moved into the hint below:
                         in the label it wrapped to two lines and pushed this
                         field out of line with Phone Number beside it. --}}
                    <label for="password">New Password</label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" placeholder="Enter new password" autocomplete="new-password">
                    <button type="button" class="pw-toggle" data-pw-toggle="password"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
                {{-- Delegated in layouts/app.blade.php's document 'input' listener,
                     same pattern as .pw-toggle -- see the comment there. --}}
                <div class="pw-strength" data-pw-strength-for="password" hidden>
                    <div class="pw-strength-bar"><span></span></div>
                    <span class="pw-strength-label"></span>
                </div>
                <p class="hint">Leave blank to keep the current password.</p>
                @error('password')<p class="err">{{ $message }}</p>@enderror
            </div>

            {{-- Required only once a new password is typed -- `confirmed` on the
                 server compares the two -- so the star and the `required`
                 attribute appear with it (script below), not before. --}}
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock" aria-hidden="true"></i></span>
                    <label for="password_confirmation">Confirm New Password<span class="req" id="confirmStar" aria-hidden="true" hidden>*</span></label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           placeholder="Confirm new password" autocomplete="new-password">
                    <button type="button" class="pw-toggle" data-pw-toggle="password_confirmation"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-device-floppy" aria-hidden="true"></i> Update User
            </button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary btn-lg">
                <i class="ti ti-x" aria-hidden="true"></i> Cancel
            </a>
        </div>
    </form>
</div>

<script>
    // Confirm New Password is required exactly when a new password is typed.
    (function () {
        var pw = document.getElementById('password');
        var confirm = document.getElementById('password_confirmation');
        var star = document.getElementById('confirmStar');
        if (!pw || !confirm || !star) return;

        function sync() {
            var needed = pw.value !== '';
            confirm.required = needed;
            star.hidden = !needed;
        }

        pw.addEventListener('input', sync);
        sync();
    })();
</script>
@endsection
