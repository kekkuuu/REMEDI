@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
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

{{-- Same .form-card vocabulary as Add Product and Add User. --}}
<div class="form-card">
    <div class="section-head">
        <h4>Account Details</h4>
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
                    <label for="name">Name</label>
                </div>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-mail" aria-hidden="true"></i></span>
                    <label for="email">Email</label>
                </div>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-shield-lock" aria-hidden="true"></i></span>
                    <label for="role">Role</label>
                </div>
                {{-- Disabled inputs are not submitted, so the hidden field below
                     carries the unchanged role. UserController refuses a
                     self-demotion anyway; this only keeps the form honest.
                     The blocked cursor on hover (.form-field select:disabled,
                     defined in the layout) is the visual cue -- not an icon in
                     the field, the pointer itself changing when you try to
                     use it. --}}
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
                    <span class="form-chip"><i class="ti ti-mail-heart" aria-hidden="true"></i></span>
                    <label for="personal_email">Personal Email</label>
                </div>
                <input type="email" id="personal_email" name="personal_email"
                       value="{{ old('personal_email', $user->personal_email) }}"
                       placeholder="name@gmail.com" autocomplete="off">
                <p class="hint">Used to send password reset codes. Optional.</p>
                @error('personal_email')<p class="err">{{ $message }}</p>@enderror
            </div>

            {{-- Optional, same rule as ProfileUpdateRequest's own phone field
                 (nullable, max:40). --}}
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-phone" aria-hidden="true"></i></span>
                    <label for="phone">Phone Number</label>
                </div>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"
                       placeholder="+63 912 345 6789">
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock" aria-hidden="true"></i></span>
                    <label for="password">New Password (leave blank to keep current)</label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" placeholder="Enter new password">
                    <button type="button" class="pw-toggle" data-pw-toggle="password"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
                {{-- Delegated in layouts/app.blade.php's document 'input' listener,
                     same pattern as .pw-toggle -- see the comment there. --}}
                <div class="pw-strength" data-pw-strength-for="password" hidden>
                    <div class="pw-strength-bar"><span></span></div>
                    <span class="pw-strength-label"></span>
                </div>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock-check" aria-hidden="true"></i></span>
                    <label for="password_confirmation">Confirm New Password</label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           placeholder="Confirm new password">
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
@endsection
