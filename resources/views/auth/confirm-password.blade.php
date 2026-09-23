@extends('layouts.app')

{{-- Reached through the `password.confirm` middleware -- today only the
     Safeguard page uses it (routes/web.php). Deliberately NOT a separate
     sign-in screen: it renders the normal app shell and the layout opens
     #passwordGateModal over it on load (data-auto-open, set in
     layouts/app.blade.php on this route), the same pop-up the sidebar link
     opens. A wrong password posted without JavaScript comes back here with
     the error shown inside that pop-up. --}}
@section('title', 'Safeguard')

@section('content')
<div class="page-head">
    <div class="page-head-text">
        <h3>Safeguard</h3>
        <p>Enter your login password to continue.</p>
    </div>
</div>
@endsection
