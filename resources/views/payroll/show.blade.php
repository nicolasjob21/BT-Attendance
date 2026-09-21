<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Payslip</h1>
    </x-slot>
    <x-slot name="back">{{ url()->previous() }}</x-slot>
    <x-slot name="backLabel">Back</x-slot>

    <div class="mx-auto max-w-2xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
            <p class="text-sm text-gray-500 dark:text-slate-400">Use <b>Download</b> to save this payslip as a PDF (choose "Save as PDF" as the printer).</p>
            <button onclick="window.print()" class="btn-app btn-md btn-brand">Download / Print</button>
        </div>

        @include('payroll._payslip', ['item' => $item])
    </div>
</x-app-layout>
