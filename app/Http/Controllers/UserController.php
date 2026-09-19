<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Columns the list may be sorted by, mapped to the actual column.
     *
     * A whitelist, not the request value: `orderBy()` interpolates its column
     * name straight into the SQL, so taking `?sort=` on trust is an injection
     * point. Anything not on this list falls back to `name`.
     *
     * `status` sorts on `is_active` and `joined` on `created_at`, because those
     * are what the column headings say — the user is sorting by what they can
     * see, not by the schema.
     */
    private const SORTABLE = [
        'id' => 'id',
        'name' => 'name',
        'email' => 'email',
        'role' => 'role',
        'status' => 'is_active',
        'joined' => 'created_at',
    ];

    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'role' => 'nullable|in:admin,staff',
            'status' => 'nullable|in:active,inactive',
            'sort' => 'nullable|string|max:20',
            'dir' => 'nullable|in:asc,desc',
        ]);

        // Counted BEFORE any filter, and deliberately so: these cards describe
        // the account list as a whole ("Total Users"), not the slice currently
        // on screen. Same rule SaleController::index follows for its "today"
        // cards -- a KPI that silently reports the filter is the bug where
        // "Total sales today" read PHP 0.00 whenever the list was narrowed.
        //
        // Skipped entirely on the AJAX path: the live-search response never
        // renders them (the cards stay untouched in the DOM on purpose, so a
        // keystroke can never make them lie), so computing four more COUNTs on
        // every keystroke would only be paying for numbers nobody reads.
        $stats = ($request->wantsJson() || $request->ajax()) ? [] : [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'admins' => User::where('role', 'admin')->count(),
        ];

        // ?archived=1 lists archived accounts, where Restore lives. The default
        // list -- and the four KPI cards above, which count through the same
        // soft-delete scope -- describe the accounts still in use.
        $archived = $request->boolean('archived');

        $query = $archived ? User::onlyTrashed() : User::query();

        if ($request->filled('search')) {
            // likeTerm() escapes the user's own % and _ — see Controller.
            //
            // GROUPED in a closure. It used to be
            // `where(name)->orWhere(email)`, which was harmless while search
            // was the only filter -- but the moment a second condition sits
            // beside it, the OR escapes the group and an email match returns
            // rows the role/status filter had excluded. Same trap as
            // SuggestController::sales(), where the role scope leaked exactly
            // this way.
            $like = $this->likeTerm($request->search);
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $sort = self::SORTABLE[$request->get('sort')] ?? 'name';
        $dir = $request->get('dir') === 'desc' ? 'desc' : 'asc';

        $users = $query->orderBy($sort, $dir)
            // A stable tie-break, so two accounts with the same role (or the
            // same status, where every row shares one of two values) do not
            // swap places between pages of the same sorted list.
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        // Realtime search: same AJAX partial-swap pattern as Inventory, POS,
        // Products and Forecasting. The table AND its footer ("Showing x to y
        // of z") are both in _rows.blade.php, since they always change
        // together -- there is nothing here for the KPI cards, which the
        // partial never renders.
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('admin.users._rows', compact('users', 'archived'))->render(),
            ]);
        }

        $archivedCount = User::onlyTrashed()->count();

        return view('admin.users.index', compact('users', 'stats', 'archived', 'archivedCount'));
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        // Same trim+lowercase-before-validate as RegisteredUserController::
        // store() -- this was the one email-write path missing it. The
        // `lowercase` rule below REJECTS a capitalised address rather than
        // folding it, so without this an admin editing "Emman@remedi.com"
        // back in got refused with no clue why (a js-confirm failure shows
        // only the generic dialog text), and a capitalised address that DID
        // pass some other path would sit inconsistent with the rest of the
        // app treating addresses as lowercase.
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email,'.$user->id,
            'role' => 'required|in:admin,staff',
            'password' => 'nullable|confirmed|min:8',
        ]);

        // Prevent admin from demoting themselves and locking themselves out
        if ($user->id === auth()->id() && $validated['role'] !== 'admin') {
            return $this->actionFailed($request, 'You cannot change your own role away from Admin.', 'role');
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        AuditTrail::log('Updated', "Updated user account: {$user->name}");

        return $this->actionOk($request, "User \"{$user->name}\" updated successfully.", redirect()->route('users.index'));
    }

    public function toggleActive(Request $request, User $user)
    {
        if ($user->id === auth()->id()) {
            return $this->actionFailed($request, 'You cannot deactivate your own account.', 'user');
        }

        // The self-check above stops an admin locking THEMSELVES out, but does
        // nothing about two different active admins deactivating each other in
        // the same instant -- both requests pass EnsureUserIsActive (each
        // actor's own is_active is still true when it's read), both pass the
        // self-check (the target is the OTHER admin), and both writes land,
        // leaving zero active admins and every role:admin route -- including
        // this one -- unreachable through the UI. destroy() gets this
        // protection for free (the actor can never delete themselves, so the
        // actor alone guarantees a survivor); toggling doesn't have that
        // guarantee, so it needs the same explicit count ProfileController::
        // destroy() takes, plus a lock so two concurrent toggles can't both
        // read "at least one other active admin" before either commits.
        $status = DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked->isAdmin() && $locked->is_active) {
                $otherActiveAdmins = User::where('role', 'admin')
                    ->where('is_active', true)
                    ->whereKeyNot($locked->getKey())
                    ->count();

                if ($otherActiveAdmins === 0) {
                    return null;
                }
            }

            $locked->is_active = ! $locked->is_active;
            $locked->save();
            $user->is_active = $locked->is_active;

            return $locked->is_active ? 'activated' : 'deactivated';
        });

        if ($status === null) {
            return $this->actionFailed(
                $request,
                'This is the only active administrator account. Deactivating it would leave no one '
                    .'able to manage users, products or reports. Promote another account to Admin first.',
                'user'
            );
        }

        AuditTrail::log('Updated', "Account {$status}: {$user->name}");

        // `state` lets the row update itself in place -- the badge and the
        // button's own label both flip -- instead of the page reloading just to
        // change one word.
        return $this->actionOk($request, "User \"{$user->name}\" {$status} successfully.", back(), [
            'state' => [
                'is_active' => $user->is_active,
                'badge' => $user->is_active ? 'Active' : 'Inactive',
                'action' => $user->is_active ? 'Deactivate' : 'Activate',
            ],
        ]);
    }

    /**
     * Archive an account -- the replacement for Delete.
     *
     * Answers in two shapes, the same way PosController::checkout does: JSON
     * for the AJAX confirm dialog on the user list, and the original redirect
     * for a plain form post, which is what happens with JavaScript off.
     *
     * The old "a cashier with sales cannot be deleted" refusal is gone, and it
     * is the clearest case for why. It existed because a delete cascaded into
     * `sales` and took every transaction that person had rung up with it (two
     * accounts and 25 sale ids were lost before it was added), and the
     * message had to send the admin to Deactivate instead. An archived account
     * loses nothing: the row stays, `Sale::user()` reads it back withTrashed(),
     * and every receipt and list still names its cashier.
     *
     * Archiving also IS the sign-in block, not just a tidy-up. The auth
     * provider loads users through the soft-delete scope, so the account can
     * no longer log in, and a session it already holds stops resolving to a
     * user on its next request. Deactivate remains the reversible, in-place
     * way to suspend someone who is still on the list; archive is for accounts
     * that are finished with.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->id === auth()->id()) {
            return $this->actionFailed($request, 'You cannot archive your own account.', 'user');
        }

        // Captured first: the name is read after the model has been archived,
        // and a value read off a trashed record is the kind of thing that
        // quietly becomes null later.
        $name = $user->name;

        // Logged after the archive succeeds, not before -- same rule as
        // ProductController::destroy. A refused archive must not leave an
        // audit entry claiming it happened.
        $user->delete();

        AuditTrail::log('Archived', "Archived user account: {$name}");

        return $this->actionOk($request, "User \"{$name}\" archived. They can no longer sign in, and their sales history is untouched.", redirect()->route('users.index'));
    }

    /** Bring an archived account back. */
    public function restore(Request $request, User $user)
    {
        abort_unless($user->trashed(), 404);

        $user->restore();

        AuditTrail::log('Restored', "Restored user account: {$user->name}");

        return $this->actionOk($request, "User \"{$user->name}\" restored.", redirect()->route('users.index', ['archived' => 1]));
    }
}
