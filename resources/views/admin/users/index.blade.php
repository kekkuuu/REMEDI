@extends('layouts.app')

@section('title', 'User Management')

@section('content')
<style>
    /* Action buttons sit side by side on one line — a 16px gap with wrapping
       let a three-button row stack into three lines. */
    .actions-cell {
        display: inline-flex;
        align-items: center;
        flex-wrap: nowrap;
        gap: 6px;
        white-space: nowrap;
    }

    .actions-cell form { margin: 0; display: inline-flex; }

    .action-btn {
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 13px;
    }
</style>

<div style="display:flex; justify-content:space-between; margin-bottom:16px;">
    <form method="GET" style="display:flex; gap:8px;">
        <input type="text" name="search" placeholder="Search by name or email" value="{{ request('search') }}" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:6px;" data-suggest-url="{{ route('suggest.users') }}">
        <button type="submit" class="btn btn-secondary">Search</button>
    </form>
    <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
        <i class="ti ti-user-plus" aria-hidden="true"></i> Add User
    </a>
</div>

<div class="card">
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Date Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse($users as $user)
            <tr>
                <td>{{ $user->id }}</td>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email }}</td>
                <td>
                    <span class="badge {{ $user->role === 'admin' ? 'badge-success' : 'badge-warning' }}">
                        {{ ucfirst($user->role) }}
                    </span>
                </td>
                <td>
                    {{-- data-user-status, not a colour class, is what the toggle
                         handler targets: an admin's ROLE badge is also
                         .badge-success and sits earlier in the row, so matching
                         on colour rewrote the role to "Active". --}}
                    @if($user->is_active)
                        <span class="badge badge-success" data-user-status>Active</span>
                    @else
                        <span class="badge badge-danger" data-user-status>Inactive</span>
                    @endif
                </td>
                <td>{{ $user->created_at->format('M d, Y') }}</td>
                <td>
                    <div class="actions-cell">
                        <a href="{{ route('users.edit', $user) }}" class="btn btn-info action-btn"><i class="ti ti-pencil" aria-hidden="true"></i> Edit</a>

                        @if($user->id !== auth()->id())
                            <form method="POST" action="{{ route('users.toggle', $user) }}"
                                  class="js-confirm"
                                  data-confirm-title="{{ $user->is_active ? 'Deactivate' : 'Activate' }} this account?"
                                  data-confirm-body="{{ $user->is_active
                                        ? $user->name . ' will be signed out and unable to sign in again.'
                                        : $user->name . ' will be able to sign in again.' }}"
                                  data-confirm-body-off="{{ $user->name }} will be signed out and unable to sign in again."
                                  data-confirm-body-on="{{ $user->name }} will be able to sign in again."
                                  data-confirm-label="{{ $user->is_active ? 'Deactivate' : 'Activate' }}"
                                  data-confirm-icon="{{ $user->is_active ? 'ti-user-off' : 'ti-user-check' }}"
                                  data-confirm-tone="neutral"
                                  data-on-success="toggle">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn action-btn {{ $user->is_active ? 'btn-warning' : 'btn-success' }}">
                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>

                            {{-- Stays a real POST form: the shared js-confirm
                                 handler in layouts/app.blade.php only intercepts
                                 submit, so with JavaScript off this still
                                 deletes (unconfirmed) rather than being a dead
                                 button — same trade-off as #logoutModal. --}}
                            <form method="POST" action="{{ route('users.destroy', $user) }}"
                                  class="js-confirm"
                                  data-confirm-title="Delete this account?"
                                  data-confirm-body="Permanently delete {{ $user->name }} ({{ $user->email }})? This cannot be undone."
                                  data-confirm-label="Delete"
                                  data-confirm-icon="ti-trash"
                                  data-on-success="remove-row">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger action-btn"><i class="ti ti-trash" aria-hidden="true"></i> Delete</button>
                            </form>
                        @else
                            <span class="badge badge-success">You</span>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7">No users found.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div style="margin-top:16px;">{{ $users->links() }}</div>
</div>

@endsection
