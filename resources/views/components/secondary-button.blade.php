<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-app btn-md btn-secondary']) }}>
    {{ $slot }}
</button>
