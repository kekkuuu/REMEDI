<x-error-page
    code="500"
    title="Something went wrong"
    message="An unexpected error happened on our end. Nothing from this action was saved — please try again, and let an administrator know if it keeps happening."
    :primary="[
        'label' => auth()->check() ? 'Go to Dashboard' : 'Go to Login',
        'href' => auth()->check() ? route('dashboard') : route('login'),
    ]"
/>
