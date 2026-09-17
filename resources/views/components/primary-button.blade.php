<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-app btn-md btn-brand']) }}>
    {{ $slot }}
</button>
