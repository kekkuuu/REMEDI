@extends('layouts.app')

@section('title', 'My profile')

@section('content')
<style>
    .profile-head {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .profile-head-mark {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        background: var(--brand-tint);
        border: 1px solid var(--brand-soft);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        color: var(--brand);
        flex-shrink: 0;
    }

    .profile-head h3 {
        margin: 0;
        font-family: 'Outfit', sans-serif;
        font-size: 22px;
        font-weight: 700;
        color: var(--ink);
    }

    .profile-head p { margin: 3px 0 0; font-size: 13.5px; color: var(--ink-soft); }

    /* minmax(0, …) and min-width:0 on the columns, never a bare 1fr. An fr
       track's implicit minimum is min-content and a grid item's default
       min-width is auto, so at 375px this grid resolved to a 634px track in a
       360px content area -- 274px of the profile hanging off the right edge
       with no way to scroll to it. */
    .profile-grid {
        display: grid;
        grid-template-columns: 340px minmax(0, 1fr);
        gap: 20px;
        align-items: start;
    }

    @media (max-width: 950px) {
        .profile-grid { grid-template-columns: minmax(0, 1fr); }
    }

    /* Each column stacks its own cards, so a tall card on one side no longer
       leaves a hole on the other. */
    .profile-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }

    /* ── Identity card ── */
    .profile-card { padding: 0; overflow: hidden; }

    .profile-card-top {
        background: linear-gradient(165deg, var(--brand-tint), #f0fdfa);
        padding: 26px 20px 20px;
        text-align: center;
        border-bottom: 1px solid var(--line);
    }

    .profile-avatar {
        width: 96px;
        height: 96px;
        margin: 0 auto 14px;
        border-radius: 50%;
        background: var(--brand);
        color: #fff;
        font-family: 'Outfit', sans-serif;
        font-size: 34px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 24px -10px rgba(16, 185, 129, .8);
    }

    .profile-name {
        font-family: 'Outfit', sans-serif;
        font-size: 20px;
        font-weight: 700;
        color: var(--ink);
    }

    .profile-role { margin-top: 4px; font-size: 13px; font-weight: 600; color: var(--brand-darker); }

    .profile-meta { padding: 6px 20px 20px; }

    .profile-meta-row {
        display: flex;
        align-items: flex-start;
        gap: 11px;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .profile-meta-row:last-child { border-bottom: none; }

    .profile-meta-row > i {
        width: 30px;
        height: 30px;
        flex-shrink: 0;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid var(--line);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        color: var(--brand);
    }

    .profile-meta-label {
        display: block;
        font-size: 11px;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: #94a3b8;
        font-weight: 600;
    }

    .profile-meta-value { display: block; font-size: 14px; color: var(--ink); margin-top: 2px; }

    /* ── Form ── */
    .profile-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px 20px;
    }

    @media (max-width: 640px) {
        .profile-form-grid { grid-template-columns: 1fr; }
    }

    .field label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 6px;
    }

    .field input,
    .field select { width: 100%; }

    .field .err { margin: 5px 0 0; font-size: 13px; color: #991b1b; }

    .field-locked {
        position: relative;
        display: flex;
        align-items: center;
    }

    .field-locked input {
        background: #f8fafc;
        color: #64748b;
        cursor: not-allowed;
    }

    .field-locked i {
        position: absolute;
        right: 12px;
        color: #94a3b8;
        font-size: 16px;
        pointer-events: none;
    }

    .profile-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid var(--line);
    }

    .section-title {
        font-family: 'Outfit', sans-serif;
        font-size: 17px;
        font-weight: 700;
        color: var(--ink);
        margin: 0 0 18px;
    }

    .icon-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }

    .icon-head i {
        width: 42px;
        height: 42px;
        flex-shrink: 0;
        border-radius: 12px;
        background: var(--brand-tint);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 21px;
        color: var(--brand);
    }

    .icon-head strong {
        display: block;
        font-family: 'Outfit', sans-serif;
        font-size: 16px;
        color: var(--ink);
    }

    .icon-head small { display: block; font-size: 13px; color: var(--ink-soft); margin-top: 3px; }
</style>

<div class="profile-head">
    <span class="profile-head-mark" aria-hidden="true"><i class="ti ti-user-circle"></i></span>
    <div>
        <h3>My Profile</h3>
        <p>View and manage your personal information and account settings.</p>
    </div>
</div>

@if(session('status') === 'profile-updated')
    <div class="alert alert-success">
        <i class="ti ti-circle-check" aria-hidden="true"></i>
        <span>Profile updated.</span>
    </div>
@endif

