<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center px-5 py-2.5 bg-transparent border border-brand-400 dark:border-brand-400/60 rounded-none font-display font-semibold text-xs text-brand-600 dark:text-brand-300 uppercase tracking-widest hover:bg-brand-400 hover:text-white dark:hover:text-ink focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 ring-offset-white dark:ring-offset-ink disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
