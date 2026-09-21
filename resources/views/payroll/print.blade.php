<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="utf-8">
    <title>{{ $single ? 'Payslip · '.$single->employee?->full_name : 'Payslips' }} · {{ $period->label() }}</title>
    @vite(['resources/css/app.css'])
    <style>
        body { background: #fff; }
        .sheet { page-break-after: always; break-after: page; max-width: 720px; margin: 0 auto; padding: 24px; }
        .sheet:last-child { page-break-after: auto; break-after: auto; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } .card { box-shadow: none !important; border: 1px solid #ddd; } }
    </style>
</head>
<body class="font-sans text-gray-800">
    <div class="toolbar sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-gray-200 bg-white/95 px-6 py-3 backdrop-blur">
        <div class="text-sm">
            @if($single)
                Payslip · <b>{{ $single->employee?->full_name }}</b> <span class="text-gray-500">{{ $single->employee?->employee_no }}</span> · {{ $period->label() }}
                · <span class="badge {{ $single->employee?->paysByCard() ? 'badge-info' : 'badge-neutral' }}">{{ $single->employee?->paysByCard() ? 'Card' : 'Cash' }}</span>
            @else
                <b>{{ $items->count() }}</b> payslip(s) · {{ $period->label() }}
                @if($method) · <span class="badge {{ $method === 'card' ? 'badge-info' : 'badge-neutral' }}">{{ $method === 'card' ? 'Card' : 'Cash' }} employees</span>@endif
                <span class="text-gray-500">· one per page</span>
            @endif
        </div>
        <div class="flex gap-2">
            <a href="{{ route('payroll.index', ['period' => $period->id]) }}" class="btn-app btn-md btn-secondary">Back</a>
            <button onclick="window.print()" class="btn-app btn-md btn-brand">{{ $single ? 'Print' : 'Print all' }}</button>
        </div>
    </div>
    @forelse($items as $item)
        <div class="sheet">@include('payroll._payslip', ['item' => $item])</div>
    @empty
        <p class="p-10 text-center text-gray-500">No payslips match.</p>
    @endforelse
</body>
</html>
