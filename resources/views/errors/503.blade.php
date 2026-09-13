{{--
    No auth()/route() calls here, unlike the other error views. A 503 often
    means the database itself is unreachable (a mid-deploy migration, a
    restart) or the app is deliberately down for maintenance -- the very
    conditions under which asking Eloquent "is this user signed in?" could
    throw a second, uglier error on top of the first. Everything on this page
    has to be answerable with no backend at all.
--}}
<x-error-page
    code="503"
    title="Back in a moment"
    message="REMEDI is undergoing brief maintenance. This usually takes a minute or two — please check back shortly."
    :primary="['label' => 'Try Again', 'href' => '/']"
/>
