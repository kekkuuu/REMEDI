<x-error-page
    code="419"
    title="This page timed out"
    message="For your security, a form left open too long stops working and has to be reloaded before it can be submitted. Nothing was saved from that attempt — please try again."
    :primary="[
        'label' => auth()->check() ? 'Go to Dashboard' : 'Go to Login',
        'href' => auth()->check() ? route('dashboard') : route('login'),
    ]"
/>
