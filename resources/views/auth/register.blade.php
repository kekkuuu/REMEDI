@extends('layouts.app')

@section('title', 'Add New User')

@section('content')
<div class="page-back">
    <a href="{{ route('users.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
</div>

<div class="card">
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:16px;">
            <div>
                <label>Name</label><br>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>

            <div>
                <label>Email</label><br>
                <input type="email" name="email" value="{{ old('email') }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>

            <div>
                <label>Role</label><br>
                <select name="role" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    <option value="staff" {{ old('role') === 'staff' ? 'selected' : '' }}>Staff</option>
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                </select>
            </div>

            <div></div>

            <div>
                <label>Password</label><br>
                <input type="password" name="password" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>

            <div>
                <label>Confirm Password</label><br>
                <input type="password" name="password_confirmation" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
        </div>

        <div style="margin-top:20px;">
            <button type="submit" class="btn btn-primary">Create Account</button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection
