@extends('layouts.app')

@section('title', 'Manage Categories')

@section('content')
<div class="page-head">
    <a href="{{ route('products.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>Manage Categories</h3>
        <p>Categories drive the sidebar, the product form and the return rules.</p>
    </div>
</div>

{{-- Add panel. The circled + marks this card as the one that DOES something,
     so it does not read as a second table header.

     The card now holds only the TRIGGER; the form itself lives in the centred
     dialog below, the same two-step the profile page's Change Password uses.
     No `js-confirm` on it any more either -- the dialog IS the deliberate step,
     and a confirm modal opening on top of a form modal is two dialogs deep for
     one category name. --}}
<div class="form-card" style="margin-bottom:20px;">
    <div class="cat-add">
        <span class="cat-add-mark" aria-hidden="true"><i class="ti ti-plus"></i></span>
        <div class="cat-add-body">
            <h4>Add New Category</h4>
            <p class="cat-add-note">A new category appears in the sidebar, in the product form, and
               in the return rules that read the category name.</p>
        </div>
        <button type="button" id="addCategoryTrigger" class="btn btn-primary btn-lg">
            <i class="ti ti-plus" aria-hidden="true"></i> Add Category
        </button>
    </div>
</div>

{{-- Centred dialog, .remedi-modal like #logoutModal, the POS receipt and the
     Change Password card: fixed and flex-centred, so it opens OVER the page
     rather than pushing the table below it around. --}}
<div class="remedi-modal" id="addCategoryModal" role="dialog" aria-modal="true"
     aria-labelledby="addCategoryTitle">
  <div class="remedi-modal__panel">
    <h3 id="addCategoryTitle">Add New Category</h3>
    <p>It will appear in the sidebar and in the product form straight away.</p>

    <form method="POST" action="{{ route('categories.store') }}" id="addCategoryForm">
        @csrf

        <div class="cat-modal-field">
            <label for="new_category_name">Category name</label>
            <input type="text" id="new_category_name" name="name" placeholder="e.g. First Aid"
                   value="{{ old('name') }}" required>
            {{-- Its own error bag, so a failed RENAME further down the page
                 cannot open this dialog: both actions validate a field called
                 `name`, and the default bag cannot tell them apart. --}}
            @error('name', 'addCategory')
                <p class="cat-modal-err">{{ $message }}</p>
            @enderror
        </div>

        <div class="remedi-modal__actions">
            <button type="button" class="btn btn-secondary" id="addCategoryCancel">Cancel</button>
            <button type="submit" class="btn btn-primary">
                <i class="ti ti-plus" aria-hidden="true"></i> Add Category
            </button>
        </div>
    </form>
  </div>
</div>

{{-- No JavaScript: the trigger cannot open anything, so the dialog drops out of
     its overlay and renders as a plain block in the flow, with the dead trigger
     hidden. The form still posts and still works. Same fallback as the profile
     page's Change Password. --}}
<noscript>
    <style>
        #addCategoryTrigger { display: none; }
        #addCategoryModal { position: static; display: block; padding: 0; background: none; z-index: auto; }
        #addCategoryModal .remedi-modal__panel { max-width: none; padding: 0; box-shadow: none; animation: none; background: none; }
    </style>
</noscript>

<div class="form-card">
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr>
                <th>Category</th>
                <th style="text-align:right;"># Products</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach($categories as $cat)
            <tr>
                {{-- The name is still an input, so renaming survives the
                     redesign -- it just looks like text until you touch it.
                     CategoryController::update refuses to rename a
                     rule-driving category (renaming MEDICINE once reclassified
                     1,393 products), so the guard is server-side either way. --}}
                <td>
                    <form method="POST" action="{{ route('categories.update', $cat) }}" class="cat-name">
                        @csrf
                        @method('PUT')
                        <span class="cat-icon" aria-hidden="true"><i class="ti {{ $cat->icon }}"></i></span>
                        <input type="text" name="name" value="{{ $cat->name }}" class="cat-rename"
                               data-original="{{ $cat->name }}" aria-label="Rename {{ $cat->name }}">
                        <button type="submit" class="btn btn-primary btn-sm cat-save" hidden>
                            <i class="ti ti-device-floppy" aria-hidden="true"></i> Save
                        </button>
                    </form>
                </td>

                <td style="text-align:right;"><span class="cat-count">{{ number_format($cat->products_count) }}</span></td>

                <td>
                    <div class="cat-actions">
                        <form method="POST" action="{{ route('categories.destroy', $cat) }}"
                              class="js-confirm"
                              data-confirm-title="Delete this category?"
                              data-confirm-body="Delete the category &quot;{{ $cat->name }}&quot;? Only categories with no products can be removed."
                              data-confirm-label="Delete"
                              data-confirm-icon="ti-trash"
                              data-on-success="remove-row">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger-outline btn-sm">
                                <i class="ti ti-trash" aria-hidden="true"></i> Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

<style>
    .cat-add-note { margin: 0; font-size: 13.5px; color: var(--ink-soft); max-width: 62ch; }

    .cat-modal-field { margin-bottom: 18px; }

    .cat-modal-field label {
        display: block;
        margin-bottom: 7px;
        font-size: 13.5px;
        font-weight: 500;
        color: var(--ink);
    }

    .cat-modal-field input {
        width: 100%;
        padding: 11px 13px;
        border: 1px solid var(--line);
        border-radius: 10px;
        font-size: 14px;
        font-family: inherit;
        color: var(--ink);
        background: #fff;
    }

    .cat-modal-field input:focus {
        outline: none;
        border-color: var(--brand);
        box-shadow: 0 0 0 3px var(--brand-soft);
    }

    .cat-modal-err { margin: 8px 0 0; font-size: 12.5px; color: #dc2626; }
</style>

<script>
    /* The Add Category dialog. Same shell and the same guards as #logoutModal
       and the profile page's Change Password: Escape, backdrop click, a focus
       trap over the panel's own controls, focus moved in on open and restored
       to the trigger on close, and REMEDI.lockScroll so opening it part way
       down a long category list does not throw the page back to the top.

       It opens by itself when the server bounced the form back with an error,
       because the post is a plain form submit: without that the page would
       reload with the dialog shut and the reason for the bounce out of sight.
       The error bag is named, so a failed rename in the table below cannot
       trigger it. */
    (function () {
        var trigger = document.getElementById('addCategoryTrigger');
        var modal = document.getElementById('addCategoryModal');
        if (!trigger || !modal) return;

        var panel = modal.querySelector('.remedi-modal__panel');
        var cancel = document.getElementById('addCategoryCancel');
        var input = document.getElementById('new_category_name');
        var lastFocus = null;
        var unlockScroll = null;

        function focusable() {
            return [].slice.call(panel.querySelectorAll('input, button')).filter(function (el) {
                return !el.disabled && el.offsetParent !== null;
            });
        }

        function open() {
            lastFocus = document.activeElement;
            modal.classList.add('is-open');
            if (window.REMEDI && REMEDI.lockScroll) unlockScroll = REMEDI.lockScroll();
            input.focus({ preventScroll: true });
        }

        function close() {
            modal.classList.remove('is-open');
            if (unlockScroll) { unlockScroll(); unlockScroll = null; }

            /* Focus must never be left inside a dialog that is now
               display:none. Prefer whatever held it before, but fall back to
               the trigger: lastFocus can be <body> (the dialog opened from a
               script, or from a click that did not focus anything), and
               body.focus() is a no-op that would strand the caret on a hidden
               input -- from there Tab walks the page from the top. */
            var back = (lastFocus && lastFocus !== document.body && lastFocus.focus) ? lastFocus : trigger;
            back.focus({ preventScroll: true });
        }

        trigger.addEventListener('click', open);
        if (cancel) cancel.addEventListener('click', close);

        modal.addEventListener('mousedown', function (e) {
            if (!panel.contains(e.target)) close();
        });

        document.addEventListener('keydown', function (e) {
            if (!modal.classList.contains('is-open')) return;

            if (e.key === 'Escape') { close(); return; }

            if (e.key === 'Tab') {
                var items = focusable();
                if (!items.length) return;
                var first = items[0], last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault(); last.focus({ preventScroll: true });
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault(); first.focus({ preventScroll: true });
                }
            }
        });

        @if($errors->addCategory->any())
            open();   /* reopened: the server rejected the name */
        @endif
    })();
</script>

<script>
    /* Reveal Save only once a name has actually changed. A Save button on every
       row invites clicks that submit the value already stored -- which is a
       write, an audit entry and a cache clear for nothing. */
    (function () {
        document.querySelectorAll('.cat-rename').forEach(function (input) {
            var save = input.parentElement.querySelector('.cat-save');
            if (!save) return;

            var sync = function () {
                save.hidden = input.value.trim() === (input.dataset.original || '').trim();
            };

            input.addEventListener('input', sync);
            sync();
        });
    })();
</script>
@endsection
