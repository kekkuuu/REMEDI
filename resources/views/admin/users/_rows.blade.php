{{-- Shared by the full page render and the AJAX search/filter refresh — see
     the AJAX partial pattern in CLAUDE.md. Self-contained: everything the
     table needs ($columns, $initialsOf) is computed here rather than in
     index.blade.php, since this view is also rendered standalone. --}}
@php
    // Every sortable heading. Kept as a block (never the parenthesised
    // one-line @php form) and never named literally inside a comment — see
    // index.blade.php's note on why, which applies here just as much.
    $columns = [];

    foreach (['id' => 'ID', 'name' => 'Name', 'role' => 'Role', 'status' => 'Status', 'joined' => 'Date Joined'] as $key => $label) {
        $active = request('sort') === $key;
        $desc = $active && request('dir') === 'desc';

        $columns[$key] = [
            'label' => $label,
            'url' => request()->fullUrlWithQuery(['sort' => $key, 'dir' => $active && ! $desc ? 'desc' : 'asc', 'page' => null]),
            'active' => $active,
            'icon' => $active ? ($desc ? 'ti-chevron-down' : 'ti-chevron-up') : 'ti-selector',
            'aria' => $active ? ($desc ? 'descending' : 'ascending') : 'none',
        ];
    }

    // At most two initials, from the first and last word of the name.
    $initialsOf = function (string $name) {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

        return mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    };
@endphp

<div class="table-scroll"><table class="remedi-table">
    <thead>
        <tr>
            @foreach(['id', 'name'] as $key)
                <th aria-sort="{{ $columns[$key]['aria'] }}">
                    <a href="{{ $columns[$key]['url'] }}" class="th-sort {{ $columns[$key]['active'] ? 'is-sorted' : '' }}">
                        {{ $columns[$key]['label'] }} <i class="ti {{ $columns[$key]['icon'] }}" aria-hidden="true"></i>
                    </a>
                </th>
            @endforeach
            <th>Email</th>
            @foreach(['role', 'status', 'joined'] as $key)
                <th aria-sort="{{ $columns[$key]['aria'] }}">
                    <a href="{{ $columns[$key]['url'] }}" class="th-sort {{ $columns[$key]['active'] ? 'is-sorted' : '' }}">
                        {{ $columns[$key]['label'] }} <i class="ti {{ $columns[$key]['icon'] }}" aria-hidden="true"></i>
                    </a>
                </th>
            @endforeach
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    @forelse($users as $user)
        <tr>
            {{-- User::$staff_code ("ADM-001" / "STF-004"), not the bare
                 primary key -- matches what My Profile shows for the same
                 account. Sorting stays on the real `id` column (see the
                 ?sort=id link above this table); only the DISPLAYED text
                 changes. --}}
            <td>{{ $user->staff_code }}</td>
            <td>
                <div class="user-cell">
                    <span class="user-avatar" aria-hidden="true">{{ $initialsOf($user->name) }}</span>
                    <div>
                        <div class="user-cell-name">{{ $user->name }}</div>
                        <div class="user-cell-role">{{ $user->isAdmin() ? 'System Administrator' : 'Staff' }}</div>
                    </div>
                </div>
            </td>
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
                @if($archived)
                    <span class="badge badge-warning" data-user-status>Archived</span>
                @elseif($user->is_active)
                    <span class="badge badge-success" data-user-status>Active</span>
                @else
                    <span class="badge badge-danger" data-user-status>Inactive</span>
                @endif
            </td>
            <td>{{ $user->created_at->format('M d, Y') }}</td>
            <td>
                <div class="actions-cell">
                @if($archived)
                    <form method="POST" action="{{ route('users.restore', $user) }}"
                          class="js-confirm"
                          data-confirm-title="Restore this account?"
                          data-confirm-body="{{ $user->name }} ({{ $user->email }}) goes back on the user list and can sign in again."
                          data-confirm-label="Restore"
                          data-confirm-icon="ti-archive-off"
                          data-confirm-tone="neutral"
                          data-on-success="remove-row">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-success action-btn"><i class="ti ti-archive-off" aria-hidden="true"></i> Restore</button>
                    </form>
                @else
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
                            <button type="submit" class="btn action-btn action-toggle {{ $user->is_active ? 'btn-warning' : 'btn-success' }}">
                                <i class="ti {{ $user->is_active ? 'ti-user-off' : 'ti-user-check' }}" aria-hidden="true"></i>
                                {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>

                        {{-- Stays a real POST form: the shared js-confirm
                             handler in layouts/app.blade.php only intercepts
                             submit, so with JavaScript off this still
                             archives (unconfirmed) rather than being a dead
                             button -- same trade-off as #logoutModal.

                             Archive, not delete: the account can no longer
                             sign in and leaves the list, but its sales keep
                             their cashier and it can be restored. Deactivate
                             (above) is the in-place way to suspend someone
                             who stays on the list. --}}
                        <form method="POST" action="{{ route('users.destroy', $user) }}"
                              class="js-confirm"
                              data-confirm-title="Archive this account?"
                              data-confirm-body="Archive {{ $user->name }} ({{ $user->email }})? They will be signed out and unable to sign in, and leave this list. Their sales history is kept, and you can restore the account from the Archived list."
                              data-confirm-label="Archive"
                              data-confirm-icon="ti-archive"
                              data-confirm-tone="neutral"
                              data-on-success="remove-row">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger action-btn"><i class="ti ti-archive" aria-hidden="true"></i> Archive</button>
                        </form>
                    @else
                        <span class="badge badge-success">You</span>
                    @endif
                @endif
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="7">{{ $archived ? 'No archived accounts.' : 'No users found.' }}</td></tr>
    @endforelse
    </tbody>
</table></div>

<div class="users-foot">
    <span class="users-count">
        @if($users->total())
            Showing {{ $users->firstItem() }} to {{ $users->lastItem() }} of {{ number_format($users->total()) }} users
        @else
            No users to show
        @endif
    </span>
    <div>{{ $users->links() }}</div>
</div>
