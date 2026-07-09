@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 dark:border-brand-400/20 focus:border-brand-500 focus:ring-brand-500 rounded-xs shadow-xs']) }}>
