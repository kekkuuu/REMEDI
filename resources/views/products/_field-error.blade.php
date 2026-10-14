{{-- The error line under one product-form field. Rendered on every field,
     empty and hidden when there is nothing to say, so the same element serves
     a server-side redisplay (no JavaScript) and the inline check in
     public/assets/remedi.js (REMEDI.checkFields / showFieldErrors), which
     finds it by data-error-for. The field points at it with
     aria-describedby="<field>-error". --}}
<p class="err" id="{{ $field }}-error" data-error-for="{{ $field }}" role="alert" @unless($errors->has($field)) hidden @endunless>{{ $errors->first($field) }}</p>
