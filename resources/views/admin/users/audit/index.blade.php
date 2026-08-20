@extends('layouts.app')

@section('title', 'Audit Trail')

@section('content')
<form method="GET" style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
    <input type="text" name="search" placeholder="Search username or details..." value="{{ request('search') }}" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; flex:1;">
    <select name="action" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:6px;">
        <option value="">All Actions</option>
        @foreach(['Created', 'Updated', 'Deleted', 'Login', 'Logout', 'Viewed'] as $action)
            <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ $action }}</option>
        @endforeach
    </select>
    <select name="role" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:6px;">
        <option value="">All Roles</option>
        <option value="admin" {{ request('role') === 'admin' ? 'selected' : '' }}>Admin</option>
        <option value="staff" {{ request('role') === 'staff' ? 'selected' : '' }}>Staff</option>
    </select>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <a href="{{ route('audit.index') }}" class="btn btn-secondary">Reset</a>
</form>

<div class="card">
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Username</th>
                <th>Role</th>
                <th>Action</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td>{{ $log->created_at->format('M d, Y h:i A') }}</td>
                <td>{{ $log->username }}</td>
                <td>
                    <span class="badge {{ $log->role === 'admin' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($log->role) }}</span>
                </td>
                <td>
                    @php
                        $actionColors = ['Created' => 'badge-success', 'Updated' => 'badge-warning', 'Deleted' => 'badge-danger', 'Login' => 'badge-success', 'Logout' => 'badge-warning', 'Viewed' => 'badge-success'];
                    @endphp
                    <span class="badge {{ $actionColors[$log->action] ?? 'badge-success' }}">{{ $log->action }}</span>
                </td>
                <td>{{ $log->details }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No activity logged yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div style="margin-top:16px;">{{ $logs->links() }}</div>
</div>
@endsection