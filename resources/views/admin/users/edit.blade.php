@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
<div class="page-back">
    <a href="{{ route('users.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
</div>

<div class="card">
    <form method="POST" action="{{ route('users.update', $user) }}">
        @csrf
        @method('PUT')

        <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:16px;">
            <div>
                <label>Name</label><br>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Email</label><br>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Role</label><br>
                <select name="role" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;" {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                    <option value="admin" {{ $user->role === 'admin' ? 'selected' : '' }}>Admin</option>
                    <option value="staff" {{ $user->role === 'staff' ? 'selected' : '' }}>Staff</option>
                </select>
                @if($user->id === auth()->id())
                    <input type="hidden" name="role" value="{{ $user->role }}">
                    <small>You cannot change your own role.</small>
                @endif
            </div>
            <div></div>
            <div>
                <label>New Password (leave blank to keep current)</label><br>
                <input type="password" name="password" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Confirm New Password</label><br>
                <input type="password" name="password_confirmation" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
        </div>

        <div style="margin-top:20px;">
            <button type="submit" class="btn btn-primary">Update User</button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection
