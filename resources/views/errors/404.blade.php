<x-error-page
    code="404"
    title="Page not found"
    message="The page you're looking for doesn't exist, or the link may be out of date."
    :primary="[
        'label' => auth()->check() ? 'Go to Dashboard' : 'Go to Login',
        'href' => auth()->check() ? route('dashboard') : route('login'),
    ]"
/>
