@extends('layouts.app')

@section('title', 'Add New User')

@section('content')
{{-- This is the admin "Add User" form, not public signup — hence the back
     link to the user list rather than to login. --}}
<div class="page-head">
    <a href="{{ route('users.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>Add New User</h3>
        <p>Create an account and assign it a role.</p>
    </div>
</div>

{{-- Same .form-card vocabulary as Add Product and Edit User. --}}
<div class="form-card">
    <div class="section-head">
        <h4>Account Details</h4>
    </div>

    <form method="POST" action="{{ route('register') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-user-plus"
          data-confirm-title="Create this account?" data-confirm-body="A new account will be created with the role selected above."
          data-confirm-label="Create account">
        @csrf

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-user" aria-hidden="true"></i></span>
                    <label for="name">Name</label>
                </div>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="Enter full name" required autofocus>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-mail" aria-hidden="true"></i></span>
                    <label for="email">Email</label>
                </div>
                {{-- The whole address is typed here, and the placeholder shows
                     the shape of one. No domain is appended for you: a field
                     that completes what you typed has to say so, and this one
                     is plain. --}}
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="e.g. jane@remedi.com" autocomplete="off" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-shield-lock" aria-hidden="true"></i></span>
                    <label for="role">Role</label>
                </div>
                <select id="role" name="role" required>
                    <option value="staff" {{ old('role') === 'staff' ? 'selected' : '' }}>Staff</option>
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                </select>
            </div>

            {{-- Keeps Role alone on its row, so the two password fields pair up
                 on the next one. --}}
            <div class="form-field" aria-hidden="true"></div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock" aria-hidden="true"></i></span>
                    <label for="password">Password</label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="Enter password" required>
                    <button type="button" class="pw-toggle" data-pw-toggle="password"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock-check" aria-hidden="true"></i></span>
                    <label for="password_confirmation">Confirm Password</label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           placeholder="Confirm password" required>
                    <button type="button" class="pw-toggle" data-pw-toggle="password_confirmation"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-user-plus" aria-hidden="true"></i> Create Account
            </button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary btn-lg">
                <i class="ti ti-x" aria-hidden="true"></i> Cancel
            </a>
        </div>
    </form>
</div>
@endsection
