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

    /* Change Password card, matched to the supplied design. Scoped to
       .password-card so the airier metrics do not leak into the profile form
       beside it, which is a denser two-column layout and reads worse at these
       sizes. Measured against the reference: 46px inputs (we had 40), a 50px
       button (39), and 10px radii throughout (7-8). */
    .password-card .icon-head { margin-bottom: 18px; }
    .password-card .icon-head strong { font-size: 17px; }
    .password-card .icon-head small { font-size: 13px; line-height: 1.5; }

    /* The form itself now lives in #changePasswordModal, so these are scoped
       there rather than to the card it used to sit in. */
    #changePasswordModal .field { margin-bottom: 16px !important; }
    #changePasswordModal .field label { font-size: 13.5px; font-weight: 600; margin-bottom: 8px; }

    #changePasswordModal .field input {
        height: 46px;
        padding: 12px 14px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        font-size: 14.5px;
    }

    #changePasswordModal .field input:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px var(--brand-soft);
        outline: none;
    }

    #changePasswordModal .remedi-modal__actions .btn {
        height: 50px;
        border-radius: 10px;
        font-size: 15px;
        font-weight: 600;
    }

    /* .remedi-modal__panel centres its text for the confirm dialogs; a form
       needs its labels left-aligned, and a little more room than the 400px a
       yes/no question wants. */
    .remedi-modal__panel--form {
        text-align: left;
        max-width: 440px;
    }

    .remedi-modal__panel--form .icon-head { margin-bottom: 20px; }
    .remedi-modal__panel--form .icon-head strong { font-size: 17px; }
    .remedi-modal__panel--form .icon-head small { font-size: 13px; line-height: 1.5; }
    .remedi-modal__panel--form .remedi-modal__actions { margin-top: 4px; }

    /* Collapsed-state trigger: outlined, not filled. The filled green button is
       the one that actually changes the password — giving both the same weight
       made the card look like it had two submits. */
    .btn-outline-brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 44px;
        padding: 0 22px;
        border: 1px solid var(--brand);
        border-radius: 10px;
        background: #fff;
        color: var(--brand-darker);
        font-family: inherit;
        font-size: 14.5px;
        font-weight: 600;
        cursor: pointer;
        transition: background .14s ease;
    }

    .btn-outline-brand:hover { background: var(--brand-tint); }
    .btn-outline-brand[hidden] { display: none; }

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
         Every row is a real column. The phone / language fields were added
         by migration 2026_08_19_000002 and are edited in the form beside
         this card, so what shows here is what the account holder entered
         -- not a placeholder. A field left blank says so. (Department was
         part of that same migration and is still a real, fillable column
         -- it's just no longer shown or edited here.) --}}
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
    <div class="card password-card">
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

        {{-- Where the AJAX submit reports back. The server-rendered session and
             @error output around it is untouched, so with JavaScript off this
             form behaves exactly as it always did. --}}
        <div id="passwordFlash" aria-live="polite"></div>

        {{-- The card only ever shows this trigger; the form itself lives in a
             centred dialog below, the same way the POS shows its receipt. --}}
        <button type="button" id="changePasswordTrigger" class="btn-outline-brand">
            Change Password
        </button>
    </div>

    {{-- Centred dialog, .remedi-modal like #logoutModal and the POS receipt:
         fixed and flex-centred so it opens over the page rather than pushing
         the profile layout around. --}}
    <div class="remedi-modal" id="changePasswordModal" role="dialog" aria-modal="true"
         aria-labelledby="changePasswordModalTitle">
      <div class="remedi-modal__panel remedi-modal__panel--form">
        <div class="icon-head">
            <i class="ti ti-lock" aria-hidden="true"></i>
            <span>
                <strong id="changePasswordModalTitle">Change Password</strong>
                <small>Update your password periodically to keep your account secure.</small>
            </span>
        </div>

        <form method="POST" action="{{ route('password.update') }}" id="changePasswordForm">
            @csrf
            @method('PUT')

            <div class="field" style="margin-bottom:14px;">
                <label for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password" autocomplete="current-password">
                <p class="err" data-err-for="current_password" hidden></p>
                @error('current_password', 'updatePassword')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="field" style="margin-bottom:14px;">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" autocomplete="new-password">
                <p class="err" data-err-for="password" hidden></p>
                @error('password', 'updatePassword')<p class="err">{{ $message }}</p>@enderror
                {{-- Delegated in layouts/app.blade.php's document 'input' listener,
                     same pattern as .pw-toggle -- see the comment there. --}}
                <div class="pw-strength" data-pw-strength-for="password" hidden>
                    <div class="pw-strength-bar"><span></span></div>
                    <span class="pw-strength-label"></span>
                </div>
            </div>

            <div class="field" style="margin-bottom:18px;">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">
                <p class="err" data-err-for="password_confirmation" hidden></p>
                @error('password_confirmation', 'updatePassword')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="remedi-modal__actions">
                <button type="button" class="btn btn-secondary" id="changePasswordCancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Change Password</button>
            </div>
        </form>
      </div>
    </div>

    {{-- No JavaScript: the trigger cannot open anything, so drop the dialog out
         of its overlay and render it as a plain block in the flow, with the
         dead trigger hidden. The form still posts and still works. --}}
    <noscript>
        <style>
            #changePasswordTrigger { display: none; }
            #changePasswordModal { position: static; display: block; padding: 0; background: none; z-index: auto; }
            #changePasswordModal .remedi-modal__panel { max-width: none; padding: 0; box-shadow: none; animation: none; background: none; }
            #changePasswordModal .icon-head { display: none; }
        </style>
    </noscript>

        <script>
        /* Change password over AJAX. The page also carries the profile form,
           the avatar and the activity list, and a full reload to report one
           line of text threw all of that away — including anything half-typed
           in the profile form beside it.

           Laravel already answers a failed validation with 422 + {errors} when
           the request is AJAX, so the failure path needs no server-side
           branching; only the success shape is explicit in PasswordController. */
        (function () {
            const form = document.getElementById('changePasswordForm');
            const flash = document.getElementById('passwordFlash');
            if (!form || !flash) return;

            const btn = form.querySelector('button[type=submit]');
            const label = btn.textContent;
            let busy = false;

            /* ── The dialog ──
               Same shell and the same guards as #logoutModal: Escape, backdrop
               click, focus moved in on open and restored to the trigger on
               close. The trap here walks every focusable control rather than
               alternating between two, because this panel holds three inputs
               and two buttons. */
            const trigger = document.getElementById('changePasswordTrigger');
            const modal = document.getElementById('changePasswordModal');
            const panel = modal ? modal.querySelector('.remedi-modal__panel') : null;
            const cancel = document.getElementById('changePasswordCancel');
            let lastFocus = null;

            function focusable() {
                return [...panel.querySelectorAll('input, button')].filter(function (el) {
                    return !el.disabled && el.offsetParent !== null;
                });
            }

            // REMEDI.lockScroll + preventScroll, same as the layout's dialogs:
            // body{overflow:hidden} clamps the page offset to zero, and focusing
            // a field inside a fixed overlay lets the browser scroll the
            // document to reveal it. Between them, opening this dialog part way
            // down the profile page threw you back to the top.
            let unlockScroll = null;

            function openModal() {
                lastFocus = document.activeElement;
                modal.classList.add('is-open');
                unlockScroll = REMEDI.lockScroll();
                form.querySelector('#current_password').focus({ preventScroll: true });
            }

            function closeModal() {
                modal.classList.remove('is-open');
                if (unlockScroll) { unlockScroll(); unlockScroll = null; }
                clearErrors();
                form.reset();

                // Fall back to the trigger: lastFocus can be <body>, and
                // body.focus() is a no-op that leaves the caret on a password
                // field inside a now-hidden dialog.
                var back = (lastFocus && lastFocus !== document.body && lastFocus.focus) ? lastFocus : trigger;
                if (back) back.focus({ preventScroll: true });
            }

            if (trigger && modal) {
                trigger.addEventListener('click', openModal);
                if (cancel) cancel.addEventListener('click', closeModal);

                modal.addEventListener('mousedown', function (e) {
                    if (!panel.contains(e.target)) closeModal();
                });

                document.addEventListener('keydown', function (e) {
                    if (!modal.classList.contains('is-open')) return;

                    if (e.key === 'Escape') { closeModal(); return; }

                    if (e.key === 'Tab') {
                        const items = focusable();
                        if (!items.length) return;
                        const first = items[0], last = items[items.length - 1];
                        if (e.shiftKey && document.activeElement === first) {
                            e.preventDefault(); last.focus({ preventScroll: true });
                        } else if (!e.shiftKey && document.activeElement === last) {
                            e.preventDefault(); first.focus({ preventScroll: true });
                        }
                    }
                });

                // A server-rendered validation error means the form was posted
                // without JS and came back with something to fix, so open
                // straight onto it rather than hiding it behind the trigger.
                if (form.querySelector('.err:not([hidden])')) openModal();
            }

            function clearErrors() {
                form.querySelectorAll('[data-err-for]').forEach(function (p) {
                    p.hidden = true;
                    p.textContent = '';
                });
                flash.innerHTML = '';
            }

            function notify(type, message) {
                flash.innerHTML = '';
                const el = document.createElement('div');
                el.className = 'alert alert-' + type;
                const icon = document.createElement('i');
                icon.className = type === 'success' ? 'ti ti-circle-check' : 'ti ti-alert-circle';
                icon.setAttribute('aria-hidden', 'true');
                const span = document.createElement('span');
                span.textContent = message;
                el.appendChild(icon);
                el.appendChild(span);
                flash.appendChild(el);
            }

            function showErrors(errors) {
                let placed = false;

                Object.keys(errors || {}).forEach(function (field) {
                    const p = form.querySelector('[data-err-for="' + field + '"]');
                    const msg = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
                    if (p) { p.textContent = msg; p.hidden = false; placed = true; }
                });

                // An error for a field this form has no box for (an expired
                // session, say) still has to surface somewhere.
                if (!placed) notify('danger', 'Could not update the password.');
            }

            form.addEventListener('submit', function (e) {
                if (busy) return;
                e.preventDefault();
                clearErrors();

                busy = true;
                btn.disabled = true;
                btn.textContent = 'Updating…';

                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                })
                    .then(function (res) {
                        return res.json().then(function (data) {
                            return { ok: res.ok, status: res.status, data: data };
                        });
                    })
                    .then(function (r) {
                        busy = false;
                        btn.disabled = false;
                        btn.textContent = label;

                        if (r.ok && r.data.success) {
                            notify('success', r.data.message || 'Password updated.');

                            // Close the dialog: the job is done, and leaving
                            // three empty password boxes open invites a second
                            // change nobody asked for. The message lands on the
                            // card behind it, which is still on screen.
                            if (modal) closeModal();
                            return;
                        }

                        if (r.status === 422) { showErrors(r.data.errors); return; }
                        notify('danger', r.data.message || 'Could not update the password.');
                    })
                    .catch(function () {
                        // Offline or a non-JSON error page: fall back to a real
                        // post rather than leaving the button disabled.
                        busy = true;
                        form.submit();
                    });
            });
        })();
        </script>
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

        <form method="POST" action="{{ route('profile.update') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-user-edit"
          data-confirm-title="Save profile changes?" data-confirm-body="Your personal information will be updated."
          data-confirm-label="Save changes">
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
                {{-- Scoped to THIS account (see AuditTrailController::applyFilters'
                     user_id filter) -- an unscoped link opened the whole
                     system's audit trail, not "your" activity the heading
                     above it promises. all=1 as well: the page defaults an
                     unscoped visit to today only, and "all activity logs"
                     means the account's full history, not just today's. --}}
                <p style="margin:14px 0 0;">
                    <a href="{{ route('audit.index', ['user_id' => auth()->id(), 'all' => 1]) }}" class="view-all">View all activity logs &rsaquo;</a>
                </p>
            @endif
        @else
            <p style="color:#94a3b8; font-size:13.5px;">No recorded activity yet.</p>
        @endif
    </div>
  </div>
</div>

{{-- Delete Account was removed from this page by request. The route
     (profile.destroy) and profile/partials/delete-user-form.blade.php are left
     in place, so restoring it is re-adding the card, not rebuilding it. Admins
     can still remove accounts from User Management. --}}
@endsection
