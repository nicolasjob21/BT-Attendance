<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-app btn-md btn-danger']) }}>
    {{ $slot }}
</button>