<div class="profile-grid">

  <div class="profile-col">
    {{-- ── Identity card ──
         Every row is a real column. The phone / department / language fields
         were added by migration 2026_08_19_000002 and are edited in the form
         beside this card, so what shows here is what the account holder
         entered -- not a placeholder. A field left blank says so. --}}
    <div class="card profile-card">
        <div class="profile-card-top">
            <div class="profile-avatar">{{ strtoupper(Str::substr($user->name, 0, 2)) }}</div>
            <div class="profile-name">{{ $user->name }}</div>
            <div class="profile-role">{{ $user->isAdmin() ? 'Administrator' : 'Staff' }}</div>
        </div>

        <div class="profile-meta">
            <div class="profile-meta-row">
                <i class="ti ti-id" aria-hidden="true"></i>
                <span>
                    <span class="profile-meta-label">User ID</span>
                    <span class="profile-meta-value">{{ $user->staff_code }}</span>
                </span>
            </div>

            <div class="profile-meta-row">
                <i class="ti ti-mail" aria-hidden="true"></i>
                <span>
                    <span class="profile-meta-label">Email</span>
                    <span class="profile-meta-value">{{ $user->email }}</span>
                </span>
            </div>

            <div class="profile-meta-row">
                <i class="ti ti-phone" aria-hidden="true"></i>
                <span>
                    <span class="profile-meta-label">Phone</span>
                    <span class="profile-meta-value">{{ $user->phone ?: 'Not set' }}</span>
                </span>
            </div>

            <div class="profile-meta-row">
                <i class="ti ti-building-store" aria-hidden="true"></i>
                <span>
                    <span class="profile-meta-label">Department</span>
                    <span class="profile-meta-value">{{ $user->department ?: 'Not set' }}</span>
                </span>
            </div>

            <div class="profile-meta-row">
                <i class="ti ti-shield-check" aria-hidden="true"></i>
                <span>
                    <span class="profile-meta-label">Status</span>
                    <span class="profile-meta-value">
                        <span class="badge {{ $user->is_active ? 'badge-success' : 'badge-critical' }}">
                            {{ $user->is_active ? 'Active' : 'Deactivated' }}
                        </span>
                    </span>
                </span>
            </div>

            <div class="profile-meta-row">
                <i class="ti ti-clock" aria-hidden="true"></i>
                <span>
                    <span class="profile-meta-label">Last Login</span>
                    <span class="profile-meta-value">
                        @if($user->last_login_at)
                            {{ $user->last_login_at->format('M j, Y') }} &middot; {{ $user->last_login_at->format('g:i A') }}
                        @else
                            This session
                        @endif
                    </span>
                </span>
            </div>

            <div class="profile-meta-row">
                <i class="ti ti-calendar" aria-hidden="true"></i>
                <span>
                    <span class="profile-meta-label">Account Created</span>
                    <span class="profile-meta-value">
                        {{ $user->created_at?->format('M j, Y') ?? '—' }}
                        @if($user->created_at)
                            &middot; {{ $user->created_at->format('g:i A') }}
                        @endif
                    </span>
                </span>
            </div>
        </div>
    </div>

    {{-- ── Change password ── --}}
    <div class="card">
        <div class="icon-head">
            <i class="ti ti-lock" aria-hidden="true"></i>
            <span>
                <strong>Change Password</strong>
                <small>Update your password periodically to keep your account secure.</small>
            </span>
        </div>

        @if(session('status') === 'password-updated')
            <div class="alert alert-success">
                <i class="ti ti-circle-check" aria-hidden="true"></i>
                <span>Password updated.</span>
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            @method('PUT')

            <div class="field" style="margin-bottom:14px;">
                <label for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password" autocomplete="current-password">
                @error('current_password', 'updatePassword')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" autocomplete="new-password">
                @error('password', 'updatePassword')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="field" style="margin-bottom:18px;">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">
                @error('password_confirmation', 'updatePassword')<p class="err">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Change Password</button>
        </form>
    </div>
  </div>

  <div class="profile-col">

    {{-- ── Personal information ── --}}
    <div class="card">
        <p class="section-title">Personal Information</p>

        @unless($user->isAdmin())
            <p style="margin:-8px 0 16px; font-size:13px; color:var(--ink-soft);">
                <i class="ti ti-info-circle" aria-hidden="true"></i>
                You can update your name and phone number. Everything else is managed by an administrator.
            </p>
        @endunless

        <form method="POST" action="{{ route('profile.update') }}">
            @csrf
            @method('PATCH')

            <div class="profile-form-grid">
                <div class="field">
                    <label for="name">Full Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required>
                    @error('name')<p class="err">{{ $message }}</p>@enderror
                </div>

                @php $canEditAll = $user->isAdmin(); @endphp

                <div class="field">
                    <label for="email">Email Address</label>
                    @if($canEditAll)
                        <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required>
                        @error('email')<p class="err">{{ $message }}</p>@enderror
                    @else
                        <div class="field-locked">
                            <input id="email" type="email" value="{{ $user->email }}" disabled>
                            <i class="ti ti-lock" aria-hidden="true"></i>
                        </div>
                    @endif
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input id="phone" type="text" name="phone" value="{{ old('phone', $user->phone) }}"
                           placeholder="+63 912 345 6789">
                    @error('phone')<p class="err">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="department">Department</label>
                    @if($canEditAll)
                        <input id="department" type="text" name="department" value="{{ old('department', $user->department) }}"
                               placeholder="Pharmacy Management">
                        @error('department')<p class="err">{{ $message }}</p>@enderror
                    @else
                        <div class="field-locked">
                            <input id="department" type="text" value="{{ $user->department ?: 'Not set' }}" disabled>
                            <i class="ti ti-lock" aria-hidden="true"></i>
                        </div>
                    @endif
                </div>

                <div class="field">
                    <label for="preferred_language">Preferred Language</label>
                    @if($canEditAll)
                        <select id="preferred_language" name="preferred_language">
                            @foreach (['English', 'Filipino', 'Cebuano', 'Ilocano'] as $lang)
                                <option value="{{ $lang }}" @selected(old('preferred_language', $user->preferred_language) === $lang)>{{ $lang }}</option>
                            @endforeach
                        </select>
                        @error('preferred_language')<p class="err">{{ $message }}</p>@enderror
                    @else
                        <div class="field-locked">
                            <input id="preferred_language" type="text" value="{{ $user->preferred_language }}" disabled>
                            <i class="ti ti-lock" aria-hidden="true"></i>
                        </div>
                    @endif
                </div>

                {{-- Role is shown but not editable: this form belongs to the
                     account holder, and letting it set its own role would be a
                     privilege escalation. Changed from User Management. --}}
                <div class="field">
                    <label for="role">Role</label>
                    <div class="field-locked">
                        <input id="role" type="text" value="{{ $user->isAdmin() ? 'Administrator' : 'Staff' }}" disabled>
                        <i class="ti ti-lock" aria-hidden="true"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="user-id">User ID</label>
                    <div class="field-locked">
                        <input id="user-id" type="text" value="{{ $user->staff_code }}" disabled>
                        <i class="ti ti-lock" aria-hidden="true"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="member-since">Member Since</label>
                    <div class="field-locked">
                        <input id="member-since" type="text" value="{{ $user->created_at?->format('F j, Y') ?? '—' }}" disabled>
                        <i class="ti ti-lock" aria-hidden="true"></i>
                    </div>
                </div>
            </div>

            <div class="profile-actions">
                <a href="{{ route('dashboard') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy" aria-hidden="true"></i> Save Changes
                </button>
            </div>
        </form>
    </div>

    {{-- ── Account activity ──
         Real rows from audit_trails for this user. AuditTrail::log() now stamps
         request()->ip(), so the IP column is genuine; rows written before that
         migration, or by the scheduler (which has no request behind it), show
         a dash rather than a guess. --}}
    <div class="card">
        <div class="icon-head">
            <i class="ti ti-activity" aria-hidden="true"></i>
            <span>
                <strong>Account Activity</strong>
                <small>Your most recent recorded actions in the system.</small>
            </span>
        </div>

        @if($recentActivity->isNotEmpty())
            <div class="table-scroll"><table class="remedi-table">
                <thead>
                    <tr>
                        <th>Activity</th>
                        <th>Details</th>
                        <th>Date &amp; Time</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentActivity as $entry)
                        <tr>
                            <td style="font-weight:600;">{{ $entry->action }}</td>
                            <td style="color:#64748b;">{{ Str::limit($entry->details, 60) }}</td>
                            <td style="color:#64748b; white-space:nowrap;">
                                {{ $entry->created_at->format('M j, Y') }} &middot; {{ $entry->created_at->format('g:i A') }}
                            </td>
                            <td style="color:#64748b; white-space:nowrap;">{{ $entry->ip_address ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>

            @if(auth()->user()->isAdmin())
                <p style="margin:14px 0 0;">
                    <a href="{{ route('audit.index') }}" class="view-all">View all activity logs &rsaquo;</a>
                </p>
            @endif
        @else
            <p style="color:#94a3b8; font-size:13.5px;">No recorded activity yet.</p>
        @endif
    </div>
  </div>
</div>

{{-- Account deletion stays available but well out of the way of everything
     above, since it is irreversible. --}}
<div class="card" style="margin-top:20px; border-color:#fecaca;">
    <div class="icon-head">
        <i class="ti ti-alert-triangle" aria-hidden="true" style="background:#fee2e2; color:#dc2626;"></i>
        <span>
            <strong>Delete Account</strong>
            <small>Once deleted, this account and its data cannot be recovered.</small>
        </span>
    </div>
    @include('profile.partials.delete-user-form')
</div>
@endsection
