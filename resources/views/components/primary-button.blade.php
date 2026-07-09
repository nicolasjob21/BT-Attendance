<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-5 py-2.5 bg-accent-400 border border-transparent rounded-none font-display font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-500 active:bg-accent-600 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2 ring-offset-white dark:ring-offset-ink transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
