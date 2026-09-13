<x-error-page
    code="403"
    title="You don't have access to this page"
    message="This area is limited to admin accounts. If you think this is a mistake, ask an administrator to check your account's role."
    :primary="[
        'label' => auth()->check() ? 'Go to Dashboard' : 'Go to Login',
        'href' => auth()->check() ? route('dashboard') : route('login'),
    ]"
/>
